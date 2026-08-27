<?php

namespace App\Http\Controllers\Api\Member;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\StoreEqubPaymentRequest;
use App\Http\Resources\Api\EqubPaymentResource;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Contribution collection for members.
 *
 * Every contribution is collected through the Dashen Bank SuperApp. A record is created in
 * the `pending` state together with a signed order payload, and becomes `paid` only once
 * Dashen's settlement notification has been received and independently verified. Neither
 * the 201 response nor the SuperApp's own callback into the mini-app is proof of
 * settlement — the mini-app must poll this controller's `show` endpoint.
 *
 * Scope follows the money: a member sees and pays their own contributions and every
 * contribution on a place they sponsor for someone without an account.
 *
 * @group Member · Contributions
 * @authenticated
 */
class EqubPaymentController extends Controller
{
    /**
     * List payments for current user's memberships.
     */
    public function index(Request $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        // A member's payments are their own plus the ones on places they hold
        // for someone else in a Group Equb. A responsibility seat has no
        // member_id, so filtering on that alone hid every contribution the
        // sponsor had actually made.
        $query = EqubPayment::query()
            ->whereHas('membership', fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->with(['membership.member.user', 'membership.sponsor.user', 'membership.equbGroup.package']);

        if ($request->filled('equb_membership_id')) {
            $query->where('equb_membership_id', $request->input('equb_membership_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $payments = $query->latest('payment_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubPaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Show a single payment (only if it belongs to current user's membership).
     */
    public function show(Request $request, EqubPayment $equbPayment): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }
        // isPayableBy() resolves to the member on a normal membership and to
        // the sponsor on a place held for someone else, so both can read their
        // own receipts and neither can read anybody else's.
        if (! $equbPayment->membership?->isPayableBy($member->id)) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $equbPayment->load(['membership.member.user', 'membership.sponsor.user', 'membership.equbGroup.package']);

        return response()->json([
            'status' => 'success',
            'data' => new EqubPaymentResource($equbPayment),
        ]);
    }


    /**
     * Dashen's settlement notification.
     *
     * Signature-verified, no bearer token. Resolution is delegated wholesale to
     * DashenService rather than being half-done here: the old implementation
     * looked the payment up by `reference` before handing over, which meant a
     * batch notification — whose reference is a `batch_reference` — was
     * rejected with a 404 before the handler that knows how to resolve it ever
     * ran. One place decides what a reference means.
     *
     * The RAW body is passed through for the HMAC. Re-encoding `$request->all()`
     * would change key order and whitespace and break a valid signature.
     */
    public function notification(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Received Dashen notification: '.json_encode($payload));

        try {
            $result = app(\App\Services\DashenService::class)->handleNotificationForEqubPayment(
                $payload,
                $request->header('x-dashen-signature') ?? $request->header('x-api-signature'),
                $request->getContent(),
            );

            // 200 even when the charge itself failed: the notification was
            // handled correctly, and a non-200 tells Dashen to retry a delivery
            // that has nothing left to do. Settlement is never inferred from
            // this status code.
            return response()->json([
                'status' => $result['success'] ? 'success' : 'error',
                'message' => $result['message'] ?? 'Notification processed.',
            ]);
        } catch (\Throwable $e) {
            // A 500 here is a real processing failure and Dashen should retry.
            Log::error('Dashen notification processing failed: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Record a contribution and, for the gateway method, sign a Dashen order.
     */
    public function store(StoreEqubPaymentRequest $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        // Either their own membership, or a place they are responsible for.
        // The second case is the whole point of "My Responsibility People":
        // the seat has nobody behind it to pay, so the sponsor pays it.
        $membership = EqubMembership::query()
            ->where('id', $request->input('equb_membership_id'))
            ->where(fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->first();

        if (! $membership) {
            return response()->json(['status' => 'error', 'message' => 'Membership not found or access denied.'], 404);
        }

        $method = $request->input('payment_method');
        $amount = (float) $request->input('amount');
        $paymentDate = $request->input('payment_date');

        if ($method === EqubPaymentMethod::Dashen->value) {
            // The charge is signed server-side, so the signed amount had better
            // be the amount that is actually owed. The client still sends one —
            // it is what the member saw on the confirmation screen — but a
            // disagreement is refused rather than quietly resolved either way.
            // Silently overriding would charge a different figure from the one
            // on screen; silently accepting would let the client set the price.
            $due = round((float) $membership->contribution_amount, 2);

            if ($due <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This contribution has no amount set.',
                ], 422);
            }

            if (round($amount, 2) !== $due) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'That is not the amount due on this contribution.',
                ], 422);
            }

            $payment = EqubPayment::create([
                'equb_membership_id' => $membership->id,
                'amount' => $due,
                'payment_date' => $paymentDate,
                'payment_method' => EqubPaymentMethod::Dashen,
                'status' => EqubPaymentStatus::Pending,
            ]);

            try {
                $result = app(\App\Services\DashenService::class)->createEqubOrder($payment);
            } catch (\Throwable $e) {
                // Nothing was charged, so the pending row must not be left
                // behind looking like money the member still owes. Same rule
                // the batch path has always followed.
                $payment->delete();

                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }

            if (! ($result['success'] ?? false)) {
                $payment->delete();

                return response()->json([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Failed to initiate payment.',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated. Confirm it in the Dashen SuperApp.',
                'data' => new EqubPaymentResource($payment->load(['membership.equbGroup.package'])),
                // Handed straight to window.dashenbanksuperapp.send as the
                // `orderPayload` of an initiatePayment call. Opaque to the
                // client: it is signed, and altering any field invalidates it.
                'order_payload' => $result['order_payload'],
                'auth_payload' => $result['auth_payload'],
                'reference' => $result['reference'],
            ], 201);
        }

        $payment = EqubPayment::create([
            'equb_membership_id' => $membership->id,
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => $method === EqubPaymentMethod::Offline->value ? EqubPaymentMethod::Offline : EqubPaymentMethod::Manual,
            'status' => EqubPaymentStatus::Pending,
        ]);
        $payment->markAsPaid();
        app(\App\Services\EqubMembershipService::class)->completeIfEligible($membership);
        $this->sendPaymentSuccessNotification($payment);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment recorded. It may require admin approval.',
            'data' => new EqubPaymentResource($payment->load(['membership.member.user', 'membership.sponsor.user', 'membership.equbGroup.package'])),
        ], 201);
    }

    /**
     * Settle several contributions in one go.
     *
     * A member who holds places for "My Responsibility People" owes one
     * contribution per place, every round. Each place keeps its own payment
     * row — the ledger, the schedule and draw eligibility all count per place
     * — but the member should go through checkout once, for the total.
     *
     * The rows are created up front and tied together by batch_reference; the
     * SuperApp sees a single charge against that reference as its
     * merch_order_id, and the settlement notification marks every row carrying
     * it as paid together.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $member = $request->user()?->member;

        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $data = $request->validate([
            'equb_membership_ids' => ['required', 'array', 'min:1', 'max:20'],
            'equb_membership_ids.*' => ['integer'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['nullable', 'string'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['equb_membership_ids'])));

        $memberships = EqubMembership::query()
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q
                ->where('member_id', $member->id)
                ->orWhere('sponsor_member_id', $member->id))
            ->get();

        // All or nothing. A partial batch would either charge for places the
        // member did not choose or quietly skip one they did — both make the
        // amount on the confirmation screen a lie.
        if ($memberships->count() !== count($ids)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some of those contributions are not yours to pay.',
            ], 403);
        }

        // Each place is charged its own contribution, read from the membership
        // rather than from the request: the client never gets to say what
        // something costs.
        $total = round((float) $memberships->sum(fn (EqubMembership $m): float => (float) $m->contribution_amount), 2);

        if ($total <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'These contributions have no amount set.',
            ], 422);
        }

        $method = $data['payment_method'] ?? EqubPaymentMethod::Dashen->value;
        $isOnline = $method === EqubPaymentMethod::Dashen->value;
        $batchReference = 'EQUB-B'.strtoupper(Str::random(10));

        try {
            $payments = DB::transaction(function () use ($memberships, $data, $method, $isOnline, $batchReference) {
                return $memberships->map(fn (EqubMembership $m): EqubPayment => EqubPayment::create([
                    'equb_membership_id' => $m->id,
                    'amount' => (float) $m->contribution_amount,
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $isOnline
                        ? EqubPaymentMethod::Dashen
                        : ($method === EqubPaymentMethod::Offline->value
                            ? EqubPaymentMethod::Offline
                            : EqubPaymentMethod::Manual),
                    'status' => EqubPaymentStatus::Pending,
                    'batch_reference' => $batchReference,
                ]));
            });
        } catch (\Throwable $e) {
            Log::error('Batch Equb payment could not be created: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Could not start this payment. Please try again.',
            ], 500);
        }

        // Offline and manual settle immediately, exactly as the single path does.
        if (! $isOnline) {
            foreach ($payments as $payment) {
                $payment->markAsPaid();
                app(\App\Services\EqubMembershipService::class)->completeIfEligible($payment->membership);
            }

            $this->sendBatchSuccessNotification($member, $total, $payments->count());

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded. It may require admin approval.',
                'data' => EqubPaymentResource::collection($payments),
            ], 201);
        }

        try {
            $result = app(\App\Services\DashenService::class)
                ->createEqubBatchOrder($payments->all(), $batchReference, $total);
        } catch (\Throwable $e) {
            $this->discardBatch($payments);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        if (! ($result['success'] ?? false)) {
            // Nothing was charged, so the pending rows must not be left behind
            // looking like money the member still owes.
            $this->discardBatch($payments);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Failed to initiate payment.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment initiated. Confirm it in the Dashen SuperApp.',
            'data' => EqubPaymentResource::collection($payments),
            'order_payload' => $result['order_payload'],
            'auth_payload' => $result['auth_payload'],
            'reference' => $batchReference,
            // The authoritative sum to debit. Signed into the order, so the
            // client cannot re-add the individual amounts and arrive somewhere
            // else.
            'total_amount' => $total,
            'contributions' => $payments->count(),
        ], 201);
    }

    /** Rolls a batch back when the gateway never accepted it. */
    protected function discardBatch(\Illuminate\Support\Collection $payments): void
    {
        foreach ($payments as $payment) {
            $payment->delete();
        }
    }

    protected function sendBatchSuccessNotification($member, float $total, int $count): void
    {
        $phone = $member->user?->phone;

        if (! $phone) {
            return;
        }

        $message = $count > 1
            ? 'Your Equb payment of '.number_format($total, 2).' ETB covering '.$count
                .' contributions has been received successfully.'
            : 'Your Equb payment of '.number_format($total, 2).' ETB has been received successfully.';

        app(SmsService::class)->sendSms($phone, $message, null, null);
    }

    protected function sendPaymentSuccessNotification(EqubPayment $payment): void
    {
        $membership = $payment->membership;

        // The receipt goes to whoever paid. On a place held for someone else
        // that is the sponsor, who is also the only one of the two with a
        // phone number to text.
        $phone = $membership?->payerUser()?->phone;

        if (! $phone) {
            return;
        }

        $message = $membership->isResponsibilitySeat()
            ? 'Your Equb payment of '.$payment->amount.' ETB for '.$membership->displayName()
                .' has been received successfully.'
            : 'Your Equb payment of '.$payment->amount.' ETB has been received successfully.';

        app(SmsService::class)->sendSms($phone, $message, null, $payment);
    }
}

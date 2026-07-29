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
use Illuminate\Support\Facades\Log;

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

        $query = EqubPayment::query()
            ->whereHas('membership', fn ($q) => $q->where('member_id', $member->id))
            ->with(['membership.member.user', 'membership.equbGroup.package']);

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
        if ((int) $equbPayment->membership?->member_id !== (int) $member->id) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $equbPayment->load(['membership.member.user', 'membership.equbGroup.package']);

        return response()->json([
            'status' => 'success',
            'data' => new EqubPaymentResource($equbPayment),
        ]);
    }


    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Received Chapa webhook: '. json_encode($payload));

        if (! isset($payload['tx_ref'])) {
            return response()->json(['status' => 'error', 'message' => 'Reference not provided.'], 400);
        }

        $payment = EqubPayment::where('reference', $payload['tx_ref'])->first();

        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment not found.'], 404);
        }

        try {
            app(\App\Services\ChapaService::class)->handleWebhookForEqubPayment($payload);
            return response()->json(['status' => 'success', 'message' => 'Webhook processed.']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
    /**
     * Create a payment (offline) or initiate Chapa (online).
     */
    public function store(StoreEqubPaymentRequest $request): JsonResponse
    {
        $member = $request->user()?->member;
        if (! $member) {
            return response()->json(['status' => 'error', 'message' => 'Member profile not found.'], 404);
        }

        $membership = EqubMembership::query()
            ->where('id', $request->input('equb_membership_id'))
            ->where('member_id', $member->id)
            ->first();

        if (! $membership) {
            return response()->json(['status' => 'error', 'message' => 'Membership not found or access denied.'], 404);
        }

        $method = $request->input('payment_method');
        $amount = (float) $request->input('amount');
        $paymentDate = $request->input('payment_date');

        if ($method === EqubPaymentMethod::Chapa->value) {
            $payment = EqubPayment::create([
                'equb_membership_id' => $membership->id,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => EqubPaymentMethod::Chapa,
                'status' => EqubPaymentStatus::Pending,
            ]);
            try {
                $result = app(\App\Services\ChapaService::class)->initializeEqubPayment($payment, 'frontend');
                if (! $result['success']) {
                    return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'Failed to initiate payment.'], 422);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment initiated. Complete payment in the browser.',
                    'data' => new EqubPaymentResource($payment->load(['membership.equbGroup.package'])),
                    'checkout_url' => $result['checkout_url'],
                    'reference' => $result['reference'],
                ], 201);
            } catch (\Throwable $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
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
            'data' => new EqubPaymentResource($payment->load(['membership.member.user', 'membership.equbGroup.package'])),
        ], 201);
    }

    protected function sendPaymentSuccessNotification(EqubPayment $payment): void
    {
        $phone = $payment->membership?->member?->user?->phone;
        if ($phone) {
            $message = 'Your Equb payment of '.$payment->amount.' ETB has been received successfully.';
            app(SmsService::class)->sendSms($phone, $message, null, $payment);
        }
    }
}

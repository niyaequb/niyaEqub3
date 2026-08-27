<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EqubPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEqubPaymentRequest;
use App\Http\Requests\Admin\UpdateEqubPaymentRequest;
use App\Http\Resources\Api\EqubPaymentResource;
use App\Models\EqubPayment;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contribution register and reconciliation surface for operators.
 *
 * Unlike the member endpoints, these are not scoped to a single payer and reach every
 * contribution on the platform. `PUT` with a status transition from `pending` to `paid` is
 * the only settlement route other than Dashen's settlement notification, and exists solely
 * for charges Dashen took but never reported — always confirm against Dashen first.
 *
 * @group Admin · Contributions
 * @authenticated
 */
class EqubPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EqubPayment::query()->with(['membership.member.user', 'membership.equbGroup.package']);
        if ($request->filled('equb_membership_id')) {
            $query->where('equb_membership_id', $request->input('equb_membership_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }
        $payments = $query->latest('payment_date')->paginate($request->input('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => EqubPaymentResource::collection($payments),
            'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'per_page' => $payments->perPage(), 'total' => $payments->total()],
        ]);
    }

    public function show(EqubPayment $equbPayment): JsonResponse
    {
        $equbPayment->load(['membership.member.user', 'membership.equbGroup.package']);

        return response()->json(['status' => 'success', 'data' => new EqubPaymentResource($equbPayment)]);
    }

    public function store(StoreEqubPaymentRequest $request): JsonResponse
    {
        $payment = EqubPayment::create(array_merge($request->validated(), ['status' => EqubPaymentStatus::Pending]));
        if (in_array($payment->payment_method->value, ['offline', 'manual'], true)) {
            $payment->update(['status' => EqubPaymentStatus::Paid]);
            $this->sendPaymentSuccessNotification($payment);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Equb payment created successfully.',
            'data' => new EqubPaymentResource($payment->load(['membership.member.user', 'membership.equbGroup.package'])),
        ], 201);
    }

    public function update(UpdateEqubPaymentRequest $request, EqubPayment $equbPayment): JsonResponse
    {
        $wasPending = $equbPayment->status === EqubPaymentStatus::Pending;
        $equbPayment->update($request->validated());
        if ($wasPending && $equbPayment->fresh()->status === EqubPaymentStatus::Paid) {
            $this->sendPaymentSuccessNotification($equbPayment->fresh());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Equb payment updated successfully.',
            'data' => new EqubPaymentResource($equbPayment->fresh()->load(['membership.member.user', 'membership.equbGroup.package'])),
        ]);
    }

    public function destroy(EqubPayment $equbPayment): JsonResponse
    {
        $equbPayment->delete();

        return response()->json(['status' => 'success', 'message' => 'Equb payment deleted successfully.']);
    }

    /**
     * Re-sign a Dashen order for a contribution the payer abandoned.
     *
     * Returns a fresh signed order payload for an existing pending row. Note
     * what this can and cannot do: it produces something the MINI-APP can
     * present, because authorisation happens inside the SuperApp on the
     * member's own device. An operator calling this from the admin panel
     * cannot complete the payment themselves — the payload has to reach the
     * member's SuperApp session. Under Chapa this returned a URL an admin
     * could open anywhere, and that difference is inherent to moving inside
     * the bank's app, not something the endpoint can paper over.
     */
    public function initiateDashen(EqubPayment $equbPayment): JsonResponse
    {
        if ($equbPayment->payment_method->value !== 'dashen') {
            return response()->json(['status' => 'error', 'message' => 'Payment is not a Dashen payment.'], 422);
        }
        if ($equbPayment->isPaid()) {
            return response()->json(['status' => 'error', 'message' => 'Payment already completed.'], 422);
        }
        try {
            $result = app(\App\Services\DashenService::class)->createEqubOrder($equbPayment);
            if (! $result['success']) {
                return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'Failed to initialize payment.'], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated.',
                'order_payload' => $result['order_payload'],
                'reference' => $result['reference'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    protected function sendPaymentSuccessNotification(EqubPayment $payment): void
    {
        $member = $payment->membership?->member;
        $user = $member?->user;
        $phone = $user?->phone;
        if ($phone) {
            $message = 'Your Equb payment of '.$payment->amount.' ETB has been received successfully.';
            app(SmsService::class)->sendSms($phone, $message, null, $payment);
        }
    }
}

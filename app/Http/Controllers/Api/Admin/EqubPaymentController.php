<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\EqubPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEqubPaymentRequest;
use App\Http\Requests\Admin\UpdateEqubPaymentRequest;
use App\Http\Resources\Api\EqubPaymentResource;
use App\Models\EqubPayment;
use App\Services\Payments\EqubOrderService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contribution register and reconciliation surface for operators.
 *
 * Unlike the member endpoints, these are not scoped to a single payer and reach every
 * contribution on the platform. `PUT` with a status transition from `pending` to `paid` is
 * the only settlement route other than a bank's settlement notification, and exists solely
 * for charges a bank took but never reported — always confirm with that bank first.
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
        // Asked of the method rather than of a list of names, so adding a bank
        // never risks it being mistaken for something that settles on creation.
        if ($payment->payment_method?->settlesImmediately()) {
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
     * Re-sign a bank order for a contribution the payer abandoned.
     *
     * The bank is taken from the contribution itself rather than from the
     * request — a pending row already knows which bank it was created against,
     * and letting a caller name a different one would sign an order the
     * settlement path could never match back.
     *
     * NOTE WHAT THIS CAN AND CANNOT DO. It produces something the member's APP
     * can present, because authorisation happens inside the bank's own app on
     * the member's device. An operator calling this from the admin panel cannot
     * complete the payment themselves; the payload has to reach the member's
     * session. Under a hosted checkout this returned a URL an admin could open
     * anywhere. That difference is inherent to moving inside the bank's app,
     * not something this endpoint can paper over.
     */
    public function initiateGateway(
        EqubPayment $equbPayment,
        PaymentGatewayManager $gateways,
        EqubOrderService $orders,
    ): JsonResponse {
        $method = $equbPayment->payment_method;

        if (! $method?->isGateway()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This contribution was not collected through a bank.',
            ], 422);
        }

        if ($equbPayment->isPaid()) {
            return response()->json(['status' => 'error', 'message' => 'Payment already completed.'], 422);
        }

        $gateway = $gateways->tryGet($method->value);

        if (! $gateway) {
            return response()->json([
                'status' => 'error',
                'message' => $method->label().' is not available for payments right now.',
            ], 422);
        }

        $result = $orders->createFor($equbPayment, $gateway);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Failed to initialize payment.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment initiated.',
            'provider' => $gateway->slug(),
            'order_payload' => $result['order_payload'],
            'auth_payload' => $result['auth_payload'],
            'client' => $result['client'],
            'reference' => $result['reference'],
        ]);
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

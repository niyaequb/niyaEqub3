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
     * Initiate Chapa payment for an Equb payment (online).
     */
    public function initiateChapa(EqubPayment $equbPayment): JsonResponse
    {
        if ($equbPayment->payment_method->value !== 'chapa') {
            return response()->json(['status' => 'error', 'message' => 'Payment is not a Chapa payment.'], 422);
        }
        if ($equbPayment->isPaid()) {
            return response()->json(['status' => 'error', 'message' => 'Payment already completed.'], 422);
        }
        try {
            $result = app(\App\Services\ChapaService::class)->initializeEqubPayment($equbPayment, 'admin');
            if (! $result['success']) {
                return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'Failed to initialize payment.'], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Payment initiated.',
                'checkout_url' => $result['checkout_url'],
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

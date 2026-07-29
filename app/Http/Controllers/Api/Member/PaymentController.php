<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Member\StorePaymentRequest;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $member = $request->user()?->member;

        if (! $member) {
            return response()->json([
                'status' => 'error',
                'message' => 'Member profile not found.',
            ], 404);
        }

        $payment = DB::transaction(function () use ($request, $member): Payment {
            $status = $request->input('status', 'completed');
            $paidAt = $request->input('paid_at');

            if (! $paidAt && $status === 'completed') {
                $paidAt = now();
            }

            return Payment::query()->create([
                'member_id' => $member->id,
                'amount' => $request->input('amount'),
                'status' => $status,
                'paid_at' => $paidAt,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'payment' => new PaymentResource($payment),
        ], 201);
    }
}

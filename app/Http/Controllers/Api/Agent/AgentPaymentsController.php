<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\StorePaymentRequest;
use App\Http\Resources\Api\AgentPaymentResource;
use App\Models\AgentPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentPaymentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        $query = $agent->paymentRequests()
            ->latest();

        if ($request->filled('status')) {
            $status = PaymentStatus::tryFrom($request->string('status')->toString());
            if ($status) {
                $query->where('status', $status);
            }
        }

        $payments = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'payments' => AgentPaymentResource::collection($payments),
        ]);
    }

    public function payout(Request $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'payouts' => $agent->payouts()->where('status', 'paid'),
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        $payment = DB::transaction(function () use ($request, $agent): AgentPaymentRequest {
            $status = $request->input('status', 'pending');
            $paidAt = $request->input('paid_at');

            if (! $paidAt && $status === 'completed') {
                $paidAt = now();
            }

            return AgentPaymentRequest::query()->create([
                'agent_id' => $agent->id,
                'amount' => $request->input('amount'),
                'bank_name' => $request->filled('bank_name')
                    ? $request->input('bank_name')
                    : $agent->bank_name,
                'account_number' => $request->filled('account_number')
                    ? $request->input('account_number')
                    : $agent->account_number,
                'account_holder_name' => $request->filled('account_holder_name')
                    ? $request->input('account_holder_name')
                    : $agent->account_holder_name,
                'status' => $status,
                'paid_at' => $paidAt,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Payment request submitted successfully.',
            'payment' => new AgentPaymentResource($payment),
        ], 201);
    }
}

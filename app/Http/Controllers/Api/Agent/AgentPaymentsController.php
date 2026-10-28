<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Agent\StorePaymentRequest;
use App\Http\Resources\Api\AgentPaymentResource;
use App\Models\Agent;
use App\Models\AgentPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agent withdrawals ("payment requests").
 *
 *   GET  agent/payments              list (+ available balance and bank details)
 *   POST agent/payments              request a withdrawal { amount, note? }
 *   POST agent/payments/{id}/cancel  cancel a request that is still pending
 */
class AgentPaymentsController extends Controller
{
    /** Approved commission minus requests already paid or still waiting. */
    public static function availableBalance(Agent $agent): float
    {
        $approved = (float) $agent->commissions()
            ->where('status', 'approved')
            ->sum('commission_amount');

        $alreadyRequested = (float) $agent->paymentRequests()
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Completed])
            ->sum('amount');

        return round(max(0, $approved - $alreadyRequested), 2);
    }

    public function index(Request $request): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        $query = $agent->paymentRequests()->latest();

        if ($request->filled('status')) {
            $wanted = $request->string('status')->toString();

            if ($wanted === 'cancelled') {
                $query->whereNotNull('cancelled_at');
            } elseif ($status = PaymentStatus::tryFrom($wanted)) {
                $query->where('status', $status);
                if ($status === PaymentStatus::Failed) {
                    $query->whereNull('cancelled_at');
                }
            }
        }

        $payments = $query->paginate(max(1, min(200, $request->integer('per_page', 100))));

        return response()->json([
            'status' => 'success',
            'payments' => AgentPaymentResource::collection($payments),
            'available_balance' => self::availableBalance($agent),
            'bank' => [
                'bank_name' => $agent->bank_name,
                'account_number' => $agent->account_number,
                'account_holder_name' => $agent->account_holder_name,
            ],
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
            'payouts' => $agent->payouts()->where('status', 'paid')->latest()->get(),
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

        if (! $agent->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your agent account is not active yet, so withdrawals are not available.',
            ], 422);
        }

        // One withdrawal at a time keeps the books simple for both sides.
        if ($agent->paymentRequests()->where('status', PaymentStatus::Pending)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have a withdrawal waiting. Please wait until it is paid, or cancel it first.',
            ], 422);
        }

        $available = self::availableBalance($agent);
        $amount = round((float) $request->input('amount'), 2);

        if ($amount <= 0 || $amount > $available) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can request up to ETB '.number_format($available, 2).'.',
                'available_balance' => $available,
            ], 422);
        }

        $payment = DB::transaction(function () use ($request, $agent, $amount): AgentPaymentRequest {
            return AgentPaymentRequest::query()->create([
                'agent_id' => $agent->id,
                'amount' => $amount,
                'bank_name' => $request->filled('bank_name') ? $request->input('bank_name') : $agent->bank_name,
                'account_number' => $request->filled('account_number') ? $request->input('account_number') : $agent->account_number,
                'account_holder_name' => $request->filled('account_holder_name') ? $request->input('account_holder_name') : $agent->account_holder_name,
                'note' => $request->filled('note') ? mb_substr((string) $request->input('note'), 0, 500) : null,
                // Always pending: only an admin can mark a withdrawal as paid.
                'status' => PaymentStatus::Pending,
                'paid_at' => null,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Withdrawal request submitted.',
            'payment' => new AgentPaymentResource($payment->fresh()),
            'available_balance' => self::availableBalance($agent),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $agent = $request->user()?->agentProfile;

        if (! $agent) {
            return response()->json(['status' => 'error', 'message' => 'Agent profile not found.'], 404);
        }

        $payment = $agent->paymentRequests()->whereKey($id)->first();

        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        }

        if ($payment->status !== PaymentStatus::Pending) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only a request that is still waiting can be cancelled.',
            ], 422);
        }

        $payment->update([
            'status' => PaymentStatus::Failed,
            'cancelled_at' => now(),
            'admin_note' => $payment->admin_note ?: 'Cancelled by the agent.',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Request cancelled. The amount is back in your balance.',
            'payment' => new AgentPaymentResource($payment->fresh()),
            'available_balance' => self::availableBalance($agent),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\CommissionStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgentCommission;
use App\Models\AgentPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $agent = $user?->agentProfile;

        if (! $agent) {
            return response()->json([
                'status' => 'error',
                'message' => 'Agent profile not found.',
            ], 404);
        }

        $totalEarned = (float) AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->sum('commission_amount');

        $commissionPending = (float) AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->where('status', CommissionStatus::Pending)
            ->sum('commission_amount');

        $commissionApproved = (float) AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->where('status', CommissionStatus::Approved)
            ->sum('commission_amount');

        $commissionUnApproved = (float) AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->where('status', CommissionStatus::Pending)
            ->sum('commission_amount');
        // $commissionPaid = (float) AgentCommission::query()
        //     ->where('agent_id', $agent->id)
        //     ->where('status', CommissionStatus::Paid)
        //     ->sum('commission_amount');

        $pendingAmount = $commissionPending + $commissionApproved;

        $paymentRequestsPendingAmount = (float) AgentPaymentRequest::query()
            ->where('agent_id', $agent->id)
            ->where('status', PaymentStatus::Pending)
            ->sum('amount');

        $paymentRequestsPaidAmount = (float) AgentPaymentRequest::query()
            ->where('agent_id', $agent->id)
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');

        $balance = $commissionApproved - $paymentRequestsPaidAmount;
        // - $commissionPaid;

        return response()->json([
            'status' => 'success',
            'data' => [
                'agent_id' => $agent->id,
                'referral_code' => $agent->referral_code,
                'members_count' => $agent->members()->count(),
                'total_earned' => round($commissionApproved, 2),
                'pending_amount' => round($commissionUnApproved, 2),
                // 'paid_amount' => round($commissionPaid, 2),
                'balance' => round($balance, 2),
                'approved_amount' => round($commissionApproved, 2),
                'payment_requests_pending_amount' => round($paymentRequestsPendingAmount, 2),
                'payment_requests_paid_amount' => round($paymentRequestsPaidAmount, 2),
            ],
        ]);
    }
}

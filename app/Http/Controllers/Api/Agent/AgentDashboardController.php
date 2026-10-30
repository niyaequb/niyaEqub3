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

        $commissions = AgentCommission::query()->where('agent_id', $agent->id);

        // Everything the agent has earned so far, whatever its approval state.
        $totalEarned = (float) (clone $commissions)->sum('commission_amount');

        // Waiting for an admin to approve.
        $pending = (float) (clone $commissions)
            ->where('status', CommissionStatus::Pending)
            ->sum('commission_amount');

        // Approved and not yet paid: the money the agent can withdraw.
        $approved = (float) (clone $commissions)
            ->where('status', CommissionStatus::Approved)
            ->sum('commission_amount');

        // Commissions an admin paid directly with "Mark Paid".
        $paidDirectly = (float) (clone $commissions)
            ->where('status', CommissionStatus::Paid)
            ->sum('commission_amount');

        $requests = AgentPaymentRequest::query()->where('agent_id', $agent->id);

        $paidByRequest = (float) (clone $requests)
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');
        $paidOut = $paidByRequest + $paidDirectly;

        $requestedNotPaid = (float) (clone $requests)
            ->where('status', PaymentStatus::Pending)
            ->sum('amount');

        // Withdrawable now = approved - paid through requests - still requested.
        $balance = max(0, $approved - $paidByRequest - $requestedNotPaid);

        $monthStart = now()->startOfMonth();

        $earnedThisMonth = (float) (clone $commissions)
            ->where('created_at', '>=', $monthStart)
            ->sum('commission_amount');

        $membersCount = $agent->members()->count();
        $membersThisMonth = $agent->members()
            ->where(fn ($q) => $q->where('registered_at', '>=', $monthStart)->orWhere('created_at', '>=', $monthStart))
            ->count();

        $recentCommissions = (clone $commissions)
            ->with('member:id,full_name')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (AgentCommission $c) => [
                'id' => $c->id,
                'member_name' => $c->member?->full_name,
                'source' => is_object($c->source) ? $c->source->value : $c->source,
                'amount' => round((float) $c->commission_amount, 2),
                'status' => is_object($c->status) ? $c->status->value : $c->status,
                'created_at' => optional($c->created_at)->toIso8601String(),
            ]);

        $recentMembers = $agent->members()
            ->with('user:id,phone')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'full_name' => $m->full_name,
                'phone' => $m->user?->phone,
                'registered_at' => optional($m->registered_at ?? $m->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'agent_id' => $agent->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'is_active' => (bool) $agent->is_active,
                'referral_code' => $agent->referral_code,
                'members_count' => $membersCount,
                'members_this_month' => $membersThisMonth,
                'total_earned' => round($totalEarned, 2),
                'earned_this_month' => round($earnedThisMonth, 2),
                'pending_amount' => round($pending, 2),
                'approved_amount' => round($approved + $paidDirectly, 2),
                'paid_amount' => round($paidOut, 2),
                'balance' => round($balance, 2),
                'payment_requests_pending_amount' => round($requestedNotPaid, 2),
                'payment_requests_paid_amount' => round($paidByRequest, 2),
                'bank_name' => $agent->bank_name,
                'account_number' => $agent->account_number,
                'account_holder_name' => $agent->account_holder_name,
                'recent_commissions' => $recentCommissions,
                'recent_members' => $recentMembers,
            ],
        ]);
    }
}

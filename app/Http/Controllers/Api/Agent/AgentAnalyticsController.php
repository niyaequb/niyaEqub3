<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everything the agent app's detail pages show, for a chosen period.
 *
 * GET agent/analytics?period=month|3months|all|custom&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Money rules (same as the dashboard):
 *   total earned  = every commission, whatever its status
 *   withdrawable  = approved commission - completed requests - pending requests
 *   paid out      = completed payout requests + commissions marked "paid" directly
 */
class AgentAnalyticsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $agent = $user?->agentProfile;

        if (! $agent) {
            return response()->json(['status' => 'error', 'message' => 'Agent profile not found.'], 404);
        }

        [$key, $from, $to, $label] = $this->range($request);

        $commissions = DB::table('agent_commissions as c')
            ->leftJoin('members as m', 'm.id', '=', 'c.member_id')
            ->where('c.agent_id', $agent->id)
            ->select('c.id', 'c.source', 'c.status', 'c.commission_amount', 'c.base_amount', 'c.created_at', 'm.full_name')
            ->orderByDesc('c.created_at')
            ->get();

        $requests = DB::table('agent_payment_requests')
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->get();

        $members = DB::table('members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.agent_id', $agent->id)
            ->select(
                'm.id', 'm.full_name', 'u.phone',
                DB::raw('coalesce(m.registered_at, m.created_at) as joined_at'),
                DB::raw("(select count(*) from equb_memberships em where em.member_id = m.id and em.status = 'active') as active_equbs")
            )
            ->orderByDesc('joined_at')
            ->get();

        $in = fn ($date): bool => $this->inRange($date, $from, $to);

        // ---- All-time totals -------------------------------------------------
        $sumBy = fn ($rows, string $status): float => (float) $rows->where('status', $status)->sum('commission_amount');

        $approved = $sumBy($commissions, 'approved');
        $paidDirect = $sumBy($commissions, 'paid');
        $paidRequests = (float) $requests->where('status', 'completed')->sum('amount');
        $pendingRequests = (float) $requests->where('status', 'pending')->sum('amount');

        $totals = [
            'total_earned' => round((float) $commissions->sum('commission_amount'), 2),
            'pending' => round($sumBy($commissions, 'pending'), 2),
            'approved' => round($approved, 2),
            'paid_direct' => round($paidDirect, 2),
            'paid_requests' => round($paidRequests, 2),
            'paid_out' => round($paidDirect + $paidRequests, 2),
            'requested_pending' => round($pendingRequests, 2),
            'balance' => round(max(0, $approved - $paidRequests - $pendingRequests), 2),
            'members_total' => $members->count(),
            'members_in_equb' => $members->where('active_equbs', '>', 0)->count(),
        ];

        // ---- Period figures --------------------------------------------------
        $pCommissions = $commissions->filter(fn ($c) => $in($c->created_at))->values();
        $pMembers = $members->filter(fn ($m) => $in($m->joined_at))->values();

        $payouts = collect();
        foreach ($requests as $r) {
            $payouts->push([
                'type' => 'request',
                'amount' => round((float) $r->amount, 2),
                'status' => $r->status,
                'date' => $r->paid_at ?? $r->created_at,
                'title' => trim(($r->bank_name ?? '').' '.($r->account_number ?? '')) ?: 'Withdrawal request',
            ]);
        }
        foreach ($commissions->where('status', 'paid') as $c) {
            $payouts->push([
                'type' => 'commission',
                'amount' => round((float) $c->commission_amount, 2),
                'status' => 'completed',
                'date' => $c->created_at,
                'title' => 'Commission paid'.($c->full_name ? ' · '.$c->full_name : ''),
            ]);
        }
        $pPayouts = $payouts->filter(fn ($p) => $in($p['date']))->sortByDesc('date')->values();
        $pPaid = $pPayouts->where('status', 'completed');

        $period = [
            'earned' => round((float) $pCommissions->sum('commission_amount'), 2),
            'commissions_count' => $pCommissions->count(),
            'members_new' => $pMembers->count(),
            'members_new_in_equb' => $pMembers->where('active_equbs', '>', 0)->count(),
            'paid_out' => round((float) $pPaid->sum('amount'), 2),
            'paid_by_request' => round((float) $pPaid->where('type', 'request')->sum('amount'), 2),
            'paid_direct' => round((float) $pPaid->where('type', 'commission')->sum('amount'), 2),
        ];

        // ---- Breakdowns (period) ---------------------------------------------
        $byStatus = collect(['pending', 'approved', 'paid'])->map(fn ($s) => [
            'key' => $s,
            'amount' => round((float) $pCommissions->where('status', $s)->sum('commission_amount'), 2),
            'count' => $pCommissions->where('status', $s)->count(),
        ])->values();

        $bySource = collect(['signup', 'first_payment', 'payment'])->map(fn ($s) => [
            'key' => $s,
            'amount' => round((float) $pCommissions->where('source', $s)->sum('commission_amount'), 2),
            'count' => $pCommissions->where('source', $s)->count(),
        ])->values();

        // ---- Chart series ----------------------------------------------------
        $seriesFrom = $from ?? $this->earliest($commissions, $members, $to);
        $series = $this->series($seriesFrom, $to, $pCommissions, $pMembers, $pPaid);

        return response()->json([
            'status' => 'success',
            'data' => [
                'range' => [
                    'period' => $key,
                    'label' => $label,
                    'from' => $from?->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'totals' => $totals,
                'period' => $period,
                'by_status' => $byStatus,
                'by_source' => $bySource,
                'series' => $series,
                'commissions' => $pCommissions->take(200)->map(fn ($c) => [
                    'id' => $c->id,
                    'member_name' => $c->full_name,
                    'source' => $c->source,
                    'status' => $c->status,
                    'amount' => round((float) $c->commission_amount, 2),
                    'base_amount' => round((float) $c->base_amount, 2),
                    'created_at' => $this->iso($c->created_at),
                ])->values(),
                'members' => $pMembers->take(300)->map(fn ($m) => [
                    'id' => $m->id,
                    'full_name' => $m->full_name,
                    'phone' => $m->phone,
                    'active_equbs' => (int) $m->active_equbs,
                    'registered_at' => $this->iso($m->joined_at),
                ])->values(),
                'payouts' => $pPayouts->take(200)->map(fn ($p) => [
                    ...$p,
                    'date' => $this->iso($p['date']),
                ])->values(),
                'requests_pending' => $requests->where('status', 'pending')->map(fn ($r) => [
                    'amount' => round((float) $r->amount, 2),
                    'created_at' => $this->iso($r->created_at),
                ])->values(),
            ],
        ]);
    }

    /** @return array{0: string, 1: ?CarbonInterface, 2: CarbonInterface, 3: string} */
    public static function rangeFor(Request $request): array
    {
        return (new self)->range($request);
    }

    /** @return array{0: string, 1: ?CarbonInterface, 2: CarbonInterface, 3: string} */
    protected function range(Request $request): array
    {
        $key = (string) $request->query('period', 'month');
        $to = now()->endOfDay();

        switch ($key) {
            case 'all':
                return ['all', null, $to, 'All time'];
            case '3months':
                return ['3months', now()->subMonthsNoOverflow(3)->startOfDay(), $to, 'Last 3 months'];
            case 'custom':
                try {
                    $from = Carbon::parse((string) $request->query('from'))->startOfDay();
                    $end = $request->filled('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : $to;
                    if ($from->gt($end)) {
                        [$from, $end] = [$end->copy()->startOfDay(), $from->copy()->endOfDay()];
                    }

                    return ['custom', $from, $end, $from->format('M j, Y').' – '.$end->format('M j, Y')];
                } catch (\Throwable) {
                    // fall through to this month
                }
                // no break
            default:
                return ['month', now()->startOfMonth(), $to, 'This month'];
        }
    }

    protected function inRange($date, ?CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($date === null) {
            return false;
        }

        $d = Carbon::parse($date);

        return ($from === null || $d->gte($from)) && $d->lte($to);
    }

    protected function earliest($commissions, $members, CarbonInterface $to): CarbonInterface
    {
        $dates = collect([$commissions->min('created_at'), $members->min('joined_at')])->filter();
        $first = $dates->isEmpty() ? $to->copy()->subMonths(5) : Carbon::parse($dates->min());

        // At most two years of bars.
        return $first->max($to->copy()->subMonths(23)->startOfMonth())->startOfDay();
    }

    /** Daily bars for a month or less, monthly bars for longer ranges. */
    protected function series(CarbonInterface $from, CarbonInterface $to, $commissions, $members, $paid): array
    {
        $daily = $from->diffInDays($to) <= 31;
        $buckets = [];

        $cursor = $daily ? Carbon::parse($from)->startOfDay() : Carbon::parse($from)->startOfMonth();
        while ($cursor->lte($to)) {
            $k = $daily ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
            $buckets[$k] = [
                'key' => $k,
                'label' => $daily ? $cursor->format('j') : $cursor->format('M y'),
                'earned' => 0.0,
                'members' => 0,
                'paid' => 0.0,
            ];
            $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow();
        }

        $keyOf = fn ($date) => Carbon::parse($date)->format($daily ? 'Y-m-d' : 'Y-m');

        foreach ($commissions as $c) {
            $k = $keyOf($c->created_at);
            if (isset($buckets[$k])) {
                $buckets[$k]['earned'] += (float) $c->commission_amount;
            }
        }
        foreach ($members as $m) {
            $k = $keyOf($m->joined_at);
            if (isset($buckets[$k])) {
                $buckets[$k]['members']++;
            }
        }
        foreach ($paid as $p) {
            $k = $keyOf($p['date']);
            if (isset($buckets[$k])) {
                $buckets[$k]['paid'] += (float) $p['amount'];
            }
        }

        return array_values(array_map(fn ($b) => [
            ...$b,
            'earned' => round($b['earned'], 2),
            'paid' => round($b['paid'], 2),
        ], $buckets));
    }

    protected function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->toIso8601String() : null;
    }
}

<?php

namespace App\Services\Equb;

use App\Enums\EqubPaymentStatus;
use App\Models\AgentCommission;
use App\Services\EqubReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the company actually earns.
 *
 * THE DISTINCTION THIS FILE EXISTS FOR
 *
 * Money collected is not revenue. An Equb is a circle in which members pay
 * each other; almost every birr that arrives is on its way back out to
 * whoever wins the round. Our income is the service fee on top of it, and
 * nothing else.
 *
 *     collected       what members paid in            a flow through us
 *     members' money  collected minus the fee         a liability we hold
 *     service fee     our percentage                  revenue
 *     commissions     what agents earned on it        cost of sale
 *     net             fee minus commissions           what is left
 *
 * A report that shows "collected" under a heading like Revenue tells a
 * founder their company is twenty times its real size, and every decision
 * downstream of that number is wrong. So the four figures are always shown
 * together here, never one on its own.
 *
 * WHY COMMISSIONS ARE DATED, NOT MATCHED
 *
 * An agent commission on an Equb contribution stores no link back to the
 * contribution: `reference_id` is null on that path, because equb_payments is
 * a different table from payments and the column points at the latter. So
 * commissions are attributed by when they were booked rather than by which
 * payment produced them. Over a month the two are the same figure; on a
 * single day they can differ slightly, and the report says so rather than
 * implying a precision it does not have.
 */
class EqubProfitService
{
    protected const BREAKDOWN_LIMIT = 15;

    public function __construct(
        protected EqubRules $rules,
        protected EqubReportService $reports,
    ) {}

    /**
     * The whole profit picture for a window.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $f = $this->reports->normalizeFilters($filters);
        $start = $f['start'];
        $end = $f['end'];

        [$prevStart, $prevEnd] = $f['period']->previousRange($start, $end);

        $current = $this->window($filters, $start, $end);
        $previous = $this->window($filters, $prevStart, $prevEnd);

        return [
            'rate' => $this->rules->feePercent(),
            'model' => $this->rules->feeModel(),
            'summary' => $current,
            'previous' => $previous,
            'growth' => $this->growth($current, $previous),
            'series' => $this->series($filters, $start, $end, $f['period']->granularity($start, $end)),
            'by_equb' => $this->breakdown($filters, $start, $end, 'equb'),
            'by_package' => $this->breakdown($filters, $start, $end, 'package'),
        ];
    }

    /**
     * Fee, cost and net for one window.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int>
     */
    public function window(array $filters, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = $this->reports->query($filters, $start, $end)
            ->where('p.status', EqubPaymentStatus::Paid->value)
            ->selectRaw('coalesce(sum(p.amount), 0) as collected')
            ->selectRaw('count(*) as transactions')
            ->first();

        $collected = round((float) ($row->collected ?? 0), 2);
        $fee = $this->rules->feeOn($collected);
        $memberShare = round($collected - $fee, 2);
        $commissions = $this->commissions($start, $end);
        $net = round($fee - $commissions, 2);

        return [
            'collected' => $collected,
            'member_share' => $memberShare,
            'fee' => $fee,
            'commissions' => $commissions,
            'net' => $net,
            'transactions' => (int) ($row->transactions ?? 0),
            // Our fee as a share of everything that passed through. Should sit
            // at the configured rate; a drift means some contributions were
            // taken on a different rate, which is worth seeing.
            'effective_rate' => $collected > 0 ? round(($fee / $collected) * 100, 2) : 0.0,
            // How much of the fee survives paying the agents who brought the
            // money in. This is the margin that actually matters.
            'net_margin' => $fee > 0 ? round(($net / $fee) * 100, 1) : 0.0,
            'fee_per_transaction' => ($row->transactions ?? 0) > 0
                ? round($fee / (int) $row->transactions, 2)
                : 0.0,
        ];
    }

    /**
     * Agent commissions booked in the window.
     *
     * Every status counts. The ledger has three — pending, approved, paid —
     * and none of them means "reversed": a commission that has been earned is
     * owed whether or not it has been paid out yet, and counting only the
     * settled ones would flatter the margin by however much the business
     * happens to be behind on paying its agents.
     *
     * The ledger is immutable by design (AgentCommission refuses updates and
     * deletes), so there is no correction to exclude either.
     */
    protected function commissions(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return round((float) AgentCommission::query()
            ->whereBetween('created_at', [$start, $end])
            ->sum('commission_amount'), 2);
    }

    /**
     * Fee earned bucket by bucket, for the trend.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function series(array $filters, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $rows = $this->reports->trend($filters, $start, $end, $granularity);

        return array_map(function (array $bucket): array {
            $fee = $this->rules->feeOn((float) $bucket['collected']);

            return [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'collected' => $bucket['collected'],
                'fee' => $fee,
                'member_share' => round((float) $bucket['collected'] - $fee, 2),
                'transactions' => $bucket['transactions'],
            ];
        }, $rows);
    }

    /**
     * Which Equbs and packages the fee came from.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdown(array $filters, CarbonImmutable $start, CarbonImmutable $end, string $by): Collection
    {
        [$idSql, $labelSql] = $by === 'package'
            ? ['coalesce(ep.id, pep.id)', 'coalesce(ep.name, pep.name)']
            : ['coalesce(peg.id, eg.id)', 'coalesce(peg.name, eg.name)'];

        $rows = $this->reports->query($filters, $start, $end)
            ->where('p.status', EqubPaymentStatus::Paid->value)
            ->select([
                DB::raw("{$idSql} as entity_id"),
                DB::raw("{$labelSql} as entity_label"),
            ])
            ->selectRaw('coalesce(sum(p.amount), 0) as collected')
            ->selectRaw('count(*) as transactions')
            ->selectRaw('count(distinct m.id) as members')
            ->groupBy(DB::raw($idSql), DB::raw($labelSql))
            ->orderByDesc('collected')
            ->limit(self::BREAKDOWN_LIMIT)
            ->get();

        $totalFee = (float) $rows->sum(fn ($r): float => $this->rules->feeOn((float) $r->collected));

        return $rows->map(function ($r) use ($totalFee): array {
            $collected = round((float) $r->collected, 2);
            $fee = $this->rules->feeOn($collected);

            return [
                'id' => $r->entity_id,
                'label' => $r->entity_label ?: __('filament.equb_report.unassigned'),
                'collected' => $collected,
                'fee' => $fee,
                'member_share' => round($collected - $fee, 2),
                'transactions' => (int) $r->transactions,
                'members' => (int) $r->members,
                'share' => $totalFee > 0 ? round(($fee / $totalFee) * 100, 1) : 0.0,
            ];
        })->values();
    }

    /**
     * @param  array<string, float|int>  $current
     * @param  array<string, float|int>  $previous
     * @return array<string, float|null>
     */
    protected function growth(array $current, array $previous): array
    {
        $out = [];

        foreach (['collected', 'fee', 'commissions', 'net'] as $key) {
            $now = (float) ($current[$key] ?? 0);
            $was = (float) ($previous[$key] ?? 0);

            // Null means "there is no baseline", not "no growth". The view
            // prints "new" rather than inventing a percentage against zero.
            $out[$key] = match (true) {
                $was == 0.0 && $now == 0.0 => 0.0,
                $was == 0.0 => null,
                default => round((($now - $was) / abs($was)) * 100, 1),
            };
        }

        return $out;
    }
}

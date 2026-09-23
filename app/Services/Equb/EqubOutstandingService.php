<?php

namespace App\Services\Equb;

use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Support\Equb\MembershipStanding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receivables: what the Equb is owed, by whom, and for how long.
 *
 * WHAT THE OLD FIGURE COUNTED
 *
 * "Outstanding" used to mean the sum of equb_payments rows sitting at status
 * `pending`. Two things made that useless in practice.
 *
 * A member who joins and never pays creates no payment row at all. The single
 * worst account on the books — someone who owes four rounds and has paid
 * nothing — contributed exactly zero to the outstanding figure, because the
 * system had nothing to sum. The people it did count were the ones who had at
 * least started a payment.
 *
 * And the report defaults to filtering on status = paid, so even those rows
 * were excluded before the sum ran. The card read 0.00 ETB on a book full of
 * debt, which is what prompted this file.
 *
 * WHAT IT COUNTS NOW
 *
 * The schedule, not the rows. Every active membership implies a series of
 * contributions: one on the join date and one every `frequency` days after,
 * up to the group's total rounds. Whatever has actually settled is subtracted
 * from what the calendar has asked for so far, and the difference is the
 * debt. No payment row is needed for a debt to exist, which is the point.
 *
 * AS OF A DATE, NOT OVER A RANGE
 *
 * Collections are a flow: "how much came in during March". Receivables are a
 * level: "how much was owed on 31 March". Asking for arrears "between two
 * dates" is a category error, so every method here takes a single moment and
 * reports the position at it — normally the end of whatever window the report
 * page is showing.
 */
class EqubOutstandingService
{
    /** Rows returned to the screen before we stop and say "and more". */
    public const ROW_LIMIT = 300;

    protected const BREAKDOWN_LIMIT = 15;

    public function __construct(
        protected EqubRules $rules,
        protected EqubStandingService $standings,
    ) {}

    /**
     * Headline receivables position.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = [], ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $row = $this->baseQuery($filters, $asOf)
            ->selectRaw('count(*) as memberships')
            ->selectRaw('coalesce(sum('.$this->expectedSql($asOf).'), 0) as expected')
            ->selectRaw('coalesce(sum(coalesce(paid.paid_amount, 0)), 0) as paid')
            ->selectRaw('coalesce(sum('.$this->arrearsSql($asOf).'), 0) as arrears')
            ->selectRaw('coalesce(sum('.$this->advanceSql($asOf).'), 0) as advance')
            ->selectRaw('sum(case when '.$this->arrearsSql($asOf).' > 0 then 1 else 0 end) as in_arrears')
            ->selectRaw('sum(case when coalesce(paid.paid_count, 0) = 0 and '.$this->roundsDue($asOf).' > 0 then 1 else 0 end) as never_paid')
            ->selectRaw('coalesce(sum(case when coalesce(paid.paid_count, 0) = 0 then '.$this->arrearsSql($asOf).' else 0 end), 0) as never_paid_amount')
            ->selectRaw('count(distinct coalesce(em.member_id, em.sponsor_member_id)) as people')
            ->first();

        $expected = round((float) ($row->expected ?? 0), 2);
        $paid = round((float) ($row->paid ?? 0), 2);
        $arrears = round((float) ($row->arrears ?? 0), 2);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'memberships' => (int) ($row->memberships ?? 0),
            'people' => (int) ($row->people ?? 0),
            'expected' => $expected,
            'paid' => $paid,
            'arrears' => $arrears,
            'advance' => round((float) ($row->advance ?? 0), 2),
            'members_in_arrears' => (int) ($row->in_arrears ?? 0),
            'never_paid_count' => (int) ($row->never_paid ?? 0),
            'never_paid_amount' => round((float) ($row->never_paid_amount ?? 0), 2),
            // Of everything the calendar has asked for so far, how much has
            // actually arrived. The one number that says whether the book is
            // healthy, and it cannot be gamed by when payments are recorded.
            'collection_rate' => $expected > 0 ? round(($paid / $expected) * 100, 1) : 0.0,
            'arrears_rate' => $expected > 0 ? round(($arrears / $expected) * 100, 1) : 0.0,
        ];
    }

    /**
     * Arrears split by how long they have been owed.
     *
     * The bands are computed arithmetically rather than with date maths: every
     * round falls `frequency` days after the last, so the first uncovered
     * round came due `days since joining - rounds covered x frequency` days
     * ago. No calendar function, and it behaves the same on every driver.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function ageing(array $filters = [], ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $boundaries = $this->rules->ageingBuckets();

        $query = $this->baseQuery($filters, $asOf)
            ->where(DB::raw($this->arrearsSql($asOf)), '>', 0);

        $overdue = $this->daysOverdueSql($asOf);
        $arrears = $this->arrearsSql($asOf);

        $previous = 0;

        foreach ($boundaries as $index => $days) {
            $query->selectRaw(
                "coalesce(sum(case when {$overdue} > {$previous} and {$overdue} <= {$days} then {$arrears} else 0 end), 0) as band_{$index}"
            );
            $query->selectRaw(
                "sum(case when {$overdue} > {$previous} and {$overdue} <= {$days} then 1 else 0 end) as band_{$index}_count"
            );
            $previous = $days;
        }

        $query->selectRaw("coalesce(sum(case when {$overdue} > {$previous} then {$arrears} else 0 end), 0) as band_old");
        $query->selectRaw("sum(case when {$overdue} > {$previous} then 1 else 0 end) as band_old_count");

        $row = $query->first();

        $bands = [];
        $previous = 0;

        foreach ($boundaries as $index => $days) {
            $bands[] = [
                'key' => (string) $days,
                'label' => $previous === 0
                    ? __('filament.equb_report.ageing_first', ['days' => $days])
                    : __('filament.equb_report.ageing_band', ['from' => $previous + 1, 'to' => $days]),
                'amount' => round((float) ($row->{"band_{$index}"} ?? 0), 2),
                'count' => (int) ($row->{"band_{$index}_count"} ?? 0),
                'severity' => $index === 0 ? 'warning' : 'danger',
            ];
            $previous = $days;
        }

        $bands[] = [
            'key' => 'older',
            'label' => __('filament.equb_report.ageing_older', ['days' => $previous]),
            'amount' => round((float) ($row->band_old ?? 0), 2),
            'count' => (int) ($row->band_old_count ?? 0),
            'severity' => 'danger',
        ];

        $total = array_sum(array_column($bands, 'amount'));

        return array_map(function (array $band) use ($total): array {
            $band['share'] = $total > 0 ? round(($band['amount'] / $total) * 100, 1) : 0.0;

            return $band;
        }, $bands);
    }

    /**
     * The debtors themselves, worst first.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters = [], ?CarbonImmutable $asOf = null, int $limit = self::ROW_LIMIT): Collection
    {
        $asOf ??= CarbonImmutable::now();

        $arrears = $this->arrearsSql($asOf);
        $overdue = $this->daysOverdueSql($asOf);
        $roundsDue = $this->roundsDue($asOf);

        $query = $this->baseQuery($filters, $asOf)
            ->select([
                'em.id as membership_id',
                'em.contribution_amount',
                'em.contribution_frequency_days',
                'em.join_date',
                'em.has_won',
                'em.status as membership_status',
                'em.responsibility_name',
                'em.draw_blocked_until',
                'm.id as member_id',
                'm.full_name',
                'u.phone',
                'eg.id as group_id',
                'eg.name as group_name',
                'eg.owner_member_id',
                DB::raw('coalesce(peg.name, eg.name) as equb_name'),
                DB::raw('coalesce(ep.name, pep.name) as package_name'),
                DB::raw('coalesce(paid.paid_amount, 0) as paid_amount'),
                DB::raw('coalesce(paid.paid_count, 0) as paid_count'),
                'paid.last_paid_at',
                DB::raw("{$roundsDue} as rounds_due"),
                DB::raw($this->totalRounds().' as total_rounds'),
                DB::raw("{$arrears} as arrears"),
                DB::raw($this->expectedSql($asOf).' as expected'),
                DB::raw("{$overdue} as days_overdue"),
            ])
            ->where(DB::raw($arrears), '>', 0)
            ->orderByDesc(DB::raw($arrears))
            ->limit($limit);

        // Narrowing to one ageing band, for the drill-down's own filter.
        if (filled($filters['bucket'] ?? null)) {
            [$from, $to] = $this->bucketBounds((string) $filters['bucket']);

            $query->where(DB::raw($overdue), '>', $from);

            if ($to !== null) {
                $query->where(DB::raw($overdue), '<=', $to);
            }
        }

        if (($filters['only_never_paid'] ?? false)) {
            $query->where(DB::raw('coalesce(paid.paid_count, 0)'), '=', 0);
        }

        return $query->get()->map(function ($r): array {
            $contribution = (float) $r->contribution_amount;
            $arrears = round((float) $r->arrears, 2);
            $missed = $contribution > 0 ? (int) ceil(($arrears - 0.005) / $contribution) : 0;
            $paidCount = (int) $r->paid_count;
            $roundsDue = (int) $r->rounds_due;

            $status = match (true) {
                $paidCount === 0 && $roundsDue > 0 => MembershipStanding::NEVER_PAID,
                $missed >= $this->rules->suspendAt() => MembershipStanding::SUSPENDED,
                $missed >= $this->rules->blockAt() => MembershipStanding::BLOCKED,
                $missed >= $this->rules->warnAt() => MembershipStanding::WARNED,
                default => MembershipStanding::CURRENT,
            };

            return [
                'membership_id' => (int) $r->membership_id,
                'member_id' => $r->member_id ? (int) $r->member_id : null,
                // A place held for someone with no Niya account shows the
                // name the sponsor gave it; the payer is named underneath,
                // because that is who owes the money and who to call.
                'name' => $r->full_name ?: ($r->responsibility_name ?: __('filament.equb_report.unknown_member')),
                'held_for' => $r->responsibility_name,
                'phone' => $r->phone,
                'equb' => $r->equb_name,
                'group' => $r->owner_member_id ? $r->group_name : null,
                'package' => $r->package_name,
                'contribution' => round($contribution, 2),
                'rounds_due' => $roundsDue,
                'rounds_paid' => $contribution > 0 ? (int) floor(((float) $r->paid_amount + 0.005) / $contribution) : $paidCount,
                'total_rounds' => (int) $r->total_rounds,
                'expected' => round((float) $r->expected, 2),
                'paid' => round((float) $r->paid_amount, 2),
                'arrears' => $arrears,
                'missed_rounds' => $missed,
                'days_overdue' => max(0, (int) $r->days_overdue),
                'last_paid_at' => $r->last_paid_at,
                'has_won' => (bool) $r->has_won,
                'admin_blocked' => filled($r->draw_blocked_until),
                'status' => $status,
                // A member who has already collected a payout and then stopped
                // paying is the most expensive kind of debt there is: the
                // circle has handed over the money and has no way to get it
                // back except from this person.
                'critical' => (bool) $r->has_won && $arrears > 0,
            ];
        });
    }

    /**
     * Arrears rolled up, by platform Equb or by package.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function breakdown(array $filters, string $by = 'equb', ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();

        [$idSql, $labelSql] = match ($by) {
            'package' => ['coalesce(ep.id, pep.id)', 'coalesce(ep.name, pep.name)'],
            'group' => ['eg.id', 'eg.name'],
            default => ['coalesce(peg.id, eg.id)', 'coalesce(peg.name, eg.name)'],
        };

        $arrears = $this->arrearsSql($asOf);

        $rows = $this->baseQuery($filters, $asOf)
            ->select([
                DB::raw("{$idSql} as entity_id"),
                DB::raw("{$labelSql} as entity_label"),
            ])
            ->selectRaw("coalesce(sum({$arrears}), 0) as arrears")
            ->selectRaw('coalesce(sum('.$this->expectedSql($asOf).'), 0) as expected')
            ->selectRaw('coalesce(sum(coalesce(paid.paid_amount, 0)), 0) as paid')
            ->selectRaw("sum(case when {$arrears} > 0 then 1 else 0 end) as debtors")
            ->selectRaw('count(*) as memberships')
            ->groupBy(DB::raw($idSql), DB::raw($labelSql))
            ->orderByDesc(DB::raw("sum({$arrears})"))
            ->limit(self::BREAKDOWN_LIMIT)
            ->get();

        return $rows->map(function ($r): array {
            $expected = round((float) $r->expected, 2);
            $arrears = round((float) $r->arrears, 2);

            return [
                'id' => $r->entity_id,
                'label' => $r->entity_label ?: __('filament.equb_report.unassigned'),
                'expected' => $expected,
                'paid' => round((float) $r->paid, 2),
                'arrears' => $arrears,
                'debtors' => (int) $r->debtors,
                'memberships' => (int) $r->memberships,
                'collection_rate' => $expected > 0 ? round((((float) $r->paid) / $expected) * 100, 1) : 0.0,
            ];
        })->values();
    }

    // -----------------------------------------------------------------
    // Query construction
    // -----------------------------------------------------------------

    /**
     * Every membership that can owe something, with its settled total.
     *
     * The payments are pulled in through a derived table rather than a plain
     * join, because joining the rows directly would multiply each membership
     * by its number of contributions and quietly inflate every sum in this
     * file by a factor nobody would notice until the figures were checked by
     * hand.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters, CarbonImmutable $asOf): QueryBuilder
    {
        $paid = DB::table('equb_payments')
            ->select('equb_membership_id')
            ->selectRaw('sum(amount) as paid_amount')
            ->selectRaw('count(*) as paid_count')
            ->selectRaw('max(coalesce(bank_paid_at, created_at)) as last_paid_at')
            ->where('status', EqubPaymentStatus::Paid->value)
            // Contributions settled after the reporting moment do not count
            // towards the position at that moment. Without this, looking back
            // at the end of last month would show today's payments already
            // applied and the arrears would read better than they were.
            ->where(function (QueryBuilder $q) use ($asOf): void {
                $q->where('payment_date', '<=', $asOf)
                    ->orWhereNull('payment_date');
            })
            ->groupBy('equb_membership_id');

        $q = DB::table('equb_memberships as em')
            ->join('equb_groups as eg', 'eg.id', '=', 'em.equb_group_id')
            ->leftJoin('equb_groups as peg', 'peg.id', '=', 'eg.parent_equb_group_id')
            ->leftJoin('equb_packages as ep', 'ep.id', '=', 'eg.equb_package_id')
            ->leftJoin('equb_packages as pep', 'pep.id', '=', 'peg.equb_package_id')
            // Whoever actually owes: the member on an ordinary place, the
            // sponsor on a place held for somebody without an account.
            ->leftJoin('members as m', 'm.id', '=', DB::raw('coalesce(em.member_id, em.sponsor_member_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('agents as ag', 'ag.id', '=', 'm.agent_id')
            ->leftJoinSub($paid, 'paid', fn ($join) => $join->on('paid.equb_membership_id', '=', 'em.id'));

        // Only live memberships owe anything. A cancelled place stops
        // accruing; a completed one has run its course.
        $statuses = $filters['membership_statuses'] ?? [EqubMembershipStatus::Active->value];

        if ($statuses !== []) {
            $q->whereIn('em.status', (array) $statuses);
        }

        // Cancelled Equbs are not debts, they are abandoned plans.
        $q->whereNotIn('eg.status', ['cancelled']);

        if (filled($filters['equb_group_ids'] ?? null)) {
            $ids = (array) $filters['equb_group_ids'];
            $q->where(function (QueryBuilder $sub) use ($ids): void {
                $sub->whereIn('eg.id', $ids)->orWhereIn('peg.id', $ids);
            });
        }

        if (filled($filters['equb_package_ids'] ?? null)) {
            $ids = (array) $filters['equb_package_ids'];
            $q->where(function (QueryBuilder $sub) use ($ids): void {
                $sub->whereIn('ep.id', $ids)->orWhereIn('pep.id', $ids);
            });
        }

        if (filled($filters['agent_ids'] ?? null)) {
            $q->whereIn('ag.id', (array) $filters['agent_ids']);
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(function (QueryBuilder $sub) use ($term): void {
                $sub->where('m.full_name', 'like', $term)
                    ->orWhere('u.phone', 'like', $term)
                    ->orWhere('em.responsibility_name', 'like', $term)
                    ->orWhere('eg.name', 'like', $term)
                    ->orWhere('peg.name', 'like', $term);
            });
        }

        return $q;
    }

    // -----------------------------------------------------------------
    // The formula, as SQL. Mirrors EqubStandingService::build().
    // -----------------------------------------------------------------

    protected function roundsDue(CarbonImmutable $asOf): string
    {
        return $this->standings->roundsDueSql($asOf);
    }

    protected function totalRounds(): string
    {
        return $this->standings->totalRoundsSql();
    }

    protected function expectedSql(CarbonImmutable $asOf): string
    {
        return '('.$this->roundsDue($asOf).' * coalesce(em.contribution_amount, 0))';
    }

    protected function arrearsSql(CarbonImmutable $asOf): string
    {
        $expected = $this->expectedSql($asOf);

        return "(case when ({$expected} - coalesce(paid.paid_amount, 0)) > 0
            then ({$expected} - coalesce(paid.paid_amount, 0))
            else 0 end)";
    }

    /** Money paid beyond what is due — the mirror image of arrears. */
    protected function advanceSql(CarbonImmutable $asOf): string
    {
        $expected = $this->expectedSql($asOf);

        return "(case when (coalesce(paid.paid_amount, 0) - {$expected}) > 0
            then (coalesce(paid.paid_amount, 0) - {$expected})
            else 0 end)";
    }

    /**
     * How long the oldest uncovered round has been outstanding.
     *
     * Rounds are evenly spaced, so the due date of the first round the money
     * has not reached is simply `rounds covered` intervals after the join
     * date. Subtracting that from the days elapsed gives the age of the debt
     * without a single date function.
     *
     * Zero when nothing is owed. Without that guard a member who has paid
     * every due round still reports a day or two "overdue" — the gap between
     * the last round they covered and today — which is not a debt at all, and
     * would age a paid-up membership into a collections band the moment
     * anything read this column without also filtering on arrears.
     */
    protected function daysOverdueSql(CarbonImmutable $asOf): string
    {
        $elapsed = $this->standings->daysSinceSql('em.join_date', $asOf);
        $frequency = $this->standings->frequencySql();
        $arrears = $this->arrearsSql($asOf);
        $covered = '(case when coalesce(em.contribution_amount, 0) > 0
            then floor(coalesce(paid.paid_amount, 0) / em.contribution_amount)
            else 0 end)';

        return "(case
            when em.join_date is null then 0
            when {$arrears} <= 0 then 0
            when ({$elapsed} - ({$covered} * {$frequency})) < 0 then 0
            else ({$elapsed} - ({$covered} * {$frequency}))
        end)";
    }

    /**
     * Turn a bucket key back into the days-overdue range it stands for.
     *
     * @return array{0: int, 1: int|null}
     */
    protected function bucketBounds(string $bucket): array
    {
        $boundaries = $this->rules->ageingBuckets();

        if ($bucket === 'older') {
            return [end($boundaries) ?: 90, null];
        }

        $to = (int) $bucket;
        $from = 0;

        foreach ($boundaries as $days) {
            if ($days === $to) {
                break;
            }

            $from = $days;
        }

        return [$from, $to];
    }
}

<?php

namespace App\Services;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Enums\ReportPeriod;
use App\Models\EqubPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Equb payment report.
 *
 * Everything that shows a number — the report page, the four charts, the PDF,
 * the CSV and the scheduled print-out — calls build() and reads the same array.
 * That is deliberate: when an admin compares the figure on screen against the
 * paper on their desk, the two must agree, and the only way to guarantee that
 * is for both to come from one query path.
 *
 * All aggregation keys off `payment_date`, not `created_at`. A payment
 * back-dated to last Friday belongs in last Friday's takings, which is what an
 * accountant reconciling a cash book expects.
 */
class EqubReportService
{
    /** Rows kept in breakdown tables before we stop and show "other". */
    protected const BREAKDOWN_LIMIT = 10;

    /** Ceiling on the transaction list embedded in the report payload. */
    protected const DETAIL_LIMIT = 500;

    /*
     * Reporting always rolls up to the platform Equb, never the member-created
     * Group Equb sitting inside it.
     *
     * A member's "Bilal's family" group is a child of "Al Nur Daily", and the
     * money belongs to the latter — grouping by the child scatters one Equb's
     * takings across dozens of family names and makes the report useless for
     * reconciliation. Climbing to the parent when one exists, and falling back
     * to the row itself when it does not, handles both shapes in one query.
     *
     * Packages work the same way: a child group usually leaves
     * equb_package_id null and inherits the parent's.
     */
    protected const GROUP_ID = 'coalesce(peg.id, eg.id)';

    protected const GROUP_NAME = 'coalesce(peg.name, eg.name)';

    protected const PACKAGE_ID = 'coalesce(ep.id, pep.id)';

    protected const PACKAGE_NAME = 'coalesce(ep.name, pep.name)';

    /*
     * The system runs two kinds of Equb side by side, and a report that cannot
     * tell them apart is useless:
     *
     *   Single / platform Equb — the member joins "Raha Montly" directly.
     *     equb_groups.owner_member_id is null.
     *
     *   Group Equb — the member joins a family group like "Mahi Family",
     *     which itself plays inside "Raha Montly".
     *     equb_groups.owner_member_id is the founding member, and
     *     parent_equb_group_id points at the platform Equb.
     *
     * owner_member_id is the discriminator rather than parent_equb_group_id,
     * because it is what actually defines a member-created group; the parent
     * link is how that group is settled, not what it is.
     */
    protected const IS_GROUP_EQUB = 'case when eg.owner_member_id is null then 0 else 1 end';

    protected const GROUP_EQUB_NAME = 'case when eg.owner_member_id is null then null else eg.name end';

    /**
     * Normalise whatever the filter form (or a saved schedule) hands us into a
     * predictable shape. Doing this once means every method below can trust
     * its input, and a schedule saved months ago still resolves correctly.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $filters = []): array
    {
        $period = ReportPeriod::tryFrom($filters['period'] ?? '') ?? ReportPeriod::Daily;

        $from = $this->parseDate($filters['from'] ?? null);
        $to = $this->parseDate($filters['to'] ?? null);

        if ($period === ReportPeriod::Custom) {
            // A half-filled custom range is common (user picks "from" then
            // reads the chart before picking "to"), so fill the gap rather
            // than showing an empty report.
            $from ??= CarbonImmutable::now()->subDays(29);
            $to ??= CarbonImmutable::now();
            $start = $from->startOfDay();
            $end = $to->endOfDay();

            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
            }
        } else {
            // Presets anchor on the date the user picked, so "monthly" can
            // show March as easily as the current month.
            [$start, $end] = $period->range($from ?? CarbonImmutable::now());
        }

        return [
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'anchor' => $from,
            'equb_group_ids' => $this->intList($filters['equb_group_ids'] ?? null),
            'equb_package_ids' => $this->intList($filters['equb_package_ids'] ?? null),
            'agent_ids' => $this->intList($filters['agent_ids'] ?? null),
            'payment_methods' => $this->stringList($filters['payment_methods'] ?? null),
            'statuses' => $this->stringList($filters['statuses'] ?? null),
            'min_amount' => is_numeric($filters['min_amount'] ?? null) ? (float) $filters['min_amount'] : null,
            'max_amount' => is_numeric($filters['max_amount'] ?? null) ? (float) $filters['max_amount'] : null,
            'search' => filled($filters['search'] ?? null) ? trim((string) $filters['search']) : null,
            'include_details' => (bool) ($filters['include_details'] ?? true),
        ];
    }

    /**
     * The whole report as one array.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $f = $this->normalizeFilters($filters);

        /** @var ReportPeriod $period */
        $period = $f['period'];
        /** @var CarbonImmutable $start */
        $start = $f['start'];
        /** @var CarbonImmutable $end */
        $end = $f['end'];

        $granularity = $period->granularity($start, $end);

        [$prevStart, $prevEnd] = $period->previousRange($start, $end);

        $current = $this->totals($f, $start, $end);
        $previous = $this->totals($f, $prevStart, $prevEnd);

        return [
            'meta' => [
                'period' => $period->value,
                'period_label' => $period->label(),
                'granularity' => $granularity,
                'start' => $start->toDateTimeString(),
                'end' => $end->toDateTimeString(),
                'range_label' => $this->rangeLabel($period, $start, $end),
                'previous_range_label' => $this->rangeLabel($period, $prevStart, $prevEnd),
                'generated_at' => CarbonImmutable::now()->toDateTimeString(),
                'currency' => 'ETB',
                'filters' => $this->describeFilters($f),
                'has_filters' => $this->hasActiveFilters($f),
            ],
            'summary' => $current,
            'previous' => $previous,
            'growth' => $this->growth($current, $previous),
            'series' => $this->series($f, $start, $end, $granularity),
            'by_status' => $this->breakdownByStatus($f, $start, $end),
            'by_type' => $this->breakdownByType($f, $start, $end),
            'by_group' => $this->breakdownBy($f, $start, $end, self::GROUP_NAME, self::GROUP_ID),
            'by_group_equb' => $this->breakdownByGroupEqub($f, $start, $end),
            'by_package' => $this->breakdownBy($f, $start, $end, self::PACKAGE_NAME, self::PACKAGE_ID),
            'top_members' => $this->topMembers($f, $start, $end),
            'details' => $f['include_details']
                ? $this->details($f, $start, $end)
                : collect(),
        ];
    }

    // -----------------------------------------------------------------
    // Query construction
    // -----------------------------------------------------------------

    /**
     * One join path, used by every aggregate below.
     *
     * Joins rather than whereHas: the report groups by group, package and
     * agent, so the related tables must be on the query anyway, and pulling
     * them in once avoids a correlated subquery per filter.
     *
     * @param  array<string, mixed>  $f
     */
    protected function baseQuery(array $f, CarbonImmutable $start, CarbonImmutable $end): QueryBuilder
    {
        $q = DB::table('equb_payments as p')
            ->join('equb_memberships as em', 'em.id', '=', 'p.equb_membership_id')
            // Joined on whoever pays, not on the name attached to the place.
            //
            // A "My Responsibility People" place has a null member_id, so an
            // inner join on em.member_id dropped every contribution made on
            // one of those places out of the report entirely — real money
            // collected and banked, missing from the takings an admin
            // reconciles against. coalesce falls through to the sponsor, who
            // is the member who actually paid, and the join is left so a row
            // can never disappear even if both are somehow null.
            ->leftJoin('members as m', 'm.id', '=', DB::raw('coalesce(em.member_id, em.sponsor_member_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->join('equb_groups as eg', 'eg.id', '=', 'em.equb_group_id')
            // The platform Equb this group belongs to, when it is a
            // member-created Group Equb. Null for platform groups themselves.
            ->leftJoin('equb_groups as peg', 'peg.id', '=', 'eg.parent_equb_group_id')
            ->leftJoin('equb_packages as ep', 'ep.id', '=', 'eg.equb_package_id')
            ->leftJoin('equb_packages as pep', 'pep.id', '=', 'peg.equb_package_id')
            // The member who founded a Group Equb. Null for platform Equbs,
            // which is exactly what marks a payment as a single Equb payment.
            ->leftJoin('members as gom', 'gom.id', '=', 'eg.owner_member_id')
            ->leftJoin('agents as ag', 'ag.id', '=', 'm.agent_id')
            ->leftJoin('users as au', 'au.id', '=', 'ag.user_id')
            ->whereBetween('p.payment_date', [$start, $end]);

        if ($f['equb_group_ids']) {
            // Matches either side of the parent link, so picking "Al Nur Daily"
            // pulls in every family group playing inside it as well.
            $q->where(function (QueryBuilder $sub) use ($f): void {
                $sub->whereIn('eg.id', $f['equb_group_ids'])
                    ->orWhereIn('peg.id', $f['equb_group_ids']);
            });
        }

        if ($f['equb_package_ids']) {
            $q->where(function (QueryBuilder $sub) use ($f): void {
                $sub->whereIn('ep.id', $f['equb_package_ids'])
                    ->orWhereIn('pep.id', $f['equb_package_ids']);
            });
        }

        if ($f['agent_ids']) {
            $q->whereIn('ag.id', $f['agent_ids']);
        }

        if ($f['payment_methods']) {
            $q->whereIn('p.payment_method', $f['payment_methods']);
        }

        if ($f['statuses']) {
            $q->whereIn('p.status', $f['statuses']);
        }

        if ($f['min_amount'] !== null) {
            $q->where('p.amount', '>=', $f['min_amount']);
        }

        if ($f['max_amount'] !== null) {
            $q->where('p.amount', '<=', $f['max_amount']);
        }

        if ($f['search']) {
            $term = '%'.$f['search'].'%';
            $q->where(function (QueryBuilder $sub) use ($term): void {
                $sub->where('m.full_name', 'like', $term)
                    ->orWhere('u.phone', 'like', $term)
                    // The name on a place held for someone else lives on the
                    // membership, not on a member row, so searching for a
                    // child by name would otherwise never match.
                    ->orWhere('em.responsibility_name', 'like', $term)
                    ->orWhere('p.reference', 'like', $term)
                    ->orWhere('eg.name', 'like', $term)
                    ->orWhere('peg.name', 'like', $term);
            });
        }

        return $q;
    }

    /**
     * Headline figures for a window.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, float|int>
     */
    protected function totals(array $f, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $paid = EqubPaymentStatus::Paid->value;
        $pending = EqubPaymentStatus::Pending->value;
        $failed = EqubPaymentStatus::Failed->value;

        $row = $this->baseQuery($f, $start, $end)
            ->selectRaw($this->sumIf('p.status', $paid, 'p.amount').' as collected')
            ->selectRaw($this->sumIf('p.status', $pending, 'p.amount').' as outstanding')
            ->selectRaw($this->sumIf('p.status', $failed, 'p.amount').' as failed_amount')
            ->selectRaw($this->countIf('p.status', $paid).' as paid_count')
            ->selectRaw($this->countIf('p.status', $pending).' as pending_count')
            ->selectRaw($this->countIf('p.status', $failed).' as failed_count')
            ->selectRaw('count(*) as transactions')
            ->selectRaw('count(distinct m.id) as members')
            // Alias is `groups_count`, not `groups`: GROUPS became a reserved
            // word in MySQL 8.0 (window-function frame syntax), so an
            // unquoted `as groups` is a 1064 syntax error there while parsing
            // fine on SQLite. Renaming keeps it portable without backticks,
            // which Postgres would reject in turn.
            ->selectRaw('count(distinct '.self::GROUP_ID.') as groups_count')
            ->first();

        $collected = (float) ($row->collected ?? 0);
        $outstanding = (float) ($row->outstanding ?? 0);
        $failedAmount = (float) ($row->failed_amount ?? 0);
        $paidCount = (int) ($row->paid_count ?? 0);
        $transactions = (int) ($row->transactions ?? 0);

        $billed = $collected + $outstanding + $failedAmount;

        return [
            'collected' => round($collected, 2),
            'outstanding' => round($outstanding, 2),
            'failed_amount' => round($failedAmount, 2),
            'gross' => round($billed, 2),
            'paid_count' => $paidCount,
            'pending_count' => (int) ($row->pending_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
            'transactions' => $transactions,
            'members' => (int) ($row->members ?? 0),
            'groups' => (int) ($row->groups_count ?? 0),
            // Average is over settled payments only. Averaging across pending
            // rows would understate the takings on a day with many unpaid
            // invoices raised.
            'average_payment' => $paidCount > 0 ? round($collected / $paidCount, 2) : 0.0,
            'collection_rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0.0,
            'success_rate' => $transactions > 0 ? round(($paidCount / $transactions) * 100, 1) : 0.0,
        ];
    }

    /**
     * Percentage movement against the comparable previous window.
     *
     * @param  array<string, float|int>  $current
     * @param  array<string, float|int>  $previous
     * @return array<string, float|null>
     */
    protected function growth(array $current, array $previous): array
    {
        $keys = ['collected', 'outstanding', 'transactions', 'members', 'average_payment', 'collection_rate'];
        $out = [];

        foreach ($keys as $key) {
            $now = (float) ($current[$key] ?? 0);
            $was = (float) ($previous[$key] ?? 0);

            // A jump from zero is not "infinite growth" — it is new activity
            // with no baseline, so we return null and the view says "new"
            // instead of printing a meaningless percentage.
            $out[$key] = match (true) {
                $was == 0.0 && $now == 0.0 => 0.0,
                $was == 0.0 => null,
                default => round((($now - $was) / abs($was)) * 100, 1),
            };
        }

        return $out;
    }

    /**
     * The trend line: one bucket per hour, day or month, with empty buckets
     * filled in so the chart shows a flat stretch rather than closing the gap.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>>
     */
    protected function series(array $f, CarbonImmutable $start, CarbonImmutable $end, string $granularity): array
    {
        $paid = EqubPaymentStatus::Paid->value;
        $pending = EqubPaymentStatus::Pending->value;
        $failed = EqubPaymentStatus::Failed->value;

        $expression = $this->bucketExpression('p.payment_date', $granularity);

        $rows = $this->baseQuery($f, $start, $end)
            ->selectRaw("{$expression} as bucket")
            ->selectRaw($this->sumIf('p.status', $paid, 'p.amount').' as collected')
            ->selectRaw($this->sumIf('p.status', $pending, 'p.amount').' as outstanding')
            ->selectRaw($this->sumIf('p.status', $failed, 'p.amount').' as failed_amount')
            ->selectRaw($this->countIf('p.status', $paid).' as paid_count')
            ->selectRaw('count(*) as transactions')
            ->groupBy(DB::raw($expression))
            ->orderBy(DB::raw($expression))
            ->get()
            ->keyBy(fn ($r) => (string) $r->bucket);

        $format = ReportPeriod::bucketLabelFormat($granularity);
        $buckets = [];
        $cursor = $this->floorTo($start, $granularity);

        // Guard against a pathological custom range producing tens of
        // thousands of points and stalling the browser.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($end) && $guard++ < 1500) {
            $key = $this->bucketKey($cursor, $granularity);
            $row = $rows->get($key);

            $buckets[] = [
                'key' => $key,
                'label' => $cursor->translatedFormat($format),
                'date' => $cursor->toDateTimeString(),
                'collected' => round((float) ($row->collected ?? 0), 2),
                'outstanding' => round((float) ($row->outstanding ?? 0), 2),
                'failed_amount' => round((float) ($row->failed_amount ?? 0), 2),
                'paid_count' => (int) ($row->paid_count ?? 0),
                'transactions' => (int) ($row->transactions ?? 0),
            ];

            $cursor = match ($granularity) {
                'hour' => $cursor->addHour(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdownByStatus(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $rows = $this->baseQuery($f, $start, $end)
            ->select('p.status')
            ->selectRaw('sum(p.amount) as amount')
            ->selectRaw('count(*) as transactions')
            ->groupBy('p.status')
            ->get();

        $total = (float) $rows->sum('amount');

        return collect(EqubPaymentStatus::cases())
            ->map(function (EqubPaymentStatus $status) use ($rows, $total): array {
                $row = $rows->firstWhere('status', $status->value);
                $amount = (float) ($row->amount ?? 0);

                return [
                    'key' => $status->value,
                    'label' => ucfirst($status->value),
                    'amount' => round($amount, 2),
                    'transactions' => (int) ($row->transactions ?? 0),
                    'share' => $total > 0 ? round(($amount / $total) * 100, 1) : 0.0,
                ];
            })
            ->values();
    }

    /**
     * Group Equb payments against single (platform) Equb payments.
     *
     * The headline split for this business: money arriving through family
     * groups behaves differently from money arriving from members who joined
     * an Equb on their own, and the two need to be readable at a glance.
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdownByType(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $rows = $this->baseQuery($f, $start, $end)
            ->selectRaw(self::IS_GROUP_EQUB.' as is_group')
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Paid->value, 'p.amount').' as collected')
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Pending->value, 'p.amount').' as outstanding')
            ->selectRaw('count(*) as transactions')
            ->selectRaw('count(distinct m.id) as members')
            ->selectRaw('count(distinct '.self::GROUP_ID.') as equbs')
            ->groupBy(DB::raw(self::IS_GROUP_EQUB))
            ->get()
            ->keyBy(fn ($r) => (int) $r->is_group);

        $total = (float) $rows->sum('collected');

        // Both rows are always emitted, even at zero. "No Group Equb money
        // came in today" is a real finding; an absent row reads as an
        // oversight.
        return collect([1, 0])
            ->map(function (int $key) use ($rows, $total): array {
                $row = $rows->get($key);
                $collected = (float) ($row->collected ?? 0);

                return [
                    'key' => $key === 1 ? 'group_equb' : 'individual',
                    'label' => $key === 1
                        ? __('filament.equb_report.group_equb')
                        : __('filament.equb_report.individual_equb'),
                    'collected' => round($collected, 2),
                    'outstanding' => round((float) ($row->outstanding ?? 0), 2),
                    'transactions' => (int) ($row->transactions ?? 0),
                    'members' => (int) ($row->members ?? 0),
                    'equbs' => (int) ($row->equbs ?? 0),
                    'share' => $total > 0 ? round(($collected / $total) * 100, 1) : 0.0,
                ];
            })
            ->values();
    }

    /**
     * Member-created Group Equbs by name, with the platform Equb each one
     * plays inside and the member who founded it.
     *
     * Rolling everything up to the parent Equb answers "how much did Raha
     * Montly take". It cannot answer "how much came from Mahi Family", which
     * is the question an admin actually gets asked, so that stays its own
     * breakdown rather than being folded away.
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdownByGroupEqub(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->baseQuery($f, $start, $end)
            ->whereNotNull('eg.owner_member_id')
            ->select([
                'eg.id as group_equb_id',
                'eg.name as group_equb_name',
                'eg.invite_code',
                'peg.name as parent_name',
                'gom.full_name as owner_name',
            ])
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Paid->value, 'p.amount').' as collected')
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Pending->value, 'p.amount').' as outstanding')
            ->selectRaw('count(*) as transactions')
            ->selectRaw('count(distinct m.id) as members')
            ->groupBy('eg.id', 'eg.name', 'eg.invite_code', 'peg.name', 'gom.full_name')
            ->orderByDesc('collected')
            ->limit(self::BREAKDOWN_LIMIT)
            ->get()
            ->map(fn ($r): array => [
                'id' => (int) $r->group_equb_id,
                'label' => $r->group_equb_name,
                'parent' => $r->parent_name ?: __('filament.equb_report.unassigned'),
                'owner' => $r->owner_name ?: '—',
                'invite_code' => $r->invite_code,
                'collected' => round((float) $r->collected, 2),
                'outstanding' => round((float) $r->outstanding, 2),
                'transactions' => (int) $r->transactions,
                'members' => (int) $r->members,
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdownByMethod(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $rows = $this->baseQuery($f, $start, $end)
            ->select('p.payment_method')
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Paid->value, 'p.amount').' as collected')
            ->selectRaw('sum(p.amount) as amount')
            ->selectRaw('count(*) as transactions')
            ->groupBy('p.payment_method')
            ->get();

        $total = (float) $rows->sum('collected');

        return collect(EqubPaymentMethod::cases())
            ->map(function (EqubPaymentMethod $method) use ($rows, $total): array {
                $row = $rows->firstWhere('payment_method', $method->value);
                $collected = (float) ($row->collected ?? 0);

                return [
                    'key' => $method->value,
                    'label' => ucfirst($method->value),
                    'collected' => round($collected, 2),
                    'amount' => round((float) ($row->amount ?? 0), 2),
                    'transactions' => (int) ($row->transactions ?? 0),
                    'share' => $total > 0 ? round(($collected / $total) * 100, 1) : 0.0,
                ];
            })
            ->values();
    }

    /**
     * Generic "top N by collected amount" breakdown, used for groups,
     * packages and agents.
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function breakdownBy(array $f, CarbonImmutable $start, CarbonImmutable $end, string $labelColumn, string $idColumn): Collection
    {
        $rows = $this->baseQuery($f, $start, $end)
            ->select([
                DB::raw("{$idColumn} as entity_id"),
                DB::raw("{$labelColumn} as entity_label"),
            ])
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Paid->value, 'p.amount').' as collected')
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Pending->value, 'p.amount').' as outstanding')
            ->selectRaw('count(*) as transactions')
            ->selectRaw('count(distinct m.id) as members')
            ->groupBy(DB::raw($idColumn), DB::raw($labelColumn))
            ->orderByDesc('collected')
            ->limit(self::BREAKDOWN_LIMIT)
            ->get();

        $total = (float) $rows->sum('collected');

        return $rows->map(fn ($r): array => [
            'id' => $r->entity_id,
            // Agents and packages are optional on a payment, so an unlabelled
            // row means "no agent"/"no package" rather than bad data.
            'label' => $r->entity_label ?: __('filament.equb_report.unassigned'),
            'collected' => round((float) $r->collected, 2),
            'outstanding' => round((float) $r->outstanding, 2),
            'transactions' => (int) $r->transactions,
            'members' => (int) $r->members,
            'share' => $total > 0 ? round(((float) $r->collected / $total) * 100, 1) : 0.0,
        ])->values();
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function topMembers(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->baseQuery($f, $start, $end)
            ->select(['m.id as member_id', 'm.full_name', 'u.phone'])
            ->selectRaw($this->sumIf('p.status', EqubPaymentStatus::Paid->value, 'p.amount').' as collected')
            ->selectRaw('count(*) as transactions')
            ->groupBy('m.id', 'm.full_name', 'u.phone')
            ->orderByDesc('collected')
            ->limit(self::BREAKDOWN_LIMIT)
            ->get()
            ->map(fn ($r): array => [
                'id' => $r->member_id,
                'name' => $r->full_name,
                'phone' => $r->phone,
                'collected' => round((float) $r->collected, 2),
                'transactions' => (int) $r->transactions,
            ])
            ->values();
    }

    /**
     * The line-by-line transaction list that backs the detail table, the CSV
     * and the printed report.
     *
     * @param  array<string, mixed>  $f
     * @return Collection<int, array<string, mixed>>
     */
    protected function details(array $f, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->baseQuery($f, $start, $end)
            ->select([
                'p.id',
                'p.amount',
                'p.payment_date',
                'p.payment_method',
                'p.status',
                'p.reference',
                'm.full_name as member_name',
                'u.phone as member_phone',
                // Whose place the contribution was for, when it is one held
                // on someone else's behalf. member_name above is now the
                // payer, which is the right anchor for a money report — but a
                // reader still needs to see which place it settled.
                'em.responsibility_name as held_for',
                DB::raw(self::GROUP_NAME.' as group_name'),
                DB::raw(self::GROUP_EQUB_NAME.' as group_equb_name'),
                DB::raw(self::IS_GROUP_EQUB.' as is_group_equb'),
                DB::raw(self::PACKAGE_NAME.' as package_name'),
                'au.name as agent_name',
            ])
            ->orderByDesc('p.payment_date')
            ->orderByDesc('p.id')
            ->limit(self::DETAIL_LIMIT)
            ->get()
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                // Reads "Bilal (for Amina)" so one column carries both facts:
                // whose money it was, and which place it paid off. Every
                // surface that prints this row — table, PDF, CSV — gets it
                // without needing its own column.
                'member_name' => filled($r->held_for)
                    ? trim(($r->member_name ?: '—').' ('.__('filament.equb_report.for').' '.$r->held_for.')')
                    : $r->member_name,
                'held_for' => $r->held_for,
                'member_phone' => $r->member_phone,
                'group_name' => $r->group_name,
                // Null for a single Equb payment. The view shows the two side
                // by side so a reader can see at a glance which route the
                // money came in through.
                'group_equb_name' => $r->group_equb_name,
                'is_group_equb' => (bool) $r->is_group_equb,
                'package_name' => $r->package_name,
                'agent_name' => $r->agent_name,
                'amount' => round((float) $r->amount, 2),
                'payment_date' => $r->payment_date,
                'payment_method' => $r->payment_method,
                'status' => $r->status,
                'reference' => $r->reference,
            ])
            ->values();
    }

    /**
     * Streams every matching row for CSV export, bypassing DETAIL_LIMIT.
     * Chunked so a year-long export does not load into memory at once.
     *
     * @param  array<string, mixed>  $filters
     */
    public function eachDetailRow(array $filters, callable $callback, int $chunkSize = 1000): void
    {
        $f = $this->normalizeFilters($filters);

        $this->baseQuery($f, $f['start'], $f['end'])
            ->select([
                'p.id',
                'p.amount',
                'p.payment_date',
                'p.payment_method',
                'p.status',
                'p.reference',
                'm.full_name as member_name',
                'u.phone as member_phone',
                'em.responsibility_name as held_for',
                DB::raw(self::GROUP_NAME.' as group_name'),
                DB::raw(self::GROUP_EQUB_NAME.' as group_equb_name'),
                DB::raw(self::PACKAGE_NAME.' as package_name'),
                'au.name as agent_name',
            ])
            ->orderBy('p.id')
            ->chunk($chunkSize, function (Collection $rows) use ($callback): void {
                foreach ($rows as $row) {
                    $callback($row);
                }
            });
    }

    /** Total rows a CSV export would produce, for the confirmation message. */
    public function countRows(array $filters): int
    {
        $f = $this->normalizeFilters($filters);

        return $this->baseQuery($f, $f['start'], $f['end'])->count();
    }

    // -----------------------------------------------------------------
    // SQL helpers
    // -----------------------------------------------------------------

    /**
     * Conditional sum that reads the same on MySQL, SQLite and Postgres.
     * Production runs MySQL and the test suite runs SQLite, so hand-rolled
     * DATE_FORMAT calls would pass review and then fail in CI.
     */
    protected function sumIf(string $column, string $value, string $target): string
    {
        $value = str_replace("'", "''", $value);

        return "coalesce(sum(case when {$column} = '{$value}' then {$target} else 0 end), 0)";
    }

    protected function countIf(string $column, string $value): string
    {
        $value = str_replace("'", "''", $value);

        return "sum(case when {$column} = '{$value}' then 1 else 0 end)";
    }

    /** Truncates a timestamp to the start of its bucket, per driver. */
    protected function bucketExpression(string $column, string $granularity): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => match ($granularity) {
                'hour' => "strftime('%Y-%m-%d %H:00:00', {$column})",
                'month' => "strftime('%Y-%m-01', {$column})",
                default => "strftime('%Y-%m-%d', {$column})",
            },
            'pgsql' => match ($granularity) {
                'hour' => "to_char(date_trunc('hour', {$column}), 'YYYY-MM-DD HH24:00:00')",
                'month' => "to_char(date_trunc('month', {$column}), 'YYYY-MM-01')",
                default => "to_char({$column}, 'YYYY-MM-DD')",
            },
            default => match ($granularity) {
                'hour' => "DATE_FORMAT({$column}, '%Y-%m-%d %H:00:00')",
                'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
                default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            },
        };
    }

    /** PHP-side twin of bucketExpression, so gap-filling keys line up. */
    protected function bucketKey(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'hour' => $date->format('Y-m-d H:00:00'),
            'month' => $date->format('Y-m-01'),
            default => $date->format('Y-m-d'),
        };
    }

    protected function floorTo(CarbonImmutable $date, string $granularity): CarbonImmutable
    {
        return match ($granularity) {
            'hour' => $date->startOfHour(),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    // -----------------------------------------------------------------
    // Presentation helpers
    // -----------------------------------------------------------------

    protected function rangeLabel(ReportPeriod $period, CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($period === ReportPeriod::Daily || $start->isSameDay($end)) {
            return $start->translatedFormat('l, d F Y');
        }

        if ($period === ReportPeriod::Monthly && $start->isSameMonth($end) && $start->day === 1) {
            return $start->translatedFormat('F Y');
        }

        if ($start->isSameYear($end)) {
            return $start->translatedFormat('d M').' – '.$end->translatedFormat('d M Y');
        }

        return $start->translatedFormat('d M Y').' – '.$end->translatedFormat('d M Y');
    }

    /**
     * Human-readable summary of the active filters, printed on the PDF so a
     * report on someone's desk always says what it was filtered to. A page of
     * numbers with no context is how the wrong figure ends up in a meeting.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, string>
     */
    protected function describeFilters(array $f): array
    {
        $parts = [];

        if ($f['equb_group_ids']) {
            $names = DB::table('equb_groups')->whereIn('id', $f['equb_group_ids'])->pluck('name');
            $parts[] = __('filament.equb_report.filter_groups').': '.$names->implode(', ');
        }

        if ($f['equb_package_ids']) {
            $names = DB::table('equb_packages')->whereIn('id', $f['equb_package_ids'])->pluck('name');
            $parts[] = __('filament.equb_report.filter_packages').': '.$names->implode(', ');
        }

        if ($f['agent_ids']) {
            $names = DB::table('agents')
                ->leftJoin('users', 'users.id', '=', 'agents.user_id')
                ->whereIn('agents.id', $f['agent_ids'])
                ->pluck('users.name');
            $parts[] = __('filament.equb_report.filter_agents').': '.$names->implode(', ');
        }

        if ($f['payment_methods']) {
            $parts[] = __('filament.equb_report.filter_methods').': '.collect($f['payment_methods'])->map(fn ($m) => ucfirst($m))->implode(', ');
        }

        if ($f['statuses']) {
            $parts[] = __('filament.equb_report.filter_statuses').': '.collect($f['statuses'])->map(fn ($s) => ucfirst($s))->implode(', ');
        }

        if ($f['min_amount'] !== null || $f['max_amount'] !== null) {
            $parts[] = __('filament.equb_report.filter_amount').': '
                .($f['min_amount'] !== null ? number_format($f['min_amount'], 2) : '0')
                .' – '
                .($f['max_amount'] !== null ? number_format($f['max_amount'], 2) : '∞');
        }

        if ($f['search']) {
            $parts[] = __('filament.equb_report.filter_search').': "'.$f['search'].'"';
        }

        return $parts;
    }

    /** @param  array<string, mixed>  $f */
    protected function hasActiveFilters(array $f): bool
    {
        return (bool) ($f['equb_group_ids'] || $f['equb_package_ids'] || $f['agent_ids']
            || $f['payment_methods'] || $f['statuses'] || $f['search']
            || $f['min_amount'] !== null || $f['max_amount'] !== null);
    }

    // -----------------------------------------------------------------
    // Input coercion
    // -----------------------------------------------------------------

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\Carbon\Carbon::instance($value));
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            // A malformed date from a stale bookmark or hand-edited URL should
            // fall back to the default window, not throw a 500 at the user.
            return null;
        }
    }

    /** @return array<int, int> */
    protected function intList(mixed $value): array
    {
        return collect(is_array($value) ? $value : (filled($value) ? [$value] : []))
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    protected function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : (filled($value) ? [$value] : []))
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => (string) ($v instanceof \BackedEnum ? $v->value : $v))
            ->unique()
            ->values()
            ->all();
    }

    /** Convenience for callers that only need a headline number. */
    public function collectedBetween(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return (float) EqubPayment::query()
            ->where('status', EqubPaymentStatus::Paid)
            ->whereBetween('payment_date', [$start, $end])
            ->sum('amount');
    }
}

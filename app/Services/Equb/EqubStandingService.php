<?php

namespace App\Services\Equb;

use App\Enums\EqubGroupStatus;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubMembership;
use App\Models\EqubPayment;
use App\Support\Equb\MembershipStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a member owes, and how they have behaved.
 *
 * ONE DEFINITION OF "BEHIND"
 *
 * Before this class existed, "outstanding" meant the sum of payment rows
 * sitting at status `pending`. That answered a question nobody asks. A member
 * who joins an Equb and never pays creates no rows at all, so the single
 * worst case in the business — money owed by someone who has never paid
 * anything — reported as zero. And because the report defaults to filtering
 * on status = paid, even the genuine pending rows were filtered out before
 * they could be summed, so the figure on screen was zero twice over.
 *
 * Arrears here are derived from the schedule instead:
 *
 *     rounds due to date  = how many contributions the calendar has asked for
 *     expected to date    = rounds due x contribution amount
 *     arrears             = expected to date - what has actually settled
 *
 * A membership with no payment rows is fully in arrears, which is correct and
 * is the entire point.
 *
 * TWO PATHS, ONE FORMULA
 *
 * The lottery needs the full picture for a few hundred memberships, including
 * behaviour the database cannot easily express — when each contribution
 * settled against when it was due. That runs in PHP.
 *
 * The receivables report needs the money columns for potentially tens of
 * thousands of memberships. That runs in SQL.
 *
 * Both live in this class so the two can be read side by side, because a
 * formula implemented twice in two languages is a formula that will disagree
 * with itself eventually. The SQL below is the money half of the PHP above,
 * line for line.
 */
class EqubStandingService
{
    public function __construct(protected EqubRules $rules) {}

    // -----------------------------------------------------------------
    // PHP path — full standing, used by the lottery
    // -----------------------------------------------------------------

    /**
     * Standing for a single membership.
     */
    public function for(EqubMembership $membership, ?CarbonImmutable $asOf = null): MembershipStanding
    {
        return $this->forMany(collect([$membership]), $asOf)->first();
    }

    /**
     * Standing for many memberships, with one query for all their payments.
     *
     * @param  Collection<int, EqubMembership>  $memberships
     * @return Collection<int, MembershipStanding> keyed by membership id
     */
    public function forMany(Collection $memberships, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $ids = $memberships->pluck('id')->filter()->all();

        if ($ids === []) {
            return collect();
        }

        // Only settled contributions count. A pending row is a promise and a
        // failed one is a non-event; neither reduces what is owed.
        $payments = EqubPayment::query()
            ->whereIn('equb_membership_id', $ids)
            ->where('status', EqubPaymentStatus::Paid)
            ->get(['id', 'equb_membership_id', 'amount', 'payment_date', 'bank_paid_at', 'created_at'])
            ->groupBy('equb_membership_id');

        return $memberships
            ->mapWithKeys(fn (EqubMembership $m): array => [
                $m->id => $this->build($m, $payments->get($m->id, collect()), $asOf),
            ]);
    }

    /**
     * @param  Collection<int, EqubPayment>  $payments  Settled contributions only.
     */
    protected function build(EqubMembership $membership, Collection $payments, CarbonImmutable $asOf): MembershipStanding
    {
        $group = $membership->equbGroup;

        $contribution = (float) ($membership->contribution_amount ?? 0);
        $frequency = max(1, (int) ($membership->contribution_frequency_days ?: 1));
        $joinedAt = $membership->join_date
            ? CarbonImmutable::instance($membership->join_date)->startOfDay()
            : null;

        $totalRounds = $group ? $this->totalRoundsFor($group) : 0;

        $paidAmount = round((float) $payments->sum('amount'), 2);
        $paidRounds = $contribution > 0
            ? (int) floor(($paidAmount + 0.005) / $contribution)
            : $payments->count();

        // When each contribution actually settled, oldest first. bank_paid_at
        // is the bank's own timestamp and the only honest answer where it
        // exists; created_at is when the member started the payment, which is
        // the best available stand-in for a row marked paid by hand.
        $settlements = $payments
            ->map(fn (EqubPayment $p): CarbonImmutable => CarbonImmutable::instance(
                $p->bank_paid_at ?? $p->created_at ?? $p->payment_date ?? now()
            ))
            ->sort()
            ->values();

        $lastPaidAt = $settlements->last();

        // Rounds the calendar has asked for.
        $roundsDue = $this->roundsDue($joinedAt, $frequency, $totalRounds, $group?->status, $asOf);

        $expectedToDate = round($roundsDue * $contribution, 2);
        $difference = round($expectedToDate - $paidAmount, 2);

        $arrears = max(0.0, $difference);
        $advanceAmount = max(0.0, -$difference);

        $missedRounds = ($contribution > 0 && $arrears > 0)
            ? (int) ceil(($arrears - 0.005) / $contribution)
            : 0;

        $advanceRounds = ($contribution > 0 && $advanceAmount > 0)
            ? (int) floor(($advanceAmount + 0.005) / $contribution)
            : 0;

        // The first round they have not covered fell due this many days ago.
        // Pure arithmetic rather than date maths: every round is `frequency`
        // days apart, so the due date of round N is N intervals after joining
        // and no calendar is needed to find it.
        $daysSinceJoin = $joinedAt ? max(0, (int) $joinedAt->diffInDays($asOf, false)) : 0;
        $daysOverdue = $arrears > 0
            ? max(0, $daysSinceJoin - ($paidRounds * $frequency))
            : 0;

        [$streak, $lateCount] = $this->punctuality($settlements, $joinedAt, $frequency);

        $cleanRecord = $lateCount === 0 && $arrears <= 0.009 && $paidRounds > 0;

        $status = $this->status(
            totalRounds: $totalRounds,
            roundsDue: $roundsDue,
            paidRounds: $paidRounds,
            missedRounds: $missedRounds,
            advanceRounds: $advanceRounds,
            daysOverdue: $daysOverdue,
        );

        return new MembershipStanding(
            membershipId: (int) $membership->id,
            payerMemberId: $membership->payerMemberId(),
            displayName: $membership->displayName(),
            contribution: $contribution,
            frequencyDays: $frequency,
            totalRounds: $totalRounds,
            roundsDue: $roundsDue,
            joinedAt: $joinedAt,
            paidAmount: $paidAmount,
            paidRounds: $paidRounds,
            lastPaidAt: $lastPaidAt,
            expectedToDate: $expectedToDate,
            arrears: $arrears,
            advanceAmount: $advanceAmount,
            missedRounds: $missedRounds,
            advanceRounds: $advanceRounds,
            daysOverdue: $daysOverdue,
            onTimeStreak: $streak,
            lateCount: $lateCount,
            cleanRecord: $cleanRecord,
            roundsWaited: $membership->has_won ? 0 : $roundsDue,
            hasWon: (bool) $membership->has_won,
            status: $status,
        );
    }

    /**
     * How many contributions the schedule has asked for by $asOf.
     *
     * Round 1 falls due on the join date itself, so a member who joined today
     * already owes one round. The grace period pushes the boundary back a few
     * days: a round that came due on Friday is not counted against anyone
     * until the following Monday, because a bank transfer in flight over a
     * weekend is not a missed payment.
     */
    protected function roundsDue(
        ?CarbonImmutable $joinedAt,
        int $frequency,
        int $totalRounds,
        ?EqubGroupStatus $groupStatus,
        CarbonImmutable $asOf,
    ): int {
        if (! $joinedAt || $totalRounds <= 0) {
            return 0;
        }

        // Nothing is owed on an Equb that has not started. Members join
        // during registration and wait, sometimes for weeks, and billing them
        // for that wait would put the entire register into arrears on day one.
        if (in_array($groupStatus, [EqubGroupStatus::Draft, EqubGroupStatus::Registration], true)) {
            return 0;
        }

        $effective = $asOf->subDays($this->rules->graceDays());

        if ($effective->lessThan($joinedAt)) {
            return 0;
        }

        $elapsed = (int) floor($joinedAt->diffInDays($effective, false) / $frequency) + 1;

        return max(0, min($elapsed, $totalRounds));
    }

    /**
     * Was each contribution on time, and how long is the current run?
     *
     * The k-th settled contribution is measured against the k-th scheduled
     * due date — derived from the join date and the frequency, never from the
     * payment row's own `payment_date`. That column is written by whatever
     * created the row, so a row could always claim it was paid on the day it
     * was due. The schedule cannot be edited by the thing being judged.
     *
     * The schedule is counted in days, because that is the only thing the
     * membership stores. A "monthly" Equb is thirty-day intervals, which drift
     * against the calendar by roughly five days a year — so a member paying on
     * the sixth of every month will eventually be measured as late. That is
     * the same schedule the member is shown in the app (EqubMembership's
     * payment_schedule counts days too), so the two agree; the grace period is
     * the lever for it, and an Equb on monthly rounds wants rather more than
     * the two days a daily Equb needs.
     *
     * @param  Collection<int, CarbonImmutable>  $settlements  Oldest first.
     * @return array{0: int, 1: int}  [current on-time streak, times late]
     */
    protected function punctuality(Collection $settlements, ?CarbonImmutable $joinedAt, int $frequency): array
    {
        if (! $joinedAt || $settlements->isEmpty()) {
            return [0, 0];
        }

        $grace = $this->rules->graceDays();
        $onTime = [];

        foreach ($settlements as $index => $settledAt) {
            $due = $joinedAt->addDays($index * $frequency)->endOfDay()->addDays($grace);
            $onTime[] = $settledAt->lessThanOrEqualTo($due);
        }

        $lateCount = count(array_filter($onTime, fn (bool $ok): bool => ! $ok));

        // Trailing run only. A streak is a claim about now, so one late
        // payment resets it however good the history before it was.
        $streak = 0;

        for ($i = count($onTime) - 1; $i >= 0; $i--) {
            if (! $onTime[$i]) {
                break;
            }

            $streak++;
        }

        return [$streak, $lateCount];
    }

    protected function status(
        int $totalRounds,
        int $roundsDue,
        int $paidRounds,
        int $missedRounds,
        int $advanceRounds,
        int $daysOverdue,
    ): string {
        if ($totalRounds <= 0) {
            return MembershipStanding::UNKNOWN;
        }

        // Joined, owes rounds, has never paid a birr. Kept separate from
        // "blocked" because it is a different conversation: one member needs
        // chasing for a missed round, the other may never have intended to
        // pay at all.
        if ($roundsDue > 0 && $paidRounds === 0) {
            return MembershipStanding::NEVER_PAID;
        }

        if ($missedRounds >= $this->rules->suspendAt()) {
            return MembershipStanding::SUSPENDED;
        }

        if ($missedRounds >= $this->rules->blockAt()) {
            return MembershipStanding::BLOCKED;
        }

        if ($missedRounds >= $this->rules->warnAt()) {
            return MembershipStanding::WARNED;
        }

        return $advanceRounds > 0 ? MembershipStanding::AHEAD : MembershipStanding::CURRENT;
    }

    /**
     * Total contribution rounds for a group. Mirrors EqubGroup::totalRounds()
     * and EqubMembership::expected_total_amount, which must all agree.
     */
    public function totalRoundsFor(\App\Models\EqubGroup $group): int
    {
        return method_exists($group, 'totalRounds') ? (int) $group->totalRounds() : 0;
    }

    // -----------------------------------------------------------------
    // SQL path — the money half, for reporting over everyone at once
    // -----------------------------------------------------------------

    /**
     * Rounds the schedule has asked for, as SQL.
     *
     * Reads as the PHP above: zero before the Equb starts, otherwise one
     * round per elapsed interval since joining, capped at the total.
     * Expects `em` (equb_memberships) and `eg` (equb_groups) in scope.
     */
    public function roundsDueSql(CarbonImmutable $asOf): string
    {
        $effective = $asOf->subDays($this->rules->graceDays());
        $elapsed = $this->daysSinceSql('em.join_date', $effective);
        $frequency = $this->frequencySql();
        $total = $this->totalRoundsSql();

        return "(case
            when em.join_date is null then 0
            when {$total} <= 0 then 0
            when eg.status in ('draft', 'registration') then 0
            when {$elapsed} < 0 then 0
            else (case
                when (floor({$elapsed} / {$frequency}) + 1) > {$total} then {$total}
                else (floor({$elapsed} / {$frequency}) + 1)
            end)
        end)";
    }

    /** Contribution rounds in the whole Equb, from the group's own settings. */
    public function totalRoundsSql(): string
    {
        return "(case
            when eg.duration_type = 'per_member'
                then coalesce(eg.max_members, eg.current_members_count, 0)
            else coalesce(eg.duration_value, 0)
        end)";
    }

    /** Days between a column and a fixed moment, per driver. */
    public function daysSinceSql(string $column, CarbonImmutable $moment): string
    {
        $at = $moment->toDateTimeString();

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "cast(julianday('{$at}') - julianday({$column}) as integer)",
            'pgsql' => "floor(extract(epoch from (timestamp '{$at}' - {$column})) / 86400)",
            default => "timestampdiff(day, {$column}, '{$at}')",
        };
    }

    /** Never zero: a frequency of zero would divide the schedule by nothing. */
    public function frequencySql(): string
    {
        return '(case when coalesce(em.contribution_frequency_days, 0) < 1 then 1 else em.contribution_frequency_days end)';
    }
}

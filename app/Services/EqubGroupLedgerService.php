<?php

namespace App\Services;

use App\Enums\EqubMembershipStatus;
use App\Enums\EqubPaymentStatus;
use App\Models\EqubGroup;
use Illuminate\Support\Carbon;

/**
 * Builds the "who has paid / who has not" picture for a group.
 *
 * One query for the memberships (with aggregates) plus one for the group, so it
 * stays flat no matter how many members are in the circle. Feeds the mobile
 * group dashboard, the admin ledger page and the reminder job.
 */
class EqubGroupLedgerService
{
    /**
     * @return array{group: array<string, mixed>, members: array<int, array<string, mixed>>}
     */
    public function forGroup(EqubGroup $group): array
    {
        $totalRounds = max(0, $group->totalRounds());
        $contribution = (float) $group->fixed_contribution_amount;
        $frequency = max(1, (int) $group->contribution_frequency_days);

        $memberships = $group->memberships()
            // sponsor.user is loaded alongside member.user because a
            // "My Responsibility People" seat has no member behind it: the
            // sponsor is where its name, contact and money all come from.
            ->with(['member.user:id,phone,name,profile_picture', 'sponsor.user:id,phone,name'])
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', EqubPaymentStatus::Paid)], 'amount')
            ->withCount(['payments as paid_rounds' => fn ($q) => $q->where('status', EqubPaymentStatus::Paid)])
            ->withCount(['payments as pending_rounds' => fn ($q) => $q->where('status', EqubPaymentStatus::Pending)])
            ->withMax(['payments as last_payment_at' => fn ($q) => $q->where('status', EqubPaymentStatus::Paid)], 'payment_date')
            ->get();

        $rows = [];
        $groupPaid = 0.0;
        $groupExpected = 0.0;
        $groupDueSoFar = 0.0;
        $paidUpCount = 0;
        $behindCount = 0;
        $seatCount = 0;

        foreach ($memberships as $membership) {
            $paidRounds = (int) $membership->paid_rounds;
            $paidTotal = (float) ($membership->paid_total ?? 0);
            $expectedTotal = $contribution * $totalRounds;

            $dueRounds = $this->roundsDueSoFar($group, $membership->join_date, $frequency, $totalRounds);
            $dueAmount = $contribution * $dueRounds;

            $overdueRounds = max(0, $dueRounds - $paidRounds);
            $outstandingNow = max(0, $dueAmount - $paidTotal);
            $remainingTotal = max(0, $expectedTotal - $paidTotal);

            $status = $this->memberStatus($membership->status, $overdueRounds, $remainingTotal);

            $groupPaid += $paidTotal;
            $groupExpected += $expectedTotal;
            $groupDueSoFar += $dueAmount;

            if ($membership->status === EqubMembershipStatus::Active) {
                $overdueRounds > 0 ? $behindCount++ : $paidUpCount++;
            }

            $isSeat = $membership->isResponsibilitySeat();

            if ($isSeat) {
                $seatCount++;
            }

            $rows[] = [
                'membership_id' => $membership->id,
                'member_id' => $membership->member_id,
                'name' => $isSeat
                    ? $membership->displayName()
                    : ($membership->member?->full_name ?? $membership->member?->user?->name),
                'phone' => $isSeat
                    ? $membership->responsibility_phone
                    : $membership->member?->user?->phone,
                'profile_picture_url' => $isSeat ? null : $membership->member?->user?->profile_picture_url,
                'role' => $this->roleValue($membership->role),

                // --- My Responsibility People -------------------------
                // A seat is counted like any other member above; these
                // fields only say who is answerable for it, so the app can
                // show "paid by Bilal" and put the pay button in front of
                // the right person.
                'is_responsibility_seat' => $isSeat,
                'sponsor_member_id' => $membership->sponsor_member_id,
                'sponsor_name' => $isSeat
                    ? ($membership->sponsor?->full_name ?? $membership->sponsor?->user?->name)
                    : null,
                'relation' => $membership->responsibility_relation,
                'payer_member_id' => $membership->payerMemberId(),
                'joined_at' => $membership->join_date?->toIso8601String(),
                'rounds_total' => $totalRounds,
                'rounds_due' => $dueRounds,
                'rounds_paid' => $paidRounds,
                'rounds_overdue' => $overdueRounds,
                'rounds_pending' => (int) $membership->pending_rounds,
                'contribution_amount' => round($contribution, 2),
                'total_paid' => round($paidTotal, 2),
                'outstanding_now' => round($outstandingNow, 2),
                'remaining_total' => round($remainingTotal, 2),
                'progress' => $expectedTotal > 0 ? round(min(1, $paidTotal / $expectedTotal), 4) : 0,
                'last_payment_at' => $membership->last_payment_at
                    ? Carbon::parse($membership->last_payment_at)->toIso8601String()
                    : null,
                'next_due_date' => $this->nextDueDate($group, $membership->join_date, $frequency, $paidRounds, $totalRounds)?->toIso8601String(),
                'payment_status' => $status,
                'has_won' => (bool) $membership->has_won,
                'win_date' => $membership->win_date?->toIso8601String(),
                'membership_status' => $membership->status?->value,
                'is_eligible_for_draw' => $this->isEligibleForDraw($group, $membership, $paidRounds, $overdueRounds),
            ];
        }

        // Owner first, then the members who are behind, then everybody else.
        //
        // Within a bucket rows are keyed on who pays rather than on the row's
        // own name, so the people a sponsor is responsible for sit directly
        // under the sponsor instead of scattering alphabetically across the
        // list. Their own name is the last tiebreaker.
        $payerKey = fn (array $r): string => (string) ($r['is_responsibility_seat']
            ? ($r['sponsor_name'] ?? '')
            : ($r['name'] ?? ''));

        usort($rows, function (array $a, array $b) use ($payerKey): int {
            return [
                $a['role'] === 'owner' ? 0 : 1,
                $a['rounds_overdue'] > 0 ? 0 : 1,
                $payerKey($a),
                $a['is_responsibility_seat'] ? 1 : 0,
                $a['name'] ?? '',
            ] <=> [
                $b['role'] === 'owner' ? 0 : 1,
                $b['rounds_overdue'] > 0 ? 0 : 1,
                $payerKey($b),
                $b['is_responsibility_seat'] ? 1 : 0,
                $b['name'] ?? '',
            ];
        });

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'status' => $group->status?->value,
                'members_count' => $memberships->count(),
                'contribution_amount' => round($contribution, 2),
                'contribution_frequency_days' => $frequency,
                'rounds_total' => $totalRounds,
                'rounds_completed' => $group->draws()->count(),
                'pot_per_round' => round($group->potPerRound(), 2),
                'expected_total' => round($groupExpected, 2),
                'due_to_date' => round($groupDueSoFar, 2),
                'total_paid' => round($groupPaid, 2),
                'total_unpaid' => round(max(0, $groupDueSoFar - $groupPaid), 2),
                'remaining_total' => round(max(0, $groupExpected - $groupPaid), 2),
                'collection_rate' => $groupDueSoFar > 0 ? round(min(1, $groupPaid / $groupDueSoFar), 4) : 0,
                'progress' => $groupExpected > 0 ? round(min(1, $groupPaid / $groupExpected), 4) : 0,
                'members_paid_up' => $paidUpCount,
                'members_behind' => $behindCount,
                // Of members_count, how many are places held on someone
                // else's behalf. members_count already includes them; this is
                // the breakdown, not an addition to it.
                'responsibility_seats_count' => $seatCount,
                'currency' => 'ETB',
            ],
            'members' => $rows,
        ];
    }

    /** Just the totals — used by list screens and widgets. */
    public function summaryFor(EqubGroup $group): array
    {
        return $this->forGroup($group)['group'];
    }

    /** Memberships that owe money right now, for reminders. */
    public function membersBehind(EqubGroup $group): array
    {
        return array_values(array_filter(
            $this->forGroup($group)['members'],
            fn (array $row): bool => $row['rounds_overdue'] > 0
                && $row['membership_status'] === EqubMembershipStatus::Active->value
        ));
    }

    /**
     * How many contributions should have been made by today. Counts from the
     * group start date once the Equb is running, otherwise from the join date.
     */
    protected function roundsDueSoFar(EqubGroup $group, ?Carbon $joinDate, int $frequency, int $totalRounds): int
    {
        $anchor = $group->equb_start_date ?? $joinDate;

        if (! $anchor || $anchor->isFuture()) {
            return 0;
        }

        // The first contribution is due on day one.
        $elapsed = (int) floor($anchor->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $due = (int) floor($elapsed / $frequency) + 1;

        return max(0, min($due, $totalRounds));
    }

    protected function nextDueDate(EqubGroup $group, ?Carbon $joinDate, int $frequency, int $paidRounds, int $totalRounds): ?Carbon
    {
        if ($paidRounds >= $totalRounds) {
            return null;
        }

        $anchor = $group->equb_start_date ?? $joinDate;

        return $anchor?->copy()->startOfDay()->addDays($paidRounds * $frequency);
    }

    /** Works whether or not `role` has been cast to the enum yet. */
    protected function roleValue($role): string
    {
        if ($role instanceof \BackedEnum) {
            return (string) $role->value;
        }

        return (string) ($role ?? 'member');
    }

    protected function memberStatus(?EqubMembershipStatus $status, int $overdueRounds, float $remainingTotal): string
    {
        if ($status === EqubMembershipStatus::Completed || $remainingTotal <= 0) {
            return 'completed';
        }

        if ($status === EqubMembershipStatus::Cancelled) {
            return 'cancelled';
        }

        return match (true) {
            $overdueRounds >= 3 => 'overdue',
            $overdueRounds > 0 => 'behind',
            default => 'paid_up',
        };
    }

    protected function isEligibleForDraw(EqubGroup $group, $membership, int $paidRounds, int $overdueRounds): bool
    {
        if ($membership->status !== EqubMembershipStatus::Active || $membership->has_won) {
            return false;
        }

        if ($paidRounds < 1) {
            return false;
        }

        return ! ($group->draw_requires_up_to_date && $overdueRounds > 0);
    }
}

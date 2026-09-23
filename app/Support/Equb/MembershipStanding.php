<?php

namespace App\Support\Equb;

use Carbon\CarbonImmutable;

/**
 * Where one membership stands against the schedule it signed up to.
 *
 * Everything the Equb needs to judge a member — whether they owe anything,
 * whether they pay early, whether they have ever been late — is derived from
 * two facts and nothing else: the schedule implied by (join date, amount,
 * frequency), and the contributions that have actually settled against it.
 *
 * It is a value object on purpose. Nothing here queries, nothing here writes,
 * and two standings built from the same inputs are always identical — which
 * is what lets a draw be recomputed and checked long after it ran.
 */
final class MembershipStanding
{
    public const CURRENT = 'current';

    public const AHEAD = 'ahead';

    public const WARNED = 'warned';

    public const BLOCKED = 'blocked';

    public const SUSPENDED = 'suspended';

    public const NEVER_PAID = 'never_paid';

    /** No schedule could be derived — the group has no duration configured. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly int $membershipId,
        public readonly ?int $payerMemberId,
        public readonly string $displayName,

        // The schedule
        public readonly float $contribution,
        public readonly int $frequencyDays,
        public readonly int $totalRounds,
        public readonly int $roundsDue,
        public readonly ?CarbonImmutable $joinedAt,

        // What has actually been paid
        public readonly float $paidAmount,
        public readonly int $paidRounds,
        public readonly ?CarbonImmutable $lastPaidAt,

        // The gap, in both directions
        public readonly float $expectedToDate,
        public readonly float $arrears,
        public readonly float $advanceAmount,
        public readonly int $missedRounds,
        public readonly int $advanceRounds,
        public readonly int $daysOverdue,

        // Behaviour
        public readonly int $onTimeStreak,
        public readonly int $lateCount,
        public readonly bool $cleanRecord,
        public readonly int $roundsWaited,
        public readonly bool $hasWon,

        public readonly string $status,
    ) {}

    public function isInArrears(): bool
    {
        return $this->arrears > 0.009;
    }

    /** Behind far enough that the draw must not reach them. */
    public function isBlocked(): bool
    {
        return in_array($this->status, [self::BLOCKED, self::SUSPENDED, self::NEVER_PAID], true);
    }

    public function isAhead(): bool
    {
        return $this->advanceRounds > 0;
    }

    /** Translation key for the status badge. */
    public function label(): string
    {
        return __('filament.equb_standing.'.$this->status);
    }

    /** Filament badge colour. */
    public function color(): string
    {
        return match ($this->status) {
            self::AHEAD => 'success',
            self::CURRENT => 'primary',
            self::WARNED => 'warning',
            self::BLOCKED => 'danger',
            self::SUSPENDED, self::NEVER_PAID => 'danger',
            default => 'gray',
        };
    }

    /**
     * Which ageing band this sits in, given the report's boundaries.
     *
     * Returns the band's upper bound in days, or null for "older than the
     * last boundary" — the bucket that matters most and the one a report has
     * to be able to name.
     */
    public function ageingBucket(array $boundaries): ?int
    {
        foreach ($boundaries as $days) {
            if ($this->daysOverdue <= $days) {
                return $days;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'payer_member_id' => $this->payerMemberId,
            'name' => $this->displayName,
            'contribution' => $this->contribution,
            'frequency_days' => $this->frequencyDays,
            'total_rounds' => $this->totalRounds,
            'rounds_due' => $this->roundsDue,
            'paid_amount' => $this->paidAmount,
            'paid_rounds' => $this->paidRounds,
            'expected_to_date' => $this->expectedToDate,
            'arrears' => $this->arrears,
            'advance_amount' => $this->advanceAmount,
            'missed_rounds' => $this->missedRounds,
            'advance_rounds' => $this->advanceRounds,
            'days_overdue' => $this->daysOverdue,
            'on_time_streak' => $this->onTimeStreak,
            'late_count' => $this->lateCount,
            'clean_record' => $this->cleanRecord,
            'rounds_waited' => $this->roundsWaited,
            'has_won' => $this->hasWon,
            'status' => $this->status,
            'last_paid_at' => $this->lastPaidAt?->toDateTimeString(),
        ];
    }
}

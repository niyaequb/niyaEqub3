<?php

namespace App\Support\Equb;

/**
 * One membership's place in a draw: how much of the pool it holds, or why it
 * holds none.
 *
 * `weight` is a share of the pool, not a probability — odds are only knowable
 * once every entry is in and the total is known, which is why `odds` is
 * filled in afterwards by the engine rather than at construction.
 *
 * `reasons` is what makes this worth keeping as an object. A member asking
 * "why did I not win" deserves a better answer than "the computer chose
 * someone else", and an admin about to run a draw needs to see who is out of
 * it before pressing the button, not afterwards.
 */
final class DrawEntry
{
    /** @param  array<int, string>  $reasons */
    public function __construct(
        public readonly int $membershipId,
        public readonly ?int $payerMemberId,
        public readonly string $name,
        public readonly MembershipStanding $standing,
        public readonly bool $eligible,
        public float $weight,
        public array $reasons = [],
        public float $odds = 0.0,
        public float $rawWeight = 0.0,
        public bool $cappedByPayerShare = false,
    ) {}

    public function ineligible(): bool
    {
        return ! $this->eligible;
    }

    /**
     * Does this place hold its whole Group Equb out of the draw?
     *
     * Not the same question as "may this place win", and the difference
     * matters. A group wins together, so the group is held back when one of
     * its members would be collecting money they have stopped paying for, or
     * collecting a second payout — those are the cases where the circle loses.
     *
     * A member who is merely too new to win on their own account does not hold
     * the group back. They owe nothing yet, their obligation runs on exactly
     * the same terms as everybody else's, and blocking a whole family because
     * one of them joined last week punishes five people for nothing.
     */
    public function blocksGroup(): bool
    {
        return $this->ineligible() && (
            $this->standing->isInArrears()
            || $this->standing->isBlocked()
            || $this->standing->hasWon
        );
    }

    /** The single sentence to show beside a name. */
    public function reason(): ?string
    {
        return $this->reasons[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'payer_member_id' => $this->payerMemberId,
            'name' => $this->name,
            'eligible' => $this->eligible,
            'weight' => round($this->weight, 4),
            'raw_weight' => round($this->rawWeight, 4),
            'odds' => round($this->odds, 4),
            'capped' => $this->cappedByPayerShare,
            'status' => $this->standing->status,
            'missed_rounds' => $this->standing->missedRounds,
            'advance_rounds' => $this->standing->advanceRounds,
            'on_time_streak' => $this->standing->onTimeStreak,
            'arrears' => $this->standing->arrears,
            'reasons' => $this->reasons,
        ];
    }
}

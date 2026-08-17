<?php

namespace App\Models;

use App\Enums\EqubMembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class EqubMembership extends Model
{
    protected $fillable = ['equb_group_id', 'member_id', 'role', 'invited_by_member_id', 'cohort_id', 'contribution_amount', 'contribution_frequency_days', 'join_date', 'calculated_end_date', 'draw_position', 'has_won', 'win_date', 'status', 'last_overdue_notified_at', 'sponsor_member_id', 'responsibility_name', 'responsibility_phone', 'responsibility_relation', 'responsibility_note'];

    protected function casts(): array
    {
        return [
            'contribution_amount' => 'decimal:2',
            'join_date' => 'datetime',
            'calculated_end_date' => 'datetime',
            'win_date' => 'datetime',
            'has_won' => 'boolean',
            'status' => EqubMembershipStatus::class,
            'role' => \App\Enums\EqubMembershipRole::class,
            'last_overdue_notified_at' => 'datetime',
        ];
    }

    public function equbGroup(): BelongsTo
    {
        return $this->belongsTo(EqubGroup::class, 'equb_group_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The member answerable for a "My Responsibility People" seat.
     *
     * Set only on responsibility seats — a normal membership answers for
     * itself, and this stays null there rather than pointing back at the same
     * member twice.
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sponsor_member_id');
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EqubPayment::class, 'equb_membership_id');
    }

    public function winsAsWinner(): HasMany
    {
        return $this->hasMany(EqubDraw::class, 'winner_membership_id');
    }

    /** Who invited this member into a group Equb, if anyone. */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'invited_by_member_id');
    }

    /** Rounds this membership was part of the winning group. */
    public function drawWins(): HasMany
    {
        return $this->hasMany(EqubDrawWinner::class, 'equb_membership_id');
    }

    public function isOwner(): bool
    {
        return $this->role === \App\Enums\EqubMembershipRole::Owner;
    }

    public function isEligibleForDraw(): bool
    {
        return $this->status === EqubMembershipStatus::Active && !$this->has_won;
    }

    // ------------------------------------------------------------------
    // My Responsibility People
    //
    // A seat held for someone with no Niya account: a child, an elderly
    // parent, a neighbour without a phone. It is a full membership — it pays
    // in every round and it can be drawn — but there is nobody behind it to
    // pay, to notify or to hand a payout to. The sponsor does all three.
    //
    // member_id is the discriminator rather than a separate flag column,
    // because the two can then never disagree: a seat with no account has no
    // member_id, and that is the same fact stated once.
    // ------------------------------------------------------------------

    public function isResponsibilitySeat(): bool
    {
        return $this->member_id === null;
    }

    /** Seats held on someone else's behalf. */
    public function scopeResponsibilitySeats($query)
    {
        return $query->whereNull('member_id');
    }

    /** Memberships belonging to a real Niya account. */
    public function scopeRealMembers($query)
    {
        return $query->whereNotNull('member_id');
    }

    public function scopeSponsoredBy($query, int $memberId)
    {
        return $query->where('sponsor_member_id', $memberId);
    }

    /**
     * Who owes the money on this seat, and who receives its payout.
     *
     * Every financial question about a membership goes through here rather
     * than reading member_id directly, so a responsibility seat cannot end up
     * with an obligation nobody is attached to.
     */
    public function payerMemberId(): ?int
    {
        return $this->member_id ?? $this->sponsor_member_id;
    }

    public function payerMember(): ?Member
    {
        return $this->member ?? $this->sponsor;
    }

    /** The account that gets the push notification or SMS for this seat. */
    public function payerUser(): ?User
    {
        return $this->payerMember()?->user;
    }

    /**
     * The name to show wherever a member name would appear.
     *
     * A responsibility seat shows the name the sponsor typed in, never the
     * sponsor's own — the point of the feature is that the circle can see who
     * each seat is for.
     */
    public function displayName(): string
    {
        if ($this->isResponsibilitySeat()) {
            return trim((string) $this->responsibility_name) ?: 'Responsibility seat';
        }

        return (string) ($this->member?->full_name
            ?? $this->member?->user?->name
            ?? 'Member');
    }

    /** True when [$memberId] is the person answerable for this seat. */
    public function isPayableBy(?int $memberId): bool
    {
        return $memberId !== null && (int) $this->payerMemberId() === (int) $memberId;
    }

    // ------------------------------------------------------------------
    // Exit rules
    //
    // An Equb only works because the person who collects the pot in round 1
    // keeps paying until round N. If they can walk away the moment the money
    // lands, everyone still waiting for a turn has funded a payout they will
    // never get back — the circle does not just lose a member, it loses the
    // money.
    //
    // Every exit path funnels through exitBlockReason() so the rule lives in
    // exactly one place: a member leaving voluntarily, an owner removing
    // someone, and (with an explicit override) support closing an account.
    // ------------------------------------------------------------------

    /**
     * Has this membership already collected a payout?
     *
     * Checks three sources rather than trusting the flag alone. `has_won` is
     * what the draw sets, but it is a denormalised boolean on a mutable row;
     * the draw tables are the ledger. If any of them says this membership took
     * money, it took money.
     */
    public function hasReceivedPayout(): bool
    {
        if ($this->has_won) {
            return true;
        }

        // Group Equb: one round can produce several winners.
        if ($this->drawWins()->exists()) {
            return true;
        }

        // Platform Equb: single winner recorded on the draw itself.
        return $this->winsAsWinner()->exists();
    }

    /** Total actually awarded to this membership across every round. */
    public function totalWonAmount(): float
    {
        return (float) $this->drawWins()->sum('amount_won');
    }

    /**
     * Why this membership cannot leave the Equb, or null if it may.
     *
     * Returns a sentence meant to be shown to the person, not an error code —
     * someone being told they cannot leave deserves to know what they still
     * owe and why.
     */
    public function exitBlockReason(): ?string
    {
        if ($this->hasReceivedPayout()) {
            $outstanding = $this->remaining_amount;

            if ($outstanding > 0) {
                return 'You have already received your Equb payout, so this Equb cannot be left. '
                    .number_format($outstanding, 2).' ETB is still owed to the other members.';
            }

            // Paid up in full. Still not a "leave" — the membership is finished,
            // and deleting it would erase the draw history that proves the
            // payout was earned and settled.
            return 'You have already received your Equb payout. This Equb is complete and stays on your record.';
        }

        if ($this->payments()->where('status', \App\Enums\EqubPaymentStatus::Paid)->exists()) {
            return 'You have already made contributions to this Equb. Contact support to arrange a refund before leaving.';
        }

        return null;
    }

    public function canExit(): bool
    {
        return $this->exitBlockReason() === null;
    }

    /** Won, and paid every round owed. Nothing outstanding. */
    public function isSettled(): bool
    {
        return $this->hasReceivedPayout() && $this->remaining_amount <= 0;
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()->where('status', \App\Enums\EqubPaymentStatus::Paid)->sum('amount');
    }

    public function getExpectedTotalAmountAttribute(): float
    {
        $group = $this->equbGroup;
        if (!$group) {
            return 0.0;
        }

        $rounds = 0;
        if ($group->duration_type === \App\Enums\EqubDurationType::PerMember) {
            // For PerMember, we expect as many rounds as there are members (max or current).
            // Using max_members as the primary target, fallback to current_members_count.
            $rounds = $group->max_members ?? ($group->current_members_count ?? 0);
        } elseif ($group->duration_type === \App\Enums\EqubDurationType::Fixed) {
            // there is duration value and unit, we can calculate rounds based on the contribution frequency and also there is a contribution freq
            // based on this
            $rounds = (int) $group->duration_value ?? 0;
        }

        return (float) (($this->contribution_amount ?? 0) * $rounds);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->expected_total_amount - $this->total_paid);
    }

    /**
     * Generate the expected payment schedule based on join date, rounds, and frequency.
     * Matches against actual paid contributions to determine status.
     */
    public function getPaymentScheduleAttribute(): array
    {
        $group = $this->equbGroup;
        if (!$group) {
            return [];
        }

        $rounds = 0;
        // if ($group->duration_type === \App\Enums\EqubDurationType::PerMember) {
        //     $rounds = $group->max_members ?? $group->current_members_count ?? 0;
        // } elseif ($group->duration_type === \App\Enums\EqubDurationType::Fixed) {
        $rounds = (int) $group->duration_value ?? 0;
        // }

        if ($rounds <= 0) {
            return [];
        }

        $paidCount = $this->payments()->where('status', \App\Enums\EqubPaymentStatus::Paid)->count();
        $schedule = [];

        for ($i = 0; $i < $rounds; $i++) {
            $expectedDate = $this->join_date->copy();

            // Advance by intervals
            if ($i > 0) {
                $expectedDate->addDays($i * $this->contribution_frequency_days);
            }

            $schedule[] = [
                'round' => $i + 1,
                'expected_date' => $expectedDate->toIso8601String(),
                'amount' => (float) $this->contribution_amount,
                'status' => $i < $paidCount ? 'paid' : 'pending',
            ];
        }

        return $schedule;
    }

    /**
     * Get the next draw date (first pending round in the schedule).
     */
    /**
     * Get the next draw date (first pending round in the schedule).
     */
    public function getNextDrawDateAttribute(): ?\Carbon\Carbon
    {
        $schedule = $this->payment_schedule;

        if (empty($schedule)) {
            return null;
        }

        $today = now()->startOfDay();
        $nearestDate = null;
        $smallestDiff = null;

        $todayDraw = $this->equbGroup->draws()->whereDate('draw_date', $today)->first();

        foreach ($schedule as $payment) {
            $expectedDate = \Carbon\Carbon::parse($payment['expected_date'])->startOfDay();

            // Check if this payment date is today
            if ($expectedDate->isSameDay($today)) {
                // If there's a draw today, skip this date (draw already done)
                if ($todayDraw) {
                    continue;
                } else {
                    // No draw today, so today is the next draw date
                    return $today;
                }
            }

            // Calculate absolute difference for all dates
            $diffInDays = abs($expectedDate->diffInDays($today));

            if ($smallestDiff === null || $diffInDays < $smallestDiff) {
                $smallestDiff = $diffInDays;
                $nearestDate = $expectedDate;
            }
        }

        return $nearestDate;
    }
}

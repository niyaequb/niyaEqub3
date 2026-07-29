<?php

namespace App\Models;

use App\Enums\EqubMembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class EqubMembership extends Model
{
    protected $fillable = ['equb_group_id', 'member_id', 'role', 'invited_by_member_id', 'cohort_id', 'contribution_amount', 'contribution_frequency_days', 'join_date', 'calculated_end_date', 'draw_position', 'has_won', 'win_date', 'status', 'last_overdue_notified_at'];

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

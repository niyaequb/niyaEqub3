<?php

namespace App\Models;

use App\Enums\EqubDrawType;
use App\Enums\EqubGroupModerationStatus;
use App\Enums\EqubGroupStatus;
use App\Enums\EqubGroupVisibility;
use App\Enums\EqubMembershipStatus;
use App\Enums\WinnerSelectionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EqubGroup extends Model
{
    protected $fillable = [
        'equb_package_id',
        'owner_member_id',
        'name',
        'visibility',
        'moderation_status',
        'invite_code',
        'description',
        'allow_member_invites',
        'fixed_contribution_amount',
        'contribution_frequency_days',
        'duration_type',
        'duration_value',
        'duration_unit',
        'terms_content',
        'registration_open_at',
        'registration_close_at',
        'equb_start_date',
        'equb_end_date',
        'max_members',
        'status',
        'is_locked',
        'current_members_count',
        'draw_type',
        'winner_selection_mode',
        'winners_per_draw',
        'min_winners_per_draw',
        'max_winners_per_draw',
        'winner_split_plan',
        'split_plan_cursor',
        'total_amount_per_draw',
        'payout_per_winner',
        'draw_requires_up_to_date',
        'approved_at',
        'approved_by_admin_id',
        'rejection_reason',
    ];

    protected $attributes = [
        'duration_type' => 'fixed',
    ];

    protected function casts(): array
    {
        return [
            'registration_open_at' => 'datetime',
            'registration_close_at' => 'datetime',
            'equb_start_date' => 'datetime',
            'equb_end_date' => 'datetime',
            'approved_at' => 'datetime',
            'duration_value' => 'integer',
            'status' => EqubGroupStatus::class,
            'is_locked' => 'boolean',
            'draw_type' => EqubDrawType::class,
            'duration_type' => \App\Enums\EqubDurationType::class,
            'duration_unit' => \App\Enums\EqubDurationUnit::class,
            'fixed_contribution_amount' => 'decimal:2',
            'total_amount_per_draw' => 'decimal:2',
            // --- Group Equb ---
            'visibility' => EqubGroupVisibility::class,
            'moderation_status' => EqubGroupModerationStatus::class,
            'winner_selection_mode' => WinnerSelectionMode::class,
            'winner_split_plan' => 'array',
            'split_plan_cursor' => 'integer',
            'winners_per_draw' => 'integer',
            'min_winners_per_draw' => 'integer',
            'max_winners_per_draw' => 'integer',
            'payout_per_winner' => 'decimal:2',
            'draw_requires_up_to_date' => 'boolean',
            'allow_member_invites' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EqubGroup $group): void {
            if ($group->owner_member_id && empty($group->invite_code)) {
                $group->invite_code = static::generateInviteCode();
            }
        });
    }

    public static function generateInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('invite_code', $code)->exists());

        return $code;
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function package(): BelongsTo
    {
        return $this->belongsTo(EqubPackage::class, 'equb_package_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_member_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EqubMembership::class, 'equb_group_id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', EqubMembershipStatus::Active);
    }

    public function draws(): HasMany
    {
        return $this->hasMany(EqubDraw::class, 'equb_group_id');
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class, 'equb_group_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EqubGroupInvitation::class, 'equb_group_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** Platform groups that everyone may browse and join. */
    public function scopePublic($query)
    {
        return $query->where('visibility', EqubGroupVisibility::Public);
    }

    /** Member-created groups. */
    public function scopePrivate($query)
    {
        return $query->where('visibility', EqubGroupVisibility::Private);
    }

    public function scopeOwnedBy($query, int $memberId)
    {
        return $query->where('owner_member_id', $memberId);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public function isMemberCreated(): bool
    {
        return $this->owner_member_id !== null;
    }

    public function isOwnedBy(?int $memberId): bool
    {
        return $memberId !== null && (int) $this->owner_member_id === (int) $memberId;
    }

    public function isApproved(): bool
    {
        return $this->moderation_status === EqubGroupModerationStatus::Approved;
    }

    public function isRegistrationOpen(): bool
    {
        if ($this->status !== EqubGroupStatus::Registration) {
            return false;
        }
        if ($this->registration_open_at && $this->registration_open_at->isFuture()) {
            return false;
        }
        if ($this->registration_close_at && $this->registration_close_at->isPast()) {
            return false;
        }
        if ($this->max_members && $this->current_members_count >= $this->max_members) {
            return false;
        }

        return true;
    }

    /**
     * Total number of contribution rounds (= number of draw rounds when one
     * member wins per round). Mirrors EqubMembership::expected_total_amount.
     */
    public function totalRounds(): int
    {
        if ($this->duration_type === \App\Enums\EqubDurationType::PerMember) {
            return (int) ($this->max_members ?? $this->current_members_count ?? 0);
        }

        return (int) ($this->duration_value ?? 0);
    }

    /** The pot handed out in a single round. */
    public function potPerRound(): float
    {
        if ($this->total_amount_per_draw !== null && (float) $this->total_amount_per_draw > 0) {
            return (float) $this->total_amount_per_draw;
        }

        return (float) $this->fixed_contribution_amount * (int) $this->current_members_count;
    }

    /** How many winners the next round should produce. */
    public function winnersForNextRound(): int
    {
        if ($this->winner_selection_mode === WinnerSelectionMode::FixedSize) {
            return max(1, (int) ($this->winners_per_draw ?? 1));
        }

        if ($this->winner_selection_mode === WinnerSelectionMode::Single) {
            return 1;
        }

        $plan = is_array($this->winner_split_plan) ? $this->winner_split_plan : [];
        $planned = $plan[$this->split_plan_cursor] ?? null;

        return max(1, (int) ($planned ?? $this->winners_per_draw ?? $this->min_winners_per_draw ?? 1));
    }

    /** 1-based number of the round that is about to run. */
    public function nextRoundNumber(): int
    {
        return (int) $this->draws()->count() + 1;
    }
}

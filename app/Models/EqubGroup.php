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
        'parent_equb_group_id',
        'has_won_round',
        'won_round_at',
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
            'has_won_round' => 'boolean',
            'won_round_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EqubGroup $group): void {
            if ($group->owner_member_id && empty($group->invite_code)) {
                $group->invite_code = static::generateInviteCode();
            }

            // Member-created groups are live the moment they exist. There is
            // no approval queue any more: the parent Equb is already vetted by
            // an admin and the group inherits its amounts and schedule, so
            // there is nothing left for a human to sign off.
            //
            // This lives on the model rather than in the service so that every
            // creation path lands the same way — the mobile API, the Filament
            // "Create Group Equb" button, seeders and tinker — and so that the
            // database column default can never quietly reintroduce 'pending'.
            if ($group->owner_member_id
                && $group->moderation_status !== EqubGroupModerationStatus::Rejected) {
                $group->moderation_status = EqubGroupModerationStatus::Approved;
                $group->approved_at = $group->approved_at ?? now();
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

    /** The running platform Equb this Group Equb takes part in. */
    public function parentGroup(): BelongsTo
    {
        return $this->belongsTo(EqubGroup::class, 'parent_equb_group_id');
    }

    /** Member-created Group Equbs competing inside this platform Equb. */
    public function subGroups(): HasMany
    {
        return $this->hasMany(EqubGroup::class, 'parent_equb_group_id');
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

    /**
     * What one person contributes per round. Always inherited from the parent
     * Equb's package so a Group Equb never carries a hand-typed amount.
     */
    public function contributionPerPerson(): float
    {
        $source = $this->parentGroup ?? $this;

        if ((float) $source->fixed_contribution_amount > 0) {
            return (float) $source->fixed_contribution_amount;
        }

        return (float) ($source->package?->fixed_contribution_amount ?? 0);
    }

    /**
     * The terms a member agrees to before creating or joining.
     *
     * Always the platform Equb's own terms_content, set by an admin on the Equb
     * Group. A Group Equb never carries its own copy, so editing the Equb's
     * terms updates every group inside it.
     */
    public function termsContent(): ?string
    {
        $source = $this->parentGroup ?? $this;

        $terms = trim((string) ($source->terms_content ?? ''));

        return $terms !== '' ? $terms : null;
    }

    /** Everyone's contribution for a single round: per person x head-count. */
    public function roundTotal(): float
    {
        return $this->contributionPerPerson() * max(0, (int) $this->current_members_count);
    }

    /** Group Equbs on this parent that have not won a round yet. */
    public function eligibleSubGroups()
    {
        return $this->subGroups()
            ->whereNotNull('owner_member_id')
            ->where('moderation_status', EqubGroupModerationStatus::Approved)
            ->whereNotIn('status', [EqubGroupStatus::Cancelled, EqubGroupStatus::Completed])
            ->where('has_won_round', false);
    }
}

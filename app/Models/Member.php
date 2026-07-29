<?php

namespace App\Models;

use App\Enums\RegisteredVia;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'full_name',
        'gender',
        'date_of_birth',
        'address',
        'city',
        'agent_id',
        'registered_via',
        'referral_code_used',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registered_via' => RegisteredVia::class,
            'registered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member): void {
            if ($member->agent_id && $member->registered_via === RegisteredVia::Direct) {
                $agent = $member->agent;
                $member->registered_via = RegisteredVia::Agent;
                $member->referral_code_used = $agent?->referral_code;
            }
        });

        static::deleting(function (Member $member): void {
            // Delete associated user when member is deleted
            $member->user()?->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function equbMemberships(): HasMany
    {
        return $this->hasMany(EqubMembership::class);
    }

    /** Group Equbs this member created. */
    public function ownedEqubGroups(): HasMany
    {
        return $this->hasMany(EqubGroup::class, 'owner_member_id');
    }

    /** Invitations to join someone else's group Equb. */
    public function equbGroupInvitations(): HasMany
    {
        return $this->hasMany(EqubGroupInvitation::class, 'member_id');
    }
}

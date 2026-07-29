<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Agent extends Model
{
    /** @use HasFactory<\Database\Factories\AgentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'referral_code',
        'commission_rule_id',
        'is_active',
        'bank_name',
        'account_number',
        'account_holder_name',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Agent $agent): void {
            // Delete associated user when agent is deleted
            $agent->user()?->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AgentPayout::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Member::class, 'agent_id', 'member_id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(AgentPaymentRequest::class, 'agent_id');
    }
}

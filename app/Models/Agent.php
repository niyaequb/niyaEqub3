<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

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

    /**
     * Fully remove an agent: unlink their members (members are kept),
     * remove commissions, payouts and payment requests, then the agent
     * row and its login user. Safe to call on orphaned agents too.
     */
    public function purge(): bool
    {
        return (bool) DB::transaction(fn () => $this->delete());
    }

    /** Remove everything that hangs off this agent (members are only unlinked). */
    public function cleanUpRelations(): void
    {
        $agentId = $this->getKey();

        DB::table('members')
            ->where('agent_id', $agentId)
            ->update(['agent_id' => null]);

        $payoutIds = DB::table('agent_payouts')->where('agent_id', $agentId)->pluck('id');
        $commissionIds = DB::table('agent_commissions')->where('agent_id', $agentId)->pluck('id');

        if ($payoutIds->isNotEmpty()) {
            DB::table('agent_payout_items')->whereIn('agent_payout_id', $payoutIds)->delete();
        }
        if ($commissionIds->isNotEmpty()) {
            DB::table('agent_payout_items')->whereIn('agent_commission_id', $commissionIds)->delete();
        }

        DB::table('agent_payouts')->where('agent_id', $agentId)->delete();
        DB::table('agent_commissions')->where('agent_id', $agentId)->delete();
        DB::table('agent_payment_requests')->where('agent_id', $agentId)->delete();
    }

    protected static function booted(): void
    {
        // Before the agent row goes: clear everything linked to it.
        static::deleting(function (Agent $agent): void {
            $agent->cleanUpRelations();
        });

        // After the agent row is gone: remove its login so the phone
        // number is free to be used for a new agent.
        static::deleted(function (Agent $agent): void {
            if (! $agent->user_id) {
                return;
            }

            $user = User::find($agent->user_id);

            if ($user && $user->type === 'agent' && ! $user->hasRole('Super Admin')) {
                $user->delete();
            }
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

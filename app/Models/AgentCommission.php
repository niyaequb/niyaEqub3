<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class AgentCommission extends Model
{
    /** @use HasFactory<\Database\Factories\AgentCommissionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_id',
        'member_id',
        'commission_rule_id',
        'source',
        'reference_id',
        'base_amount',
        'commission_amount',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => CommissionTrigger::class,
            'status' => CommissionStatus::class,
            'base_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $commission): void {
            $immutable = [
                'agent_id',
                'member_id',
                'commission_rule_id',
                'source',
                'reference_id',
                'base_amount',
                'commission_amount',
                'created_at',
            ];

            if ($commission->isDirty($immutable)) {
                throw new RuntimeException('Agent commission ledger entries are immutable.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Agent commission ledger entries are immutable.');
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reference_id');
    }

    public function payoutItems(): HasMany
    {
        return $this->hasMany(AgentPayoutItem::class);
    }
}

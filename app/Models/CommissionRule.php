<?php

namespace App\Models;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRule extends Model
{
    /** @use HasFactory<\Database\Factories\CommissionRuleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'trigger',
        'commission_type',
        'commission_value',
        'agent_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => CommissionTrigger::class,
            'commission_type' => CommissionType::class,
            'commission_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class);
    }
}

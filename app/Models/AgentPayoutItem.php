<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPayoutItem extends Model
{
    /** @use HasFactory<\Database\Factories\AgentPayoutItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_payout_id',
        'agent_commission_id',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AgentPayout::class, 'agent_payout_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(AgentCommission::class, 'agent_commission_id');
    }
}

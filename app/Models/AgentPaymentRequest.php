<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPaymentRequest extends Model
{
    protected $fillable = [
        'agent_id',
        'amount',
        'bank_name',
        'account_number',
        'account_holder_name',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'status' => PaymentStatus::class,
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }
}

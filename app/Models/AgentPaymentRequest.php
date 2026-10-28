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
        'reference',
        'note',
        'admin_note',
        'transaction_reference',
        'processed_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => PaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // A reference the agent and the admin can both quote, e.g. WD-2610-00042.
        static::created(function (AgentPaymentRequest $request): void {
            if (blank($request->reference)) {
                try {
                    $request->forceFill([
                        'reference' => 'WD-'.now()->format('ym').'-'.str_pad((string) $request->id, 5, '0', STR_PAD_LEFT),
                    ])->saveQuietly();
                } catch (\Throwable) {
                    // Column not migrated yet; the request itself is still saved.
                }
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}

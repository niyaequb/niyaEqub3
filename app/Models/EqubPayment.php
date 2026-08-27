<?php

namespace App\Models;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Services\CommissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EqubPayment extends Model
{
    protected $fillable = [
        'equb_membership_id',
        'amount',
        'payment_date',
        'payment_method',
        'status',
        'reference',
        // Shared by every contribution settled in one gateway transaction.
        // See the add_batch_reference migration.
        'batch_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'payment_method' => EqubPaymentMethod::class,
            'status' => EqubPaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EqubPayment $payment): void {
            // The reference is the merchant order id the bank carries, so it
            // has to exist before the order is signed. Offline and manual rows
            // have no bank transaction behind them and stay unreferenced.
            //
            // Asked of the method rather than of a named bank, so a new bank
            // gets a reference without this line being edited.
            if ($payment->payment_method?->isGateway() && empty($payment->reference)) {
                $payment->reference = 'EQUB-'.strtoupper(Str::random(12));
            }
        });

        static::created(function (EqubPayment $payment): void {
            if ($payment->status === EqubPaymentStatus::Paid) {
                app(CommissionService::class)->recordEqubPaymentCommission($payment);
            }
        });

        static::updated(function (EqubPayment $payment): void {
            if ($payment->wasChanged('status') && $payment->status === EqubPaymentStatus::Paid) {
                app(CommissionService::class)->recordEqubPaymentCommission($payment);
            }
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(EqubMembership::class, 'equb_membership_id');
    }

    /**
     * The other contributions settled by the same gateway transaction.
     *
     * Empty for an ordinary single payment. Populated when a member paid for
     * their own place and the places they hold for other people in one go.
     */
    public function batchSiblings()
    {
        if (blank($this->batch_reference)) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->where('batch_reference', $this->batch_reference)
            ->whereKeyNot($this->getKey());
    }

    public function isPending(): bool
    {
        return $this->status === EqubPaymentStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status === EqubPaymentStatus::Paid;
    }

    public function markAsPaid(): void
    {
        $this->update(['status' => EqubPaymentStatus::Paid]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => EqubPaymentStatus::Failed]);
    }
}

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
            if ($payment->payment_method === EqubPaymentMethod::Chapa && empty($payment->reference)) {
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

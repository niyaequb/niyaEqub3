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

        // What the bank says about the transaction that settled this row.
        // Written only by markAsPaid(), from a verified gateway response —
        // never from a request. See the add_bank_settlement migration.
        'bank_transaction_id',
        'bank_reference',
        'bank_paid_at',
        'bank_amount',
        'bank_payer_name',
        'bank_payer_phone',
        'bank_payer_account',
        'bank_receipt_url',
        'bank_payload',

        // Reconciliation. Separate from `status` on purpose: status says the
        // money was credited, these say somebody checked it against the bank.
        // A row can be paid for months without either being true.
        'reconciled_at',
        'reconciled_by',
        'reconciled_via',
        'reconcile_note',
        'reconcile_flag',
    ];

    /**
     * Normalised settlement keys, and the columns they land in.
     *
     * The keys are what every gateway returns from extractSettlement(), so
     * nothing outside a gateway has to know that Dashen spell it `trxnID` and
     * the next bank spells it something else.
     */
    protected const SETTLEMENT_COLUMNS = [
        'transaction_id' => 'bank_transaction_id',
        'bank_reference' => 'bank_reference',
        'paid_at' => 'bank_paid_at',
        'amount' => 'bank_amount',
        'payer_name' => 'bank_payer_name',
        'payer_phone' => 'bank_payer_phone',
        'payer_account' => 'bank_payer_account',
        'receipt_url' => 'bank_receipt_url',
        'payload' => 'bank_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'payment_method' => EqubPaymentMethod::class,
            'status' => EqubPaymentStatus::class,
            'bank_paid_at' => 'datetime',
            'bank_amount' => 'decimal:2',
            'bank_payload' => 'array',
            'reconciled_at' => 'datetime',
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

    /**
     * Statement lines the bank matched to this contribution.
     *
     * Normally none or one. Two is a finding, not a shape to design around:
     * either the member was charged twice or two credits were matched to the
     * same row, and both need somebody to look. `whereDoesntHave` on this
     * relation is how the reconciliation queues find contributions the bank
     * has no record of.
     */
    public function statementLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'equb_payment_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function isPending(): bool
    {
        return $this->status === EqubPaymentStatus::Pending;
    }

    public function isPaid(): bool
    {
        return $this->status === EqubPaymentStatus::Paid;
    }

    /** Checked against the bank, by a rule or by a person. */
    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }

    /**
     * Credited without the bank ever confirming it.
     *
     * Not the same as unreconciled. This one is about evidence: `paid` with no
     * bank_transaction_id means somebody vouched for the money rather than the
     * bank confirming it, and that distinction is what an auditor is looking
     * for when they ask how a figure is supported.
     */
    public function isUnverified(): bool
    {
        return $this->isPaid() && blank($this->bank_transaction_id);
    }

    /**
     * How strongly this contribution is supported, worst first.
     *
     * Used wherever a payment is shown next to money, so the difference
     * between "the bank says so" and "an operator said so" is never left to
     * be inferred from an empty column.
     */
    public function evidenceLevel(): string
    {
        return match (true) {
            filled($this->bank_transaction_id) && $this->isReconciled() => 'confirmed',
            filled($this->bank_transaction_id) => 'bank_confirmed',
            $this->isReconciled() => 'vouched',
            default => 'unsupported',
        };
    }

    /**
     * Credit this contribution, and record what the bank said while doing it.
     *
     * The settlement array is optional because not every route into this has
     * one — an operator marking a row paid by hand has only their own eyes on
     * the merchant portal, and that is still a legitimate way for money to be
     * confirmed. A row with a status and no bank data is "someone vouched for
     * this"; a row with both is "the bank said so, and here is the receipt".
     * The difference is visible in the admin table, which is the point.
     *
     * Empty values are skipped rather than written as null, so re-settling a
     * row from a thinner response cannot erase details an earlier, richer one
     * already recorded.
     *
     * @param  array<string, mixed>  $settlement  From PaymentGateway::extractSettlement()
     */
    public function markAsPaid(array $settlement = []): void
    {
        $attributes = ['status' => EqubPaymentStatus::Paid];

        foreach (self::SETTLEMENT_COLUMNS as $key => $column) {
            $value = $settlement[$key] ?? null;

            if ($value !== null && $value !== '' && $value !== []) {
                $attributes[$column] = $value;
            }
        }

        $this->update($attributes);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => EqubPaymentStatus::Failed]);
    }
}

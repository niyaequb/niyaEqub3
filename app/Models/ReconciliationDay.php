<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's books, as they stood when somebody checked them.
 *
 * The figures here are a snapshot, not a view. Recomputing them will often
 * give a different answer — a late settlement lands, a statement is
 * re-imported, an amount is corrected — and that is precisely why the snapshot
 * exists. "The 3rd balanced when I signed it" and "the 3rd balances now" are
 * different claims, and a system that silently replaces the first with the
 * second cannot be audited.
 */
class ReconciliationDay extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_VARIANCE = 'variance';

    public const STATUS_SIGNED_OFF = 'signed_off';

    protected $fillable = [
        'business_date',
        'gateway',
        'our_count',
        'our_amount',
        'unverified_count',
        'unverified_amount',
        'bank_count',
        'bank_amount',
        'matched_count',
        'matched_amount',
        'unmatched_bank_count',
        'unmatched_bank_amount',
        'unmatched_ours_count',
        'unmatched_ours_amount',
        'variance',
        'status',
        'statement_loaded',
        'note',
        'signed_off_by',
        'signed_off_at',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'our_amount' => 'decimal:2',
            'unverified_amount' => 'decimal:2',
            'bank_amount' => 'decimal:2',
            'matched_amount' => 'decimal:2',
            'unmatched_bank_amount' => 'decimal:2',
            'unmatched_ours_amount' => 'decimal:2',
            'variance' => 'decimal:2',
            'statement_loaded' => 'boolean',
            'signed_off_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function isSignedOff(): bool
    {
        return $this->status === self::STATUS_SIGNED_OFF;
    }

    public function balances(): bool
    {
        return abs((float) $this->variance) < 0.01;
    }

    /**
     * Can this day be signed off?
     *
     * A day with no statement loaded has nothing to reconcile against — the
     * two sides would be our own figures compared with themselves, which
     * always balances and means nothing. Signing that off would be worse than
     * leaving it open, because it would look checked.
     */
    public function canSignOff(): bool
    {
        return ! $this->isSignedOff() && $this->statement_loaded;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SIGNED_OFF => 'success',
            self::STATUS_BALANCED => 'info',
            self::STATUS_VARIANCE => 'danger',
            default => 'warning',
        };
    }

    public function statusLabel(): string
    {
        return __('filament.reconciliation.day_'.$this->status);
    }

    /**
     * Does the stored snapshot still agree with what the data says now?
     *
     * Called with freshly computed figures. A day that was signed off as
     * balanced and no longer balances is not an error to correct silently —
     * it is the single most important thing this module can tell anyone.
     */
    public function hasDriftedFrom(array $fresh): bool
    {
        if (! $this->isSignedOff()) {
            return false;
        }

        return abs((float) $this->our_amount - (float) ($fresh['our_amount'] ?? 0)) >= 0.01
            || abs((float) $this->bank_amount - (float) ($fresh['bank_amount'] ?? 0)) >= 0.01;
    }
}

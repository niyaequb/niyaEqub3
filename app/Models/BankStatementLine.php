<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credit on the bank's statement.
 *
 * A line is the bank's account of what happened. A payment is ours. The point
 * of this table is that the two are kept apart and joined explicitly, with the
 * reason for the join recorded — rather than our records being quietly
 * overwritten with the bank's and the disagreement disappearing.
 */
class BankStatementLine extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_MATCHED = 'matched';

    /** Matched, but the two sides disagree about the amount. */
    public const STATUS_MISMATCH = 'amount_mismatch';

    /** The bank's transaction id already sits on a different payment. */
    public const STATUS_DUPLICATE = 'duplicate';

    /** Deliberately set aside: a fee, a reversal, a transfer of our own. */
    public const STATUS_IGNORED = 'ignored';

    /**
     * How a match was made, strongest first.
     *
     * The order is the confidence order, and it is why the rule is stored:
     * a match on the bank's own transaction id is a fact, and one on amount
     * and date is an inference. Both end up as `matched`, and an auditor must
     * be able to tell them apart afterwards.
     */
    public const RULE_TRANSACTION_ID = 'transaction_id';

    public const RULE_BANK_REFERENCE = 'bank_reference';

    public const RULE_MERCHANT_REFERENCE = 'merchant_reference';

    public const RULE_AMOUNT_DATE_PAYER = 'amount_date_payer';

    public const RULE_MANUAL = 'manual';

    protected $fillable = [
        'bank_statement_id',
        'gateway',
        'external_ref',
        'bank_reference',
        'merchant_reference',
        'posted_at',
        'amount',
        'direction',
        'currency',
        'payer_name',
        'payer_account',
        'payer_phone',
        'narrative',
        'raw',
        'match_status',
        'equb_payment_id',
        'match_rule',
        'match_confidence',
        'match_note',
        'matched_at',
        'matched_by',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'matched_at' => 'datetime',
            'amount' => 'decimal:2',
            'raw' => 'array',
            'match_confidence' => 'integer',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(EqubPayment::class, 'equb_payment_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('direction', 'credit');
    }

    /**
     * Money in our account that nobody has been credited for.
     *
     * The most expensive row in the system: a member has paid, the bank has
     * the money, and as far as the Equb is concerned they still owe it.
     */
    public function scopeOrphaned(Builder $query): Builder
    {
        return $query->credits()
            ->whereIn('match_status', [self::STATUS_UNMATCHED, self::STATUS_DUPLICATE]);
    }

    public function isMatched(): bool
    {
        return $this->match_status === self::STATUS_MATCHED;
    }

    public function needsAttention(): bool
    {
        return in_array($this->match_status, [
            self::STATUS_UNMATCHED,
            self::STATUS_MISMATCH,
            self::STATUS_DUPLICATE,
        ], true);
    }

    public function statusColor(): string
    {
        return match ($this->match_status) {
            self::STATUS_MATCHED => 'success',
            self::STATUS_MISMATCH => 'danger',
            self::STATUS_DUPLICATE => 'danger',
            self::STATUS_IGNORED => 'gray',
            default => 'warning',
        };
    }

    /** How the match was made, in words an auditor can read. */
    public function ruleLabel(): ?string
    {
        return $this->match_rule
            ? __('filament.reconciliation.rule_'.$this->match_rule)
            : null;
    }

    /**
     * Attach this line to a contribution.
     *
     * The confidence and the rule travel with the link, because a match is
     * only as good as the evidence behind it and that evidence is not
     * recoverable later from the fact of the link alone.
     */
    public function linkTo(EqubPayment $payment, string $rule, int $confidence, ?string $note = null, ?int $userId = null): void
    {
        $this->forceFill([
            'equb_payment_id' => $payment->id,
            'match_status' => $this->amountAgreesWith($payment)
                ? self::STATUS_MATCHED
                : self::STATUS_MISMATCH,
            'match_rule' => $rule,
            'match_confidence' => max(0, min(100, $confidence)),
            'match_note' => $note,
            'matched_at' => now(),
            'matched_by' => $userId,
        ])->save();
    }

    public function unlink(): void
    {
        $this->forceFill([
            'equb_payment_id' => null,
            'match_status' => self::STATUS_UNMATCHED,
            'match_rule' => null,
            'match_confidence' => null,
            'match_note' => null,
            'matched_at' => null,
            'matched_by' => null,
        ])->save();
    }

    /**
     * Do the two sides agree on the figure?
     *
     * A birr of tolerance would be wrong here. Rounding differences between a
     * bank and a merchant are not normal, and treating a small gap as noise is
     * how a systematic fee deduction goes unnoticed for months. One cent is
     * floating-point slack, nothing more.
     */
    public function amountAgreesWith(EqubPayment $payment): bool
    {
        return abs((float) $this->amount - (float) $payment->amount) < 0.01;
    }
}

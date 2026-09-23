<?php

namespace App\Services\Reconciliation;

use App\Enums\EqubPaymentStatus;
use App\Models\BankStatementLine;
use App\Models\EqubPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairs the bank's credits with our contributions.
 *
 * FOUR RULES, IN DESCENDING ORDER OF EVIDENCE
 *
 *   1. transaction id      The bank's own id, already stored on the payment
 *                          when settlement confirmed it. This is not a match,
 *                          it is the same fact written twice. Confidence 100.
 *
 *   2. bank reference      The FT or core-banking reference. Nearly as strong;
 *                          it can be reused across a reversal pair, which is
 *                          the only reason it is not first. Confidence 95.
 *
 *   3. merchant reference  Our own order id, echoed back by the bank or pulled
 *                          out of the narration. Strong, and the rule that
 *                          rescues a payment the bank never confirmed to us
 *                          directly. Confidence 90.
 *
 *   4. amount + date + payer  A guess. Used only when the first three find
 *                          nothing, only when exactly one candidate fits, and
 *                          it is recorded as a guess so an auditor can tell.
 *                          Confidence 60–75 depending on what agreed.
 *
 * WHY AMBIGUITY IS LEFT UNMATCHED
 *
 * Rule 4 refuses to choose when two contributions fit equally well. Two
 * members paying 1,000 ETB on the same morning is ordinary, and picking either
 * one has a fifty per cent chance of crediting the wrong person — which is
 * worse than leaving a line for a human, because it looks resolved. The line
 * stays unmatched with a note saying how many candidates there were.
 *
 * A MATCH NEVER MARKS ANYTHING PAID
 *
 * This class links records and raises flags. It does not settle contributions:
 * a statement line proves money reached the account, not which member it was
 * for, and crediting a membership on that basis would let a mapping mistake
 * pay off somebody's Equb. Settling stays with PaymentSettlementService, which
 * asks the bank about a specific reference. The one thing matching does write
 * is `reconciled_at` — the claim that a figure has been checked, which is what
 * it is.
 */
class ReconciliationMatcher
{
    /** How many days either side of a credit to look for a contribution. */
    public const DATE_WINDOW_DAYS = 3;

    /** Flags a matcher can raise on a payment. */
    public const FLAG_AMOUNT_MISMATCH = 'amount_mismatch';

    public const FLAG_DUPLICATE_TXN = 'duplicate_txn';

    public const FLAG_BANK_ONLY = 'bank_only';

    public const FLAG_NO_BANK_RECORD = 'no_bank_record';

    public const FLAG_UNVERIFIED = 'unverified';

    /**
     * Match every unmatched line for a bank.
     *
     * @return array{examined: int, matched: int, mismatched: int, duplicates: int, ambiguous: int, unmatched: int}
     */
    public function matchAll(string $gateway, ?int $statementId = null, ?int $userId = null): array
    {
        $lines = BankStatementLine::query()
            ->where('gateway', $gateway)
            ->credits()
            ->whereIn('match_status', [
                BankStatementLine::STATUS_UNMATCHED,
                BankStatementLine::STATUS_DUPLICATE,
            ])
            ->when($statementId, fn ($q) => $q->where('bank_statement_id', $statementId))
            ->orderBy('posted_at')
            ->get();

        $tally = [
            'examined' => $lines->count(),
            'matched' => 0,
            'mismatched' => 0,
            'duplicates' => 0,
            'ambiguous' => 0,
            'unmatched' => 0,
        ];

        // Payments already claimed within this run. Two lines must never be
        // matched to one contribution: that is either a double charge or a
        // matching error, and both need a person to look at them.
        $claimed = BankStatementLine::query()
            ->where('gateway', $gateway)
            ->whereNotNull('equb_payment_id')
            ->pluck('equb_payment_id')
            ->filter()
            ->flip();

        foreach ($lines as $line) {
            $outcome = $this->matchLine($line, $claimed, $userId);

            $tally[$outcome]++;

            if ($line->equb_payment_id) {
                $claimed[$line->equb_payment_id] = true;
            }
        }

        return $tally;
    }

    /**
     * Match one line, returning which bucket it landed in.
     *
     * @param  Collection<int, mixed>  $claimed  Payment ids already taken.
     * @return 'matched'|'mismatched'|'duplicates'|'ambiguous'|'unmatched'
     */
    public function matchLine(BankStatementLine $line, Collection $claimed, ?int $userId = null): string
    {
        foreach ([
            BankStatementLine::RULE_TRANSACTION_ID => 100,
            BankStatementLine::RULE_BANK_REFERENCE => 95,
            BankStatementLine::RULE_MERCHANT_REFERENCE => 90,
        ] as $rule => $confidence) {
            $payment = $this->byReference($line, $rule);

            if (! $payment) {
                continue;
            }

            // The same bank transaction already sitting on another
            // contribution is not a match, it is a duplicate — and one of the
            // two credits is money the business may have to give back.
            if ($claimed->has($payment->id)) {
                $line->forceFill([
                    'match_status' => BankStatementLine::STATUS_DUPLICATE,
                    'match_rule' => $rule,
                    'match_confidence' => $confidence,
                    'match_note' => __('filament.reconciliation.note_already_claimed', [
                        'payment' => $payment->id,
                    ]),
                ])->save();

                $this->flag($payment, self::FLAG_DUPLICATE_TXN);

                return 'duplicates';
            }

            $line->linkTo($payment, $rule, $confidence, null, $userId);
            $this->afterMatch($line, $payment, $userId);

            return $line->match_status === BankStatementLine::STATUS_MISMATCH
                ? 'mismatched'
                : 'matched';
        }

        // Nothing identified it. Fall back to circumstance.
        $candidates = $this->byCircumstance($line, $claimed);

        if ($candidates->count() === 1) {
            /** @var EqubPayment $payment */
            $payment = $candidates->first();
            $confidence = $this->circumstantialConfidence($line, $payment);

            $line->linkTo(
                $payment,
                BankStatementLine::RULE_AMOUNT_DATE_PAYER,
                $confidence,
                __('filament.reconciliation.note_circumstantial'),
                $userId,
            );

            $this->afterMatch($line, $payment, $userId);

            return $line->match_status === BankStatementLine::STATUS_MISMATCH
                ? 'mismatched'
                : 'matched';
        }

        if ($candidates->count() > 1) {
            // Deliberately not chosen. Guessing here credits the wrong member
            // half the time, and a wrong match looks settled.
            $line->forceFill([
                'match_status' => BankStatementLine::STATUS_UNMATCHED,
                'match_note' => __('filament.reconciliation.note_ambiguous', [
                    'count' => $candidates->count(),
                ]),
            ])->save();

            return 'ambiguous';
        }

        $line->forceFill([
            'match_status' => BankStatementLine::STATUS_UNMATCHED,
            'match_note' => __('filament.reconciliation.note_no_candidate'),
        ])->save();

        return 'unmatched';
    }

    // -----------------------------------------------------------------
    // The rules
    // -----------------------------------------------------------------

    /**
     * Find a contribution by an identifier the bank gave us.
     */
    protected function byReference(BankStatementLine $line, string $rule): ?EqubPayment
    {
        [$value, $columns] = match ($rule) {
            BankStatementLine::RULE_TRANSACTION_ID => [
                $line->external_ref,
                ['bank_transaction_id'],
            ],
            BankStatementLine::RULE_BANK_REFERENCE => [
                $line->bank_reference,
                ['bank_reference'],
            ],
            // Our own order id can be either the row's reference or the batch
            // reference the bank was actually given, since one charge can
            // cover several contributions.
            BankStatementLine::RULE_MERCHANT_REFERENCE => [
                $line->merchant_reference,
                ['reference', 'batch_reference'],
            ],
            default => [null, []],
        };

        if (blank($value)) {
            return null;
        }

        $query = EqubPayment::query()
            ->where('payment_method', $line->gateway)
            ->where(function ($q) use ($columns, $value): void {
                foreach ($columns as $index => $column) {
                    $index === 0
                        ? $q->where($column, $value)
                        : $q->orWhere($column, $value);
                }
            });

        // A batch reference matches several rows. The bank's credit is for the
        // whole charge, so it pairs with the batch as a unit — represented by
        // its first row, with the rest reconciled alongside in afterMatch().
        return $query->orderBy('id')->first();
    }

    /**
     * Contributions that could plausibly be this credit.
     *
     * @param  Collection<int, mixed>  $claimed
     * @return Collection<int, EqubPayment>
     */
    protected function byCircumstance(BankStatementLine $line, Collection $claimed): Collection
    {
        if ($line->amount === null || (float) $line->amount <= 0) {
            return collect();
        }

        $query = EqubPayment::query()
            ->where('payment_method', $line->gateway)
            // Only contributions the bank could have been paying for. A
            // cancelled row is not a candidate.
            ->whereIn('status', [EqubPaymentStatus::Paid->value, EqubPaymentStatus::Pending->value])
            ->whereBetween('amount', [
                (float) $line->amount - 0.01,
                (float) $line->amount + 0.01,
            ])
            // Nothing that another line already took.
            ->whereNotIn('id', $claimed->keys()->all() ?: [0])
            // Nothing another line took on a previous run either.
            ->whereDoesntHave('statementLines');

        if ($line->posted_at) {
            $from = CarbonImmutable::instance($line->posted_at)->subDays(self::DATE_WINDOW_DAYS)->startOfDay();
            $to = CarbonImmutable::instance($line->posted_at)->addDays(self::DATE_WINDOW_DAYS)->endOfDay();

            // Against when the money moved, or failing that when the order was
            // created. payment_date is the round the contribution belongs to
            // and can be weeks away from the charge, so matching on it would
            // put the window in the wrong place entirely.
            $query->where(function ($q) use ($from, $to): void {
                $q->whereBetween('bank_paid_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to): void {
                        $inner->whereNull('bank_paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            });
        }

        $candidates = $query->limit(10)->get();

        // The payer's phone narrows it decisively when the bank supplies one.
        if ($line->payer_phone && $candidates->count() > 1) {
            $byPhone = $candidates->filter(
                fn (EqubPayment $payment): bool => $this->samePhone($payment, $line->payer_phone)
            );

            if ($byPhone->count() === 1) {
                return $byPhone->values();
            }
        }

        return $candidates;
    }

    /**
     * How much to trust a circumstantial match.
     *
     * Amount and a date window alone is the floor. Every extra thing that
     * agrees — the payer's phone, the same day rather than merely the same
     * week — lifts it, and the figure is shown next to the match so a reviewer
     * can sort by it and check the weakest first.
     */
    protected function circumstantialConfidence(BankStatementLine $line, EqubPayment $payment): int
    {
        $confidence = 60;

        if ($line->payer_phone && $this->samePhone($payment, $line->payer_phone)) {
            $confidence += 10;
        }

        $moved = $payment->bank_paid_at ?? $payment->created_at;

        if ($line->posted_at && $moved && $line->posted_at->isSameDay($moved)) {
            $confidence += 5;
        }

        return min(75, $confidence);
    }

    protected function samePhone(EqubPayment $payment, ?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        $tail = fn (?string $value): string => substr(preg_replace('/\D+/', '', (string) $value) ?: '', -9);
        $needle = $tail($phone);

        if (strlen($needle) < 9) {
            return false;
        }

        if ($tail($payment->bank_payer_phone) === $needle) {
            return true;
        }

        return $tail($payment->membership?->payerUser()?->phone) === $needle;
    }

    // -----------------------------------------------------------------
    // After a match
    // -----------------------------------------------------------------

    /**
     * Record that the contribution has been checked against the bank.
     *
     * Deliberately narrow. This marks the payment reconciled and clears or
     * raises the amount flag, and does nothing else — no status change, no
     * membership recalculation, no receipt. A statement line says money
     * arrived in our account; only the bank's answer about a specific
     * reference says whose contribution it was, and that path already exists.
     *
     * Where the line matched a batch, the whole batch is marked, because one
     * bank transaction settled all of it.
     */
    protected function afterMatch(BankStatementLine $line, EqubPayment $payment, ?int $userId): void
    {
        $mismatch = ! $line->amountAgreesWith($payment);

        // A batch charge covers several contributions; the credit reconciles
        // all of them or none.
        $siblings = filled($payment->batch_reference)
            ? EqubPayment::where('batch_reference', $payment->batch_reference)->get()
            : collect([$payment]);

        // For a batch, the bank's single credit should equal the batch total,
        // not one row of it — comparing against one row would flag every
        // batch as a mismatch.
        if ($siblings->count() > 1) {
            $batchTotal = (float) $siblings->sum('amount');
            $mismatch = abs((float) $line->amount - $batchTotal) >= 0.01;

            if (! $mismatch && $line->match_status === BankStatementLine::STATUS_MISMATCH) {
                $line->forceFill(['match_status' => BankStatementLine::STATUS_MATCHED])->save();
            }
        }

        foreach ($siblings as $sibling) {
            $sibling->forceFill([
                'reconciled_at' => now(),
                'reconciled_by' => $userId,
                'reconciled_via' => $userId ? 'manual' : 'statement',
                'reconcile_flag' => $mismatch ? self::FLAG_AMOUNT_MISMATCH : null,
                'reconcile_note' => $mismatch
                    ? __('filament.reconciliation.note_amount_gap', [
                        'ours' => number_format((float) $sibling->amount, 2),
                        'bank' => number_format((float) $line->amount, 2),
                    ])
                    : null,
            ])->save();
        }
    }

    protected function flag(EqubPayment $payment, string $flag, ?string $note = null): void
    {
        $payment->forceFill([
            'reconcile_flag' => $flag,
            'reconcile_note' => $note,
        ])->save();
    }

    // -----------------------------------------------------------------
    // By hand
    // -----------------------------------------------------------------

    /**
     * An operator pairing a line with a contribution themselves.
     *
     * Recorded as RULE_MANUAL with their user id, so the audit trail
     * distinguishes a judgement from an identifier. A person deciding two
     * records belong together is weaker evidence than a transaction id, and
     * the trail should say so.
     */
    public function matchManually(BankStatementLine $line, EqubPayment $payment, int $userId, ?string $note = null): void
    {
        DB::transaction(function () use ($line, $payment, $userId, $note): void {
            $line->linkTo($payment, BankStatementLine::RULE_MANUAL, 80, $note, $userId);
            $this->afterMatch($line, $payment, $userId);
        });
    }

    /**
     * Set a line aside: a fee, a reversal, an internal transfer.
     *
     * Ignored rather than deleted. The credit did reach the account, and a
     * day's control total has to be able to explain every birr in it —
     * including the ones that were never a member's contribution.
     */
    public function ignore(BankStatementLine $line, int $userId, string $reason): void
    {
        $line->forceFill([
            'match_status' => BankStatementLine::STATUS_IGNORED,
            'match_rule' => BankStatementLine::RULE_MANUAL,
            'match_note' => $reason,
            'matched_at' => now(),
            'matched_by' => $userId,
        ])->save();
    }
}

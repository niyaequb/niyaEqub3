<?php

namespace App\Services\Reconciliation;

use App\Enums\EqubPaymentStatus;
use App\Models\BankStatementLine;
use App\Models\EqubPayment;
use App\Models\ReconciliationDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The day's books, and everything that does not add up.
 *
 * WHAT RECONCILIATION ACTUALLY ASKS
 *
 * Not "did the payment succeed" — settlement answers that. It asks a harder
 * question: does the money in our bank account equal the money we told members
 * we received? Those two figures are produced by different systems and they
 * drift for ordinary reasons — a settlement that never got confirmed, a member
 * charged twice, a fee deducted in transit, an operator marking a row paid on
 * the strength of a screenshot. Each of those has a different fix and a
 * different person to call, so they are seven separate queues rather than one
 * list of problems.
 *
 * THE TWO DIRECTIONS
 *
 * Every exception is a gap in one of two directions, and confusing them is how
 * reconciliation goes wrong:
 *
 *   the bank has money we did not credit    a member paid and is still being
 *                                           chased for it. Costs us a customer.
 *
 *   we credited money the bank never had    the books overstate cash. Costs us
 *                                           the money.
 *
 * The first is urgent and fixable. The second is an accounting problem. They
 * are never shown in the same list.
 */
class ReconciliationService
{
    /** Rows returned per exception queue before the UI says "and more". */
    public const QUEUE_LIMIT = 200;

    /** A contribution pending longer than this is stuck, not in flight. */
    public const STUCK_AFTER_HOURS = 2;

    // Exception keys, which double as translation keys.
    public const EX_BANK_ONLY = 'bank_only';

    public const EX_UNVERIFIED = 'unverified';

    public const EX_AMOUNT_MISMATCH = 'amount_mismatch';

    public const EX_DUPLICATE = 'duplicate';

    public const EX_STUCK_PENDING = 'stuck_pending';

    public const EX_FAILED_WITH_CREDIT = 'failed_with_credit';

    public const EX_LOW_CONFIDENCE = 'low_confidence';

    public function __construct(protected ReconciliationMatcher $matcher) {}

    // -----------------------------------------------------------------
    // Control totals
    // -----------------------------------------------------------------

    /**
     * Compute a day's figures from live data.
     *
     * Does not save. compute() answers "what do the books say right now";
     * close() is what turns that into a record somebody stands behind.
     *
     * @return array<string, mixed>
     */
    public function compute(CarbonImmutable $date, string $gateway): array
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();

        /*
         * Our side is keyed on when the money MOVED, not on payment_date.
         *
         * payment_date is the round a contribution belongs to and is routinely
         * weeks from the charge; reconciling on it would compare Monday's
         * bank credits against a set of contributions scattered across the
         * month. bank_paid_at is the bank's own timestamp, and created_at
         * stands in for a row nobody confirmed a time for.
         */
        $ours = EqubPayment::query()
            ->where('payment_method', $gateway)
            ->where('status', EqubPaymentStatus::Paid)
            ->where(function (Builder $q) use ($start, $end): void {
                $q->whereBetween('bank_paid_at', [$start, $end])
                    ->orWhere(function (Builder $inner) use ($start, $end): void {
                        $inner->whereNull('bank_paid_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            });

        $ourCount = (clone $ours)->count();
        $ourAmount = (float) (clone $ours)->sum('amount');

        // Credited without the bank ever confirming it. The figure an auditor
        // asks about first.
        $unverified = (clone $ours)->whereNull('bank_transaction_id');
        $unverifiedCount = (clone $unverified)->count();
        $unverifiedAmount = (float) (clone $unverified)->sum('amount');

        $bank = BankStatementLine::query()
            ->where('gateway', $gateway)
            ->credits()
            ->where('match_status', '!=', BankStatementLine::STATUS_IGNORED)
            ->whereBetween('posted_at', [$start, $end]);

        $bankCount = (clone $bank)->count();
        $bankAmount = (float) (clone $bank)->sum('amount');

        $matched = (clone $bank)->where('match_status', BankStatementLine::STATUS_MATCHED);
        $matchedCount = (clone $matched)->count();
        $matchedAmount = (float) (clone $matched)->sum('amount');

        $unmatchedBank = (clone $bank)->whereIn('match_status', [
            BankStatementLine::STATUS_UNMATCHED,
            BankStatementLine::STATUS_DUPLICATE,
        ]);
        $unmatchedBankCount = (clone $unmatchedBank)->count();
        $unmatchedBankAmount = (float) (clone $unmatchedBank)->sum('amount');

        $unmatchedOurs = (clone $ours)->whereDoesntHave('statementLines');
        $unmatchedOursCount = (clone $unmatchedOurs)->count();
        $unmatchedOursAmount = (float) (clone $unmatchedOurs)->sum('amount');

        $statementLoaded = BankStatementLine::query()
            ->where('gateway', $gateway)
            ->whereBetween('posted_at', [$start, $end])
            ->exists();

        // Bank minus ours. Positive means the bank holds more than we have
        // credited — money owed to members. Negative means we have credited
        // more than arrived.
        $variance = round($bankAmount - $ourAmount, 2);

        return [
            'business_date' => $date->toDateString(),
            'gateway' => $gateway,
            'our_count' => $ourCount,
            'our_amount' => round($ourAmount, 2),
            'unverified_count' => $unverifiedCount,
            'unverified_amount' => round($unverifiedAmount, 2),
            'bank_count' => $bankCount,
            'bank_amount' => round($bankAmount, 2),
            'matched_count' => $matchedCount,
            'matched_amount' => round($matchedAmount, 2),
            'unmatched_bank_count' => $unmatchedBankCount,
            'unmatched_bank_amount' => round($unmatchedBankAmount, 2),
            'unmatched_ours_count' => $unmatchedOursCount,
            'unmatched_ours_amount' => round($unmatchedOursAmount, 2),
            'variance' => $variance,
            'statement_loaded' => $statementLoaded,
        ];
    }

    /**
     * Store the day's figures, without disturbing a sign-off.
     *
     * A signed-off day is a statement somebody made, and recomputing must not
     * quietly rewrite it. The fresh figures are returned so the caller can
     * show the drift, which is a finding rather than a correction.
     */
    public function close(CarbonImmutable $date, string $gateway): ReconciliationDay
    {
        $fresh = $this->compute($date, $gateway);

        $day = ReconciliationDay::firstOrNew([
            'business_date' => $date->toDateString(),
            'gateway' => $gateway,
        ]);

        if ($day->exists && $day->isSignedOff()) {
            return $day;
        }

        $day->fill($fresh);
        $day->computed_at = now();
        $day->status = match (true) {
            ! $fresh['statement_loaded'] => ReconciliationDay::STATUS_OPEN,
            abs($fresh['variance']) < 0.01 && $fresh['unmatched_bank_count'] === 0 => ReconciliationDay::STATUS_BALANCED,
            default => ReconciliationDay::STATUS_VARIANCE,
        };
        $day->save();

        return $day;
    }

    /**
     * Somebody puts their name to a day.
     *
     * A note is required when the day does not balance, because "signed off
     * with a 4,300 ETB variance and no explanation" is not a sign-off, it is
     * a gap with a signature on it.
     *
     * @return array{ok: bool, message: string}
     */
    public function signOff(ReconciliationDay $day, int $userId, ?string $note = null): array
    {
        if (! $day->canSignOff()) {
            return [
                'ok' => false,
                'message' => $day->isSignedOff()
                    ? __('filament.reconciliation.already_signed')
                    : __('filament.reconciliation.no_statement_yet'),
            ];
        }

        if (! $day->balances() && blank($note)) {
            return ['ok' => false, 'message' => __('filament.reconciliation.note_required')];
        }

        $day->forceFill([
            'status' => ReconciliationDay::STATUS_SIGNED_OFF,
            'signed_off_by' => $userId,
            'signed_off_at' => now(),
            'note' => $note,
        ])->save();

        return ['ok' => true, 'message' => __('filament.reconciliation.signed_off')];
    }

    public function reopen(ReconciliationDay $day, int $userId, string $reason): void
    {
        $day->forceFill([
            'status' => ReconciliationDay::STATUS_OPEN,
            'signed_off_by' => null,
            'signed_off_at' => null,
            // The old note is kept and appended to rather than replaced: why a
            // day was reopened matters as much as why it was signed.
            'note' => trim(($day->note ? $day->note."\n" : '').__('filament.reconciliation.reopened_note', [
                'user' => $userId,
                'reason' => $reason,
                'at' => now()->toDateTimeString(),
            ])),
        ])->save();
    }

    // -----------------------------------------------------------------
    // The exception queues
    // -----------------------------------------------------------------

    /**
     * Every exception class, with its count and its money.
     *
     * Counted in one pass so the page can show the whole picture without
     * loading seven lists — most of them will be empty most days, and the
     * useful thing is seeing at a glance which are not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exceptionSummary(string $gateway, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return collect($this->exceptionKeys())
            ->map(function (string $key) use ($gateway, $from, $to): array {
                $query = $this->exceptionQuery($key, $gateway, $from, $to);

                return [
                    'key' => $key,
                    'label' => __('filament.reconciliation.ex_'.$key),
                    'description' => __('filament.reconciliation.ex_'.$key.'_description'),
                    'count' => (clone $query)->count(),
                    'amount' => round((float) (clone $query)->sum($this->amountColumn($key)), 2),
                    'severity' => $this->severity($key),
                    'direction' => $this->direction($key),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    public function exceptionKeys(): array
    {
        return [
            self::EX_BANK_ONLY,
            self::EX_DUPLICATE,
            self::EX_AMOUNT_MISMATCH,
            self::EX_FAILED_WITH_CREDIT,
            self::EX_UNVERIFIED,
            self::EX_STUCK_PENDING,
            self::EX_LOW_CONFIDENCE,
        ];
    }

    /**
     * The query behind one exception class.
     *
     * Returns a builder over either bank lines or payments depending on which
     * side the problem lives on — which is why `direction()` exists: the UI
     * needs to know which kind of row it is about to render.
     */
    public function exceptionQuery(string $key, string $gateway, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        $lines = fn (): Builder => BankStatementLine::query()
            ->where('gateway', $gateway)
            ->credits()
            ->when($from, fn ($q) => $q->where('posted_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('posted_at', '<=', $to->endOfDay()));

        $payments = fn (): Builder => EqubPayment::query()
            ->where('payment_method', $gateway)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to->endOfDay()));

        return match ($key) {
            // Money sitting in our account that nobody was credited for.
            self::EX_BANK_ONLY => $lines()
                ->where('match_status', BankStatementLine::STATUS_UNMATCHED),

            // The bank's transaction id turned up twice.
            self::EX_DUPLICATE => $lines()
                ->where('match_status', BankStatementLine::STATUS_DUPLICATE),

            // Matched, but the two sides disagree on the figure.
            self::EX_AMOUNT_MISMATCH => $lines()
                ->where('match_status', BankStatementLine::STATUS_MISMATCH),

            // Matched on nothing better than amount and date.
            self::EX_LOW_CONFIDENCE => $lines()
                ->where('match_status', BankStatementLine::STATUS_MATCHED)
                ->where('match_confidence', '<', 80),

            /*
             * We wrote this off, and the bank has a credit for it.
             *
             * The most recoverable mistake in the system: a member paid, an
             * early sweep concluded failure from an unclear status, and they
             * have been uncredited ever since. The bank line proves otherwise.
             */
            self::EX_FAILED_WITH_CREDIT => $payments()
                ->where('status', EqubPaymentStatus::Failed)
                ->whereHas('statementLines'),

            // Credited on somebody's word, with nothing from the bank behind it.
            self::EX_UNVERIFIED => $payments()
                ->where('status', EqubPaymentStatus::Paid)
                ->whereNull('bank_transaction_id')
                ->whereDoesntHave('statementLines'),

            // Started and never resolved either way.
            self::EX_STUCK_PENDING => $payments()
                ->where('status', EqubPaymentStatus::Pending)
                ->where('created_at', '<', now()->subHours(self::STUCK_AFTER_HOURS)),

            default => $payments()->whereRaw('1 = 0'),
        };
    }

    /**
     * Rows for one exception queue, shaped for the screen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function exceptionRows(string $key, string $gateway, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, int $limit = self::QUEUE_LIMIT): Collection
    {
        $query = $this->exceptionQuery($key, $gateway, $from, $to);

        if ($this->direction($key) === 'bank') {
            return $query
                ->with(['payment.membership.member', 'statement'])
                ->orderByDesc('amount')
                ->limit($limit)
                ->get()
                ->map(fn (BankStatementLine $line): array => [
                    'kind' => 'line',
                    'id' => $line->id,
                    'when' => $line->posted_at?->format('d M Y, H:i'),
                    'amount' => (float) $line->amount,
                    'who' => $line->payer_name ?: ($line->payer_phone ?: __('filament.reconciliation.unknown_payer')),
                    'phone' => $line->payer_phone,
                    'reference' => $line->external_ref,
                    'narrative' => $line->narrative,
                    'note' => $line->match_note,
                    'confidence' => $line->match_confidence,
                    'rule' => $line->ruleLabel(),
                    'payment_id' => $line->equb_payment_id,
                    'statement' => $line->statement?->original_filename,
                ]);
        }

        return $query
            ->with(['membership.member', 'membership.equbGroup', 'statementLines'])
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn (EqubPayment $payment): array => [
                'kind' => 'payment',
                'id' => $payment->id,
                'when' => ($payment->bank_paid_at ?? $payment->created_at)?->format('d M Y, H:i'),
                'amount' => (float) $payment->amount,
                'who' => $payment->membership?->displayName() ?? __('filament.reconciliation.unknown_payer'),
                'phone' => $payment->membership?->payerUser()?->phone,
                'reference' => $payment->batch_reference ?: $payment->reference,
                'group' => $payment->membership?->equbGroup?->name,
                'status' => $payment->status?->value,
                'note' => $payment->reconcile_note,
                'bank_txn' => $payment->bank_transaction_id,
                'line_id' => $payment->statementLines->first()?->id,
            ]);
    }

    /** Which table an exception lives in: 'bank' or 'ours'. */
    public function direction(string $key): string
    {
        return in_array($key, [
            self::EX_BANK_ONLY,
            self::EX_DUPLICATE,
            self::EX_AMOUNT_MISMATCH,
            self::EX_LOW_CONFIDENCE,
        ], true) ? 'bank' : 'ours';
    }

    protected function amountColumn(string $key): string
    {
        return $this->direction($key) === 'bank' ? 'amount' : 'amount';
    }

    /**
     * How loudly to shout.
     *
     * Money the bank holds that we have not credited, and money we credited
     * twice, are the two that cost somebody something today. The rest are
     * hygiene.
     */
    public function severity(string $key): string
    {
        return match ($key) {
            self::EX_BANK_ONLY, self::EX_DUPLICATE, self::EX_FAILED_WITH_CREDIT => 'danger',
            self::EX_AMOUNT_MISMATCH, self::EX_UNVERIFIED => 'warning',
            default => 'gray',
        };
    }

    // -----------------------------------------------------------------
    // Resolving
    // -----------------------------------------------------------------

    /**
     * Mark a contribution as checked by a person.
     *
     * For the rows no automatic rule will ever settle: cash handed over at a
     * branch, a transfer that came in under somebody else's name. The note is
     * required, and the user id is stored, because this is the one path where
     * a figure is blessed on a person's authority alone.
     */
    public function acceptManually(EqubPayment $payment, int $userId, string $note): void
    {
        $payment->forceFill([
            'reconciled_at' => now(),
            'reconciled_by' => $userId,
            'reconciled_via' => 'manual',
            'reconcile_note' => $note,
            'reconcile_flag' => null,
        ])->save();
    }

    /**
     * A day's worth of figures for a range, for the trend strip.
     *
     * @return Collection<int, ReconciliationDay>
     */
    public function recentDays(string $gateway, int $days = 14): Collection
    {
        return ReconciliationDay::query()
            ->where('gateway', $gateway)
            ->where('business_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('business_date')
            ->get();
    }
}

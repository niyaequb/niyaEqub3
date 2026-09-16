<?php

namespace App\Console\Commands;

use App\Enums\EqubPaymentStatus;
use App\Models\EqubPayment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Console\Command;

/**
 * Ask the banks about contributions still waiting to be confirmed.
 *
 * WHY THIS EXISTS
 *
 * None of these banks send anything unprompted. Dashen have no webhook: the
 * only thing that happens after a member pays is a JavaScript callback in
 * their own browser, which carries no status and no signature and therefore
 * proves nothing. For a long time that left one option — an operator opening
 * the merchant portal and marking contributions paid by hand.
 *
 * `check-status` changed that. The bank will answer if asked, so settlement
 * becomes a question of asking at the right moments:
 *
 *   * the client callback, which is a fine TRIGGER even though it is not
 *     proof, and covers the member who waits for the screen to come back;
 *   * this command, which covers everyone else — the member who closed the
 *     app, lost signal, or whose phone died between the PIN and the callback.
 *
 * The second case is not rare, and it is exactly the case where a member has
 * been debited and sees nothing. That is what this is for.
 *
 * SCHEDULING
 *
 * Every five minutes is plenty; contributions are not time-critical to the
 * minute and every run costs one API call per unsettled reference.
 *
 *     * /5 * * * * cd /var/www/niya-ekub && php artisan payments:reconcile >> /dev/null 2>&1
 *
 * IT IS SAFE TO RUN OVER AND OVER
 *
 * Everything underneath is idempotent. A reference whose rows have already
 * left the pending state is skipped before the bank is called at all, and
 * markSettled() ignores non-pending rows, so a contribution cannot be
 * double-credited and a member cannot get a second receipt.
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--gateway= : Only this gateway slug, e.g. dashen}
                            {--limit=100 : Most references to check in one run}
                            {--min-age=2 : Skip contributions newer than this many minutes}
                            {--max-age=7 : Ignore contributions older than this many days}
                            {--include-failed : Also re-ask about contributions previously marked failed}
                            {--sleep=250 : Milliseconds to wait between calls}
                            {--dry-run : List what would be checked and change nothing}';

    protected $description = 'Confirm pending contributions with the bank and settle the ones that went through';

    public function handle(PaymentGatewayManager $gateways, PaymentSettlementService $settlement): int
    {
        $slugs = $this->option('gateway')
            ? [(string) $this->option('gateway')]
            : $gateways->acceptedMethods();

        if ($slugs === []) {
            $this->warn('No payment gateway is configured on this server.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $checked = $settled = $failed = $stillPending = 0;

        foreach ($slugs as $slug) {
            $gateway = $gateways->tryGet($slug);

            if (! $gateway) {
                continue;
            }

            // Not an error and not worth shouting about: a bank without an
            // order-query endpoint reconciles by hand, which is a documented
            // state rather than a broken one.
            if (! $gateway->canVerifySettlement()) {
                $this->line("  <fg=gray>{$slug}: no order-query endpoint configured, skipping</>");

                continue;
            }

            $references = $this->pendingReferences($slug);

            if ($references->isEmpty()) {
                $this->line("  <fg=gray>{$slug}: nothing pending</>");

                continue;
            }

            $this->line("  <fg=white;options=bold>{$slug}</> — {$references->count()} reference(s) to check");

            foreach ($references as $reference) {
                if ($dryRun) {
                    $this->line("    <fg=gray>would check</> {$reference}");
                    $checked++;

                    continue;
                }

                $result = $settlement->reconcile($gateway, $reference);
                $checked++;

                if ($result['success'] ?? false) {
                    // "Already settled" comes back successful too, but with no
                    // payments credited by this run. Only count real work.
                    if (($result['message'] ?? '') === 'Already settled') {
                        continue;
                    }

                    $settled++;
                    $this->line("    <fg=green>settled</>  {$reference}");

                    continue;
                }

                // The rows are untouched unless the bank explicitly said no,
                // so this branch is mostly "ask again next run".
                if ($this->wasMarkedFailed($reference)) {
                    $failed++;
                    $this->line("    <fg=red>failed</>   {$reference} — ".($result['message'] ?? ''));
                } else {
                    $stillPending++;
                    $this->line("    <fg=yellow>pending</>  {$reference} — ".($result['message'] ?? ''));
                }

                // These banks have not published a rate limit, so pace the
                // sweep rather than discovering one during a busy round.
                usleep(max(0, (int) $this->option('sleep')) * 1000);
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? "  {$checked} reference(s) would be checked. Nothing changed."
            : "  checked {$checked} · settled {$settled} · failed {$failed} · still pending {$stillPending}");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Unsettled references for one gateway, newest first.
     *
     * Grouped to the reference the BANK knows, which for a member settling
     * several places at once is the batch reference — one charge, one
     * question, however many contributions it covers.
     *
     * The age window matters at both ends. A contribution seconds old is very
     * likely still on the customer's PIN screen, and asking about it wastes a
     * call to be told nothing. One a fortnight old is not going to start
     * succeeding now; it needs a person, not another API call every five
     * minutes forever.
     *
     * WITH --include-failed, rows already written off are asked about again.
     *
     * A failure here was never a fact about money, only a conclusion drawn
     * from whatever the bank said at the time — and early on that included
     * statuses nobody had confirmed the meaning of. If a contribution was
     * marked failed wrongly, a member who really paid is sitting there
     * uncredited, and no scheduled run would ever look at them again. This is
     * how that gets undone. Not for the cron; for the morning after a bad
     * assumption is found.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function pendingReferences(string $slug)
    {
        $statuses = [EqubPaymentStatus::Pending];

        if ($this->option('include-failed')) {
            $statuses[] = EqubPaymentStatus::Failed;
        }

        return EqubPayment::query()
            ->where('payment_method', $slug)
            ->whereIn('status', $statuses)
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('min-age')))
            ->where('created_at', '>=', now()->subDays((int) $this->option('max-age')))
            ->orderByDesc('created_at')
            ->get(['reference', 'batch_reference'])
            ->map(fn (EqubPayment $payment) => $payment->batch_reference ?: $payment->reference)
            ->filter()
            ->unique()
            ->take((int) $this->option('limit'))
            ->values();
    }

    /**
     * Did that reference actually end up failed, or is it simply not settled?
     *
     * Read back from the database rather than inferred from the result array,
     * because the distinction the operator cares about is what the rows now
     * say, and only handleUnverified() decides that.
     */
    protected function wasMarkedFailed(string $reference): bool
    {
        return EqubPayment::query()
            ->where(fn ($query) => $query
                ->where('reference', $reference)
                ->orWhere('batch_reference', $reference))
            ->where('status', EqubPaymentStatus::Failed)
            ->exists();
    }
}

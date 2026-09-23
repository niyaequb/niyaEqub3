<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentGatewayManager;
use App\Services\Reconciliation\ReconciliationMatcher;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pair imported bank credits with contributions, and close off the days.
 *
 * Runs after an import and again on a schedule, because matching improves as
 * settlement catches up: a credit imported on Monday that matched nothing may
 * match on Tuesday once the sweep has confirmed the contribution with the bank
 * and written the transaction id onto it. Re-running is cheap and only ever
 * looks at lines that are still unmatched.
 */
class MatchBankStatements extends Command
{
    protected $signature = 'payments:match-statements
                            {--gateway= : Only this bank, e.g. dashen}
                            {--statement= : Only lines from this statement id}
                            {--days=14 : How many days back to recompute control totals}
                            {--no-close : Match only; do not rebuild the daily figures}';

    protected $description = 'Match imported bank statement lines to contributions and rebuild daily control totals';

    public function handle(
        PaymentGatewayManager $gateways,
        ReconciliationMatcher $matcher,
        ReconciliationService $reconciliation,
    ): int {
        $slugs = $this->option('gateway')
            ? [(string) $this->option('gateway')]
            : $gateways->acceptedMethods();

        if ($slugs === []) {
            $this->warn('No payment gateway is configured on this server.');

            return self::SUCCESS;
        }

        foreach ($slugs as $slug) {
            $this->line("  <fg=white;options=bold>{$slug}</>");

            $result = $matcher->matchAll(
                $slug,
                $this->option('statement') ? (int) $this->option('statement') : null,
            );

            if ($result['examined'] === 0) {
                $this->line('    <fg=gray>nothing waiting to be matched</>');
            } else {
                $this->line(sprintf(
                    '    examined %d · matched %d · amount mismatch %d · duplicate %d · ambiguous %d · no candidate %d',
                    $result['examined'],
                    $result['matched'],
                    $result['mismatched'],
                    $result['duplicates'],
                    $result['ambiguous'],
                    $result['unmatched'],
                ));
            }

            if ($this->option('no-close')) {
                continue;
            }

            // Recompute recent days rather than only today. A statement
            // covering last week changes last week's figures, and a control
            // total nobody refreshed is a control total nobody can trust.
            $days = max(1, (int) $this->option('days'));
            $closed = 0;
            $variance = 0;

            for ($i = 0; $i < $days; $i++) {
                $date = CarbonImmutable::now()->subDays($i)->startOfDay();
                $day = $reconciliation->close($date, $slug);
                $closed++;

                if ($day->status === \App\Models\ReconciliationDay::STATUS_VARIANCE) {
                    $variance++;
                }
            }

            $this->line("    recomputed {$closed} day(s)".($variance > 0
                ? " — <fg=red>{$variance} with a variance</>"
                : ' — <fg=green>all balanced or awaiting a statement</>'));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\ReportPrintSchedule;
use App\Services\ReportPrintService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fires any report print schedule that has come due.
 *
 * Runs every minute. The work is decided by next_run_at rather than by a cron
 * expression, so a server that was asleep at 08:00 still prints the 08:00
 * report when it comes back — late, but not skipped, which matters when the
 * report is a day's takings.
 */
class RunScheduledReportPrints extends Command
{
    protected $signature = 'reports:run-scheduled-prints
                            {--schedule= : Run one schedule by ID, ignoring its timing}
                            {--dry-run : Report what would run without printing}
                            {--prune=60 : Delete finished print jobs older than this many days}';

    protected $description = 'Build and dispatch any Equb payment reports whose print schedule is due';

    public function handle(ReportPrintService $printer): int
    {
        // Recorded on every tick, including ticks with nothing to do, so the UI
        // can distinguish "no schedules were due" from "the scheduler is not
        // running at all" — which look identical from the outside and have
        // completely different fixes.
        $printer->recordSchedulerHeartbeat();

        // Manual override: lets an admin verify a schedule end to end from the
        // server without waiting for its next slot.
        if ($id = $this->option('schedule')) {
            return $this->runOne((int) $id, $printer);
        }

        $due = ReportPrintSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get();

        if ($due->isEmpty()) {
            $this->components->info('No report print schedules are due.');
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($due as $schedule) {
            if ($this->option('dry-run')) {
                $this->components->twoColumnDetail(
                    $schedule->name,
                    'would run — '.$schedule->period.' / '.$schedule->delivery,
                );

                continue;
            }

            $result = $printer->runSchedule($schedule);

            $this->components->twoColumnDetail(
                $schedule->name,
                $result['ok']
                    ? '<fg=green>'.$result['message'].'</>'
                    : '<fg=red>'.$result['message'].'</>',
            );

            $result['ok'] ? $succeeded++ : $failed++;
        }

        if (! $this->option('dry-run') && $due->isNotEmpty()) {
            Log::info('[ReportPrint] Scheduled run complete', [
                'due' => $due->count(),
                'succeeded' => $succeeded,
                'failed' => $failed,
            ]);
        }

        // Housekeeping runs on the same tick so it needs no second schedule
        // entry, and stale claims get released even when no agent is running.
        $released = $printer->releaseStaleClaims();

        if ($released > 0) {
            $this->components->info("Released {$released} stale print claim(s).");
        }

        if (($days = (int) $this->option('prune')) > 0) {
            // Only once an hour: pruning on every minute would scan the jobs
            // table 1,440 times a day to delete the same nothing.
            if ((int) now()->minute === 5) {
                $deleted = $printer->prune($days);

                if ($deleted > 0) {
                    $this->components->info("Pruned {$deleted} finished print job(s) older than {$days} days.");
                }
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function runOne(int $id, ReportPrintService $printer): int
    {
        $schedule = ReportPrintSchedule::find($id);

        if (! $schedule) {
            $this->components->error("No print schedule with ID {$id}.");

            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $filters = $printer->resolveScheduleFilters($schedule);

            $this->components->info("Would run '{$schedule->name}'");
            $this->components->twoColumnDetail('Period', $schedule->period);
            $this->components->twoColumnDetail('Window from', (string) ($filters['from'] ?? '—'));
            $this->components->twoColumnDetail('Delivery', $schedule->delivery);
            $this->components->twoColumnDetail('Paper / format', $schedule->paper.' / '.$schedule->format);

            return Command::SUCCESS;
        }

        $result = $printer->runSchedule($schedule);

        $result['ok']
            ? $this->components->info($result['message'])
            : $this->components->error($result['message']);

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}

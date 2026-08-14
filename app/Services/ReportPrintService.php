<?php

namespace App\Services;

use App\Models\ReportPrintJob;
use App\Models\ReportPrintSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts reports on paper.
 *
 * Generating and printing are kept apart deliberately. The server renders a
 * report the moment its schedule falls due and stores it as a job; delivery
 * happens separately, either straight to a network printer or by a browser
 * print agent collecting the queue. That way an office PC being switched off
 * at 08:00 delays the printing, never the report.
 */
class ReportPrintService
{
    /** Disk holding rendered artefacts. Private — these contain member data. */
    public const DISK = 'local';

    public const DIRECTORY = 'report-prints';

    /**
     * Proof that Laravel's task scheduler is actually running.
     *
     * Every other part of scheduled printing can be configured perfectly and
     * still produce nothing if `schedule:run` is not firing — which is the
     * normal state of a development machine, and a common oversight on a new
     * server. Recording a heartbeat lets the UI say so plainly instead of
     * leaving someone to wonder why 08:00 came and went.
     */
    public const HEARTBEAT_KEY = 'reports.scheduler.heartbeat';

    /** Minutes before a silent scheduler is treated as stopped. */
    public const HEARTBEAT_GRACE = 5;

    public function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
    }

    public function schedulerHeartbeat(): ?Carbon
    {
        $value = Cache::get(self::HEARTBEAT_KEY);

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function schedulerIsRunning(): bool
    {
        return $this->schedulerHeartbeat()?->greaterThan(
            now()->subMinutes(self::HEARTBEAT_GRACE)
        ) ?? false;
    }

    public function __construct(
        protected EqubReportService $reports,
        protected ReportRenderService $renderer,
        protected PrinterService $printer,
    ) {}

    /**
     * Build a report, render it, and put it in the print queue.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $options
     */
    public function queue(array $filters, array $options = []): ReportPrintJob
    {
        $report = $this->reports->build($filters);

        $format = $options['format'] ?? 'pdf';
        $paper = $this->renderer->normalizePaper($options['paper'] ?? 'a4');
        $delivery = $options['delivery'] ?? 'agent';

        // A thermal roll cannot render an A4 layout, and an office laser
        // cannot interpret ESC/POS. Rather than let a mismatched pair fail at
        // the printer, reconcile them here.
        if (str_starts_with($paper, 'thermal') && $format === 'pdf' && ($options['prefer_escpos'] ?? false)) {
            $format = 'escpos';
        }

        // The browser print agent drives printing by calling print() on an
        // iframe. Firefox and Safari refuse to do that for an embedded PDF —
        // the plugin owns the document — so agent jobs are always rendered as
        // HTML. PDF stays the format for downloads and network printers.
        if ($delivery === 'agent' && $format === 'pdf') {
            $format = 'html';
        }

        // The local spooler prints through the vendor driver, and the helper
        // that drives it takes a PDF. HTML would be handed to the printer as
        // literal markup, so upgrade it rather than printing tag soup.
        if (($options['connection'] ?? null) === 'system' && $format === 'html') {
            $format = 'pdf';
        }

        $renderOptions = [
            'paper' => $paper,
            'include_details' => $options['include_details'] ?? ! str_starts_with($paper, 'thermal'),
            'show_signatures' => $options['show_signatures'] ?? ! str_starts_with($paper, 'thermal'),
            'generated_by' => $options['generated_by'] ?? null,
        ];

        [$contents, $extension] = match ($format) {
            'escpos' => [$this->renderer->escpos($report, $renderOptions), 'bin'],
            'html' => [$this->renderer->html($report, $renderOptions), 'html'],
            default => [$this->renderer->pdf($report, $renderOptions), 'pdf'],
        };

        $path = self::DIRECTORY.'/'.Carbon::now()->format('Y/m').'/'
            .Str::slug($report['meta']['period_label'].'-'.$report['meta']['range_label'])
            .'-'.Str::random(8).'.'.$extension;

        Storage::disk(self::DISK)->put($path, $contents);

        return ReportPrintJob::create([
            'report_print_schedule_id' => $options['schedule_id'] ?? null,
            'source' => $options['source'] ?? 'manual',
            'status' => ReportPrintJob::STATUS_QUEUED,
            'title' => $options['title'] ?? ($report['meta']['period_label'].' — '.$report['meta']['range_label']),
            'period' => $report['meta']['period'],
            'filters' => $this->serializableFilters($filters),
            'summary' => [
                'collected' => $report['summary']['collected'],
                'outstanding' => $report['summary']['outstanding'],
                'transactions' => $report['summary']['transactions'],
                'members' => $report['summary']['members'],
                'range_label' => $report['meta']['range_label'],
            ],
            'format' => $format,
            'paper' => $paper,
            'copies' => max(1, min(10, (int) ($options['copies'] ?? 1))),
            'delivery' => $delivery,
            'file_path' => $path,
            'file_disk' => self::DISK,
            'created_by' => $options['created_by'] ?? null,
        ]);
    }

    /**
     * Push a queued job straight at a network printer.
     *
     * @param  array<string, mixed>  $printer
     * @return array{ok: bool, message: string, detail?: string}
     */
    public function deliverToPrinter(ReportPrintJob $job, array $printer): array
    {
        $contents = $job->fileContents();

        if ($contents === null) {
            $job->markFailed('Rendered file is missing from storage.');

            return ['ok' => false, 'message' => __('filament.equb_report.print_file_missing')];
        }

        $mime = match ($job->format) {
            'escpos' => 'application/octet-stream',
            'html' => 'text/html',
            default => 'application/pdf',
        };

        $result = $this->printer->send(
            $contents,
            [...$printer, 'copies' => $job->copies],
            $job->title,
            $mime,
        );

        if ($result['ok']) {
            $job->markPrinted();
        } else {
            $job->markFailed(trim(($result['message'] ?? '').' '.($result['detail'] ?? '')));
        }

        return $result;
    }

    /**
     * Run one schedule: build the report and deliver it however the schedule
     * says. Always returns, never throws — the caller is a cron command that
     * must carry on to the next schedule regardless.
     *
     * @return array{ok: bool, message: string, job?: ReportPrintJob}
     */
    public function runSchedule(ReportPrintSchedule $schedule): array
    {
        try {
            $filters = $this->resolveScheduleFilters($schedule);

            $job = $this->queue($filters, [
                'schedule_id' => $schedule->id,
                'source' => 'schedule',
                'format' => $schedule->format,
                'paper' => $schedule->paper,
                'copies' => $schedule->copies,
                'delivery' => $schedule->delivery,
                'title' => $schedule->name,
                'created_by' => $schedule->created_by,
                'connection' => $schedule->printer_connection,
                'prefer_escpos' => $schedule->format === 'escpos',
            ]);

            if ($schedule->delivery === 'network') {
                $result = $this->deliverToPrinter($job, [
                    'connection' => $schedule->printer_connection ?: $schedule->printer_protocol,
                    'name' => $schedule->printer_name,
                    'host' => $schedule->printer_host,
                    'port' => $schedule->printer_port,
                    'queue' => $schedule->printer_queue,
                    'paper' => $schedule->paper,
                ]);

                $schedule->markRun($result['ok'] ? 'printed' : 'failed', $result['detail'] ?? $result['message']);

                return ['ok' => $result['ok'], 'message' => $result['message'], 'job' => $job];
            }

            // delivery = agent (or none): the job simply waits in the queue.
            $schedule->markRun('queued');

            return [
                'ok' => true,
                'message' => __('filament.equb_report.queued_for_agent'),
                'job' => $job,
            ];
        } catch (\Throwable $e) {
            Log::error('[ReportPrint] Schedule run failed', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);

            $schedule->markRun('failed', $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Work out which dates a scheduled run should cover.
     *
     * A schedule stores intent ("the daily report"), not dates. Anchoring on
     * the completed period rather than today is the important part: the daily
     * report printed at 08:00 on Tuesday should show Monday's takings, since
     * Tuesday has barely begun.
     *
     * @return array<string, mixed>
     */
    public function resolveScheduleFilters(ReportPrintSchedule $schedule): array
    {
        $filters = $schedule->filters ?? [];
        $filters['period'] = $schedule->period;

        $tz = $schedule->timezone ?: config('app.timezone', 'UTC');
        $now = Carbon::now($tz);

        // An explicit `from` on a saved schedule means the admin pinned a
        // specific window, so leave it alone.
        if (! empty($filters['from'])) {
            return $filters;
        }

        $filters['from'] = match ($schedule->period) {
            'daily' => $now->copy()->subDay()->toDateString(),
            'weekly' => $now->copy()->subWeek()->toDateString(),
            'monthly' => $now->copy()->subMonthNoOverflow()->toDateString(),
            default => $now->copy()->subDay()->toDateString(),
        };

        return $filters;
    }

    /**
     * Filters are stored as JSON, so Carbon instances and enums must be
     * flattened before they are written.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function serializableFilters(array $filters): array
    {
        return collect($filters)
            ->map(function ($value) {
                if ($value instanceof \BackedEnum) {
                    return $value->value;
                }

                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                return $value;
            })
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
            ->all();
    }

    /**
     * Drop old artefacts. Reports contain member names and phone numbers, so
     * keeping every rendering forever is a liability rather than an asset —
     * and the report can always be rebuilt from its stored filters.
     */
    public function prune(int $days = 60): int
    {
        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        ReportPrintJob::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', [
                ReportPrintJob::STATUS_PRINTED,
                ReportPrintJob::STATUS_FAILED,
                ReportPrintJob::STATUS_CANCELLED,
            ])
            ->chunkById(100, function ($jobs) use (&$deleted): void {
                foreach ($jobs as $job) {
                    $job->delete(); // model event removes the file too
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Jobs stuck in "printing" — an agent claimed them and then the tab was
     * closed. Put them back so the next agent picks them up.
     */
    public function releaseStaleClaims(int $minutes = 15): int
    {
        return ReportPrintJob::query()
            ->where('status', ReportPrintJob::STATUS_PRINTING)
            ->where('claimed_at', '<', Carbon::now()->subMinutes($minutes))
            ->where('attempts', '<', 3)
            ->update([
                'status' => ReportPrintJob::STATUS_QUEUED,
                'claimed_at' => null,
                'claimed_by' => null,
            ]);
    }
}

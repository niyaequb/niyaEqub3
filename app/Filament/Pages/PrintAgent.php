<?php

namespace App\Filament\Pages;

use App\Enums\ReportPeriod;
use App\Models\PrintStation;
use App\Models\ReportPrintJob;
use App\Models\ReportPrintSchedule;
use App\Services\Printing\PrintAgentService;
use App\Services\PrinterService;
use App\Services\ReportPrintService;
use App\Services\ReportRenderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The bridge between the server and a printer on somebody's desk.
 *
 * A cloud server cannot open a connection to a USB printer — there is no route
 * to it. So rather than the server pushing, the office pulls: this page is left
 * open on the machine the printer is attached to, and it collects whatever is
 * waiting, loads each report into a hidden frame and prints it. Whatever
 * printer that browser can reach, this page can print to.
 *
 * What changed in the rebuild, and why:
 *
 *   The agent used to stop at the first page refresh. Whether it was running
 *   was a boolean in Livewire's component state, which does not survive a
 *   reload — so an office PC that refreshed overnight was simply not printing
 *   in the morning, with nothing anywhere saying so. Running is now a property
 *   of a station row and a heartbeat, so opening the page is enough and
 *   closing it is visible from every other branch.
 *
 *   Polling paused itself whenever the tab went behind Excel. Livewire skips
 *   19 of every 20 polls for a background tab unless the directive carries
 *   keep-alive, which this one did not: a ten-second poll became one every
 *   three minutes on precisely the machine that is never in the foreground.
 *
 *   Nothing checked whether silent printing actually worked. The old page
 *   printed the --kiosk-printing tip in a collapsible panel and left it there;
 *   a schedule firing at 08:00 into a print dialog nobody is present to
 *   dismiss has printed nothing, and the first anyone knew was the missing
 *   report. It is now measured, per station, and re-measured on every job.
 *
 *   A failed job stayed failed, there was no way to stop printing without
 *   deleting schedules, and every agent competed for every job regardless of
 *   what paper was in it.
 */
class PrintAgent extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.print-agent';

    protected static ?int $navigationSort = 8;

    /**
     * This browser's durable identity, held in its own localStorage.
     *
     * Not derived from the user or the IP: two people share a desk and one PC
     * changes address twice an afternoon, but the machine with the printer
     * plugged into it stays the machine with the printer plugged into it.
     */
    public ?string $stationKey = null;

    public ?int $stationId = null;

    /** The job currently in the print frame, if any. */
    public ?int $activeJobId = null;

    public ?string $activeJobTitle = null;

    public int $activeCopies = 1;

    public int $printedThisSession = 0;

    public ?string $lastPrintedAt = null;

    /** Surfaced in the UI so a silent failure is never invisible. */
    public ?string $lastError = null;

    /** Set while a deliberate silent-printing probe is in flight. */
    public bool $probing = false;

    // -----------------------------------------------------------------
    // Page chrome
    // -----------------------------------------------------------------

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-printer';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.print_agent.title');
    }

    public function getTitle(): string
    {
        return __('filament.print_agent.title');
    }

    public function getSubheading(): ?string
    {
        return __('filament.print_agent.subtitle');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.print-agent')
        );
    }

    /** Managing schedules and the master switch is a separate privilege. */
    protected function canManage(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.equb-reports.schedule')
        );
    }

    protected function agent(): PrintAgentService
    {
        return app(PrintAgentService::class);
    }

    /** The same service, reachable from the view. */
    public function agentService(): PrintAgentService
    {
        return $this->agent();
    }

    /**
     * The one-page document used to test silent printing.
     *
     * Deliberately not a report: the check has to be runnable at any moment by
     * anyone who can open this page, and a real report would put member names
     * and amounts on a sheet of paper for no reason. It says enough that
     * whoever finds it by the printer knows what it is and can act on it.
     */
    public function probeDocument(): string
    {
        $station = $this->station();

        $title = e(__('filament.print_agent.probe_title'));
        $line = e(__('filament.print_agent.probe_line'));
        $help = e(__('filament.print_agent.probe_help'));
        $where = e($station?->displayName() ?? '—');
        $when = e(now()->translatedFormat('l j F Y, g:i A'));
        $brand = e((string) config('printing.brand.name', config('app.name')));

        return <<<HTML
        <!doctype html>
        <html><head><meta charset="utf-8"><title>{$title}</title>
        <style>
            @page { margin: 15mm; }
            body { font: 12pt/1.5 "Segoe UI", system-ui, sans-serif; color: #111; }
            h1 { font-size: 16pt; margin: 0 0 4mm; }
            .meta { font-size: 10pt; color: #555; margin-bottom: 8mm; }
            .box { border: 1px solid #999; padding: 5mm; font-size: 11pt; }
        </style></head>
        <body>
            <h1>{$title}</h1>
            <div class="meta">{$brand} &middot; {$where} &middot; {$when}</div>
            <div class="box"><p>{$line}</p><p>{$help}</p></div>
        </body></html>
        HTML;
    }

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    /**
     * Called by the browser once it knows which station it is.
     *
     * Server-side code cannot read localStorage, so the handshake has to start
     * in the page. Everything after this point — polling, claiming, the whole
     * UI — is gated on a station existing, which is why the blade shows a
     * "connecting" state until this returns.
     */
    public function connect(string $key, array $info = []): void
    {
        $key = trim($key);

        if ($key === '' || ! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $key)) {
            $this->lastError = __('filament.print_agent.bad_key');

            return;
        }

        // A runaway loop in a browser extension should not be able to fill the
        // table with thousands of desks nobody owns.
        if (PrintStation::query()->where('key', '!=', $key)->count() >= 200) {
            $this->lastError = __('filament.print_agent.too_many_stations');

            return;
        }

        $station = $this->agent()->register($key, [
            'browser' => $info['browser'] ?? null,
            'platform' => $info['platform'] ?? null,
        ]);

        $this->stationKey = $key;
        $this->stationId = $station->getKey();

        // The page has just (re)loaded, so any job this station was holding is
        // gone with the frame that was printing it. Put it back rather than
        // leaving it claimed by a tab that no longer exists.
        $this->agent()->releaseFor($station);

        $this->activeJobId = null;
        $this->activeJobTitle = null;
    }

    public function station(): ?PrintStation
    {
        return $this->stationId ? PrintStation::find($this->stationId) : null;
    }

    // -----------------------------------------------------------------
    // The loop
    // -----------------------------------------------------------------

    /**
     * Polled by the browser. Says we are still here, then takes a job.
     *
     * One call does both on purpose: a heartbeat and a claim are the same
     * question asked twice, and a separate heartbeat request would double the
     * traffic from a tab that is open for eight hours a day.
     */
    public function tick(): void
    {
        $station = $this->station();

        if (! $station) {
            return;
        }

        $station->heartbeat();

        // Already printing something. The heartbeat above is the point of this
        // tick; taking a second job now would print two reports into the same
        // frame and lose one of them.
        if ($this->activeJobId !== null || $this->probing) {
            return;
        }

        $job = $this->agent()->nextJobFor($station);

        if (! $job) {
            return;
        }

        $this->activeJobId = $job->id;
        $this->activeJobTitle = $job->title;
        $this->activeCopies = max(1, min((int) $station->max_copies, (int) $job->copies));
        $this->lastError = null;

        // A browser event rather than a watched property: the front end needs
        // to act once, when a job arrives, not on every re-render.
        $this->dispatch(
            'print-job-ready',
            url: route('admin.print-jobs.content', ['job' => $job->id]),
            title: $job->title,
            copies: $this->activeCopies,
        );
    }

    /**
     * Called by the browser when a job has been handed to the printer.
     *
     * $elapsedMs is how long window.print() took to return, and it is the only
     * evidence available that the document went out without a dialog. There is
     * no API for the --kiosk-printing flag; the behaviour is the signal. Under
     * the threshold means nothing was waiting for a human.
     */
    public function confirmPrinted(int $elapsedMs = 0): void
    {
        $job = $this->heldJob();

        if (! $job) {
            $this->clearActive();

            return;
        }

        $silent = $elapsedMs > 0
            && $elapsedMs <= max(50, (int) config('printing.agent.silent_threshold_ms', 400));

        $job->markPrinted($silent);

        $this->printedThisSession++;
        $this->lastPrintedAt = now()->format('g:i:s A');
        $this->lastError = null;
        $this->clearActive();

        // Chain straight into the next job so a backlog drains in one go
        // instead of one report per poll interval.
        $this->tick();
    }

    public function reportFailure(string $reason = ''): void
    {
        $reason = trim($reason) ?: __('filament.print_agent.browser_refused');
        $job = $this->heldJob();

        if (! $job) {
            $this->clearActive();

            return;
        }

        $job->markFailed($reason);

        $this->lastError = $reason;
        $this->clearActive();
    }

    /** Puts an in-flight job back so another agent — or this one — can retry. */
    public function releaseActiveJob(): void
    {
        $this->heldJob()?->requeue();

        $this->clearActive();
    }

    /**
     * The job this tab is printing, if it is still ours to finish.
     *
     * Checked rather than assumed because a claim can move on underneath us: a
     * second tab on the same machine, or the stale-claim sweep after a long
     * stall, will have handed the job to somebody else. Reporting the outcome
     * of a job we no longer hold would overwrite whatever that other printer
     * is in the middle of doing.
     */
    protected function heldJob(): ?ReportPrintJob
    {
        if ($this->activeJobId === null || $this->stationId === null) {
            return null;
        }

        $job = ReportPrintJob::find($this->activeJobId);

        if (! $job
            || $job->status !== ReportPrintJob::STATUS_PRINTING
            || (int) $job->print_station_id !== (int) $this->stationId) {
            return null;
        }

        return $job;
    }

    protected function clearActive(): void
    {
        $this->activeJobId = null;
        $this->activeJobTitle = null;
        $this->activeCopies = 1;
    }

    // -----------------------------------------------------------------
    // Silent printing
    // -----------------------------------------------------------------

    /** Ask the browser to print a one-line document and time how long it takes. */
    public function startProbe(): void
    {
        if (! $this->station()) {
            $this->notifyNoStation();

            return;
        }

        $this->probing = true;
        $this->dispatch('print-probe');
    }

    /**
     * The measurement comes back.
     *
     * A dialog blocks window.print() until somebody dismisses it, so anything
     * that returns in a few milliseconds went straight to the spooler. The
     * number is stored rather than reduced to a yes/no, because "412ms" tells
     * an administrator something a red cross does not.
     */
    public function recordProbe(int $milliseconds): void
    {
        $this->probing = false;

        $station = $this->station();

        if (! $station) {
            return;
        }

        $station->recordSilentProbe($milliseconds);
        $station->refresh();

        Notification::make()
            ->title($station->silent_ready
                ? __('filament.print_agent.probe_silent')
                : __('filament.print_agent.probe_dialog'))
            ->body($station->silent_ready
                ? __('filament.print_agent.probe_silent_body', ['ms' => $milliseconds])
                : __('filament.print_agent.probe_dialog_body', ['ms' => $milliseconds]))
            ->status($station->silent_ready ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    public function cancelProbe(): void
    {
        $this->probing = false;
    }

    // -----------------------------------------------------------------
    // Reads for the view
    // -----------------------------------------------------------------

    public function masterEnabled(): bool
    {
        return $this->agent()->isEnabled();
    }

    /** @return array<int, array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->agent()->diagnostics($this->station());
    }

    /** @return Collection<int, PrintStation> */
    public function stations(): Collection
    {
        return $this->agent()->stations();
    }

    /**
     * The queue, as the page shows it.
     *
     * Not named queue(): a Livewire component resolves unknown properties and
     * methods through magic, and a name that collides with anything the
     * framework already understands fails in a way that is very hard to read.
     *
     * @return Collection<int, ReportPrintJob>
     */
    public function queuedJobs(): Collection
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->whereIn('status', [ReportPrintJob::STATUS_QUEUED, ReportPrintJob::STATUS_PRINTING])
            ->with('targetStation:id,name', 'station:id,name')
            ->inQueueOrder()
            ->limit(12)
            ->get();
    }

    /** @return Collection<int, ReportPrintJob> */
    public function history(): Collection
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->whereIn('status', [
                ReportPrintJob::STATUS_PRINTED,
                ReportPrintJob::STATUS_FAILED,
                ReportPrintJob::STATUS_CANCELLED,
            ])
            ->with('station:id,name')
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    /** @return Collection<int, ReportPrintSchedule> */
    public function schedules(): Collection
    {
        return ReportPrintSchedule::query()
            ->with('targetStation:id,name')
            ->orderByDesc('is_active')
            ->orderBy('run_at')
            ->get();
    }

    public function queueDepth(): int
    {
        return $this->agent()->queueDepth();
    }

    public function retryingCount(): int
    {
        return $this->agent()->retryingCount();
    }

    /** Poll interval in milliseconds, for the wire:poll directive. */
    public function pollMs(): int
    {
        return max(3, (int) config('printing.agent.poll_seconds', 10)) * 1000;
    }

    public function silentThresholdMs(): int
    {
        return max(50, (int) config('printing.agent.silent_threshold_ms', 400));
    }

    public function loadTimeoutMs(): int
    {
        return max(10, (int) config('printing.agent.load_timeout_seconds', 45)) * 1000;
    }

    public function kioskCommand(): string
    {
        return $this->agent()->kioskCommand($this->station()?->platform);
    }

    // -----------------------------------------------------------------
    // Station management
    // -----------------------------------------------------------------

    /** This desk's own switch, separate from the master one. */
    public function toggleStation(): void
    {
        $station = $this->station();

        if (! $station) {
            $this->notifyNoStation();

            return;
        }

        $station->update(['is_enabled' => ! $station->is_enabled]);

        if (! $station->is_enabled) {
            $this->agent()->releaseFor($station);
            $this->clearActive();
        }

        Notification::make()
            ->title($station->is_enabled
                ? __('filament.print_agent.station_on', ['name' => $station->name])
                : __('filament.print_agent.station_off', ['name' => $station->name]))
            ->status($station->is_enabled ? 'success' : 'warning')
            ->send();
    }

    /** Switch another desk off from here — a manager, remotely. */
    public function toggleStationById(int $id): void
    {
        if (! $this->canManage()) {
            return;
        }

        $station = PrintStation::find($id);

        if (! $station) {
            return;
        }

        $station->update(['is_enabled' => ! $station->is_enabled]);

        if (! $station->is_enabled) {
            $this->agent()->releaseFor($station);
        }

        Notification::make()
            ->title($station->is_enabled
                ? __('filament.print_agent.station_on', ['name' => $station->name])
                : __('filament.print_agent.station_off', ['name' => $station->name]))
            ->status($station->is_enabled ? 'success' : 'warning')
            ->send();
    }

    /**
     * Forget a desk that no longer exists.
     *
     * Its jobs are not deleted with it — a report that was addressed to a
     * retired PC should still be printable somewhere, so the rows fall back to
     * "any station" rather than disappearing with the desk.
     */
    public function forgetStation(int $id): void
    {
        if (! $this->canManage() || $id === $this->stationId) {
            return;
        }

        PrintStation::find($id)?->delete();

        Notification::make()
            ->title(__('filament.print_agent.station_forgotten'))
            ->success()
            ->send();
    }

    protected function notifyNoStation(): void
    {
        Notification::make()
            ->title(__('filament.print_agent.not_registered'))
            ->body(__('filament.print_agent.not_registered_body'))
            ->warning()
            ->send();
    }

    // -----------------------------------------------------------------
    // Queue management
    // -----------------------------------------------------------------

    public function retryJob(int $id): void
    {
        $job = ReportPrintJob::find($id);

        if (! $job) {
            return;
        }

        // An operator pressing retry has usually just fixed the thing that
        // broke, so the attempt counter starts again — otherwise a job that
        // has already used its three tries fails immediately and looks like
        // the button did nothing.
        $job->requeue(resetAttempts: true);

        Notification::make()->title(__('filament.print_agent.job_requeued'))->success()->send();
    }

    public function cancelJob(int $id): void
    {
        ReportPrintJob::find($id)?->cancel();

        Notification::make()->title(__('filament.print_agent.job_cancelled'))->send();
    }

    /** Pull a specific job to the front and print it here, now. */
    public function printJobHere(int $id): void
    {
        $station = $this->station();
        $job = ReportPrintJob::find($id);

        if (! $station || ! $job) {
            $this->notifyNoStation();

            return;
        }

        // Taking a job that another desk is genuinely in the middle of printing
        // would produce two copies and no way to tell which came out. A desk
        // that has gone quiet is fair game — that is what the release sweep is
        // for — but one still checking in is left alone.
        if ($job->status === ReportPrintJob::STATUS_PRINTING
            && $job->print_station_id !== $station->getKey()
            && $job->station?->isOnline()) {
            Notification::make()
                ->title(__('filament.print_agent.already_printing_elsewhere', [
                    'station' => $job->station->name,
                ]))
                ->warning()
                ->send();

            return;
        }

        $job->forceFill([
            'target_station_id' => $station->getKey(),
            'priority' => ReportPrintJob::PRIORITY_URGENT,
            'status' => ReportPrintJob::STATUS_QUEUED,
            'next_attempt_at' => null,
            'claimed_at' => null,
            'claimed_by' => null,
            'print_station_id' => null,
        ])->save();

        $this->tick();
    }

    public function releaseStuckJobs(): void
    {
        $released = $this->agent()->releaseStaleClaims();

        Notification::make()
            ->title(trans_choice('filament.print_agent.released', $released, ['count' => $released]))
            ->status($released > 0 ? 'success' : 'info')
            ->send();
    }

    // -----------------------------------------------------------------
    // Schedules
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function saveSchedule(array $data, ?int $id = null): void
    {
        if (! $this->canManage()) {
            return;
        }

        $values = [
            'name' => $data['name'],
            'period' => $data['period'] ?? 'daily',
            'frequency' => $data['frequency'] ?? 'daily',
            'run_at' => $data['run_at'] ?? '08:00',
            'day_of_week' => ($data['frequency'] ?? 'daily') === 'weekly' ? (int) ($data['day_of_week'] ?? 1) : null,
            'day_of_month' => ($data['frequency'] ?? 'daily') === 'monthly' ? (int) ($data['day_of_month'] ?? 1) : null,
            'timezone' => config('app.timezone', 'Africa/Addis_Ababa'),
            'delivery' => $data['delivery'] ?? 'agent',
            'target_station_id' => ($data['delivery'] ?? 'agent') === 'agent'
                ? ($data['target_station_id'] ?: null)
                : null,
            'copies' => max(1, min(10, (int) ($data['copies'] ?? 1))),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => Auth::id(),
        ];

        if (($data['delivery'] ?? 'agent') === 'network') {
            $values += [
                'format' => $data['format'] ?? 'pdf',
                'paper' => $data['paper'] ?? 'a4',
                'printer_connection' => $data['printer_connection'] ?? PrinterService::CONNECTION_SYSTEM,
                'printer_name' => ($data['printer_connection'] ?? null) === PrinterService::CONNECTION_SHARE
                    ? ($data['printer_share'] ?? null)
                    : ($data['printer_name'] ?? null),
                'printer_host' => $data['printer_host'] ?? null,
                'printer_port' => $data['printer_port'] ?? null,
                'printer_queue' => $data['printer_queue'] ?? null,
            ];
        } else {
            // Agent delivery renders HTML, because Firefox and Safari refuse to
            // print an embedded PDF from script — the plugin owns the document.
            // Paper follows the station, which is the only thing that knows
            // what is actually loaded in the tray.
            $station = $values['target_station_id']
                ? PrintStation::find($values['target_station_id'])
                : null;

            $values += [
                'format' => 'html',
                'paper' => $station?->paper ?? ($data['paper'] ?? 'a4'),
            ];
        }

        $schedule = $id ? ReportPrintSchedule::find($id) : null;

        if ($schedule) {
            // Editing the timing clears an old auto-pause: the admin has just
            // looked at it, which is the review the pause was asking for.
            $schedule->fill($values + ['consecutive_failures' => 0, 'paused_at' => null, 'paused_reason' => null])->save();
        } else {
            $schedule = ReportPrintSchedule::create($values);
        }

        Notification::make()
            ->title(__('filament.equb_report.schedule_saved'))
            ->body(__('filament.print_agent.schedule_next_run', [
                'when' => $schedule->next_run_at
                    ? $schedule->next_run_at->timezone($schedule->timezone)->translatedFormat('l j M, g:i A')
                    : '—',
            ]))
            ->success()
            ->send();
    }

    public function toggleSchedule(int $id): void
    {
        if (! $this->canManage()) {
            return;
        }

        $schedule = ReportPrintSchedule::find($id);

        if (! $schedule) {
            return;
        }

        if ($schedule->is_active) {
            $schedule->update(['is_active' => false]);
        } else {
            // Going through resume() rather than a plain update clears the
            // failure counter, so a schedule switched back on does not pause
            // itself again on its very next failure.
            $schedule->resume();
        }

        Notification::make()
            ->title($schedule->is_active
                ? __('filament.equb_report.schedule_enabled')
                : __('filament.equb_report.schedule_disabled'))
            ->success()
            ->send();
    }

    public function runScheduleNow(int $id): void
    {
        if (! $this->canManage()) {
            return;
        }

        $schedule = ReportPrintSchedule::find($id);

        if (! $schedule) {
            return;
        }

        // force: a person pressing "Run now" outranks the master switch. They
        // are standing at the printer; that is the whole context.
        $result = app(ReportPrintService::class)->runSchedule($schedule, force: true);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();

        $this->tick();
    }

    public function deleteSchedule(int $id): void
    {
        if (! $this->canManage()) {
            return;
        }

        ReportPrintSchedule::whereKey($id)->delete();

        Notification::make()->title(__('filament.equb_report.schedule_deleted'))->success()->send();
    }

    // -----------------------------------------------------------------
    // Header actions
    // -----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->stopAllAction(),
            $this->startAllAction(),
            $this->testPrintAction(),
            $this->newScheduleAction(),
            ActionGroup::make([
                $this->stationSettingsAction(),
                $this->checkSilentAction(),
                $this->releaseStuckAction(),
            ])
                ->label(__('filament.print_agent.more'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    protected function stopAllAction(): Action
    {
        return Action::make('stopAll')
            ->label(__('filament.print_agent.stop_all'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (): bool => $this->canManage() && $this->masterEnabled())
            ->modalHeading(__('filament.print_agent.stop_all_heading'))
            ->modalDescription(__('filament.print_agent.stop_all_description'))
            ->modalSubmitActionLabel(__('filament.print_agent.stop_all_confirm'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('filament.print_agent.stop_reason'))
                    ->helperText(__('filament.print_agent.stop_reason_helper'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $this->agent()->disable($data['reason'] ?? null, Auth::user()?->name);
                $this->clearActive();

                Notification::make()
                    ->title(__('filament.print_agent.stopped_all'))
                    ->body(__('filament.print_agent.stopped_all_body'))
                    ->warning()
                    ->send();
            });
    }

    protected function startAllAction(): Action
    {
        return Action::make('startAll')
            ->label(__('filament.print_agent.start_all'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => $this->canManage() && ! $this->masterEnabled())
            ->requiresConfirmation()
            ->modalHeading(__('filament.print_agent.start_all_heading'))
            ->modalDescription(__('filament.print_agent.start_all_description'))
            ->action(function (): void {
                $this->agent()->enable();

                Notification::make()
                    ->title(__('filament.print_agent.started_all'))
                    ->success()
                    ->send();

                $this->tick();
            });
    }

    protected function testPrintAction(): Action
    {
        return Action::make('testPrint')
            ->label(__('filament.print_agent.test_print'))
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->action(function (): void {
                $station = $this->station();

                if (! $station) {
                    $this->notifyNoStation();

                    return;
                }

                // Queues today's report so the whole chain — render, queue,
                // claim, print — is proved before it is trusted to run at
                // 08:00 unattended. Targeted at this desk and jumped to the
                // front, because somebody is standing over it waiting.
                app(ReportPrintService::class)->queue(
                    ['period' => 'daily', 'from' => now()->toDateString()],
                    [
                        'source' => 'manual',
                        'delivery' => 'agent',
                        'format' => 'html',
                        'paper' => $station->paper,
                        'target_station_id' => $station->getKey(),
                        'priority' => ReportPrintJob::PRIORITY_URGENT,
                        'title' => __('filament.print_agent.test_job_title'),
                        'created_by' => Auth::id(),
                        'generated_by' => Auth::user()?->name,
                    ],
                );

                Notification::make()
                    ->title(__('filament.print_agent.test_queued'))
                    ->body(__('filament.print_agent.test_queued_body'))
                    ->success()
                    ->send();

                $this->tick();
            });
    }

    protected function checkSilentAction(): Action
    {
        return Action::make('checkSilent')
            ->label(__('filament.print_agent.check_silent'))
            ->icon('heroicon-o-beaker')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('filament.print_agent.check_silent_heading'))
            ->modalDescription(__('filament.print_agent.check_silent_description'))
            ->modalSubmitActionLabel(__('filament.print_agent.check_silent_confirm'))
            ->action(fn () => $this->startProbe());
    }

    protected function releaseStuckAction(): Action
    {
        return Action::make('releaseStuck')
            ->label(__('filament.print_agent.release_stuck'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(fn () => $this->releaseStuckJobs());
    }

    protected function stationSettingsAction(): Action
    {
        return Action::make('stationSettings')
            ->label(__('filament.print_agent.station_settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->color('gray')
            ->modalHeading(__('filament.print_agent.station_settings_heading'))
            ->modalDescription(__('filament.print_agent.station_settings_description'))
            ->fillForm(function (): array {
                $station = $this->station();

                return [
                    'name' => $station?->name,
                    'location' => $station?->location,
                    'paper' => $station?->paper ?? 'a4',
                    'max_copies' => $station?->max_copies ?? 5,
                    'printer_hint' => $station?->printer_hint,
                ];
            })
            ->schema([
                TextInput::make('name')
                    ->label(__('filament.print_agent.station_name'))
                    ->placeholder(__('filament.print_agent.station_name_placeholder'))
                    ->helperText(__('filament.print_agent.station_name_helper'))
                    ->required()
                    ->maxLength(120),

                TextInput::make('location')
                    ->label(__('filament.print_agent.station_location'))
                    ->placeholder(__('filament.print_agent.station_location_placeholder'))
                    ->maxLength(120),

                Select::make('paper')
                    ->label(__('filament.print_agent.station_paper'))
                    ->options(ReportRenderService::paperOptions())
                    ->helperText(__('filament.print_agent.station_paper_helper'))
                    ->native(false)
                    ->required(),

                TextInput::make('max_copies')
                    ->label(__('filament.print_agent.station_max_copies'))
                    ->helperText(__('filament.print_agent.station_max_copies_helper'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20),

                Textarea::make('printer_hint')
                    ->label(__('filament.print_agent.station_hint'))
                    ->placeholder(__('filament.print_agent.station_hint_placeholder'))
                    ->helperText(__('filament.print_agent.station_hint_helper'))
                    ->rows(2)
                    ->maxLength(200),
            ])
            ->action(function (array $data): void {
                $station = $this->station();

                if (! $station) {
                    $this->notifyNoStation();

                    return;
                }

                $station->update([
                    'name' => $data['name'],
                    'location' => $data['location'] ?: null,
                    'paper' => $data['paper'],
                    'max_copies' => max(1, min(20, (int) ($data['max_copies'] ?: 5))),
                    'printer_hint' => $data['printer_hint'] ?: null,
                ]);

                Notification::make()->title(__('filament.print_agent.station_saved'))->success()->send();
            });
    }

    protected function newScheduleAction(): Action
    {
        return Action::make('newSchedule')
            ->label(__('filament.print_agent.new_schedule'))
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading(__('filament.print_agent.new_schedule_heading'))
            ->modalDescription(__('filament.print_agent.new_schedule_description'))
            ->modalSubmitActionLabel(__('filament.print_agent.save_schedule'))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => [
                'period' => 'daily',
                'frequency' => 'daily',
                'run_at' => '08:00',
                'delivery' => 'agent',
                'target_station_id' => $this->stationId,
                'copies' => 1,
                'paper' => $this->station()?->paper ?? 'a4',
                'is_active' => true,
                'day_of_week' => 1,
                'day_of_month' => 1,
            ])
            ->schema($this->scheduleFields())
            ->action(fn (array $data) => $this->saveSchedule($data));
    }

    /** Edit an existing schedule — same form, filled from the row. */
    public function editScheduleAction(): Action
    {
        return Action::make('editSchedule')
            ->label(__('filament.print_agent.edit_schedule'))
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->modalHeading(__('filament.print_agent.edit_schedule_heading'))
            ->modalSubmitActionLabel(__('filament.print_agent.save_schedule'))
            ->modalWidth('3xl')
            ->fillForm(function (array $arguments): array {
                $schedule = ReportPrintSchedule::find($arguments['schedule'] ?? 0);

                if (! $schedule) {
                    return [];
                }

                return [
                    'name' => $schedule->name,
                    'period' => $schedule->period,
                    'frequency' => $schedule->frequency,
                    'run_at' => $schedule->run_at,
                    'day_of_week' => $schedule->day_of_week ?: 1,
                    'day_of_month' => $schedule->day_of_month ?: 1,
                    'delivery' => $schedule->delivery,
                    'target_station_id' => $schedule->target_station_id,
                    'paper' => $schedule->paper,
                    'copies' => $schedule->copies,
                    'format' => $schedule->format,
                    'printer_connection' => $schedule->printer_connection,
                    'printer_name' => $schedule->printer_name,
                    'printer_share' => $schedule->printer_connection === PrinterService::CONNECTION_SHARE
                        ? $schedule->printer_name
                        : null,
                    'printer_host' => $schedule->printer_host,
                    'printer_port' => $schedule->printer_port,
                    'printer_queue' => $schedule->printer_queue,
                    'is_active' => $schedule->is_active,
                ];
            })
            ->schema($this->scheduleFields())
            ->action(fn (array $data, array $arguments) => $this->saveSchedule($data, (int) ($arguments['schedule'] ?? 0)));
    }

    /**
     * One form for creating and editing.
     *
     * Ordered as the question is actually asked — what, when, where it comes
     * out — rather than by which column it maps to.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function scheduleFields(): array
    {
        return [
            Section::make(__('filament.print_agent.sched_what'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament.equb_report.schedule_name'))
                        ->placeholder(__('filament.print_agent.sched_name_placeholder'))
                        ->required()
                        ->maxLength(120),

                    Select::make('period')
                        ->label(__('filament.equb_report.report_type'))
                        ->options(collect(ReportPeriod::cases())
                            ->reject(fn (ReportPeriod $p) => $p === ReportPeriod::Custom)
                            ->mapWithKeys(fn (ReportPeriod $p) => [$p->value => $p->label()])
                            ->all())
                        ->helperText(__('filament.print_agent.sched_period_helper'))
                        ->native(false)
                        ->required(),
                ])
                ->columns(2),

            Section::make(__('filament.print_agent.sched_when'))
                ->schema([
                    Select::make('frequency')
                        ->label(__('filament.equb_report.frequency'))
                        ->options([
                            'daily' => __('filament.equb_report.freq_daily'),
                            'weekly' => __('filament.equb_report.freq_weekly'),
                            'monthly' => __('filament.equb_report.freq_monthly'),
                        ])
                        ->native(false)
                        ->live()
                        ->required(),

                    TimePicker::make('run_at')
                        ->label(__('filament.equb_report.run_at'))
                        ->seconds(false)
                        // Stored 24-hour so the scheduler parses it without
                        // ambiguity, shown 12-hour because that is how the
                        // office says it.
                        ->format('H:i')
                        ->displayFormat('h:i A')
                        ->minutesStep(5)
                        ->native(false)
                        ->prefixIcon('heroicon-m-clock')
                        ->helperText(__('filament.print_agent.sched_time_helper', [
                            'zone' => config('app.timezone', 'Africa/Addis_Ababa'),
                            'now' => now(config('app.timezone', 'Africa/Addis_Ababa'))->format('g:i A'),
                        ]))
                        ->required(),

                    Select::make('day_of_week')
                        ->label(__('filament.equb_report.day_of_week'))
                        ->options([
                            1 => __('filament.equb_report.monday'),
                            2 => __('filament.equb_report.tuesday'),
                            3 => __('filament.equb_report.wednesday'),
                            4 => __('filament.equb_report.thursday'),
                            5 => __('filament.equb_report.friday'),
                            6 => __('filament.equb_report.saturday'),
                            7 => __('filament.equb_report.sunday'),
                        ])
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('frequency') === 'weekly'),

                    TextInput::make('day_of_month')
                        ->label(__('filament.equb_report.day_of_month'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->helperText(__('filament.equb_report.day_of_month_helper'))
                        ->visible(fn (Get $get): bool => $get('frequency') === 'monthly'),
                ])
                ->columns(2),

            Section::make(__('filament.print_agent.sched_where'))
                ->schema([
                    Select::make('delivery')
                        ->label(__('filament.equb_report.delivery'))
                        ->options([
                            'agent' => __('filament.print_agent.delivery_station'),
                            'network' => __('filament.equb_report.delivery_network'),
                            'none' => __('filament.equb_report.delivery_none'),
                        ])
                        ->native(false)
                        ->live()
                        ->required()
                        ->helperText(fn (Get $get): string => match ($get('delivery')) {
                            'network' => __('filament.print_agent.delivery_network_helper'),
                            'none' => __('filament.equb_report.delivery_none_helper'),
                            default => __('filament.print_agent.delivery_station_helper'),
                        }),

                    Select::make('target_station_id')
                        ->label(__('filament.print_agent.sched_station'))
                        ->options(fn (): array => PrintStation::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (PrintStation $s) => [
                                $s->id => $s->displayName().' · '.$s->paperLabel(),
                            ])
                            ->all())
                        ->placeholder(__('filament.print_agent.sched_station_any'))
                        ->helperText(__('filament.print_agent.sched_station_helper'))
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('delivery') === 'agent'),

                    Select::make('paper')
                        ->label(__('filament.equb_report.paper'))
                        ->options(ReportRenderService::paperOptions())
                        ->native(false)
                        // Only asked when no station will answer it. A bound
                        // station knows what is in its tray and overrides this.
                        ->visible(fn (Get $get): bool => $get('delivery') === 'network'
                            || ($get('delivery') === 'agent' && blank($get('target_station_id')))),

                    TextInput::make('copies')
                        ->label(__('filament.equb_report.copies'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->visible(fn (Get $get): bool => $get('delivery') !== 'none'),

                    Select::make('format')
                        ->label(__('filament.equb_report.format'))
                        ->options([
                            'pdf' => 'PDF',
                            'html' => 'HTML',
                            'escpos' => __('filament.equb_report.format_escpos'),
                        ])
                        ->native(false)
                        ->helperText(__('filament.equb_report.format_helper'))
                        ->visible(fn (Get $get): bool => $get('delivery') === 'network'),

                    Group::make($this->printerFields())
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('delivery') === 'network'),

                    Toggle::make('is_active')
                        ->label(__('filament.print_agent.sched_active'))
                        ->helperText(__('filament.print_agent.sched_active_helper'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    /**
     * The network printer picker.
     *
     * Connection type first and everything else follows from it. Leading with
     * "printer IP" invites exactly the wrong answer for a USB printer — there
     * is no address to type, and the DNS failure that follows explains nothing.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function printerFields(): array
    {
        $printers = app(PrinterService::class);
        $connections = $printers->availableConnectionOptions();
        $installed = $printers->discoverOptions();

        return [
            Grid::make(2)->schema([
                Select::make('printer_connection')
                    ->label(__('filament.equb_report.how_connected'))
                    ->options($connections)
                    ->default(fn (): string => array_key_first($connections) ?? PrinterService::CONNECTION_RAW)
                    ->native(false)
                    ->live()
                    ->required(fn (Get $get): bool => $get('delivery') === 'network')
                    ->helperText(fn (Get $get): string => match ($get('printer_connection')) {
                        PrinterService::CONNECTION_SHARE => __('filament.equb_report.connection_share_helper'),
                        PrinterService::CONNECTION_RAW => __('filament.equb_report.connection_raw_helper'),
                        PrinterService::CONNECTION_IPP => __('filament.equb_report.connection_ipp_helper'),
                        default => __('filament.equb_report.connection_system_helper'),
                    }),

                Select::make('printer_name')
                    ->label(__('filament.equb_report.choose_printer'))
                    ->options($installed)
                    ->searchable()
                    ->native(false)
                    ->helperText($installed === []
                        ? __('filament.equb_report.no_printers_found')
                        : __('filament.equb_report.choose_printer_helper', ['count' => count($installed)]))
                    ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SYSTEM),

                TextInput::make('printer_share')
                    ->label(__('filament.equb_report.share_path'))
                    ->placeholder('\\\\FRONT-DESK\\HP1102')
                    ->helperText(__('filament.equb_report.share_path_helper'))
                    ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SHARE),

                TextInput::make('printer_host')
                    ->label(__('filament.equb_report.printer_host'))
                    ->placeholder('192.168.1.50')
                    ->visible(fn (Get $get): bool => in_array(
                        $get('printer_connection'),
                        [PrinterService::CONNECTION_RAW, PrinterService::CONNECTION_IPP],
                        true,
                    )),

                TextInput::make('printer_port')
                    ->label(__('filament.equb_report.printer_port'))
                    ->numeric()
                    ->placeholder(fn (Get $get): string => $get('printer_connection') === PrinterService::CONNECTION_IPP ? '631' : '9100')
                    ->helperText(__('filament.equb_report.printer_port_helper'))
                    ->visible(fn (Get $get): bool => in_array(
                        $get('printer_connection'),
                        [PrinterService::CONNECTION_RAW, PrinterService::CONNECTION_IPP],
                        true,
                    )),

                TextInput::make('printer_queue')
                    ->label(__('filament.equb_report.printer_queue'))
                    ->helperText(__('filament.equb_report.printer_queue_helper'))
                    ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_IPP),
            ]),
        ];
    }
}

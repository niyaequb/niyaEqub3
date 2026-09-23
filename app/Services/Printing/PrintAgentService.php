<?php

namespace App\Services\Printing;

use App\Models\PrintStation;
use App\Models\ReportPrintJob;
use App\Models\ReportPrintSchedule;
use App\Services\ReportPrintService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Everything the print agent needs that is not a database row.
 *
 * Three jobs, which used to be spread across a Livewire component and nowhere
 * at all:
 *
 *   The master switch. "Turn it off if I don't want it" has to mean one
 *   control that stops every printer everywhere, that survives a refresh, and
 *   that says who turned it off and why — otherwise the next person finds a
 *   silent system and no explanation.
 *
 *   Stations. A desk registers itself by a key its browser keeps, so the same
 *   machine is the same station tomorrow, and a job can be routed to it.
 *
 *   Diagnostics. Almost every "the printer doesn't work" is one of six things,
 *   and five of them are invisible from the printer: the scheduler isn't
 *   running, the master switch is off, no agent is open, the browser wasn't
 *   launched with kiosk printing, the schedule paused itself, or the queue is
 *   backed up behind a job nobody can print. The page checks all six rather
 *   than printing a tip and hoping.
 */
class PrintAgentService
{
    /** Settings keys. Namespaced so they sort together in the settings table. */
    public const KEY_ENABLED = 'printing.enabled';

    public const KEY_REASON = 'printing.disabled_reason';

    public const KEY_BY = 'printing.disabled_by';

    public const KEY_AT = 'printing.disabled_at';

    public function __construct(
        protected SettingsService $settings,
        protected ReportPrintService $reports,
    ) {}

    // -----------------------------------------------------------------
    // The master switch
    // -----------------------------------------------------------------

    /**
     * Is printing allowed at all?
     *
     * Absent means yes. A system that has never been configured should print,
     * not sit silently waiting to be switched on by somebody who does not know
     * there is a switch.
     */
    public function isEnabled(): bool
    {
        $value = $this->settings->get(self::KEY_ENABLED);

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function enable(): void
    {
        $this->settings->set(self::KEY_ENABLED, '1');
        $this->settings->set(self::KEY_REASON, '');
        $this->settings->set(self::KEY_BY, '');
        $this->settings->set(self::KEY_AT, '');
    }

    /**
     * Stop everything.
     *
     * The reason is recorded with the switch rather than left to a note
     * somewhere: whoever finds the system quiet next week needs to know
     * whether it was deliberate, and a bare `false` cannot tell them.
     */
    public function disable(?string $reason = null, ?string $by = null): void
    {
        $this->settings->set(self::KEY_ENABLED, '0');
        $this->settings->set(self::KEY_REASON, mb_substr(trim((string) $reason), 0, 500));
        $this->settings->set(self::KEY_BY, (string) ($by ?? Auth::user()?->name ?? ''));
        $this->settings->set(self::KEY_AT, now()->toIso8601String());

        // Anything mid-flight goes back in the queue rather than being left in
        // "printing" forever, held by a station that has just been told to stop.
        ReportPrintJob::query()
            ->where('status', ReportPrintJob::STATUS_PRINTING)
            ->update([
                'status' => ReportPrintJob::STATUS_QUEUED,
                'claimed_at' => null,
                'claimed_by' => null,
                'print_station_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function disabledReason(): ?string
    {
        $value = (string) $this->settings->get(self::KEY_REASON, '');

        return $value === '' ? null : $value;
    }

    public function disabledBy(): ?string
    {
        $value = (string) $this->settings->get(self::KEY_BY, '');

        return $value === '' ? null : $value;
    }

    public function disabledAt(): ?Carbon
    {
        $value = (string) $this->settings->get(self::KEY_AT, '');

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Stations
    // -----------------------------------------------------------------

    /**
     * Find or create the station this browser is.
     *
     * The key comes from the browser's own storage. It is not derived from the
     * user or the IP address on purpose: two people share a desk and one PC
     * moves between two IPs in an afternoon, but the machine with the printer
     * plugged into it stays the machine with the printer plugged into it.
     */
    public function register(string $key, array $attributes = []): PrintStation
    {
        $key = Str::limit(preg_replace('/[^A-Za-z0-9\-_]/', '', $key) ?: Str::random(32), 64, '');

        $station = PrintStation::query()->firstOrNew(['key' => $key]);

        if (! $station->exists) {
            $station->fill([
                'name' => $attributes['name'] ?? $this->suggestName(),
                'location' => $attributes['location'] ?? null,
                'paper' => $attributes['paper'] ?? 'a4',
                'is_enabled' => true,
                'created_by' => Auth::id(),
            ]);

            $station->save();
        }

        $station->heartbeat($attributes);

        return $station;
    }

    /** A first name for a new desk, so nobody has to invent one to get started. */
    protected function suggestName(): string
    {
        $count = PrintStation::query()->count();

        return $count === 0
            ? __('filament.print_agent.default_station_name')
            : __('filament.print_agent.default_station_name_n', ['n' => $count + 1]);
    }

    /** @return Collection<int, PrintStation> */
    public function stations(): Collection
    {
        return PrintStation::query()
            ->orderByDesc('is_enabled')
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, PrintStation> */
    public function onlineStations(): Collection
    {
        return PrintStation::query()->enabled()->alive()->orderBy('name')->get();
    }

    public function hasLiveStation(): bool
    {
        return PrintStation::query()->enabled()->alive()->exists();
    }

    // -----------------------------------------------------------------
    // Handing out work
    // -----------------------------------------------------------------

    /**
     * The next job this station may print, already claimed.
     *
     * Returns null for every reason a station might legitimately get nothing:
     * printing is off, this desk is off, the queue is empty, or another agent
     * won the row in the moment between the select and the update. The caller
     * does not need to tell those apart — it polls again in ten seconds.
     */
    public function nextJobFor(PrintStation $station): ?ReportPrintJob
    {
        if (! $this->isEnabled() || ! $station->is_enabled) {
            return null;
        }

        $candidates = ReportPrintJob::query()
            ->forAgent()
            ->ready()
            ->forStation($station)
            ->inQueueOrder()
            ->limit(5)
            ->get();

        foreach ($candidates as $job) {
            if (! $job->claim($station)) {
                // Another agent took it between the select and the update.
                continue;
            }

            if (! $job->fileExists()) {
                // No amount of retrying brings a deleted file back, so this one
                // fails outright rather than occupying three attempts.
                $job->markFailed(
                    __('filament.print_agent.file_missing', ['title' => $job->title]),
                    permanent: true,
                );

                continue;
            }

            return $job;
        }

        return null;
    }

    /**
     * Jobs that have been "printing" for longer than printing can take.
     *
     * Purely a matter of elapsed time, and deliberately not conditioned on
     * whether the holding station is still alive. A tab that was refreshed
     * mid-print leaves its claim behind while the station carries on sending
     * heartbeats from the reloaded page — so a liveness condition here would
     * leave exactly that job, the commonest kind of orphan, stuck forever.
     *
     * Five minutes is safe because the front end gives up on a document after
     * forty-five seconds and reports a failure; anything still claimed after
     * five is not being printed by anybody.
     */
    public function releaseStaleClaims(): int
    {
        $minutes = max(1, (int) config('printing.agent.stale_claim_minutes', 5));

        return ReportPrintJob::query()
            ->where('status', ReportPrintJob::STATUS_PRINTING)
            ->where('claimed_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => ReportPrintJob::STATUS_QUEUED,
                'claimed_at' => null,
                'claimed_by' => null,
                'print_station_id' => null,
                'updated_at' => now(),
            ]);
    }

    /** Put back whatever this station is holding — it is stopping cleanly. */
    public function releaseFor(PrintStation $station): int
    {
        return ReportPrintJob::query()
            ->where('print_station_id', $station->getKey())
            ->where('status', ReportPrintJob::STATUS_PRINTING)
            ->update([
                'status' => ReportPrintJob::STATUS_QUEUED,
                'claimed_at' => null,
                'claimed_by' => null,
                'print_station_id' => null,
                'updated_at' => now(),
            ]);
    }

    // -----------------------------------------------------------------
    // Counts
    // -----------------------------------------------------------------

    public function queueDepth(): int
    {
        return ReportPrintJob::query()->forAgent()->queued()->count();
    }

    public function readyDepth(): int
    {
        return ReportPrintJob::query()->forAgent()->ready()->count();
    }

    public function retryingCount(): int
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->queued()
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '>', now())
            ->count();
    }

    public function failedCount(int $hours = 24): int
    {
        return ReportPrintJob::query()
            ->where('status', ReportPrintJob::STATUS_FAILED)
            ->where('updated_at', '>=', now()->subHours($hours))
            ->count();
    }

    /**
     * Jobs that have been waiting far longer than they should.
     *
     * Not the same as "the queue is long". A queue of twelve at 08:01 is a
     * morning's reports draining normally; one job still queued at 11:00 means
     * nothing is collecting it.
     */
    public function stalledCount(): int
    {
        return ReportPrintJob::query()
            ->forAgent()
            ->ready()
            ->where('created_at', '<', now()->subMinutes(30))
            ->count();
    }

    // -----------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------

    /**
     * Why nothing is printing, in the order the answers actually occur.
     *
     * Each entry is a plain statement an operator can act on. `fix` carries a
     * command to copy where there is one, because the two most common causes —
     * the scheduler not running and the browser not launched for silent
     * printing — are both fixed by a line somebody has to type.
     *
     * @return array<int, array{key: string, ok: bool, severity: string, title: string, body: string, fix: ?string}>
     */
    public function diagnostics(?PrintStation $station = null): array
    {
        $checks = [];

        // 1. The master switch. Checked first because everything below is
        //    irrelevant if printing is deliberately off, and reporting six
        //    problems when the answer is "you turned it off" is noise.
        $enabled = $this->isEnabled();
        $checks[] = [
            'key' => 'master',
            'ok' => $enabled,
            'severity' => 'critical',
            'title' => $enabled
                ? __('filament.print_agent.check_master_ok')
                : __('filament.print_agent.check_master_off'),
            'body' => $enabled
                ? __('filament.print_agent.check_master_ok_body')
                : __('filament.print_agent.check_master_off_body', [
                    'who' => $this->disabledBy() ?: __('filament.print_agent.somebody'),
                    'when' => $this->disabledAt()?->diffForHumans() ?: __('filament.print_agent.earlier'),
                    'why' => $this->disabledReason() ?: __('filament.print_agent.no_reason_given'),
                ]),
            'fix' => null,
        ];

        // 2. The scheduler. Every other part can be configured perfectly and
        //    still produce nothing if schedule:run is not firing, which is the
        //    normal state of a development machine and a common oversight on a
        //    new server. From the outside it is indistinguishable from "no
        //    reports were due".
        $schedulerOk = $this->reports->schedulerIsRunning();
        $checks[] = [
            'key' => 'scheduler',
            'ok' => $schedulerOk,
            'severity' => 'critical',
            'title' => $schedulerOk
                ? __('filament.print_agent.check_scheduler_ok')
                : __('filament.print_agent.check_scheduler_down'),
            'body' => $schedulerOk
                ? __('filament.print_agent.check_scheduler_ok_body', [
                    'ago' => $this->reports->schedulerHeartbeat()?->diffForHumans() ?: '—',
                ])
                : __('filament.print_agent.check_scheduler_down_body'),
            'fix' => $schedulerOk ? null : 'php artisan schedule:work',
        ];

        // 3. Somebody has to be listening. A cloud server cannot see a printer
        //    in an office; the agent page is the only route to one.
        $live = $this->onlineStations();
        $checks[] = [
            'key' => 'station',
            'ok' => $live->isNotEmpty(),
            'severity' => 'critical',
            'title' => $live->isNotEmpty()
                ? trans_choice('filament.print_agent.check_station_ok', $live->count(), ['count' => $live->count()])
                : __('filament.print_agent.check_station_none'),
            'body' => $live->isNotEmpty()
                ? __('filament.print_agent.check_station_ok_body', [
                    'names' => $live->pluck('name')->join(', ', ' & '),
                ])
                : __('filament.print_agent.check_station_none_body'),
            'fix' => null,
        ];

        // 4. Silent printing. A schedule that fires at 08:00 into a print
        //    dialog nobody is there to dismiss has not printed anything.
        if ($station) {
            $checks[] = [
                'key' => 'silent',
                'ok' => (bool) $station->silent_ready,
                'severity' => 'warning',
                'title' => $station->silent_ready
                    ? __('filament.print_agent.check_silent_ok')
                    : __('filament.print_agent.check_silent_off'),
                'body' => $station->silent_ready
                    ? __('filament.print_agent.check_silent_ok_body', [
                        'ms' => (int) $station->silent_probe_ms,
                        'when' => $station->silent_checked_at?->diffForHumans() ?: '—',
                    ])
                    : ($station->silent_checked_at
                        ? __('filament.print_agent.check_silent_off_body')
                        : __('filament.print_agent.check_silent_unknown_body')),
                'fix' => $station->silent_ready ? null : $this->kioskCommand(),
            ];
        }

        // 5. A backlog that is not draining.
        $stalled = $this->stalledCount();
        $checks[] = [
            'key' => 'backlog',
            'ok' => $stalled === 0,
            'severity' => 'warning',
            'title' => $stalled === 0
                ? __('filament.print_agent.check_backlog_ok')
                : trans_choice('filament.print_agent.check_backlog_bad', $stalled, ['count' => $stalled]),
            'body' => $stalled === 0
                ? __('filament.print_agent.check_backlog_ok_body')
                : __('filament.print_agent.check_backlog_bad_body'),
            'fix' => null,
        ];

        // 6. Schedules that switched themselves off after repeated failures.
        $paused = ReportPrintSchedule::query()->whereNotNull('paused_at')->where('is_active', false)->count();
        $checks[] = [
            'key' => 'schedules',
            'ok' => $paused === 0,
            'severity' => 'warning',
            'title' => $paused === 0
                ? __('filament.print_agent.check_schedules_ok')
                : trans_choice('filament.print_agent.check_schedules_paused', $paused, ['count' => $paused]),
            'body' => $paused === 0
                ? __('filament.print_agent.check_schedules_ok_body')
                : __('filament.print_agent.check_schedules_paused_body'),
            'fix' => null,
        ];

        // 7. Nothing will ever print if nothing is scheduled to. Stated last
        //    because it is the only entry that is a question of intent rather
        //    than a fault.
        $active = ReportPrintSchedule::query()->where('is_active', true)->count();
        $checks[] = [
            'key' => 'has_schedule',
            'ok' => $active > 0,
            'severity' => 'info',
            'title' => $active > 0
                ? trans_choice('filament.print_agent.check_has_schedule_ok', $active, ['count' => $active])
                : __('filament.print_agent.check_has_schedule_none'),
            'body' => $active > 0
                ? __('filament.print_agent.check_has_schedule_ok_body')
                : __('filament.print_agent.check_has_schedule_none_body'),
            'fix' => null,
        ];

        return $checks;
    }

    /**
     * The launch command for silent printing, for the platform in front of us.
     *
     * The flag is the same everywhere; the executable is not, and handing a
     * Windows office a macOS path is the sort of small wrongness that makes
     * people stop trusting the rest of the page.
     */
    public function kioskCommand(?string $platform = null): string
    {
        $platform = strtolower((string) ($platform ?? ''));

        if (str_contains($platform, 'mac')) {
            return 'open -a "Google Chrome" --args --kiosk-printing';
        }

        if (str_contains($platform, 'linux')) {
            return 'google-chrome --kiosk-printing';
        }

        return 'msedge.exe --kiosk-printing';
    }

    /** Are any of the critical checks failing? */
    public function isHealthy(?PrintStation $station = null): bool
    {
        foreach ($this->diagnostics($station) as $check) {
            if (! $check['ok'] && $check['severity'] === 'critical') {
                return false;
            }
        }

        return true;
    }
}

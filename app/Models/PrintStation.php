<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One desk with a printer attached.
 *
 * The browser holds the station's key in localStorage and sends it with every
 * poll, so the same machine rejoins as the same station after a refresh, a
 * crash or an overnight reboot. Everything the operator configures — the name,
 * whether it is switched on, what paper is in it — lives here rather than in
 * component state, which is the difference between an agent that survives the
 * night and one that quietly stops at the first reload.
 *
 * Whether a station is running is never stored as a flag. It is inferred from
 * last_seen_at, because a flag records what somebody intended and a heartbeat
 * records what is true — and the question being asked, always, is why the
 * report did not come out this morning.
 */
class PrintStation extends Model
{
    /** Alive and taking jobs. */
    public const STATE_PRINTING = 'printing';

    /** Alive, enabled, nothing to do. */
    public const STATE_IDLE = 'idle';

    /** Nobody has said anything for longer than the grace period. */
    public const STATE_OFFLINE = 'offline';

    /** Switched off at this desk. */
    public const STATE_DISABLED = 'disabled';

    /** Switched off for everybody by the master switch. */
    public const STATE_BLOCKED = 'blocked';

    protected $fillable = [
        'key',
        'name',
        'location',
        'is_enabled',
        'paper',
        'max_copies',
        'printer_hint',
        'silent_ready',
        'silent_probe_ms',
        'silent_checked_at',
        'last_seen_at',
        'listening_since',
        'browser',
        'platform',
        'printed_count',
        'failed_count',
        'last_printed_at',
        'last_error',
        'last_error_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'silent_ready' => 'boolean',
            'max_copies' => 'integer',
            'silent_probe_ms' => 'integer',
            'printed_count' => 'integer',
            'failed_count' => 'integer',
            'silent_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'listening_since' => 'datetime',
            'last_printed_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ReportPrintJob::class, 'print_station_id');
    }

    public function targetedJobs(): HasMany
    {
        return $this->hasMany(ReportPrintJob::class, 'target_station_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportPrintSchedule::class, 'target_station_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** Stations that have checked in recently enough to be trusted with a job. */
    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', static::aliveSince());
    }

    public static function aliveSince(): Carbon
    {
        return now()->subSeconds(max(30, (int) config('printing.agent.offline_after', 120)));
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(static::aliveSince());
    }

    /**
     * The one question the page asks about every station.
     *
     * The master switch is checked last on purpose: a desk that is offline is
     * offline whether or not printing is globally paused, and saying "blocked"
     * about a machine nobody has turned on would send someone to the wrong
     * problem.
     */
    public function state(bool $masterEnabled = true): string
    {
        if (! $this->isOnline()) {
            return self::STATE_OFFLINE;
        }

        if (! $this->is_enabled) {
            return self::STATE_DISABLED;
        }

        if (! $masterEnabled) {
            return self::STATE_BLOCKED;
        }

        return in_array($this->getKey(), static::printingStationIds(), true)
            ? self::STATE_PRINTING
            : self::STATE_IDLE;
    }

    /**
     * Which desks are mid-print, in one query for the whole page.
     *
     * Asked per station this would be a query each, and the agent page polls
     * every ten seconds with every open agent doing the same — an office with
     * four desks would spend most of its database time answering the same
     * question four times over.
     *
     * Safe to memoise for the request because the page reads it while
     * rendering, which is after every claim and every release the request was
     * going to make.
     *
     * @var array<int, int>|null
     */
    protected static ?array $printingIds = null;

    /** @return array<int, int> */
    protected static function printingStationIds(): array
    {
        return static::$printingIds ??= ReportPrintJob::query()
            ->where('status', ReportPrintJob::STATUS_PRINTING)
            ->whereNotNull('print_station_id')
            ->distinct()
            ->pluck('print_station_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function stateColor(string $state): string
    {
        return match ($state) {
            self::STATE_PRINTING => 'info',
            self::STATE_IDLE => 'success',
            self::STATE_DISABLED => 'warning',
            self::STATE_BLOCKED => 'warning',
            default => 'gray',
        };
    }

    /** Available for a job right now, master switch included. */
    public function canTakeWork(bool $masterEnabled = true): bool
    {
        return $masterEnabled && $this->is_enabled && $this->isOnline();
    }

    // -----------------------------------------------------------------
    // Liveness and results
    // -----------------------------------------------------------------

    /**
     * Record that this desk is still there.
     *
     * saveQuietly and a direct update rather than touch(): a heartbeat every
     * fifteen seconds for eight hours is roughly two thousand writes a day per
     * station, and none of them should fire model events, bump updated_at or
     * put a row through the full save pipeline.
     */
    public function heartbeat(array $agent = []): void
    {
        $values = ['last_seen_at' => now()];

        if ($this->listening_since === null) {
            $values['listening_since'] = now();
        }

        foreach (['browser', 'platform'] as $field) {
            if (filled($agent[$field] ?? null) && $this->{$field} !== $agent[$field]) {
                $values[$field] = mb_substr((string) $agent[$field], 0, $field === 'browser' ? 120 : 60);
            }
        }

        static::withoutTimestamps(fn () => static::query()->whereKey($this->getKey())->update($values));

        $this->forceFill($values)->syncOriginal();
    }

    /**
     * Store the result of a silent-print probe.
     *
     * $milliseconds is how long window.print() took to return. There is no API
     * that reports the --kiosk-printing flag, but the behaviour is unambiguous:
     * with a dialog the call blocks until a human dismisses it, and without one
     * it returns almost immediately. Anything under the threshold means the
     * document went to the spooler on its own.
     */
    public function recordSilentProbe(int $milliseconds): void
    {
        $threshold = max(50, (int) config('printing.agent.silent_threshold_ms', 400));

        $this->forceFill([
            'silent_probe_ms' => max(0, $milliseconds),
            'silent_ready' => $milliseconds <= $threshold,
            'silent_checked_at' => now(),
        ])->save();
    }

    public function recordPrinted(bool $silently = false): void
    {
        $this->forceFill([
            'printed_count' => $this->printed_count + 1,
            'last_printed_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        // A station that prints silently in the morning and shows a dialog in
        // the afternoon has had its browser relaunched without the flag. Catch
        // it on the job that revealed it, not at 08:00 the next day.
        if ($silently !== $this->silent_ready) {
            $this->forceFill([
                'silent_ready' => $silently,
                'silent_checked_at' => now(),
            ])->save();
        }
    }

    public function recordFailure(string $error): void
    {
        $this->forceFill([
            'failed_count' => $this->failed_count + 1,
            'last_error' => mb_substr($error, 0, 500),
            'last_error_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------
    // Display
    // -----------------------------------------------------------------

    public function displayName(): string
    {
        return filled($this->location)
            ? $this->name.' — '.$this->location
            : $this->name;
    }

    public function paperLabel(): string
    {
        return match ($this->paper) {
            'a5' => 'A5',
            'thermal80' => '80mm',
            'thermal58' => '58mm',
            default => 'A4',
        };
    }

    public function isThermal(): bool
    {
        return str_starts_with((string) $this->paper, 'thermal');
    }

    public function lastSeenLabel(): ?string
    {
        return $this->last_seen_at?->diffForHumans();
    }
}

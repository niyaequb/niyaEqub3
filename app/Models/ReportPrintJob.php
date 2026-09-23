<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One rendered report waiting to reach paper.
 *
 * Jobs exist so that printing is decoupled from generating. The server can
 * build a report at 08:00 whether or not the office PC is switched on; the
 * print agent collects whatever is waiting when it next connects.
 *
 * Two things changed when the agent was rebuilt.
 *
 * A failure is now a delay rather than a loss. `attempts` had been counted
 * since the beginning and never read — markFailed() was terminal, so a printer
 * that was out of paper for five minutes lost the morning report permanently
 * and the only recovery was for somebody to notice. A job that fails now goes
 * back to the queue on a backoff and gives up only after max_attempts, so the
 * ordinary case — paper, a jam, a browser that was closed mid-print — fixes
 * itself.
 *
 * And a job can name the desk it belongs to. Without that, a branch running a
 * thermal receipt printer competes for the head office's A4 summary and wins it
 * about half the time.
 */
class ReportPrintJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PRINTING = 'printing';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Runs ahead of the queue: a test page somebody is standing over. */
    public const PRIORITY_URGENT = 10;

    public const PRIORITY_NORMAL = 100;

    protected $fillable = [
        'report_print_schedule_id',
        'target_station_id',
        'print_station_id',
        'source',
        'priority',
        'status',
        'title',
        'period',
        'filters',
        'summary',
        'format',
        'paper',
        'copies',
        'delivery',
        'file_path',
        'file_disk',
        'claimed_at',
        'claimed_by',
        'printed_at',
        'printed_silently',
        'error',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_attempt_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'summary' => 'array',
            'copies' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'priority' => 'integer',
            'printed_silently' => 'boolean',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Rendered documents are disposable — the report can always be rebuilt
        // from the stored filters — so clean the file up rather than leaving
        // the disk to fill with orphans holding member data.
        static::deleting(function (ReportPrintJob $job): void {
            $job->deleteFile();
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportPrintSchedule::class, 'report_print_schedule_id');
    }

    /** Where it should print. Null means any enabled station. */
    public function targetStation(): BelongsTo
    {
        return $this->belongsTo(PrintStation::class, 'target_station_id');
    }

    /** Where it did print, or is printing. */
    public function station(): BelongsTo
    {
        return $this->belongsTo(PrintStation::class, 'print_station_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    /** Jobs waiting for a browser-based print agent to collect them. */
    public function scopeForAgent(Builder $query): Builder
    {
        return $query->where('delivery', 'agent');
    }

    /**
     * Queued *and* due.
     *
     * The distinction matters once retries exist: a job that failed a minute
     * ago is back in the queue but must not be handed straight back to the
     * printer that just rejected it, or three attempts are spent in as many
     * seconds and the backoff achieves nothing.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED)
            ->where(fn (Builder $q) => $q
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()));
    }

    /** Jobs this station is allowed to take: its own, plus the unassigned. */
    public function scopeForStation(Builder $query, PrintStation $station): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('target_station_id')
            ->orWhere('target_station_id', $station->getKey()));
    }

    /** Urgent first, then oldest — so a backlog still drains in order. */
    public function scopeInQueueOrder(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    /**
     * Take ownership of this job, if nobody else has.
     *
     * The conditional update is the whole point: two agent tabs polling in the
     * same second both read the row as queued, but only one UPDATE ... WHERE
     * status = 'queued' matches a row, so only one tab prints it. The readiness
     * and routing conditions are repeated here rather than trusted from the
     * SELECT, because anything can change between reading the row and writing
     * to it.
     */
    public function claim(PrintStation $station): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_QUEUED)
            ->where(fn (Builder $q) => $q
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->where(fn (Builder $q) => $q
                ->whereNull('target_station_id')
                ->orWhere('target_station_id', $station->getKey()))
            ->update([
                'status' => self::STATUS_PRINTING,
                'claimed_at' => now(),
                'claimed_by' => $station->name,
                'print_station_id' => $station->getKey(),
                'last_attempt_at' => now(),
                // Computed by the database rather than from $this->attempts:
                // the value in memory was read before the claim and another
                // agent may have incremented it since.
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed) {
            $this->refresh();
        }

        return (bool) $claimed;
    }

    // -----------------------------------------------------------------
    // Outcomes
    // -----------------------------------------------------------------

    /**
     * @param  bool  $silently  Whether the browser spooled it without showing a
     *                          dialog. Recorded because a station can lose its
     *                          kiosk-printing flag when somebody relaunches the
     *                          browser the ordinary way, and this is where that
     *                          first becomes visible.
     */
    public function markPrinted(bool $silently = false): void
    {
        $this->forceFill([
            'status' => self::STATUS_PRINTED,
            'printed_at' => now(),
            'printed_silently' => $silently,
            'next_attempt_at' => null,
            'error' => null,
        ])->save();

        $this->station?->recordPrinted($silently);
    }

    /**
     * Something went wrong. Decide whether that is the end of it.
     *
     * $permanent is for failures no amount of waiting will fix — a rendered
     * file that is no longer on disk will still not be there in five minutes,
     * and burning two more attempts on it only delays the moment somebody is
     * told.
     */
    public function markFailed(string $error, bool $permanent = false): void
    {
        $error = mb_substr(trim($error), 0, 2000);
        $attempts = max(1, (int) $this->attempts);
        $max = max(1, (int) ($this->max_attempts ?: 3));

        $this->station?->recordFailure($error);

        if ($permanent || $attempts >= $max) {
            $this->forceFill([
                'status' => self::STATUS_FAILED,
                'error' => $error,
                'next_attempt_at' => null,
                'last_attempt_at' => now(),
            ])->save();

            return;
        }

        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'error' => $error,
            'claimed_at' => null,
            'claimed_by' => null,
            'print_station_id' => null,
            'last_attempt_at' => now(),
            'next_attempt_at' => now()->addSeconds(static::retryDelay($attempts)),
        ])->save();
    }

    /**
     * How long to wait before attempt number $attempt + 1.
     *
     * Escalating rather than fixed: the first retry catches a tab that was
     * closed mid-print and should be quick, while the third is waiting on
     * somebody to put paper in, which takes longer than a minute.
     */
    public static function retryDelay(int $attempt): int
    {
        $backoff = (array) config('printing.agent.retry_backoff', [60, 300, 900]);
        $backoff = array_values(array_filter(array_map('intval', $backoff), fn (int $s) => $s > 0));

        if ($backoff === []) {
            $backoff = [60, 300, 900];
        }

        return $backoff[min(max(0, $attempt - 1), count($backoff) - 1)];
    }

    /** Back to the front of the queue, attempts reset — an operator's retry. */
    public function requeue(bool $resetAttempts = false): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'claimed_at' => null,
            'claimed_by' => null,
            'print_station_id' => null,
            'printed_at' => null,
            'next_attempt_at' => null,
            'error' => null,
        ] + ($resetAttempts ? ['attempts' => 0] : []))->save();
    }

    public function cancel(): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'claimed_at' => null,
            'claimed_by' => null,
            'next_attempt_at' => null,
        ])->save();
    }

    // -----------------------------------------------------------------
    // State questions the UI asks
    // -----------------------------------------------------------------

    public function isWaitingToRetry(): bool
    {
        return $this->status === self::STATUS_QUEUED
            && $this->next_attempt_at !== null
            && $this->next_attempt_at->isFuture();
    }

    public function attemptsLeft(): int
    {
        return max(0, (int) ($this->max_attempts ?: 3) - (int) $this->attempts);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PRINTED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    // -----------------------------------------------------------------
    // The document
    // -----------------------------------------------------------------

    public function fileExists(): bool
    {
        return filled($this->file_path)
            && Storage::disk($this->file_disk ?: 'local')->exists($this->file_path);
    }

    public function fileContents(): ?string
    {
        return $this->fileExists()
            ? Storage::disk($this->file_disk ?: 'local')->get($this->file_path)
            : null;
    }

    public function deleteFile(): void
    {
        if ($this->fileExists()) {
            Storage::disk($this->file_disk ?: 'local')->delete($this->file_path);
        }
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PRINTED => 'success',
            self::STATUS_PRINTING => 'info',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'gray',
            // A job merely waiting its turn is not a warning; one waiting out
            // a backoff after a failure is.
            default => $this->isWaitingToRetry() ? 'warning' : 'gray',
        };
    }

    public function statusLabel(): string
    {
        if ($this->isWaitingToRetry()) {
            return __('filament.print_agent.status_retrying', [
                'when' => $this->next_attempt_at->diffForHumans(),
            ]);
        }

        return __('filament.print_agent.status_'.$this->status);
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
}

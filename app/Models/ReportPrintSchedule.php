<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standing instruction to build and print a payment report.
 *
 * The scheduler wakes every minute and asks each active row "are you due?"
 * via next_run_at, so the answer has to be a stored timestamp rather than a
 * cron expression evaluated on the fly — that way a schedule created at 09:05
 * for 09:00 waits until tomorrow instead of firing immediately.
 */
class ReportPrintSchedule extends Model
{
    protected $fillable = [
        'name',
        'period',
        'filters',
        'frequency',
        'run_at',
        'day_of_week',
        'day_of_month',
        'timezone',
        'delivery',
        'format',
        'paper',
        'copies',
        'printer_connection',
        'printer_name',
        'printer_host',
        'printer_port',
        'printer_protocol',
        'printer_queue',
        'is_active',
        'last_run_at',
        'next_run_at',
        'last_status',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_active' => 'boolean',
            'copies' => 'integer',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'printer_port' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep next_run_at honest whenever the timing changes. Without this a
        // schedule edited from 17:00 to 08:00 would still be sitting on
        // tonight's timestamp and print at the old time once more.
        static::saving(function (ReportPrintSchedule $schedule): void {
            if ($schedule->exists && ! $schedule->isDirty(['frequency', 'run_at', 'day_of_week', 'day_of_month', 'timezone', 'is_active'])) {
                return;
            }

            $schedule->next_run_at = $schedule->is_active
                ? $schedule->calculateNextRun()
                : null;
        });
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ReportPrintJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The next moment this schedule should fire, in UTC.
     *
     * $after lets the runner pass "now" explicitly so a job that took two
     * minutes to print does not schedule its successor in the past.
     */
    public function calculateNextRun(?CarbonImmutable $after = null): CarbonImmutable
    {
        $tz = $this->timezone ?: config('app.timezone', 'UTC');
        $after = ($after ?? CarbonImmutable::now())->setTimezone($tz);

        [$hour, $minute] = $this->runAtParts();

        $candidate = $after->setTime($hour, $minute, 0);

        $candidate = match ($this->frequency) {
            'weekly' => $this->nextWeekly($candidate, $after),
            'monthly' => $this->nextMonthly($candidate, $after),
            default => $candidate->lessThanOrEqualTo($after) ? $candidate->addDay() : $candidate,
        };

        return $candidate->setTimezone('UTC');
    }

    protected function nextWeekly(CarbonImmutable $candidate, CarbonImmutable $after): CarbonImmutable
    {
        // ISO weekdays: 1 = Monday ... 7 = Sunday.
        $target = $this->day_of_week ?: 1;

        while ((int) $candidate->isoWeekday() !== $target || $candidate->lessThanOrEqualTo($after)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    protected function nextMonthly(CarbonImmutable $candidate, CarbonImmutable $after): CarbonImmutable
    {
        $requested = $this->day_of_month ?: 1;

        for ($i = 0; $i < 3; $i++) {
            $month = $candidate->startOfMonth()->addMonthsNoOverflow($i);
            // Clamp rather than overflow: "the 31st" on a 30-day month means
            // the 30th, not the 1st of the following month.
            $day = min($requested, $month->daysInMonth);
            $attempt = $month->setDay($day)->setTime(...$this->runAtParts());

            if ($attempt->greaterThan($after)) {
                return $attempt;
            }
        }

        return $candidate->addMonthNoOverflow();
    }

    /** @return array{0: int, 1: int} */
    protected function runAtParts(): array
    {
        $parts = explode(':', (string) ($this->run_at ?: '08:00'));

        return [
            max(0, min(23, (int) ($parts[0] ?? 8))),
            max(0, min(59, (int) ($parts[1] ?? 0))),
        ];
    }

    /** Marks a completed run and books the next one. */
    public function markRun(string $status, ?string $error = null): void
    {
        $now = CarbonImmutable::now();

        $this->forceFill([
            'last_run_at' => $now,
            'last_status' => $status,
            'last_error' => $error,
            // Always advance, even on failure. A jammed printer should not
            // cause the runner to retry the same report every 60 seconds and
            // fill the queue with hundreds of identical jobs.
            'next_run_at' => $this->is_active ? $this->calculateNextRun($now) : null,
        ])->saveQuietly();
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'weekly' => __('filament.equb_report.every_week_on', [
                'day' => CarbonImmutable::now()->startOfWeek()->addDays(max(0, ($this->day_of_week ?: 1) - 1))->translatedFormat('l'),
                'time' => $this->runAtLabel(),
            ]),
            'monthly' => __('filament.equb_report.every_month_on', [
                'day' => $this->day_of_month ?: 1,
                'time' => $this->runAtLabel(),
            ]),
            default => __('filament.equb_report.every_day_at', ['time' => $this->runAtLabel()]),
        };
    }

    /**
     * run_at in 12-hour form for display.
     *
     * Stored as 24-hour because the scheduler must parse it unambiguously;
     * shown as "8:00 AM" because that is how the office says it.
     */
    public function runAtLabel(): string
    {
        [$hour, $minute] = $this->runAtParts();

        return CarbonImmutable::now()->setTime($hour, $minute)->format('g:i A');
    }
}

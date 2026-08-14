<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One rendered report waiting to reach paper.
 *
 * Jobs exist so that printing is decoupled from generating. The server can
 * build a report at 08:00 whether or not the office PC is switched on; the
 * print agent collects whatever is waiting when it next connects.
 */
class ReportPrintJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PRINTING = 'printing';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'report_print_schedule_id',
        'source',
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
        'error',
        'attempts',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'summary' => 'array',
            'copies' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Rendered PDFs are disposable — the report can always be rebuilt from
        // the stored filters — so clean the file up rather than leaving the
        // disk to fill with orphans.
        static::deleting(function (ReportPrintJob $job): void {
            $job->deleteFile();
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportPrintSchedule::class, 'report_print_schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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
     * Take ownership of this job, if nobody else has.
     *
     * The conditional update is the whole point: two agent tabs polling the
     * same second both read the row as queued, but only one UPDATE ... WHERE
     * status = 'queued' matches a row, so only one tab prints it.
     */
    public function claim(string $agentId): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_QUEUED)
            ->update([
                'status' => self::STATUS_PRINTING,
                'claimed_at' => now(),
                'claimed_by' => $agentId,
                'attempts' => $this->attempts + 1,
                'updated_at' => now(),
            ]);

        if ($claimed) {
            $this->refresh();
        }

        return (bool) $claimed;
    }

    public function markPrinted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PRINTED,
            'printed_at' => now(),
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    public function requeue(): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'claimed_at' => null,
            'claimed_by' => null,
            'printed_at' => null,
            'error' => null,
        ])->save();
    }

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
            default => 'warning',
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A statement file the bank sent, as an event rather than as data.
 *
 * The rows live in bank_statement_lines; this records the act of loading
 * them — which file, when, by whom, read with which column mapping, and what
 * it claimed to total. That last figure is the one worth keeping: if the lines
 * no longer add up to it, something has been edited since.
 */
class BankStatement extends Model
{
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'gateway',
        'original_filename',
        'file_path',
        'file_disk',
        'file_hash',
        'period_start',
        'period_end',
        'row_count',
        'imported_count',
        'duplicate_count',
        'skipped_count',
        'total_credit',
        'total_debit',
        'column_map',
        'status',
        'notes',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'column_map' => 'array',
            'total_credit' => 'decimal:2',
            'total_debit' => 'decimal:2',
            'row_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The lines cascade with the row; the file has to be removed by hand.
        // Statements carry account numbers and payer names, so an orphaned
        // copy on disk is a liability rather than a spare.
        static::deleting(function (BankStatement $statement): void {
            $statement->deleteFile();
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function fileExists(): bool
    {
        return filled($this->file_path)
            && Storage::disk($this->file_disk ?: 'local')->exists($this->file_path);
    }

    public function deleteFile(): void
    {
        if ($this->fileExists()) {
            Storage::disk($this->file_disk ?: 'local')->delete($this->file_path);
        }
    }

    /**
     * Does the file still add up to what it said when it was loaded?
     *
     * A drift here means lines were deleted or edited after the import, which
     * is the one thing a reconciliation record must never do silently.
     */
    public function linesBalance(): bool
    {
        $sum = (float) $this->lines()->where('direction', 'credit')->sum('amount');

        return abs($sum - (float) $this->total_credit) < 0.01;
    }

    public function matchedCount(): int
    {
        return $this->lines()->where('match_status', BankStatementLine::STATUS_MATCHED)->count();
    }

    public function unmatchedCount(): int
    {
        return $this->lines()->where('match_status', BankStatementLine::STATUS_UNMATCHED)->count();
    }

    public function periodLabel(): string
    {
        if (! $this->period_start) {
            return __('filament.reconciliation.period_unknown');
        }

        if (! $this->period_end || $this->period_start->isSameDay($this->period_end)) {
            return $this->period_start->translatedFormat('d M Y');
        }

        return $this->period_start->translatedFormat('d M').' – '.$this->period_end->translatedFormat('d M Y');
    }
}

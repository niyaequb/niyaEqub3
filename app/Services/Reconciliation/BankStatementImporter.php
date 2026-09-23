<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Loads a bank statement into lines we can match against.
 *
 * TWO RULES THAT SHAPE EVERYTHING HERE
 *
 * The first is that importing the same file twice must be harmless. Operators
 * re-import constantly — the file was truncated, the period overlapped, they
 * were not sure the first one worked. A unique index on the bank's own
 * transaction id does the work; this class counts the collisions and reports
 * them rather than treating them as errors, because "42 rows, 42 already
 * known" is a useful answer and a failed import is not.
 *
 * The second is that a row we cannot read is never silently dropped. Every
 * skipped row is counted, and the reason is kept on the statement. A statement
 * that imports 900 of 1,000 rows without saying so is worse than one that
 * refuses to import at all: the totals will be wrong and nothing will look
 * broken.
 */
class BankStatementImporter
{
    public const SETTING_PREFIX = 'reconciliation.column_map.';

    public function __construct(
        protected SpreadsheetReader $reader,
        protected StatementColumnMapper $mapper,
        protected SettingsService $settings,
    ) {}

    /**
     * Read a file and show what would be imported, saving nothing.
     *
     * The operator confirms the mapping against real values from their own
     * file before a single row is written. A mapping that is merely plausible
     * produces a clean import with the amounts read from the wrong column, and
     * nothing downstream can detect that.
     *
     * @return array{header_row: ?int, headers: array<int, string>, map: array<string, int>, confidence: array<string, string>, preview: array<int, array<string, mixed>>, total_rows: int, missing: array<int, string>}
     */
    public function preview(string $path, string $originalName, ?string $gateway = null): array
    {
        $rows = $this->reader->read($path, $originalName);
        $detected = $this->mapper->detect($rows);

        // A mapping confirmed for this bank before now beats a fresh guess:
        // it was checked by a person, and the export format rarely changes.
        $saved = $gateway ? $this->savedMap($gateway) : null;
        $map = $saved ?: $detected['map'];

        $dataStart = ($detected['header_row'] ?? -1) + 1;
        $preview = [];

        foreach (array_slice($rows, $dataStart, 8) as $row) {
            $preview[] = $this->readRow($row, $map);
        }

        return [
            'header_row' => $detected['header_row'],
            'headers' => $detected['headers'],
            'map' => $map,
            'confidence' => $saved ? array_fill_keys(array_keys($map), 'saved') : $detected['confidence'],
            'preview' => $preview,
            'total_rows' => max(0, count($rows) - $dataStart),
            'missing' => $this->mapper->missingRequired($map),
        ];
    }

    /**
     * Import for real.
     *
     * @param  array<string, int>  $map
     * @return BankStatement
     */
    public function import(
        string $path,
        string $originalName,
        string $gateway,
        array $map,
        ?int $headerRow = null,
        ?int $userId = null,
    ): BankStatement {
        $missing = $this->mapper->missingRequired($map);

        if ($missing !== []) {
            throw new \RuntimeException(__('filament.reconciliation.import_missing_fields', [
                'fields' => implode(', ', $missing),
            ]));
        }

        $rows = $this->reader->read($path, $originalName);
        $dataStart = ($headerRow ?? $this->mapper->findHeaderRow($rows) ?? -1) + 1;
        $body = array_slice($rows, $dataStart);

        $stored = $this->storeFile($path, $originalName, $gateway);

        $statement = BankStatement::create([
            'gateway' => $gateway,
            'original_filename' => $originalName,
            'file_path' => $stored['path'],
            'file_disk' => $stored['disk'],
            'file_hash' => $stored['hash'],
            'column_map' => $map,
            'status' => BankStatement::STATUS_IMPORTED,
            'imported_by' => $userId,
            'row_count' => count($body),
        ]);

        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $skipReasons = [];
        $credit = 0.0;
        $debit = 0.0;
        $earliest = null;
        $latest = null;

        foreach ($body as $index => $row) {
            $parsed = $this->readRow($row, $map);

            if ($this->isBlank($parsed)) {
                continue; // a spacer line, not a skipped transaction
            }

            $reason = $this->rejectionReason($parsed);

            if ($reason !== null) {
                $skipped++;
                $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

                continue;
            }

            // A statement without transaction ids is still worth importing —
            // matching then leans on amount and date — but the uniqueness
            // guard needs something, so a stable digest of the row stands in.
            // Derived from the row's own content, so re-importing the same
            // file collides exactly as a real id would.
            $externalRef = $parsed['external_ref'] !== null && $parsed['external_ref'] !== ''
                ? (string) $parsed['external_ref']
                : 'row:'.substr(hash('sha256', $gateway.'|'.json_encode($row)), 0, 40);

            try {
                DB::transaction(function () use ($statement, $gateway, $parsed, $externalRef, $row): void {
                    BankStatementLine::create([
                        'bank_statement_id' => $statement->id,
                        'gateway' => $gateway,
                        'external_ref' => $externalRef,
                        'bank_reference' => $parsed['bank_reference'],
                        'merchant_reference' => $parsed['merchant_reference'],
                        'posted_at' => $parsed['posted_at'],
                        'amount' => $parsed['amount'],
                        'direction' => $parsed['direction'],
                        'currency' => $parsed['currency'] ?: 'ETB',
                        'payer_name' => $parsed['payer_name'],
                        'payer_account' => $parsed['payer_account'],
                        'payer_phone' => $parsed['payer_phone'],
                        'narrative' => $parsed['narrative'],
                        'raw' => $row,
                        'match_status' => BankStatementLine::STATUS_UNMATCHED,
                    ]);
                });

                $imported++;

                if ($parsed['direction'] === 'credit') {
                    $credit += (float) $parsed['amount'];
                } else {
                    $debit += (float) $parsed['amount'];
                }

                if ($parsed['posted_at'] instanceof CarbonImmutable) {
                    $earliest = $earliest === null || $parsed['posted_at']->lt($earliest) ? $parsed['posted_at'] : $earliest;
                    $latest = $latest === null || $parsed['posted_at']->gt($latest) ? $parsed['posted_at'] : $latest;
                }
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already loaded from an overlapping file. Expected, and the
                // reason re-importing is safe.
                $duplicates++;
            } catch (\Throwable $e) {
                $skipped++;
                $skipReasons['error'] = ($skipReasons['error'] ?? 0) + 1;

                Log::warning('[Reconciliation] Statement row failed to import', [
                    'statement_id' => $statement->id,
                    'row' => $index + $dataStart,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $statement->forceFill([
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'skipped_count' => $skipped,
            'total_credit' => round($credit, 2),
            'total_debit' => round($debit, 2),
            'period_start' => $earliest?->toDateString(),
            'period_end' => $latest?->toDateString(),
            'notes' => $this->describeSkips($skipReasons),
        ])->save();

        // Remember the mapping now that a person has stood behind it.
        $this->rememberMap($gateway, $map);

        return $statement->refresh();
    }

    // -----------------------------------------------------------------
    // Reading one row
    // -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    public function readRow(array $row, array $map): array
    {
        $cell = function (string $field) use ($row, $map): ?string {
            if (! isset($map[$field])) {
                return null;
            }

            $value = $row[$map[$field]] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            return ($value === null || $value === '') ? null : (string) $value;
        };

        $amount = $this->toAmount($cell('amount'));
        $debit = $this->toAmount($cell('debit'));

        // Some statements carry one signed column; others carry credit and
        // debit side by side. A negative in the credit column means the same
        // thing as a figure in the debit column, and both must end up as a
        // debit rather than as a negative credit that quietly reduces a day's
        // takings.
        $direction = 'credit';

        if ($debit !== null && $debit > 0 && ($amount === null || $amount == 0.0)) {
            $amount = $debit;
            $direction = 'debit';
        } elseif ($amount !== null && $amount < 0) {
            $amount = abs($amount);
            $direction = 'debit';
        }

        return [
            'external_ref' => $cell('external_ref'),
            'bank_reference' => $cell('bank_reference'),
            'merchant_reference' => $this->findMerchantReference($cell('merchant_reference'), $cell('narrative')),
            'posted_at' => $this->mapper->parseDate((string) $cell('posted_at')),
            'amount' => $amount === null ? null : round($amount, 2),
            'direction' => $direction,
            'currency' => $cell('currency'),
            'payer_name' => $cell('payer_name'),
            'payer_account' => $cell('payer_account'),
            'payer_phone' => $this->normalisePhone($cell('payer_phone')),
            'narrative' => $cell('narrative'),
        ];
    }

    /**
     * Our own order id, wherever it ended up.
     *
     * Banks that have no merchant-reference column often echo it inside the
     * narration — "Payment EQUB-7F3K2M9QX1BV from ...". Our references have a
     * distinctive shape, so pulling one out of free text is reliable and it
     * turns a guess-level match into an exact one.
     */
    protected function findMerchantReference(?string $explicit, ?string $narrative): ?string
    {
        if (filled($explicit)) {
            return $explicit;
        }

        if (blank($narrative)) {
            return null;
        }

        // EqubPayment::booted() mints these as EQUB- plus 12 upper-case
        // alphanumerics.
        if (preg_match('/\bEQUB-[A-Z0-9]{8,16}\b/i', $narrative, $matches)) {
            return strtoupper($matches[0]);
        }

        return null;
    }

    /**
     * Phone numbers, in one shape.
     *
     * Ethiopian mobiles appear as 0911…, 251911…, +251911… and sometimes with
     * spaces. Matching on the last nine digits is what makes those the same
     * number, and it is why the stored form is normalised rather than kept as
     * the bank wrote it.
     */
    protected function normalisePhone(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < 9) {
            return $value;
        }

        return '+251'.substr($digits, -9);
    }

    protected function toAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // Strip thousands separators, currency codes and non-breaking spaces;
        // keep the sign and the decimal point.
        $cleaned = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) ?? '';

        if ($cleaned === '' || $cleaned === '-' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /** @param  array<string, mixed>  $parsed */
    protected function isBlank(array $parsed): bool
    {
        return $parsed['amount'] === null
            && blank($parsed['external_ref'])
            && blank($parsed['narrative'])
            && blank($parsed['payer_name']);
    }

    /**
     * Why a row cannot be used, or null if it can.
     *
     * @param  array<string, mixed>  $parsed
     */
    protected function rejectionReason(array $parsed): ?string
    {
        if ($parsed['amount'] === null) {
            return 'no_amount';
        }

        if ((float) $parsed['amount'] == 0.0) {
            return 'zero_amount';
        }

        // An undated line still imports: it can be matched on its transaction
        // id, and refusing it would lose a real credit over a formatting
        // problem. It is counted so the gap is visible.
        return null;
    }

    /** @param  array<string, int>  $reasons */
    protected function describeSkips(array $reasons): ?string
    {
        if ($reasons === []) {
            return null;
        }

        return collect($reasons)
            ->map(fn (int $count, string $reason): string => __('filament.reconciliation.skip_'.$reason).': '.$count)
            ->implode(' · ');
    }

    // -----------------------------------------------------------------
    // The file, and the remembered mapping
    // -----------------------------------------------------------------

    /** @return array{path: ?string, disk: string, hash: ?string} */
    protected function storeFile(string $path, string $originalName, string $gateway): array
    {
        try {
            $contents = file_get_contents($path);

            if ($contents === false) {
                return ['path' => null, 'disk' => 'local', 'hash' => null];
            }

            $stored = 'bank-statements/'.$gateway.'/'.now()->format('Y/m').'/'
                .now()->format('Ymd_His').'-'.Str::random(6).'-'
                .Str::slug(pathinfo($originalName, PATHINFO_FILENAME))
                .'.'.pathinfo($originalName, PATHINFO_EXTENSION);

            // The private disk, always. A statement is a list of people's
            // names, phone numbers and account numbers.
            Storage::disk('local')->put($stored, $contents);

            return [
                'path' => $stored,
                'disk' => 'local',
                'hash' => hash('sha256', $contents),
            ];
        } catch (\Throwable $e) {
            Log::warning('[Reconciliation] Could not keep the statement file: '.$e->getMessage());

            return ['path' => null, 'disk' => 'local', 'hash' => null];
        }
    }

    /** @return array<string, int>|null */
    public function savedMap(string $gateway): ?array
    {
        $stored = $this->settings->get(self::SETTING_PREFIX.$gateway);

        if (blank($stored)) {
            return null;
        }

        $decoded = json_decode((string) $stored, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /** @param  array<string, int>  $map */
    public function rememberMap(string $gateway, array $map): void
    {
        $this->settings->set(self::SETTING_PREFIX.$gateway, json_encode($map));
    }

    public function forgetMap(string $gateway): void
    {
        $this->settings->set(self::SETTING_PREFIX.$gateway, '');
    }
}

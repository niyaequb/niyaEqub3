<?php

namespace App\Services\Reconciliation;

use Carbon\CarbonImmutable;

/**
 * Works out which column is which.
 *
 * THE PROBLEM THIS SOLVES
 *
 * No two banks name their columns the same way, the same bank renames them
 * between exports, and nobody tells the merchant when it happens. A hard-coded
 * mapping works until the morning it silently does not — and the failure is
 * not an error, it is a statement that imports cleanly with every amount read
 * from the wrong column.
 *
 * So the mapping is guessed, shown to the operator before anything is saved,
 * and remembered per bank once confirmed. The guess is a starting point that
 * makes the confirmation quick; it is never trusted on its own.
 *
 * HOW A GUESS IS MADE
 *
 * Header names first, matched loosely against the aliases below. Where the
 * headers are useless — "Column1", or a bank that ships no header row at all —
 * the data is sniffed instead: a column of things that parse as dates is a
 * date column, a column of two-decimal numbers is an amount, a column of
 * twelve-digit strings that never repeat is a transaction id.
 */
class StatementColumnMapper
{
    /**
     * The fields a statement line can carry, and what banks call them.
     *
     * Order matters within a field: the first alias that matches wins, so the
     * most specific spellings come first. "transaction id" must beat "id", or
     * every export with a row-number column maps its row numbers as the
     * bank's transaction reference.
     *
     * @var array<string, array<int, string>>
     */
    public const ALIASES = [
        'external_ref' => [
            'transaction id', 'transactionid', 'trxn id', 'trxnid', 'txn id', 'txnid',
            'transaction no', 'transaction number', 'trans id', 'transaction ref',
            'payment id', 'reference id', 'trace no', 'rrn',
        ],
        'bank_reference' => [
            'ft reference', 'ft ref', 'core reference', 'bank reference', 'bank ref',
            'journal no', 'voucher no', 'document no', 'reference no', 'reference',
        ],
        'merchant_reference' => [
            'merchant order id', 'merchantorderid', 'merchant reference', 'merchant ref',
            'order id', 'orderid', 'order no', 'external reference', 'narration ref',
        ],
        'posted_at' => [
            'transaction date', 'value date', 'posting date', 'payment date',
            'date time', 'datetime', 'date & time', 'timestamp', 'date',
        ],
        'amount' => [
            'credit amount', 'amount credited', 'transaction amount', 'paid amount',
            'amount', 'credit', 'value',
        ],
        'debit' => ['debit amount', 'debit', 'withdrawal'],
        'payer_name' => [
            'payer name', 'sender name', 'customer name', 'from name',
            'account name', 'depositor', 'payer', 'sender', 'name',
        ],
        'payer_account' => [
            'payer account', 'sender account', 'from account', 'debit account',
            'source account', 'account no', 'account number', 'account',
        ],
        'payer_phone' => [
            'payer phone', 'phone number', 'mobile number', 'msisdn', 'phone', 'mobile',
        ],
        'narrative' => [
            'narration', 'narrative', 'description', 'particulars', 'details', 'remark', 'remarks',
        ],
        'currency' => ['currency', 'ccy'],
    ];

    /** Fields without which a line cannot be reconciled at all. */
    public const REQUIRED = ['amount'];

    /**
     * Find the header row and map its columns.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array{header_row: int, map: array<string, int>, headers: array<int, string>, confidence: array<string, string>}
     */
    public function detect(array $rows): array
    {
        $headerRow = $this->findHeaderRow($rows);
        $headers = $headerRow === null ? [] : array_map('strval', $rows[$headerRow]);

        $map = $headers === [] ? [] : $this->mapByHeader($headers);
        $confidence = array_fill_keys(array_keys($map), 'header');

        // Anything the headers could not explain, guessed from the data.
        $dataStart = $headerRow === null ? 0 : $headerRow + 1;
        $sniffed = $this->mapByContent($rows, $dataStart, array_values($map));

        foreach ($sniffed as $field => $index) {
            if (! isset($map[$field])) {
                $map[$field] = $index;
                $confidence[$field] = 'guessed';
            }
        }

        return [
            'header_row' => $headerRow,
            'headers' => $headers,
            'map' => $map,
            'confidence' => $confidence,
        ];
    }

    /**
     * Which row is the header?
     *
     * Not always the first: exports often open with a bank logo line, an
     * account summary and a blank row. The header is taken to be the first row
     * in the top twenty whose cells look like column names — several non-empty
     * text cells, few numbers — and which matches at least two known aliases.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    public function findHeaderRow(array $rows): ?int
    {
        $best = null;
        $bestScore = 0;

        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $cells = array_filter(array_map('trim', array_map('strval', $row)), fn ($c) => $c !== '');

            if (count($cells) < 2) {
                continue;
            }

            $score = 0;

            foreach ($cells as $cell) {
                if ($this->fieldFor($cell) !== null) {
                    $score++;
                }
            }

            // A row of numbers is data even if a couple of them happen to sit
            // under names we recognise.
            $numeric = count(array_filter($cells, fn ($c) => is_numeric(str_replace([',', ' '], '', $c))));

            if ($numeric > count($cells) / 2) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $index;
            }
        }

        return $bestScore >= 2 ? $best : null;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    public function mapByHeader(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $field = $this->fieldFor((string) $header);

            // First column wins. A statement with both "Reference" and
            // "Reference No" should take the earlier one rather than letting
            // the later overwrite it silently.
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    /**
     * Which field does this header name stand for?
     *
     * Compared on a squashed form — lowercase, punctuation and spaces
     * stripped — so "Trxn. ID", "trxn_id" and "TRXN ID" are one thing.
     */
    public function fieldFor(string $header): ?string
    {
        $needle = $this->squash($header);

        if ($needle === '') {
            return null;
        }

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $candidate = $this->squash($alias);

                if ($needle === $candidate) {
                    return $field;
                }
            }
        }

        // Second pass, containment rather than equality. Kept separate so an
        // exact match anywhere always beats a partial one — otherwise "Amount"
        // could be claimed by a column called "Amount in words".
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $candidate = $this->squash($alias);

                if (strlen($candidate) >= 4 && str_contains($needle, $candidate)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Guess a column's meaning from what is in it.
     *
     * Every column is scored first and the fields are then assigned to their
     * best candidate, rather than each column taking the first field it half
     * fits. The difference matters on a sheet with two numeric columns: taking
     * the first one that passes would claim the account-number column as the
     * amount and leave the real amount unmapped.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, int>  $taken  Column indexes already claimed.
     * @return array<string, int>
     */
    protected function mapByContent(array $rows, int $dataStart, array $taken): array
    {
        $sample = $this->dropLabelRow(array_slice($rows, $dataStart, 40));

        if ($sample === []) {
            return [];
        }

        $width = max(array_map('count', $sample));
        $scores = [];

        for ($column = 0; $column < $width; $column++) {
            if (in_array($column, $taken, true)) {
                continue;
            }

            $values = array_values(array_filter(
                array_map(fn (array $row): string => trim((string) ($row[$column] ?? '')), $sample),
                fn (string $v): bool => $v !== ''
            ));

            if ($values === []) {
                continue;
            }

            $total = count($values);
            $decimals = count(array_filter($values, fn ($v) => str_contains($v, '.') || str_contains($v, ',')));

            $scores[$column] = [
                'date' => count(array_filter($values, fn ($v) => $this->looksLikeDate($v))) / $total,
                // A column of money nearly always carries decimals, and that
                // is what separates "1,200.00" from a serial date or an
                // account number of a similar magnitude.
                'money' => count(array_filter($values, fn ($v) => $this->looksLikeMoney($v))) / $total,
                'decimals' => $decimals / $total,
                'identifier' => (count(array_unique($values)) === $total
                    && count(array_filter($values, fn ($v) => strlen($v) >= 8 && ! $this->looksLikeMoney($v))) === $total)
                    ? 1.0
                    : 0.0,
            ];
        }

        if ($scores === []) {
            return [];
        }

        $found = [];
        $claim = function (string $field, string $metric, float $threshold, callable $tiebreak) use (&$found, &$scores): void {
            $best = null;
            $bestScore = $threshold;

            foreach ($scores as $column => $score) {
                if ($score[$metric] > $bestScore
                    || ($best !== null && abs($score[$metric] - $bestScore) < 0.001 && $tiebreak($score, $scores[$best]))) {
                    $best = $column;
                    $bestScore = max($bestScore, $score[$metric]);
                }
            }

            if ($best !== null) {
                $found[$field] = $best;
                unset($scores[$best]);
            }
        };

        // Dates first: a date column is the least ambiguous of the three, and
        // taking it out of the running stops a column of serials being read as
        // money afterwards.
        $claim('posted_at', 'date', 0.8, fn (array $a, array $b): bool => $a['decimals'] < $b['decimals']);

        // Then the amount, preferring the numeric column that carries
        // decimals — which is the amount rather than the account number.
        $claim('amount', 'money', 0.8, fn (array $a, array $b): bool => $a['decimals'] > $b['decimals']);

        $claim('external_ref', 'identifier', 0.99, fn (): bool => false);

        return $found;
    }

    /**
     * Drop a leading row of column labels the alias table did not recognise.
     *
     * A sheet headed "Column1, Column2, Column3" has a header row; it is just
     * one we cannot name. Left in the sample it drags every ratio down by a
     * quarter on a short file and the sniffing finds nothing at all.
     *
     * @param  array<int, array<int, string>>  $sample
     * @return array<int, array<int, string>>
     */
    protected function dropLabelRow(array $sample): array
    {
        if (count($sample) < 3) {
            return $sample;
        }

        $first = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $sample[0] ?? []),
            fn ($v) => $v !== ''
        ));

        if ($first === []) {
            return $sample;
        }

        $looksTypedLater = false;

        foreach (array_slice($sample, 1, 5) as $row) {
            foreach ($row as $value) {
                if ($this->looksLikeMoney((string) $value) || $this->looksLikeDate((string) $value)) {
                    $looksTypedLater = true;
                    break 2;
                }
            }
        }

        $firstIsLabels = true;

        foreach ($first as $value) {
            if ($this->looksLikeMoney($value) || $this->looksLikeDate($value)) {
                $firstIsLabels = false;
                break;
            }
        }

        return ($firstIsLabels && $looksTypedLater)
            ? array_values(array_slice($sample, 1))
            : $sample;
    }

    /**
     * Could this cell be a date?
     *
     * Bare numbers are allowed through, but only whole ones in the Excel
     * serial range: a statement exported with the date column formatted as
     * General arrives as 45903 rather than a date, and rejecting every numeric
     * would leave those columns unmapped. A figure with a decimal point is
     * money, never a date, which is what keeps "1200.00" out of this.
     */
    public function looksLikeDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (is_numeric($value)) {
            return ! str_contains($value, '.')
                && (float) $value > 20000
                && (float) $value < 80000;
        }

        return $this->parseDate($value) !== null;
    }

    public function looksLikeMoney(string $value): bool
    {
        $cleaned = str_replace([',', ' ', "\u{00A0}"], '', $value);

        return $cleaned !== '' && is_numeric($cleaned);
    }

    /**
     * Read a date the way a bank wrote it.
     *
     * Day-first formats are tried before month-first because every bank this
     * system talks to is Ethiopian and writes 03/09/2026 for the third of
     * September. Letting strtotime decide would read that as the ninth of
     * March, silently, and put a week of transactions in the wrong month.
     */
    public function parseDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $formats = [
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
            'd.m.Y H:i:s', 'd.m.Y',
            'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d',
            'Y/m/d H:i:s', 'Y/m/d',
            'd M Y H:i:s', 'd M Y', 'd-M-Y', 'd-M-y',
            'M d, Y H:i:s', 'M d, Y',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);

                // createFromFormat fills missing parts from "now" and accepts
                // sloppy input, so the round-trip check is what rejects a
                // value that only half-matched.
                if ($parsed && $parsed->format($format) === $value) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // Wrong format for this value; try the next.
            }
        }

        // Excel keeps dates as days since 1899-12-30. A statement exported
        // with the column formatted as General arrives as 45900 rather than a
        // date, and without this every row would be skipped as undated.
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value);
        }

        return null;
    }

    /** Strip everything that varies between spellings of the same header. */
    protected function squash(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($value))) ?? '';
    }

    /**
     * Is this mapping usable?
     *
     * @param  array<string, int>  $map
     * @return array<int, string>  Missing required fields, empty when fine.
     */
    public function missingRequired(array $map): array
    {
        return array_values(array_filter(
            self::REQUIRED,
            fn (string $field): bool => ! isset($map[$field])
        ));
    }

    /** @return array<string, string> Field key => human label. */
    public static function fieldLabels(): array
    {
        return collect(array_keys(self::ALIASES))
            ->mapWithKeys(fn (string $field): array => [
                $field => __('filament.reconciliation.field_'.$field),
            ])
            ->all();
    }
}

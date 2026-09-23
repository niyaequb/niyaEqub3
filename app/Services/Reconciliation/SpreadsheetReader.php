<?php

namespace App\Services\Reconciliation;

use RuntimeException;

/**
 * Reads a bank's export into rows, whatever shape it arrives in.
 *
 * WHY THIS IS HAND-WRITTEN RATHER THAN A PACKAGE
 *
 * The obvious answer is PhpSpreadsheet. It is also a large dependency, pulled
 * in to do one thing: turn a sheet of text into an array of arrays. Banks send
 * small statements — a few thousand rows at most — with no formulas, no
 * styling worth reading and no formats beyond dates and numbers. The subset
 * that matters is small enough to write, and writing it keeps the import
 * working on a server where composer cannot reach the internet.
 *
 * WHAT IT HANDLES, AND WHY EACH ONE IS HERE
 *
 *   CSV / TSV        the common case, and the one every bank can produce.
 *   XLSX             what they send when nobody asked for CSV. A zip of XML;
 *                    ZipArchive and SimpleXML are both standard.
 *   UTF-16 / BOMs    Windows exports routinely arrive as UTF-16LE with a BOM,
 *                    which fgetcsv reads as one column of mojibake.
 *   ; and tab        European locales delimit with semicolons because the
 *                    comma is their decimal separator.
 *
 * WHAT IT DOES NOT HANDLE
 *
 *   .xls (BIFF)      the pre-2007 binary format. Genuinely hard, genuinely
 *                    rare, and the error message says to re-save as CSV rather
 *                    than failing with something cryptic.
 */
class SpreadsheetReader
{
    /** Rows read before we stop. A bank statement above this is not a statement. */
    public const MAX_ROWS = 50000;

    /**
     * Read a file into rows of strings.
     *
     * @return array<int, array<int, string>>
     *
     * @throws RuntimeException
     */
    public function read(string $path, ?string $originalName = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException(__('filament.reconciliation.import_unreadable'));
        }

        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx', 'xlsm' => $this->readXlsx($path),
            'xls' => throw new RuntimeException(__('filament.reconciliation.import_old_excel')),
            default => $this->readDelimited($path),
        };
    }

    // -----------------------------------------------------------------
    // Delimited text
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<int, string>>
     */
    protected function readDelimited(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(__('filament.reconciliation.import_unreadable'));
        }

        $contents = $this->toUtf8($contents);
        $delimiter = $this->sniffDelimiter($contents);

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue; // a blank line, which fgetcsv reports as one null cell
            }

            $rows[] = array_map(
                fn ($cell): string => trim((string) $cell),
                $row
            );

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        fclose($handle);

        return $this->dropEmptyRows($rows);
    }

    /**
     * Normalise whatever encoding the export arrived in.
     *
     * The UTF-16 case is not exotic: exporting from Excel on a Windows machine
     * with "Unicode Text" selected produces UTF-16LE with a byte-order mark,
     * and read as UTF-8 that looks like a single column of null-separated
     * characters — which reads on screen as "the file has one column" rather
     * than as an encoding problem.
     */
    protected function toUtf8(string $contents): string
    {
        // UTF-16 byte-order marks.
        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            $encoding = str_starts_with($contents, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $converted = mb_convert_encoding(substr($contents, 2), 'UTF-8', $encoding);

            return is_string($converted) ? $converted : $contents;
        }

        // UTF-8 BOM: harmless except that it glues itself to the first header
        // name, so "Date" becomes "\u{FEFF}Date" and never matches.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');

            return is_string($converted) ? $converted : $contents;
        }

        return $contents;
    }

    /**
     * Guess the delimiter from the first few lines.
     *
     * Counted across several lines rather than one, because a header row can
     * easily contain a comma inside a quoted column name and win the count on
     * its own.
     */
    protected function sniffDelimiter(string $contents): string
    {
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $contents) ?: [], 0, 10));

        $counts = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            '|' => substr_count($sample, '|'),
        ];

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    // -----------------------------------------------------------------
    // XLSX
    // -----------------------------------------------------------------

    /**
     * An .xlsx is a zip of XML. The two parts that matter are the first
     * worksheet and the shared-strings table — Excel stores every repeated
     * string once and references it by index, so a sheet read without
     * sharedStrings.xml comes out as a grid of numbers.
     *
     * @return array<int, array<int, string>>
     */
    protected function readXlsx(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException(__('filament.reconciliation.import_no_zip'));
        }

        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('filament.reconciliation.import_bad_xlsx'));
        }

        try {
            $strings = $this->sharedStrings($zip);
            $sheetXml = $this->firstSheet($zip);
        } finally {
            $zip->close();
        }

        return $this->dropEmptyRows($this->parseSheet($sheetXml, $strings));
    }

    /** @return array<int, string> */
    protected function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return []; // a sheet of nothing but numbers is legal
        }

        $doc = @simplexml_load_string($xml);

        if (! $doc) {
            return [];
        }

        $strings = [];

        foreach ($doc->si as $item) {
            // Rich text splits a single cell across several <t> runs; joining
            // them is what turns "Dashen" + " Bank" back into "Dashen Bank".
            $text = '';

            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $run) {
                $text .= (string) $run;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    protected function firstSheet(\ZipArchive $zip): string
    {
        // Sheet order in the workbook is not the same as file order in the
        // zip, so the relationship is read rather than sheet1.xml assumed.
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $target = 'xl/worksheets/sheet1.xml';

        if ($workbook !== false && $rels !== false) {
            $wb = @simplexml_load_string($workbook);
            $rel = @simplexml_load_string($rels);

            if ($wb && $rel) {
                $first = $wb->sheets->sheet[0] ?? null;
                $id = $first ? (string) $first->attributes('r', true)->id : null;

                if ($id) {
                    foreach ($rel->Relationship as $relationship) {
                        if ((string) $relationship['Id'] === $id) {
                            $target = 'xl/'.ltrim((string) $relationship['Target'], '/');
                            break;
                        }
                    }
                }
            }
        }

        $sheet = $zip->getFromName($target) ?: $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheet === false) {
            throw new RuntimeException(__('filament.reconciliation.import_no_sheet'));
        }

        return $sheet;
    }

    /**
     * @param  array<int, string>  $strings
     * @return array<int, array<int, string>>
     */
    protected function parseSheet(string $xml, array $strings): array
    {
        $doc = @simplexml_load_string($xml);

        if (! $doc) {
            throw new RuntimeException(__('filament.reconciliation.import_bad_xlsx'));
        }

        $rows = [];

        foreach ($doc->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                // Excel omits empty cells entirely, so C3 can follow A3 with
                // nothing between them. Without reading the column letter the
                // row silently shifts left and every value lands in the wrong
                // field — the kind of bug that produces a plausible-looking
                // statement with the amounts in the date column.
                $index = $this->columnIndex((string) $cell['r']);
                $cells[$index] = $this->cellValue($cell, $strings);
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            // Fill the gaps so every row is a dense, positional array.
            $width = max(array_keys($cells)) + 1;
            $dense = [];

            for ($i = 0; $i < $width; $i++) {
                $dense[$i] = $cells[$i] ?? '';
            }

            $rows[] = $dense;

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /** @param  array<int, string>  $strings */
    protected function cellValue(\SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $index = (int) $cell->v;

            return $strings[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $text = '';

            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $run) {
                $text .= (string) $run;
            }

            return trim($text);
        }

        return trim((string) $cell->v);
    }

    /**
     * "BC12" is column 55. Letters only, base-26 with no zero.
     */
    protected function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($reference), $matches);

        if (! isset($matches[1])) {
            return 0;
        }

        $index = 0;

        foreach (str_split($matches[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    /**
     * Trim leading and trailing blank rows.
     *
     * Bank exports routinely carry a title block above the table and a totals
     * line or a disclaimer below it. Blank rows in the middle are kept, since
     * they can be the boundary between an opening balance section and the
     * transactions, and the header finder needs to see that.
     *
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<int, string>>
     */
    protected function dropEmptyRows(array $rows): array
    {
        $isBlank = fn (array $row): bool => $row === []
            || collect($row)->every(fn ($cell): bool => trim((string) $cell) === '');

        while ($rows !== [] && $isBlank($rows[0])) {
            array_shift($rows);
        }

        while ($rows !== [] && $isBlank($rows[array_key_last($rows)])) {
            array_pop($rows);
        }

        return array_values($rows);
    }
}

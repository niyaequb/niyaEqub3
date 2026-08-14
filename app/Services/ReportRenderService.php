<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Turns a report array into something printable.
 *
 * Four output shapes, one template: A4/A5 PDF via dompdf, standalone HTML for
 * the browser print dialog, ESC/POS bytes for a thermal till printer, and CSV
 * for spreadsheets. The blade is shared so the paper copy and the PDF cannot
 * drift apart.
 */
class ReportRenderService
{
    /**
     * Physical page sizes in PostScript points (1pt = 1/72in).
     * Thermal rolls are continuous, so the height is deliberately generous and
     * dompdf paginates rather than clipping.
     */
    protected const PAPER_SIZES = [
        'a4' => 'a4',
        'a5' => 'a5',
        'thermal80' => [0, 0, 226.77, 1700], // 80mm roll, ~72mm printable
        'thermal58' => [0, 0, 164.41, 1700], // 58mm roll, ~48mm printable
    ];

    /** Where an Ethiopic font may be sitting, in order of preference. */
    protected const FONT_CANDIDATES = [
        'app/public/fonts/NotoSansEthiopic-Regular.ttf',
        'app/fonts/NotoSansEthiopic-Regular.ttf',
        'fonts/NotoSansEthiopic-Regular.ttf',
    ];

    /**
     * Render the printable HTML.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $options
     */
    public function html(array $report, array $options = []): string
    {
        $paper = $this->normalizePaper($options['paper'] ?? 'a4');
        [$fontFace, $fontFamily] = $this->resolveFont();

        return View::make('reports.equb-payment-report', [
            'report' => $report,
            'paper' => $paper,
            'fontFace' => $fontFace,
            'fontFamily' => $fontFamily,
            'brand' => [
                'name' => config('printing.brand.name') ?: config('app.name', 'Niya Ekub'),
                'tagline' => config('printing.brand.tagline') ?: __('filament.equb_report.payment_report'),
            ],
            'includeDetails' => $options['include_details'] ?? true,
            'showSignatures' => $options['show_signatures'] ?? true,
            'generatedBy' => $options['generated_by'] ?? null,
        ])->render();
    }

    /**
     * Render a PDF and return the raw bytes.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $options
     */
    public function pdf(array $report, array $options = []): string
    {
        $paper = $this->normalizePaper($options['paper'] ?? 'a4');
        $html = $this->html($report, $options);

        $dompdfOptions = new Options;
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        // Off by default: the template embeds its font as base64 and pulls no
        // external assets, so allowing remote fetches would only add a way for
        // a slow third-party host to hang report generation.
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');
        $dompdfOptions->set('chroot', [base_path(), storage_path()]);

        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');

        $size = self::PAPER_SIZES[$paper] ?? 'a4';
        // Wide reports read better landscape; a till roll must stay portrait.
        $orientation = (str_starts_with($paper, 'thermal') || ($options['orientation'] ?? null) === 'portrait')
            ? 'portrait'
            : 'landscape';

        $dompdf->setPaper($size, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * ESC/POS byte stream for a thermal receipt printer.
     *
     * Deliberately hand-rolled rather than pulled from a library: the output
     * is a short summary slip, and the commands used here (init, align,
     * emphasis, feed, cut) are the universally supported subset that works on
     * Epson, Xprinter, Rongta and the generic clones common in Ethiopian
     * shops.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $options
     */
    public function escpos(array $report, array $options = []): string
    {
        $width = ($options['paper'] ?? 'thermal80') === 'thermal58' ? 32 : 42;

        $ESC = "\x1B";
        $GS = "\x1D";

        $init = $ESC.'@';
        $alignCenter = $ESC.'a'.chr(1);
        $alignLeft = $ESC.'a'.chr(0);
        $boldOn = $ESC.'E'.chr(1);
        $boldOff = $ESC.'E'.chr(0);
        $doubleOn = $GS.'!'.chr(0x11);  // double width + height
        $doubleOff = $GS.'!'.chr(0x00);
        $cut = $GS.'V'.chr(66).chr(3);

        $meta = $report['meta'];
        $summary = $report['summary'];

        $line = str_repeat('-', $width)."\n";
        $money = fn ($v) => number_format((float) $v, 2);

        // Two-column row that keeps the total flush right on a fixed-width roll.
        $row = function (string $left, string $right) use ($width): string {
            $right = ' '.$right;
            $space = max(1, $width - mb_strlen($left) - mb_strlen($right));

            return mb_substr($left, 0, $width - mb_strlen($right)).str_repeat(' ', $space).$right."\n";
        };

        $out = $init;
        $out .= $alignCenter.$doubleOn.$boldOn;
        $out .= $this->ascii(config('printing.brand.name') ?: config('app.name', 'NIYA EKUB'))."\n";
        $out .= $doubleOff;
        $out .= $this->ascii(mb_strtoupper($meta['period_label'].' '.__('filament.equb_report.report')))."\n";
        $out .= $boldOff;
        $out .= $this->ascii($meta['range_label'])."\n";
        $out .= $alignLeft.$line;

        $out .= $row(__('filament.equb_report.collected'), $money($summary['collected']));
        $out .= $row(__('filament.equb_report.outstanding'), $money($summary['outstanding']));
        $out .= $row(__('filament.equb_report.transactions'), (string) number_format($summary['transactions']));
        $out .= $row(__('filament.equb_report.settled'), (string) number_format($summary['paid_count']));
        $out .= $row(__('filament.equb_report.failed'), (string) number_format($summary['failed_count']));
        $out .= $row(__('filament.equb_report.paying_members'), (string) number_format($summary['members']));
        $out .= $row(__('filament.equb_report.average_payment'), $money($summary['average_payment']));
        $out .= $line;

        $out .= $boldOn.$this->ascii(__('filament.equb_report.by_type'))."\n".$boldOff;
        foreach ($report['by_type'] as $t) {
            if ($t['transactions'] === 0) {
                continue;
            }
            $out .= $row($this->ascii($t['label']).' ('.$t['transactions'].')', $money($t['collected']));
        }
        $out .= $line;

        if ($report['by_group_equb']->isNotEmpty()) {
            $out .= $boldOn.$this->ascii(__('filament.equb_report.by_group_equb'))."\n".$boldOff;
            foreach ($report['by_group_equb']->take(8) as $g) {
                $out .= $row($this->ascii($g['label']), $money($g['collected']));
            }
            $out .= $line;
        }

        if ($report['by_group']->isNotEmpty()) {
            $out .= $boldOn.$this->ascii(__('filament.equb_report.by_group'))."\n".$boldOff;
            foreach ($report['by_group']->take(8) as $g) {
                $out .= $row($this->ascii($g['label']), $money($g['collected']));
            }
            $out .= $line;
        }

        $out .= $boldOn.$doubleOn;
        $out .= $row(__('filament.equb_report.total'), $money($summary['collected']));
        $out .= $doubleOff.$boldOff;
        $out .= $line;

        if (! empty($meta['filters'])) {
            $out .= $this->ascii(__('filament.equb_report.filters_applied').':')."\n";
            foreach ($meta['filters'] as $f) {
                $out .= $this->ascii(wordwrap($f, $width, "\n", true))."\n";
            }
            $out .= $line;
        }

        $out .= $alignCenter;
        $out .= Carbon::parse($meta['generated_at'])->format('d M Y H:i')."\n";
        $out .= $this->ascii(__('filament.equb_report.system_generated'))."\n";
        $out .= "\n\n\n";
        $out .= $cut;

        return $out;
    }

    /**
     * Streamed CSV of every matching transaction.
     *
     * Streams rather than buffers because an unfiltered year of payments would
     * otherwise be assembled in memory before the first byte reaches the
     * browser.
     *
     * @param  array<string, mixed>  $filters
     */
    public function csvResponse(array $filters, EqubReportService $reports): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $normalized = $reports->normalizeFilters($filters);
        $filename = 'equb_payments_'
            .$normalized['start']->format('Ymd').'_'
            .$normalized['end']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($filters, $reports): void {
            $handle = fopen('php://output', 'w');

            // BOM so Excel opens Amharic member names correctly instead of
            // showing mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'ID',
                __('filament.equb_report.member'),
                __('filament.equb_report.phone'),
                __('filament.equb_report.equb_group'),
                __('filament.equb_report.group_equb'),
                __('filament.equb_report.package'),
                __('filament.equb_report.agent'),
                __('filament.equb_report.date'),
                __('filament.equb_report.method'),
                __('filament.equb_report.status'),
                __('filament.equb_report.reference'),
                __('filament.equb_report.amount').' (ETB)',
            ]);

            $reports->eachDetailRow($filters, function ($row) use ($handle): void {
                fputcsv($handle, [
                    $row->id,
                    $row->member_name,
                    $row->member_phone,
                    $row->group_name,
                    $row->group_equb_name,
                    $row->package_name,
                    $row->agent_name,
                    Carbon::parse($row->payment_date)->format('Y-m-d H:i:s'),
                    $row->payment_method,
                    $row->status,
                    $row->reference,
                    number_format((float) $row->amount, 2, '.', ''),
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Locate an Ethiopic font and return it as an inline @font-face plus the
     * font-family stack to use.
     *
     * Amharic group and package names are normal in this system, and dompdf's
     * built-in DejaVu has no Ethiopic glyphs — without a real font those cells
     * print as empty boxes. Degrades quietly to DejaVu when no font is
     * installed so a missing file never blocks a report.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveFont(): array
    {
        foreach (self::FONT_CANDIDATES as $relative) {
            $path = storage_path($relative);

            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            try {
                $base64 = base64_encode((string) file_get_contents($path));
            } catch (\Throwable $e) {
                Log::warning('[Report] Could not read Ethiopic font', ['path' => $path, 'error' => $e->getMessage()]);

                continue;
            }

            $face = "@font-face { font-family: 'Noto Sans Ethiopic'; "
                ."src: url(data:font/truetype;charset=utf-8;base64,{$base64}) format('truetype'); "
                .'font-weight: normal; font-style: normal; }';

            return [$face, "'Noto Sans Ethiopic', 'DejaVu Sans', sans-serif"];
        }

        return ['', "'DejaVu Sans', sans-serif"];
    }

    public function normalizePaper(?string $paper): string
    {
        return array_key_exists($paper, self::PAPER_SIZES) ? $paper : 'a4';
    }

    /**
     * Best-effort transliteration for ESC/POS.
     *
     * Thermal printers run a single-byte code page, so Amharic simply cannot
     * be sent as UTF-8. Rather than emit garbage we strip to ASCII; the full
     * Amharic text stays available on the PDF and on screen.
     */
    protected function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        }

        return trim($converted) !== '' ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
    }

    /** @return array<string, string> */
    public static function paperOptions(): array
    {
        return [
            'a4' => 'A4 (210 × 297 mm)',
            'a5' => 'A5 (148 × 210 mm)',
            'thermal80' => __('filament.equb_report.thermal_80'),
            'thermal58' => __('filament.equb_report.thermal_58'),
        ];
    }
}

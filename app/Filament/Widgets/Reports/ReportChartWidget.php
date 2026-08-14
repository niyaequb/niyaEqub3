<?php

namespace App\Filament\Widgets\Reports;

use App\Services\EqubReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Auth;

/**
 * Shared plumbing for the report page charts.
 *
 * Each chart is a Livewire component that receives the report page's filter
 * state through $pageFilters and rebuilds itself when that state changes. The
 * report itself is memoised per request so four charts on one page issue one
 * set of queries rather than four.
 *
 * Abstract, so Filament's widget discovery skips it — only the concrete
 * charts below register with the panel.
 */
abstract class ReportChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    /** @var array<string, mixed>|null */
    protected ?array $report = null;

    /**
     * Colours are literal rather than pulled from the Tailwind palette: the
     * chart canvas is drawn by Chart.js outside the CSS cascade, so utility
     * classes would not reach it.
     */
    protected const COLOR_COLLECTED = '#16a34a';

    protected const COLOR_OUTSTANDING = '#f59e0b';

    protected const COLOR_FAILED = '#dc2626';

    protected const COLOR_PRIMARY = '#d97706';

    protected const PALETTE = [
        '#d97706', '#0ea5e9', '#16a34a', '#a855f7', '#f43f5e',
        '#14b8a6', '#f59e0b', '#6366f1', '#84cc16', '#ec4899',
    ];

    /** @return array<string, mixed> */
    protected function report(): array
    {
        return $this->report ??= app(EqubReportService::class)->build([
            ...($this->pageFilters ?? []),
            // Charts never show the line-by-line table, and pulling 500 rows
            // per chart would quadruple the page's query cost for nothing.
            'include_details' => false,
        ]);
    }

    public static function canView(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.equb-reports')
        );
    }

    /** Formats an ETB axis tick without dragging in a JS locale library. */
    protected function moneyAxisCallback(): string
    {
        return <<<'JS'
            function (value) {
                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                return value;
            }
        JS;
    }
}

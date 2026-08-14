<?php

namespace App\Filament\Widgets\Reports;

use Filament\Support\RawJs;

/**
 * Group Equb money against single (platform) Equb money.
 *
 * This replaces the old payment-method doughnut. Chapa-versus-cash is a
 * processing detail; Group Equb versus single Equb is a question about how the
 * business is actually growing, and it is the split the reports are read for.
 */
class EqubTypeChart extends ReportChartWidget
{
    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '240px';

    public function getHeading(): ?string
    {
        return __('filament.equb_report.by_type');
    }

    public function getDescription(): ?string
    {
        return __('filament.equb_report.by_type_description');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = collect($this->report()['by_type'])
            ->filter(fn ($r) => $r['collected'] > 0)
            ->values();

        if ($rows->isEmpty()) {
            return [
                'datasets' => [[
                    'data' => [1],
                    'backgroundColor' => ['#e5e7eb'],
                    'borderWidth' => 0,
                ]],
                'labels' => [__('filament.equb_report.no_data')],
            ];
        }

        // Fixed colours per type so the amber slice always means Group Equb,
        // wherever it happens to fall in the ordering.
        $colors = [
            'group_equb' => self::COLOR_PRIMARY,
            'individual' => '#0ea5e9',
        ];

        return [
            'datasets' => [[
                'data' => $rows->pluck('collected')->all(),
                'backgroundColor' => $rows->map(fn ($r) => $colors[$r['key']] ?? '#94a3b8')->all(),
                'borderWidth' => 0,
                'hoverOffset' => 6,
            ]],
            'labels' => $rows->map(fn ($r) => $r['label'].' ('.$r['transactions'].')')->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.parsed || 0;
                                var total = context.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var share = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                return context.label + ': ' +
                                    Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 }) +
                                    ' ETB (' + share + '%)';
                            }
                        }
                    }
                }
            }
        JS);
    }
}

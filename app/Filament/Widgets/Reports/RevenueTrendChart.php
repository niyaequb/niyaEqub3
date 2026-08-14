<?php

namespace App\Filament\Widgets\Reports;

use Filament\Support\RawJs;

/**
 * Money collected over the reporting window, bucketed by hour, day or month
 * depending on how wide that window is.
 *
 * Collected and outstanding sit side by side rather than stacked: the useful
 * question on a Monday morning is "how much came in versus how much is still
 * owed", and a stacked bar makes the second figure hard to read off.
 */
class RevenueTrendChart extends ReportChartWidget
{
    protected ?string $maxHeight = '300px';

    public function getHeading(): ?string
    {
        return __('filament.equb_report.collection_trend');
    }

    public function getDescription(): ?string
    {
        $report = $this->report();

        return __('filament.equb_report.trend_description', [
            'range' => $report['meta']['range_label'],
            'unit' => match ($report['meta']['granularity']) {
                'hour' => __('filament.equb_report.per_hour'),
                'month' => __('filament.equb_report.per_month'),
                default => __('filament.equb_report.per_day'),
            },
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = collect($this->report()['series']);

        return [
            'datasets' => [
                [
                    'label' => __('filament.equb_report.collected').' (ETB)',
                    'data' => $series->pluck('collected')->all(),
                    'backgroundColor' => self::COLOR_COLLECTED,
                    'borderColor' => self::COLOR_COLLECTED,
                    'borderRadius' => 3,
                    'order' => 2,
                ],
                [
                    'label' => __('filament.equb_report.outstanding').' (ETB)',
                    'data' => $series->pluck('outstanding')->all(),
                    'backgroundColor' => self::COLOR_OUTSTANDING,
                    'borderColor' => self::COLOR_OUTSTANDING,
                    'borderRadius' => 3,
                    'order' => 3,
                ],
                [
                    // Transaction volume moves on a completely different scale
                    // from birr, so it gets its own hidden axis rather than
                    // being flattened against the money bars.
                    'type' => 'line',
                    'label' => __('filament.equb_report.transactions'),
                    'data' => $series->pluck('transactions')->all(),
                    'borderColor' => self::COLOR_PRIMARY,
                    'backgroundColor' => 'rgba(217, 119, 6, 0.08)',
                    'borderWidth' => 2,
                    'pointRadius' => 2,
                    'tension' => 0.35,
                    'yAxisID' => 'volume',
                    'order' => 1,
                ],
            ],
            'labels' => $series->pluck('label')->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.parsed.y ?? 0;
                                if (context.dataset.yAxisID === 'volume') {
                                    return context.dataset.label + ': ' + value;
                                }
                                return context.dataset.label + ': ' +
                                    Number(value).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' ETB';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                                return value;
                            }
                        }
                    },
                    volume: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { precision: 0 }
                    }
                }
            }
        JS);
    }
}

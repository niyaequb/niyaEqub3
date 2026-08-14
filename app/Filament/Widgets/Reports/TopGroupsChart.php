<?php

namespace App\Filament\Widgets\Reports;

use Filament\Support\RawJs;

/**
 * The Equb groups bringing in the most money in this window.
 *
 * Horizontal bars because group names are long ("Al Nur Daily", and Amharic
 * names longer still) — on a vertical axis they would be rotated and unreadable.
 */
class TopGroupsChart extends ReportChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): ?string
    {
        return __('filament.equb_report.top_groups');
    }

    public function getDescription(): ?string
    {
        return __('filament.equb_report.top_groups_description');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = collect($this->report()['by_group'])->take(8);

        if ($rows->isEmpty()) {
            return [
                'datasets' => [['data' => [], 'backgroundColor' => self::COLOR_PRIMARY]],
                'labels' => [],
            ];
        }

        return [
            'datasets' => [
                [
                    'label' => __('filament.equb_report.collected').' (ETB)',
                    'data' => $rows->pluck('collected')->all(),
                    'backgroundColor' => self::COLOR_COLLECTED,
                    'borderRadius' => 3,
                ],
                [
                    'label' => __('filament.equb_report.outstanding').' (ETB)',
                    'data' => $rows->pluck('outstanding')->all(),
                    'backgroundColor' => self::COLOR_OUTSTANDING,
                    'borderRadius' => 3,
                ],
            ],
            // Long names are clipped rather than allowed to eat half the
            // canvas; the full name is in the table underneath.
            'labels' => $rows->map(fn ($r) => mb_strimwidth($r['label'], 0, 28, '…'))->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' +
                                    Number(context.parsed.x ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' ETB';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                                return value;
                            }
                        }
                    },
                    y: { grid: { display: false } }
                }
            }
        JS);
    }
}

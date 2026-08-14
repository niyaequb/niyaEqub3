<?php

namespace App\Filament\Widgets\Reports;

use Filament\Support\RawJs;

/**
 * Paid / pending / failed split by value.
 *
 * Deliberately by amount rather than by count: fifty small settled payments
 * and one large unpaid one is a very different position from the reverse, and
 * only the money view shows that.
 */
class PaymentStatusChart extends ReportChartWidget
{
    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '240px';

    public function getHeading(): ?string
    {
        return __('filament.equb_report.by_status');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = collect($this->report()['by_status'])->filter(fn ($r) => $r['amount'] > 0)->values();

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

        // Fixed colours per status so green always means settled, wherever the
        // slice happens to fall in the ordering.
        $colors = [
            'paid' => self::COLOR_COLLECTED,
            'pending' => self::COLOR_OUTSTANDING,
            'failed' => self::COLOR_FAILED,
        ];

        return [
            'datasets' => [[
                'data' => $rows->pluck('amount')->all(),
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

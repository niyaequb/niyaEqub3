<?php

namespace App\Filament\Pages\Reports;

/**
 * What a typical contribution looks like.
 *
 * An average on its own hides more than it shows: one 200,000 ETB payment
 * among four hundred 30 ETB ones moves it by fifty and tells nobody anything.
 * So the page pairs the mean with the smallest and largest contributions in
 * the window, and with the per-Equb averages underneath — where the spread
 * between packages is usually the real story.
 */
class AveragePaymentReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/average-payment';

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.average_payment');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.average_payment_title');
    }

    public function explainer(): ?string
    {
        return __('filament.equb_report.average_payment_explainer');
    }

    public function tiles(): array
    {
        $report = $this->report();
        $summary = $report['summary'];
        $previous = $report['previous'];

        // Straight off the detail rows rather than as another query: the list
        // is already loaded and capped, and the extremes it holds are the ones
        // a reader can click through to and check.
        $settled = $report['details']->where('status', 'paid');
        $amounts = $settled->pluck('amount');

        return [
            [
                'label' => __('filament.equb_report.average_payment'),
                'value' => number_format($summary['average_payment'], 2).' ETB',
                'sub' => __('filament.equb_report.vs_previous').': '.number_format($previous['average_payment'], 2).' ETB',
                'accent' => 'primary',
                'icon' => 'heroicon-o-calculator',
            ],
            [
                'label' => __('filament.equb_report.smallest'),
                'value' => $amounts->isNotEmpty() ? number_format($amounts->min(), 2).' ETB' : '—',
                'sub' => __('filament.equb_report.in_this_window'),
                'accent' => 'gray',
                'icon' => 'heroicon-o-arrow-trending-down',
            ],
            [
                'label' => __('filament.equb_report.largest'),
                'value' => $amounts->isNotEmpty() ? number_format($amounts->max(), 2).' ETB' : '—',
                'sub' => __('filament.equb_report.in_this_window'),
                'accent' => 'gray',
                'icon' => 'heroicon-o-arrow-trending-up',
            ],
            [
                'label' => __('filament.equb_report.settled_payments'),
                'value' => number_format($summary['paid_count']),
                'sub' => __('filament.equb_report.totalling', ['amount' => number_format($summary['collected'], 2)]),
                'accent' => 'success',
                'icon' => 'heroicon-o-check-circle',
            ],
        ];
    }

    public function panels(): array
    {
        $report = $this->report();

        $byGroup = $report['by_group']
            ->map(fn (array $row): array => [
                ...$row,
                'average' => $row['transactions'] > 0
                    ? round($row['collected'] / $row['transactions'], 2)
                    : 0.0,
                'per_member' => $row['members'] > 0
                    ? round($row['collected'] / $row['members'], 2)
                    : 0.0,
            ])
            ->all();

        $byPackage = $report['by_package']
            ->map(fn (array $row): array => [
                ...$row,
                'average' => $row['transactions'] > 0
                    ? round($row['collected'] / $row['transactions'], 2)
                    : 0.0,
            ])
            ->all();

        return [
            [
                'heading' => __('filament.equb_report.average_by_group'),
                'description' => __('filament.equb_report.average_by_group_description'),
                'icon' => 'heroicon-o-user-group',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.equb_group'), 'strong' => true],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'average', 'label' => __('filament.equb_report.per_payment'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'per_member', 'label' => __('filament.equb_report.per_member'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $byGroup,
            ],
            [
                'heading' => __('filament.equb_report.average_by_package'),
                'icon' => 'heroicon-o-archive-box',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.package'), 'strong' => true],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'average', 'label' => __('filament.equb_report.per_payment'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $byPackage,
            ],
        ];
    }

    public function chart(): ?array
    {
        $rows = array_map(fn (array $bucket): array => [
            ...$bucket,
            'average' => $bucket['paid_count'] > 0
                ? round($bucket['collected'] / $bucket['paid_count'], 2)
                : 0.0,
        ], $this->report()['series']);

        return [
            'label' => __('filament.equb_report.average_trend'),
            'series' => ['average' => __('filament.equb_report.average_payment')],
            'rows' => $rows,
        ];
    }
}

<?php

namespace App\Filament\Pages\Reports;

use Illuminate\Support\Carbon;

/**
 * Every payment attempt in the window, settled or not.
 *
 * Counts, not amounts. The question behind this page is operational rather
 * than financial — how many attempts were made, how many of them worked, and
 * what happened to the rest. A failure rate creeping up is the first sign of
 * a bank integration going wrong, and it is invisible on a page that only
 * shows money.
 */
class TransactionsReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/transactions';

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.transactions');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.transactions_title');
    }

    public function explainer(): ?string
    {
        return __('filament.equb_report.transactions_explainer');
    }

    public function tiles(): array
    {
        $summary = $this->report()['summary'];
        $previous = $this->report()['previous'];

        return [
            [
                'label' => __('filament.equb_report.transactions'),
                'value' => number_format($summary['transactions']),
                'sub' => __('filament.equb_report.vs_previous').': '.number_format($previous['transactions']),
                'accent' => 'gray',
                'icon' => 'heroicon-o-list-bullet',
            ],
            [
                'label' => __('filament.equb_report.settled'),
                'value' => number_format($summary['paid_count']),
                'sub' => __('filament.equb_report.success_rate', ['rate' => number_format($summary['success_rate'], 1)]),
                'accent' => 'success',
                'icon' => 'heroicon-o-check-circle',
            ],
            [
                'label' => __('filament.equb_report.pending'),
                'value' => number_format($summary['pending_count']),
                'sub' => number_format($summary['outstanding'], 2).' ETB',
                'accent' => $summary['pending_count'] > 0 ? 'warning' : 'gray',
                'icon' => 'heroicon-o-clock',
            ],
            [
                'label' => __('filament.equb_report.failed'),
                'value' => number_format($summary['failed_count']),
                'sub' => number_format($summary['failed_amount'], 2).' ETB',
                'accent' => $summary['failed_count'] > 0 ? 'danger' : 'gray',
                'icon' => 'heroicon-o-x-circle',
            ],
        ];
    }

    public function chart(): ?array
    {
        return [
            'label' => __('filament.equb_report.transaction_trend'),
            'series' => ['transactions' => __('filament.equb_report.transactions')],
            'rows' => $this->report()['series'],
        ];
    }

    public function panels(): array
    {
        $report = $this->report();

        $details = $report['details']->map(fn (array $row): array => [
            ...$row,
            'when' => Carbon::parse($row['payment_date'])->format('d/m/Y H:i'),
            'status_label' => ucfirst($row['status']),
            'status_label_color' => match ($row['status']) {
                'paid' => 'success',
                'pending' => 'warning',
                'failed' => 'danger',
                default => 'gray',
            },
            'route' => $row['group_equb_name'] ?: __('filament.equb_report.individual_equb'),
        ])->all();

        return [
            [
                'heading' => __('filament.equb_report.by_status'),
                'icon' => 'heroicon-o-flag',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.status'), 'strong' => true],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'amount', 'label' => __('filament.equb_report.amount'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'share', 'label' => __('filament.equb_report.share'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $report['by_status']->all(),
            ],
            [
                'heading' => __('filament.equb_report.by_type'),
                'description' => __('filament.equb_report.by_type_description'),
                'icon' => 'heroicon-o-arrows-right-left',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.route'), 'strong' => true],
                    ['key' => 'members', 'label' => __('filament.equb_report.members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $report['by_type']->all(),
            ],
            [
                'heading' => __('filament.equb_report.transaction_detail'),
                'description' => trans_choice('filament.equb_report.showing_transactions', $report['details']->count(), [
                    'count' => number_format($report['details']->count()),
                    'total' => number_format($report['summary']['transactions']),
                ]),
                'icon' => 'heroicon-o-document-text',
                'collapsible' => true,
                'columns' => [
                    ['key' => 'when', 'label' => __('filament.equb_report.date')],
                    ['key' => 'member_name', 'label' => __('filament.equb_report.member'), 'strong' => true, 'sub' => 'member_phone'],
                    ['key' => 'group_name', 'label' => __('filament.equb_report.equb_group'), 'sub' => 'route'],
                    ['key' => 'payment_method', 'label' => __('filament.equb_report.method')],
                    ['key' => 'status_label', 'label' => __('filament.equb_report.status'), 'type' => 'badge'],
                    ['key' => 'reference', 'label' => __('filament.equb_report.reference')],
                    ['key' => 'amount', 'label' => __('filament.equb_report.amount'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $details,
                'empty' => __('filament.equb_report.no_transactions'),
                'footnote' => $report['summary']['transactions'] > $report['details']->count()
                    ? __('filament.equb_report.detail_truncated')
                    : null,
            ],
        ];
    }
}

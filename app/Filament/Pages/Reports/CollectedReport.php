<?php

namespace App\Filament\Pages\Reports;

use App\Services\Equb\EqubRules;

/**
 * Money in, and where it came from.
 *
 * The heading says "collected" and never "revenue", and the tiles say so
 * twice: the members' share and the company's fee sit next to the total, so
 * the page cannot be read as a statement of income. Almost all of this money
 * is on its way back out to whoever wins a round.
 */
class CollectedReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/collected';

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.collected');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.collected_title');
    }

    public function explainer(): ?string
    {
        return __('filament.equb_report.collected_explainer', [
            'rate' => app(EqubRules::class)->feePercent(),
        ]);
    }

    public function tiles(): array
    {
        $report = $this->report();
        $summary = $report['summary'];
        $previous = $report['previous'];
        $profit = $report['profit'];

        return [
            [
                'label' => __('filament.equb_report.collected'),
                'value' => number_format($summary['collected'], 2).' ETB',
                'sub' => __('filament.equb_report.vs_previous').': '.number_format($previous['collected'], 2).' ETB',
                'accent' => 'success',
                'icon' => 'heroicon-o-banknotes',
            ],
            [
                'label' => __('filament.equb_report.members_money'),
                'value' => number_format($profit['member_share'], 2).' ETB',
                'sub' => __('filament.equb_report.members_money_hint'),
                'accent' => 'primary',
                'icon' => 'heroicon-o-users',
            ],
            [
                'label' => __('filament.equb_report.service_fee'),
                'value' => number_format($profit['fee'], 2).' ETB',
                'sub' => __('filament.equb_report.at_rate', ['rate' => $profit['rate']]),
                'accent' => 'warning',
                'icon' => 'heroicon-o-receipt-percent',
            ],
            [
                'label' => __('filament.equb_report.settled_payments'),
                'value' => number_format($summary['paid_count']),
                'sub' => __('filament.equb_report.average_of', ['amount' => number_format($summary['average_payment'], 2)]),
                'accent' => 'gray',
                'icon' => 'heroicon-o-check-circle',
            ],
        ];
    }

    public function chart(): ?array
    {
        return [
            'label' => __('filament.equb_report.collection_trend'),
            'series' => ['collected' => __('filament.equb_report.collected')],
            'rows' => $this->report()['series'],
        ];
    }

    public function panels(): array
    {
        $report = $this->report();

        return [
            [
                'heading' => __('filament.equb_report.by_group'),
                'description' => __('filament.equb_report.by_group_drill_description'),
                'icon' => 'heroicon-o-user-group',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.equb_group'), 'strong' => true],
                    ['key' => 'members', 'label' => __('filament.equb_report.members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money', 'good' => true],
                    ['key' => 'share', 'label' => __('filament.equb_report.share'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $report['by_group']->all(),
            ],
            [
                'heading' => __('filament.equb_report.by_group_equb'),
                'description' => __('filament.equb_report.by_group_equb_description'),
                'icon' => 'heroicon-o-users',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.group_equb'), 'strong' => true, 'sub' => 'parent'],
                    ['key' => 'owner', 'label' => __('filament.equb_report.owner')],
                    ['key' => 'members', 'label' => __('filament.equb_report.members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money', 'good' => true],
                ],
                'rows' => $report['by_group_equb']->all(),
                'empty' => __('filament.equb_report.no_group_equbs'),
            ],
            [
                'heading' => __('filament.equb_report.by_package'),
                'icon' => 'heroicon-o-archive-box',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.package'), 'strong' => true],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money', 'good' => true],
                    ['key' => 'share', 'label' => __('filament.equb_report.share'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $report['by_package']->all(),
            ],
        ];
    }
}

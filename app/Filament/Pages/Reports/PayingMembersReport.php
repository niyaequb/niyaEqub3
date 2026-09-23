<?php

namespace App\Filament\Pages\Reports;

use App\Services\Equb\EqubOutstandingService;
use Carbon\CarbonImmutable;

/**
 * Who actually paid in this window — and who did not.
 *
 * The headline "paying members" figure is only half a fact. Four hundred
 * members paying sounds healthy until you learn there are nine hundred active
 * memberships, at which point it is the most alarming number on the reports
 * page. So the two are shown together and the page leads with participation
 * rather than with a count.
 */
class PayingMembersReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/members';

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.paying_members');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.paying_members_title');
    }

    public function explainer(): ?string
    {
        return __('filament.equb_report.paying_members_explainer');
    }

    public function tiles(): array
    {
        $report = $this->report();
        $summary = $report['summary'];
        $previous = $report['previous'];
        $receivables = $report['receivables'];

        $expected = (int) $receivables['people'];
        $participation = $expected > 0 ? ($summary['members'] / $expected) * 100 : 0.0;

        return [
            [
                'label' => __('filament.equb_report.paying_members'),
                'value' => number_format($summary['members']),
                'sub' => __('filament.equb_report.vs_previous').': '.number_format($previous['members']),
                'accent' => 'primary',
                'icon' => 'heroicon-o-users',
            ],
            [
                'label' => __('filament.equb_report.active_memberships'),
                'value' => number_format($receivables['memberships']),
                'sub' => trans_choice('filament.equb_report.distinct_people', $expected, ['count' => number_format($expected)]),
                'accent' => 'gray',
                'icon' => 'heroicon-o-identification',
            ],
            [
                // The figure that turns a count into a judgement.
                'label' => __('filament.equb_report.participation'),
                'value' => number_format($participation, 1).'%',
                'sub' => __('filament.equb_report.participation_hint'),
                'accent' => match (true) {
                    $participation >= 90 => 'success',
                    $participation >= 70 => 'warning',
                    default => 'danger',
                },
                'icon' => 'heroicon-o-chart-pie',
            ],
            [
                'label' => __('filament.equb_report.active_groups'),
                'value' => number_format($summary['groups']),
                'sub' => __('filament.equb_report.groups_with_payments'),
                'accent' => 'gray',
                'icon' => 'heroicon-o-user-group',
            ],
        ];
    }

    public function panels(): array
    {
        $report = $this->report();

        // The other side of the same question: everybody who was expected to
        // pay in this window and did not. Kept on this page rather than only
        // on the arrears page, because "who is missing" is the natural next
        // click from "who paid".
        $silent = app(EqubOutstandingService::class)
            ->rows([
                'equb_group_ids' => $this->filters['equb_group_ids'] ?? [],
                'equb_package_ids' => $this->filters['equb_package_ids'] ?? [],
                'agent_ids' => $this->filters['agent_ids'] ?? [],
                'search' => $this->filters['search'] ?? null,
            ], CarbonImmutable::parse($report['meta']['end']), 50)
            ->map(fn (array $row): array => [
                ...$row,
                'rounds' => $row['rounds_paid'].' / '.$row['rounds_due'],
                'status_label' => __('filament.equb_standing.'.$row['status']),
                'status_label_color' => match ($row['status']) {
                    'warned' => 'warning',
                    'current' => 'primary',
                    default => 'danger',
                },
            ])
            ->all();

        return [
            [
                'heading' => __('filament.equb_report.top_members'),
                'description' => __('filament.equb_report.top_members_description'),
                'icon' => 'heroicon-o-trophy',
                'columns' => [
                    ['key' => 'name', 'label' => __('filament.equb_report.member'), 'strong' => true, 'sub' => 'phone'],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money', 'good' => true],
                ],
                'rows' => $report['top_members']->all(),
            ],
            [
                'heading' => __('filament.equb_report.members_behind'),
                'description' => __('filament.equb_report.members_behind_description'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'columns' => [
                    ['key' => 'name', 'label' => __('filament.equb_report.member'), 'strong' => true, 'sub' => 'phone'],
                    ['key' => 'equb', 'label' => __('filament.equb_report.equb_group'), 'sub' => 'group'],
                    ['key' => 'rounds', 'label' => __('filament.equb_report.rounds'), 'align' => 'right'],
                    ['key' => 'arrears', 'label' => __('filament.equb_report.outstanding'), 'align' => 'right', 'type' => 'money', 'danger' => true],
                    ['key' => 'status_label', 'label' => __('filament.equb_report.status'), 'type' => 'badge'],
                ],
                'rows' => $silent,
                'empty' => __('filament.equb_report.nobody_owes'),
            ],
            [
                'heading' => __('filament.equb_report.by_group'),
                'icon' => 'heroicon-o-user-group',
                'collapsible' => true,
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.equb_group'), 'strong' => true],
                    ['key' => 'members', 'label' => __('filament.equb_report.paying_members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $report['by_group']->all(),
            ],
        ];
    }
}

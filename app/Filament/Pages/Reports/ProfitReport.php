<?php

namespace App\Filament\Pages\Reports;

use App\Services\Equb\EqubProfitService;
use App\Services\Equb\EqubRules;

/**
 * What the company actually earns.
 *
 * THE ONE THING THIS PAGE IS FOR
 *
 * Every other money figure in this system counts members' money passing
 * through on its way to whoever wins a round. Only the service fee was ever
 * ours. Reading "collected" as revenue makes the business look roughly twenty
 * times its real size at a 5% fee, and every decision taken on that basis —
 * what to spend, what to promise, what to hire — is wrong by the same factor.
 *
 * So the page is laid out as a subtraction and refuses to show the top line
 * on its own:
 *
 *     collected           everything that came in
 *   - members' money      what goes back out to the circle
 *   = service fee         our income
 *   - agent commissions   what it cost to bring the money in
 *   = net                 what is actually left
 *
 * The rate is a setting, not a constant, and the page prints it — so the
 * figure can always be checked against the rule that produced it.
 */
class ProfitReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/profit';

    protected ?array $cachedProfit = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.profit');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.profit_title');
    }

    public function explainer(): ?string
    {
        $rules = app(EqubRules::class);

        return __('filament.equb_report.profit_explainer', [
            'rate' => $rules->feePercent(),
            'model' => __('filament.equb_report.fee_model_'.$rules->feeModel()),
        ]);
    }

    /** @return array<string, mixed> */
    protected function profit(): array
    {
        return $this->cachedProfit ??= app(EqubProfitService::class)->build($this->filters);
    }

    public function updatedFilters(): void
    {
        parent::updatedFilters();
        $this->cachedProfit = null;
    }

    public function setPeriod(string $period): void
    {
        parent::setPeriod($period);
        $this->cachedProfit = null;
    }

    public function tiles(): array
    {
        $profit = $this->profit();
        $now = $profit['summary'];

        $delta = function (?float $growth): string {
            if ($growth === null) {
                return __('filament.equb_report.new_activity');
            }

            return ($growth > 0 ? '+' : '').number_format($growth, 1).'% '.__('filament.equb_report.vs_previous');
        };

        return [
            // Deliberately first and deliberately not called revenue. It is
            // the context the fee is a percentage of.
            [
                'label' => __('filament.equb_report.collected'),
                'value' => number_format($now['collected'], 2).' ETB',
                'sub' => __('filament.equb_report.not_our_money'),
                'accent' => 'gray',
                'icon' => 'heroicon-o-arrow-down-tray',
            ],
            [
                'label' => __('filament.equb_report.members_money'),
                'value' => number_format($now['member_share'], 2).' ETB',
                'sub' => __('filament.equb_report.members_money_hint'),
                'accent' => 'primary',
                'icon' => 'heroicon-o-users',
            ],
            [
                'label' => __('filament.equb_report.service_fee'),
                'value' => number_format($now['fee'], 2).' ETB',
                'sub' => __('filament.equb_report.at_rate', ['rate' => $profit['rate']]).' · '.$delta($profit['growth']['fee']),
                'accent' => 'success',
                'icon' => 'heroicon-o-receipt-percent',
            ],
            [
                'label' => __('filament.equb_report.net_profit'),
                'value' => number_format($now['net'], 2).' ETB',
                'sub' => __('filament.equb_report.after_commissions', [
                    'amount' => number_format($now['commissions'], 2),
                ]),
                'accent' => $now['net'] >= 0 ? 'success' : 'danger',
                'icon' => 'heroicon-o-banknotes',
            ],
        ];
    }

    public function chart(): ?array
    {
        $rows = $this->profit()['series'];

        return [
            'label' => __('filament.equb_report.fee_trend'),
            // Stacked so the fee is always read against the members' money it
            // came out of, never as a bar on its own.
            'series' => [
                'fee' => __('filament.equb_report.service_fee'),
                'member_share' => __('filament.equb_report.members_money'),
            ],
            'rows' => $rows,
        ];
    }

    public function panels(): array
    {
        $profit = $this->profit();
        $now = $profit['summary'];
        $was = $profit['previous'];

        // The subtraction itself, written out. A reader who disagrees with the
        // net figure can see exactly which line they disagree with.
        $waterfall = [
            [
                'line' => __('filament.equb_report.collected'),
                'note' => __('filament.equb_report.waterfall_collected'),
                'amount' => $now['collected'],
                'previous' => $was['collected'],
            ],
            [
                'line' => __('filament.equb_report.less_members_money'),
                'note' => __('filament.equb_report.waterfall_members'),
                'amount' => -$now['member_share'],
                'previous' => -$was['member_share'],
            ],
            [
                'line' => __('filament.equb_report.service_fee'),
                'note' => __('filament.equb_report.waterfall_fee', ['rate' => $profit['rate']]),
                'amount' => $now['fee'],
                'previous' => $was['fee'],
                '_critical' => false,
            ],
            [
                'line' => __('filament.equb_report.less_commissions'),
                'note' => __('filament.equb_report.waterfall_commissions'),
                'amount' => -$now['commissions'],
                'previous' => -$was['commissions'],
            ],
            [
                'line' => __('filament.equb_report.net_profit'),
                'note' => __('filament.equb_report.waterfall_net'),
                'amount' => $now['net'],
                'previous' => $was['net'],
            ],
        ];

        return [
            [
                'heading' => __('filament.equb_report.how_it_adds_up'),
                'description' => __('filament.equb_report.how_it_adds_up_description'),
                'icon' => 'heroicon-o-calculator',
                'columns' => [
                    ['key' => 'line', 'label' => __('filament.equb_report.line'), 'strong' => true, 'sub' => 'note'],
                    ['key' => 'amount', 'label' => __('filament.equb_report.this_period'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'previous', 'label' => __('filament.equb_report.previous_period'), 'align' => 'right', 'type' => 'money'],
                ],
                'rows' => $waterfall,
                'footnote' => __('filament.equb_report.commissions_footnote'),
            ],
            [
                'heading' => __('filament.equb_report.fee_by_equb'),
                'description' => __('filament.equb_report.fee_by_equb_description'),
                'icon' => 'heroicon-o-user-group',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.equb_group'), 'strong' => true],
                    ['key' => 'members', 'label' => __('filament.equb_report.members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'member_share', 'label' => __('filament.equb_report.members_money'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'fee', 'label' => __('filament.equb_report.service_fee'), 'align' => 'right', 'type' => 'money', 'good' => true],
                    ['key' => 'share', 'label' => __('filament.equb_report.share'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $profit['by_equb']->all(),
            ],
            [
                'heading' => __('filament.equb_report.fee_by_package'),
                'icon' => 'heroicon-o-archive-box',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.package'), 'strong' => true],
                    ['key' => 'transactions', 'label' => __('filament.equb_report.count'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'collected', 'label' => __('filament.equb_report.collected'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'fee', 'label' => __('filament.equb_report.service_fee'), 'align' => 'right', 'type' => 'money', 'good' => true],
                    ['key' => 'share', 'label' => __('filament.equb_report.share'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $profit['by_package']->all(),
            ],
        ];
    }
}

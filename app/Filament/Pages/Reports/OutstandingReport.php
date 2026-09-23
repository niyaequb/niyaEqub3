<?php

namespace App\Filament\Pages\Reports;

use App\Services\Equb\EqubOutstandingService;
use App\Services\Equb\EqubRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Who owes the Equb money, how much, and for how long.
 *
 * THE FIGURE THIS PAGE REPLACES
 *
 * "Outstanding" used to be the sum of payment rows sitting at status
 * `pending`, filtered by a status filter that defaults to `paid`. Both halves
 * of that were wrong. A member who joined and never paid creates no payment
 * row at all, so the worst debts on the book counted as nothing; and the rows
 * that did exist were excluded by the filter before they could be summed. The
 * card read 0.00 ETB on a book full of arrears.
 *
 * Arrears are now derived from each membership's own schedule — rounds the
 * calendar has asked for, minus what has actually settled — so a debt exists
 * whether or not anybody ever started a payment. Which is what a debt is.
 *
 * WHAT AN OPERATOR CAME HERE TO DO
 *
 * Ring somebody. So the page is built as a worklist rather than a dashboard:
 * the ageing bands are buttons that narrow the list, the never-paid accounts
 * are called out separately because they are a different conversation, and
 * every row carries a phone number. The single most expensive row in the
 * system — a member who has already collected a payout and then stopped
 * paying — is marked in red, because the circle has handed over the money and
 * has no way to get it back except from that person.
 */
class OutstandingReport extends ReportDrilldown
{
    protected static ?string $slug = 'equb-reports/outstanding';

    protected string $view = 'filament.pages.reports.outstanding';

    /** Narrows the list to one ageing band. Its own URL so it can be shared. */
    #[Url(as: 'band', history: true)]
    public ?string $bucket = null;

    #[Url(as: 'unpaid', history: true)]
    public bool $onlyNeverPaid = false;

    protected ?array $cachedSummary = null;

    protected ?array $cachedAgeing = null;

    protected ?Collection $cachedRows = null;

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.outstanding');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.outstanding_title');
    }

    public function explainer(): ?string
    {
        $rules = app(EqubRules::class);

        return __('filament.equb_report.outstanding_explainer', [
            'grace' => $rules->graceDays(),
            'block' => $rules->blockAt(),
        ]);
    }

    /**
     * The moment the position is measured at.
     *
     * The end of whatever window the report is showing, so "outstanding" on a
     * report for last month means what was owed at the end of last month —
     * not what is owed today. A level, not a flow.
     */
    public function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->report()['meta']['end']);
    }

    /** @return array<string, mixed> */
    protected function filtersForReceivables(): array
    {
        return [
            'equb_group_ids' => $this->filters['equb_group_ids'] ?? [],
            'equb_package_ids' => $this->filters['equb_package_ids'] ?? [],
            'agent_ids' => $this->filters['agent_ids'] ?? [],
            'search' => $this->filters['search'] ?? null,
            'bucket' => $this->bucket,
            'only_never_paid' => $this->onlyNeverPaid,
        ];
    }

    /** @return array<string, mixed> */
    public function receivables(): array
    {
        return $this->cachedSummary ??= app(EqubOutstandingService::class)
            ->summary($this->filtersForReceivables(), $this->asOf());
    }

    /** @return array<int, array<string, mixed>> */
    public function bands(): array
    {
        return $this->cachedAgeing ??= app(EqubOutstandingService::class)
            ->ageing($this->filtersForReceivables(), $this->asOf());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function debtors(): Collection
    {
        return $this->cachedRows ??= app(EqubOutstandingService::class)
            ->rows($this->filtersForReceivables(), $this->asOf());
    }

    public function selectBand(?string $bucket): void
    {
        $this->bucket = ($this->bucket === $bucket) ? null : $bucket;
        $this->onlyNeverPaid = false;
        $this->clearCache();
    }

    public function toggleNeverPaid(): void
    {
        $this->onlyNeverPaid = ! $this->onlyNeverPaid;
        $this->bucket = null;
        $this->clearCache();
    }

    public function clearNarrowing(): void
    {
        $this->bucket = null;
        $this->onlyNeverPaid = false;
        $this->clearCache();
    }

    public function updatedFilters(): void
    {
        parent::updatedFilters();
        $this->clearCache();
    }

    public function setPeriod(string $period): void
    {
        parent::setPeriod($period);
        $this->clearCache();
    }

    protected function clearCache(): void
    {
        $this->cachedSummary = null;
        $this->cachedAgeing = null;
        $this->cachedRows = null;
    }

    public function tiles(): array
    {
        $r = $this->receivables();

        return [
            [
                'label' => __('filament.equb_report.total_outstanding'),
                'value' => number_format($r['arrears'], 2).' ETB',
                'sub' => trans_choice('filament.equb_report.across_members', $r['members_in_arrears'], [
                    'count' => number_format($r['members_in_arrears']),
                ]),
                'accent' => $r['arrears'] > 0 ? 'danger' : 'success',
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            [
                'label' => __('filament.equb_report.never_paid'),
                'value' => number_format($r['never_paid_amount'], 2).' ETB',
                'sub' => trans_choice('filament.equb_report.never_paid_hint', $r['never_paid_count'], [
                    'count' => number_format($r['never_paid_count']),
                ]),
                'accent' => $r['never_paid_count'] > 0 ? 'danger' : 'gray',
                'icon' => 'heroicon-o-user-minus',
            ],
            [
                'label' => __('filament.equb_report.expected_to_date'),
                'value' => number_format($r['expected'], 2).' ETB',
                'sub' => __('filament.equb_report.of_which_paid', ['amount' => number_format($r['paid'], 2)]),
                'accent' => 'gray',
                'icon' => 'heroicon-o-calendar-days',
            ],
            [
                // The health of the whole book in one number, and the one that
                // cannot be improved by recording payments differently.
                'label' => __('filament.equb_report.collection_rate'),
                'value' => number_format($r['collection_rate'], 1).'%',
                'sub' => __('filament.equb_report.collection_rate_hint'),
                'accent' => match (true) {
                    $r['collection_rate'] >= 95 => 'success',
                    $r['collection_rate'] >= 80 => 'warning',
                    default => 'danger',
                },
                'icon' => 'heroicon-o-chart-pie',
            ],
        ];
    }

    public function panels(): array
    {
        $service = app(EqubOutstandingService::class);
        $filters = $this->filtersForReceivables();
        $asOf = $this->asOf();

        return [
            [
                'heading' => __('filament.equb_report.arrears_by_equb'),
                'description' => __('filament.equb_report.arrears_by_equb_description'),
                'icon' => 'heroicon-o-user-group',
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.equb_group'), 'strong' => true],
                    ['key' => 'memberships', 'label' => __('filament.equb_report.members'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'debtors', 'label' => __('filament.equb_report.in_arrears'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'expected', 'label' => __('filament.equb_report.expected'), 'align' => 'right', 'type' => 'money'],
                    ['key' => 'arrears', 'label' => __('filament.equb_report.outstanding'), 'align' => 'right', 'type' => 'money', 'danger' => true],
                    ['key' => 'collection_rate', 'label' => __('filament.equb_report.collected_pct'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $service->breakdown($filters, 'equb', $asOf)->all(),
            ],
            [
                'heading' => __('filament.equb_report.arrears_by_package'),
                'icon' => 'heroicon-o-archive-box',
                'collapsible' => true,
                'columns' => [
                    ['key' => 'label', 'label' => __('filament.equb_report.package'), 'strong' => true],
                    ['key' => 'debtors', 'label' => __('filament.equb_report.in_arrears'), 'align' => 'right', 'type' => 'number'],
                    ['key' => 'arrears', 'label' => __('filament.equb_report.outstanding'), 'align' => 'right', 'type' => 'money', 'danger' => true],
                    ['key' => 'collection_rate', 'label' => __('filament.equb_report.collected_pct'), 'align' => 'right', 'type' => 'percent'],
                ],
                'rows' => $service->breakdown($filters, 'package', $asOf)->all(),
            ],
        ];
    }
}

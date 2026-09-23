<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ReportPeriod;
use App\Filament\Pages\EqubReports;
use App\Services\EqubReportService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * The page behind a figure on the reports screen.
 *
 * WHY THESE EXIST
 *
 * A number on a card is an answer with no working shown. "Outstanding:
 * 412,000 ETB" is true and useless — it cannot be acted on until somebody
 * knows which members, how long, and how it got there. Every headline figure
 * now has a page that takes it apart, and the card itself is the link.
 *
 * FILTERS TRAVEL WITH THE READER
 *
 * The whole filter set is carried in the query string, so clicking through
 * from a filtered report lands on a page showing the same window and the same
 * Equbs — and the back button returns to exactly what was on screen. A
 * drill-down that silently resets to "everything, this month" is worse than
 * no drill-down, because the figures no longer match the ones that were
 * clicked.
 *
 * Subclasses supply three things: the tiles across the top, the panels of
 * detail below them, and optionally a trend. Everything else — the period
 * strip, the filter chips, the header actions, access control — is here so
 * six pages cannot drift into six different layouts.
 */
abstract class ReportDrilldown extends Page
{
    protected string $view = 'filament.pages.reports.drilldown';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * The filters this page inherited, in the same shape EqubReports uses.
     *
     * @var array<string, mixed>
     */
    #[Url(as: 'f', history: true)]
    public array $filters = [];

    /** One set of queries per request, however many times the view asks. */
    protected ?array $cachedReport = null;

    public function mount(): void
    {
        if (empty($this->filters)) {
            $this->filters = [
                'period' => ReportPeriod::Daily->value,
                'from' => now()->toDateString(),
                'statuses' => [\App\Enums\EqubPaymentStatus::Paid->value],
            ];
        }
    }

    // -----------------------------------------------------------------
    // Access — the same gate as the report page itself
    // -----------------------------------------------------------------

    public static function canAccess(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.equb-reports')
        );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    // -----------------------------------------------------------------
    // Shared data
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->cachedReport ??= app(EqubReportService::class)->build($this->filters);
    }

    public function getSubheading(): ?string
    {
        return $this->report()['meta']['range_label'];
    }

    /** Back to the figure this page came from. */
    public function parentUrl(): string
    {
        return EqubReports::getUrl(['f' => $this->filters]);
    }

    /** Switch the window without losing the rest of the filters. */
    public function setPeriod(string $period): void
    {
        if (! ReportPeriod::tryFrom($period)) {
            return;
        }

        $this->filters['period'] = $period;
        $this->filters['from'] ??= now()->toDateString();
        $this->cachedReport = null;
    }

    public function updatedFilters(): void
    {
        $this->cachedReport = null;
    }

    /** @return array<string, string> */
    public function periods(): array
    {
        return collect(ReportPeriod::cases())
            ->mapWithKeys(fn (ReportPeriod $p): array => [$p->value => $p->label()])
            ->all();
    }

    // -----------------------------------------------------------------
    // What a subclass supplies
    // -----------------------------------------------------------------

    /**
     * The figures across the top.
     *
     * Each: label, value, optional sub (a line of context), accent
     * (success|warning|danger|primary|gray), and optional icon.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function tiles(): array;

    /**
     * The tables underneath.
     *
     * Each panel: heading, description, icon, columns and rows. A column is
     * [key, label, align, type] where type is money | number | percent |
     * badge | text. Keeping this declarative means every drill-down renders
     * through one template and they cannot diverge.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function panels(): array;

    /**
     * An optional bar chart: ['label' => ..., 'series' => [key => label],
     * 'rows' => [['label' => ..., key => value, ...]]].
     *
     * @return array<string, mixed>|null
     */
    public function chart(): ?array
    {
        return null;
    }

    /** A sentence under the heading explaining what the page is counting. */
    public function explainer(): ?string
    {
        return null;
    }
}

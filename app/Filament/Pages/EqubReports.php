<?php

namespace App\Filament\Pages;

use App\Enums\EqubPaymentMethod;
use App\Enums\EqubPaymentStatus;
use App\Enums\ReportPeriod;
use App\Models\Agent;
use App\Models\EqubGroup;
use App\Models\EqubPackage;
use App\Models\ReportPrintJob;
use App\Models\ReportPrintSchedule;
use App\Services\EqubReportService;
use App\Services\PrinterService;
use App\Services\ReportPrintService;
use App\Services\ReportRenderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Equb payment reporting: daily, weekly and monthly takings with filters,
 * charts, and printing.
 *
 * The page holds filter state and nothing else. Every number on screen comes
 * from EqubReportService, and so does every number on the printed copy — the
 * two cannot disagree because they are the same array.
 */
class EqubReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.equb-reports';

    protected static ?int $navigationSort = 7;

    /**
     * Filter state.
     *
     * #[Url] so a filtered report is a shareable link — "look at yesterday's
     * Al Nur numbers" should be a URL, not a list of instructions.
     *
     * @var array<string, mixed>
     */
    #[Url(as: 'f', history: true)]
    public array $filters = [];

    /** Cheap per-request memo so six blade calls issue one set of queries. */
    protected ?array $cachedReport = null;

    // -----------------------------------------------------------------
    // Navigation & access
    // -----------------------------------------------------------------

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chart-bar-square';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.equb_report.title');
    }

    public function getTitle(): string
    {
        return __('filament.equb_report.title');
    }

    public function getHeading(): string
    {
        return __('filament.equb_report.title');
    }

    public function getSubheading(): ?string
    {
        return $this->report()['meta']['range_label'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccessReports();
    }

    public static function canAccess(): bool
    {
        return static::canAccessReports();
    }

    protected static function canAccessReports(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.equb-reports')
        );
    }

    protected function canManageSchedules(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.equb-reports.schedule')
        );
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    public function mount(): void
    {
        if (empty($this->filters)) {
            $this->filters = $this->defaultFilters();
        }

        $this->filtersForm->fill($this->filters);
    }

    /** @return array<string, mixed> */
    protected function defaultFilters(): array
    {
        return [
            'period' => ReportPeriod::Daily->value,
            'from' => now()->toDateString(),
            'to' => null,
            'equb_group_ids' => [],
            'equb_package_ids' => [],
            'agent_ids' => [],
            'payment_methods' => [],
            // Paid by default: the daily print is a record of money actually
            // received, so starting from every status would make the operator
            // narrow it down by hand every single morning. Clearing the filter
            // brings pending and failed back.
            'statuses' => [EqubPaymentStatus::Paid->value],
            'min_amount' => null,
            'max_amount' => null,
            'search' => null,
        ];
    }

    /**
     * Livewire calls this on every filter change; dropping the memo makes the
     * next report() call recompute against the new state.
     */
    public function updatedFilters(): void
    {
        $this->cachedReport = null;
    }

    // -----------------------------------------------------------------
    // Filter form
    // -----------------------------------------------------------------

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        Select::make('period')
                            ->label(__('filament.equb_report.period'))
                            ->options(collect(ReportPeriod::cases())
                                ->mapWithKeys(fn (ReportPeriod $p) => [$p->value => $p->label()])
                                ->all())
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),

                        DatePicker::make('from')
                            ->label(fn (Get $get): string => $get('period') === ReportPeriod::Custom->value
                                ? __('filament.equb_report.from')
                                : __('filament.equb_report.anchor_date'))
                            // For a preset the date is an anchor, not a
                            // boundary: pick any day in March and the monthly
                            // report shows all of March.
                            ->helperText(fn (Get $get): ?string => $get('period') === ReportPeriod::Custom->value
                                ? null
                                : __('filament.equb_report.anchor_helper'))
                            ->maxDate(now()->addDay())
                            ->native(false)
                            ->live(),

                        DatePicker::make('to')
                            ->label(__('filament.equb_report.to'))
                            ->visible(fn (Get $get): bool => $get('period') === ReportPeriod::Custom->value)
                            ->afterOrEqual('from')
                            ->maxDate(now()->addDay())
                            ->native(false)
                            ->live(),

                        TextInput::make('search')
                            ->label(__('filament.equb_report.search'))
                            ->placeholder(__('filament.equb_report.search_placeholder'))
                            ->live(debounce: 600),
                    ]),

                Section::make(__('filament.equb_report.advanced_filters'))
                    ->collapsed()
                    ->collapsible()
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3])
                            ->schema([
                                Select::make('equb_group_ids')
                                    ->label(__('filament.equb_report.filter_groups'))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->options(fn () => EqubGroup::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->live(),

                                Select::make('equb_package_ids')
                                    ->label(__('filament.equb_report.filter_packages'))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->options(fn () => EqubPackage::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->live(),

                                Select::make('agent_ids')
                                    ->label(__('filament.equb_report.filter_agents'))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->options(fn () => Agent::query()
                                        ->with('user')
                                        ->get()
                                        ->mapWithKeys(fn (Agent $a) => [
                                            $a->id => ($a->user?->name ?? 'Agent #'.$a->id).' ('.$a->referral_code.')',
                                        ])
                                        ->all())
                                    ->live(),

                                Select::make('payment_methods')
                                    ->label(__('filament.equb_report.filter_methods'))
                                    ->multiple()
                                    ->options(collect(EqubPaymentMethod::cases())
                                        ->mapWithKeys(fn ($m) => [$m->value => ucfirst($m->value)])
                                        ->all())
                                    ->live(),

                                Select::make('statuses')
                                    ->label(__('filament.equb_report.filter_statuses'))
                                    ->multiple()
                                    ->options(collect(EqubPaymentStatus::cases())
                                        ->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])
                                        ->all())
                                    ->live(),

                                Grid::make(2)->schema([
                                    TextInput::make('min_amount')
                                        ->label(__('filament.equb_report.min_amount'))
                                        ->numeric()
                                        ->prefix('ETB')
                                        ->live(debounce: 600),
                                    TextInput::make('max_amount')
                                        ->label(__('filament.equb_report.max_amount'))
                                        ->numeric()
                                        ->prefix('ETB')
                                        ->live(debounce: 600),
                                ]),
                            ]),
                    ]),
            ])
            ->statePath('filters');
    }

    /**
     * Resolve the schedule the form currently describes into a real date.
     *
     * Built from an unsaved model so the preview uses exactly the same
     * calculation the scheduler will — including the month-length clamping,
     * so "the 31st" in February previews as the 28th rather than lying.
     *
     * @param  Get  $get
     */
    public function previewNextRun(Get $get): string
    {
        $timezone = config('app.timezone', 'Africa/Addis_Ababa');

        $schedule = new ReportPrintSchedule([
            'frequency' => $get('frequency') ?: 'daily',
            'run_at' => substr((string) ($get('run_at') ?: '08:00'), 0, 5),
            'day_of_week' => $get('day_of_week') ? (int) $get('day_of_week') : null,
            'day_of_month' => $get('day_of_month') ? (int) $get('day_of_month') : null,
            'timezone' => $timezone,
        ]);

        try {
            $next = $schedule->calculateNextRun()->setTimezone($timezone);
        } catch (\Throwable) {
            // A half-filled form is normal while someone is still typing;
            // showing a dash beats throwing inside a live-updating field.
            return '—';
        }

        return $next->translatedFormat('l, j F Y')
            .' '.__('filament.equb_report.at').' '.$next->format('g:i A')
            .' ('.$next->diffForHumans().')';
    }

    public function resetFilters(): void
    {
        $this->filters = $this->defaultFilters();
        $this->filtersForm->fill($this->filters);
        $this->cachedReport = null;
    }

    /** Quick period switch from the tab strip. */
    public function setPeriod(string $period): void
    {
        if (! ReportPeriod::tryFrom($period)) {
            return;
        }

        $this->filters['period'] = $period;
        $this->filters['from'] ??= now()->toDateString();
        $this->filtersForm->fill($this->filters);
        $this->cachedReport = null;
    }

    // -----------------------------------------------------------------
    // Data
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function report(): array
    {
        return $this->cachedReport ??= app(EqubReportService::class)->build($this->filters);
    }

    /**
     * Identity of the current filter set.
     *
     * Used as the Livewire key on each chart so a filter change tears the
     * chart down and rebuilds it. Relying on reactive props alone risks a
     * chart quietly showing last week's figures under this week's heading,
     * which is worse than a moment of re-render.
     */
    public function filtersFingerprint(): string
    {
        return md5(json_encode($this->filters) ?: '');
    }

    public function schedulerIsRunning(): bool
    {
        return app(ReportPrintService::class)->schedulerIsRunning();
    }

    /** @return \Illuminate\Support\Collection<int, ReportPrintSchedule> */
    public function schedules()
    {
        return ReportPrintSchedule::query()
            ->orderByDesc('is_active')
            ->orderBy('run_at')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, ReportPrintJob> */
    public function recentPrintJobs()
    {
        return ReportPrintJob::query()
            ->latest()
            ->limit(8)
            ->get();
    }

    // -----------------------------------------------------------------
    // Header actions
    // -----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('printNow')
                ->label(__('filament.equb_report.print_now'))
                ->icon('heroicon-o-printer')
                ->color('primary')
                // The browser's own print dialog, on the machine the operator
                // is sitting at. It reaches every printer Windows knows about,
                // USB included, and needs nothing installed — which is why it
                // is the primary action rather than server-side spooling.
                ->url(fn (): string => route('admin.equb-reports.print', $this->printQueryString()))
                ->openUrlInNewTab(),

            ActionGroup::make([
                Action::make('downloadPdf')
                    ->label(__('filament.equb_report.download_pdf'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->schema([
                        Select::make('paper')
                            ->label(__('filament.equb_report.paper'))
                            ->options(ReportRenderService::paperOptions())
                            ->default('a4')
                            ->native(false),
                        Toggle::make('include_details')
                            ->label(__('filament.equb_report.include_transactions'))
                            ->default(true),
                        Toggle::make('show_signatures')
                            ->label(__('filament.equb_report.include_signatures'))
                            ->default(true),
                    ])
                    ->action(function (array $data) {
                        $report = $this->report();
                        $renderer = app(ReportRenderService::class);

                        $pdf = $renderer->pdf($report, [
                            'paper' => $data['paper'] ?? 'a4',
                            'include_details' => (bool) ($data['include_details'] ?? true),
                            'show_signatures' => (bool) ($data['show_signatures'] ?? true),
                            'generated_by' => Auth::user()?->name,
                        ]);

                        $filename = 'equb-report-'
                            .$report['meta']['period']
                            .'-'.now()->format('Ymd_His').'.pdf';

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $filename,
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),

                Action::make('exportCsv')
                    ->label(__('filament.equb_report.export_csv'))
                    ->icon('heroicon-o-table-cells')
                    ->action(fn () => app(ReportRenderService::class)
                        ->csvResponse($this->filters, app(EqubReportService::class))),
            ])
                ->label(__('filament.equb_report.export'))
                ->icon('heroicon-m-arrow-down-tray')
                ->button()
                ->color('gray'),

            Action::make('newSchedule')
                ->label(__('filament.equb_report.schedule_print'))
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalWidth('2xl')
                ->modalDescription(__('filament.equb_report.schedule_description'))
                ->schema($this->scheduleFields())
                ->extraModalFooterActions([
                    Action::make('testPrinter')
                        ->label(__('filament.equb_report.test_connection'))
                        ->icon('heroicon-o-bolt')
                        ->color('gray')
                        // Cancelling would close the modal; the admin needs to
                        // stay put and adjust the printer after a failed test.
                        ->action(function (Action $action): void {
                            $data = $action->getLivewire()->mountedActions[0]['data'] ?? [];

                            if (($data['delivery'] ?? 'agent') !== 'network') {
                                Notification::make()
                                    ->title(__('filament.equb_report.test_agent_only'))
                                    ->info()
                                    ->send();

                                return;
                            }

                            $this->testPrinterConnection($data);
                        }),
                ])
                ->action(fn (array $data) => $this->saveSchedule($data))
                ->visible(fn (): bool => $this->canManageSchedules()),
        ];
    }

    /** Query string that reproduces the current view on the print route. */
    protected function printQueryString(): array
    {
        return array_filter([
            'period' => $this->filters['period'] ?? null,
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'equb_group_ids' => $this->filters['equb_group_ids'] ?? null,
            'equb_package_ids' => $this->filters['equb_package_ids'] ?? null,
            'agent_ids' => $this->filters['agent_ids'] ?? null,
            'payment_methods' => $this->filters['payment_methods'] ?? null,
            'statuses' => $this->filters['statuses'] ?? null,
            'min_amount' => $this->filters['min_amount'] ?? null,
            'max_amount' => $this->filters['max_amount'] ?? null,
            'search' => $this->filters['search'] ?? null,
            'autoprint' => 1,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // -----------------------------------------------------------------
    // Printer fields, shared by the ad-hoc send and the schedule form
    // -----------------------------------------------------------------

    /**
     * Printer fields, shared by the ad-hoc send and the schedule form.
     *
     * The connection type comes first and everything else follows from it.
     * The previous version led with "Printer IP or hostname", which invited
     * exactly the wrong answer for a USB printer — there is no address to
     * type, and the DNS failure that followed explained nothing.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function printerFields(): array
    {
        $printers = app(PrinterService::class);
        $connections = $printers->availableConnectionOptions();
        $installed = $printers->discoverOptions();
        $helperName = $printers->helperName();

        // Shown on the field itself so the state of the print chain is visible
        // before Submit, rather than being discovered from a failed job.
        $printerNote = trim(
            ($installed === []
                ? __('filament.equb_report.no_printers_found')
                : __('filament.equb_report.choose_printer_helper', ['count' => count($installed)]))
            .' '
            .($helperName
                ? __('filament.equb_report.helper_ready', ['helper' => $helperName])
                : __('filament.equb_report.helper_missing'))
        );

        return [
            Select::make('printer_connection')
                ->label(__('filament.equb_report.how_connected'))
                // Only what can work here. On Windows without a PDF helper the
                // "installed on this computer" route is hidden entirely rather
                // than offered and then failing at print time.
                ->options($connections)
                ->default(fn (): string => array_key_first($connections) ?? PrinterService::CONNECTION_RAW)
                ->native(false)
                ->live()
                ->required()
                ->helperText(fn (Get $get): string => match ($get('printer_connection')) {
                    PrinterService::CONNECTION_SHARE => __('filament.equb_report.connection_share_helper'),
                    PrinterService::CONNECTION_RAW => __('filament.equb_report.connection_raw_helper'),
                    PrinterService::CONNECTION_IPP => __('filament.equb_report.connection_ipp_helper'),
                    default => __('filament.equb_report.connection_system_helper'),
                }),

            // --- Installed on this machine (USB, Wi-Fi, Ethernet alike) ---
            Select::make('printer_name')
                ->label(__('filament.equb_report.choose_printer'))
                ->options($installed)
                ->default(fn (): ?string => array_key_first($installed))
                ->searchable()
                ->native(false)
                ->required(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SYSTEM)
                ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SYSTEM)
                // A dropdown rather than a text field: the name has to match
                // Windows exactly, hyphens and all, and retyping
                // "HP LaserJet MFP M129-M134" by hand is a support ticket.
                ->helperText($printerNote)
                ->hintAction(
                    // Filament v5 unified every action under
                    // Filament\Actions\Action — there is no separate form
                    // action class to import.
                    Action::make('refreshPrinters')
                        ->label(__('filament.equb_report.refresh'))
                        ->icon('heroicon-m-arrow-path')
                        ->action(function (): void {
                            $printers = app(PrinterService::class);
                            $found = $printers->discover(fresh: true);

                            // On zero, show why. "Found 0 printers" next to a
                            // working printer is not a message anyone can act on.
                            Notification::make()
                                ->title(__('filament.equb_report.printers_refreshed', ['count' => $found->count()]))
                                ->body($found->isNotEmpty()
                                    ? $found->pluck('name')->implode(', ')
                                    : $printers->discoveryError())
                                ->status($found->isNotEmpty() ? 'success' : 'warning')
                                ->persistent()
                                ->send();
                        }),
                ),

            // --- Shared from another PC on the LAN ---
            TextInput::make('printer_share')
                ->label(__('filament.equb_report.share_path'))
                ->placeholder('\\\\OFFICE-PC\\HP-LaserJet')
                ->helperText(__('filament.equb_report.share_path_helper'))
                ->required(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SHARE)
                ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_SHARE),

            // --- Printer with its own IP ---
            Grid::make(2)
                ->visible(fn (Get $get): bool => in_array(
                    $get('printer_connection'),
                    [PrinterService::CONNECTION_RAW, PrinterService::CONNECTION_IPP],
                    true,
                ))
                ->schema([
                    TextInput::make('printer_host')
                        ->label(__('filament.equb_report.printer_host'))
                        ->placeholder('192.168.1.50')
                        ->helperText(__('filament.equb_report.printer_host_helper'))
                        ->required(fn (Get $get): bool => in_array(
                            $get('printer_connection'),
                            [PrinterService::CONNECTION_RAW, PrinterService::CONNECTION_IPP],
                            true,
                        ))
                        // Reject a display name at the form rather than after a
                        // DNS lookup: hostnames cannot contain spaces.
                        ->rule('regex:/^[A-Za-z0-9._\-]+$/')
                        ->validationMessages([
                            'regex' => __('filament.equb_report.printer_host_invalid'),
                        ]),

                    TextInput::make('printer_port')
                        ->label(__('filament.equb_report.printer_port'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->placeholder(fn (Get $get): string => $get('printer_connection') === PrinterService::CONNECTION_IPP ? '631' : '9100')
                        ->helperText(__('filament.equb_report.printer_port_helper')),
                ]),

            TextInput::make('printer_queue')
                ->label(__('filament.equb_report.printer_queue'))
                ->placeholder('office-laser')
                ->helperText(__('filament.equb_report.printer_queue_helper'))
                ->visible(fn (Get $get): bool => $get('printer_connection') === PrinterService::CONNECTION_IPP),

            Grid::make(2)->schema([
                Select::make('paper')
                    ->label(__('filament.equb_report.paper'))
                    ->options(ReportRenderService::paperOptions())
                    ->default('a4')
                    ->native(false),

                TextInput::make('copies')
                    ->label(__('filament.equb_report.copies'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10)
                    ->default(1),
            ]),
        ];
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    protected function scheduleFields(): array
    {
        return [
            Section::make(__('filament.equb_report.what_to_print'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament.equb_report.schedule_name'))
                        ->placeholder(__('filament.equb_report.schedule_name_placeholder'))
                        ->required()
                        ->maxLength(120),

                    Select::make('period')
                        ->label(__('filament.equb_report.report_type'))
                        ->options(collect(ReportPeriod::cases())
                            ->reject(fn (ReportPeriod $p) => $p === ReportPeriod::Custom)
                            ->mapWithKeys(fn (ReportPeriod $p) => [$p->value => $p->label()])
                            ->all())
                        ->default(fn (): string => in_array($this->filters['period'] ?? null, ['daily', 'weekly', 'monthly'], true)
                            ? $this->filters['period']
                            : ReportPeriod::Daily->value)
                        ->native(false)
                        ->required(),

                    Toggle::make('inherit_filters')
                        ->label(__('filament.equb_report.inherit_filters'))
                        ->helperText(__('filament.equb_report.inherit_filters_helper'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('filament.equb_report.when_to_print'))
                ->schema([
                    Select::make('frequency')
                        ->label(__('filament.equb_report.frequency'))
                        ->options([
                            'daily' => __('filament.equb_report.freq_daily'),
                            'weekly' => __('filament.equb_report.freq_weekly'),
                            'monthly' => __('filament.equb_report.freq_monthly'),
                        ])
                        ->default('daily')
                        ->native(false)
                        ->live()
                        ->required(),

                    TimePicker::make('run_at')
                        ->label(__('filament.equb_report.run_at'))
                        ->seconds(false)
                        // Stored as 24-hour so the scheduler parses it without
                        // ambiguity, shown as 12-hour because that is how the
                        // office talks about time.
                        ->format('H:i')
                        ->displayFormat('h:i A')
                        // Five-minute steps: nobody schedules a daily report
                        // for 08:07, and a coarser picker is quicker to use.
                        ->minutesStep(5)
                        ->default('08:00')
                        ->native(false)
                        ->prefixIcon('heroicon-m-clock')
                        ->helperText(fn (): string => __('filament.equb_report.run_at_helper', [
                            'time' => now(config('app.timezone', 'Africa/Addis_Ababa'))->format('g:i A'),
                            'zone' => config('app.timezone', 'Africa/Addis_Ababa'),
                        ]))
                        ->live()
                        ->required(),

                    Select::make('day_of_week')
                        ->label(__('filament.equb_report.day_of_week'))
                        ->options([
                            1 => __('filament.equb_report.monday'),
                            2 => __('filament.equb_report.tuesday'),
                            3 => __('filament.equb_report.wednesday'),
                            4 => __('filament.equb_report.thursday'),
                            5 => __('filament.equb_report.friday'),
                            6 => __('filament.equb_report.saturday'),
                            7 => __('filament.equb_report.sunday'),
                        ])
                        ->default(1)
                        ->native(false)
                        ->live()
                        ->visible(fn (Get $get): bool => $get('frequency') === 'weekly'),

                    TextInput::make('day_of_month')
                        ->label(__('filament.equb_report.day_of_month'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->default(1)
                        ->helperText(__('filament.equb_report.day_of_month_helper'))
                        ->live()
                        ->visible(fn (Get $get): bool => $get('frequency') === 'monthly'),

                    // A schedule is easy to get subtly wrong — "every month on
                    // the 1st at 08:00" is not obviously tomorrow or in four
                    // weeks. Showing the resolved date removes the guesswork
                    // before it is saved.
                    Placeholder::make('next_run_preview')
                        ->label(__('filament.equb_report.first_run'))
                        ->content(fn (Get $get): string => $this->previewNextRun($get))
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make(__('filament.equb_report.where_to_print'))
                ->schema([
                    Select::make('delivery')
                        ->label(__('filament.equb_report.delivery'))
                        ->options([
                            'agent' => __('filament.equb_report.delivery_agent'),
                            'network' => __('filament.equb_report.delivery_network'),
                            'none' => __('filament.equb_report.delivery_none'),
                        ])
                        ->default('agent')
                        ->native(false)
                        ->live()
                        ->helperText(fn (Get $get): string => match ($get('delivery')) {
                            'network' => __('filament.equb_report.delivery_network_helper'),
                            'none' => __('filament.equb_report.delivery_none_helper'),
                            default => __('filament.equb_report.delivery_agent_helper'),
                        })
                        ->required(),

                    Select::make('paper')
                        ->label(__('filament.equb_report.paper'))
                        ->options(ReportRenderService::paperOptions())
                        ->default('a4')
                        ->native(false)
                        ->visible(fn (Get $get): bool => $get('delivery') !== 'network'),

                    TextInput::make('copies')
                        ->label(__('filament.equb_report.copies'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(1)
                        ->visible(fn (Get $get): bool => $get('delivery') !== 'network'),

                    Select::make('format')
                        ->label(__('filament.equb_report.format'))
                        ->options([
                            'pdf' => 'PDF',
                            'html' => 'HTML',
                            'escpos' => __('filament.equb_report.format_escpos'),
                        ])
                        ->default('pdf')
                        ->native(false)
                        ->helperText(__('filament.equb_report.format_helper'))
                        ->visible(fn (Get $get): bool => $get('delivery') === 'network'),

                    // The whole printer picker, reused verbatim so the ad-hoc
                    // send and the schedule cannot drift apart in what they
                    // accept.
                    Group::make($this->printerFields())
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('delivery') === 'network'),
                ])
                ->columns(2),
        ];
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    /**
     * Flattens the printer form fields into the shape PrinterService wants.
     *
     * `name` carries either a system printer name or a UNC share path, since
     * from the service's point of view both are "the thing to print to" and
     * neither has a host or port.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function printerConfig(array $data): array
    {
        $connection = $data['printer_connection'] ?? PrinterService::CONNECTION_RAW;

        return [
            'connection' => $connection,
            'name' => $connection === PrinterService::CONNECTION_SHARE
                ? ($data['printer_share'] ?? null)
                : ($data['printer_name'] ?? null),
            'host' => $data['printer_host'] ?? null,
            'port' => $data['printer_port'] ?? null,
            'queue' => $data['printer_queue'] ?? null,
            'paper' => $data['paper'] ?? 'a4',
            'copies' => (int) ($data['copies'] ?? 1),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function testPrinterConnection(array $data): void
    {
        $result = app(PrinterService::class)->test($this->printerConfig($data));

        Notification::make()
            ->title($result['message'])
            ->body($result['detail'] ?? null)
            ->status($result['ok'] ? 'success' : 'danger')
            ->persistent()
            ->send();
    }

    /** @param  array<string, mixed>  $data */
    public function dispatchToPrinter(array $data): void
    {
        $config = $this->printerConfig($data);
        $paper = $config['paper'];
        $thermal = str_starts_with($paper, 'thermal');

        // A till roll on a raw socket wants ESC/POS. Everything else gets a
        // PDF: network printers accept it over 9100, and the local spooler
        // renders it through the vendor driver.
        $escpos = $thermal && $config['connection'] === PrinterService::CONNECTION_RAW;

        $job = app(ReportPrintService::class)->queue($this->filters, [
            'source' => 'manual',
            'delivery' => 'network',
            'connection' => $config['connection'],
            'paper' => $paper,
            'format' => $escpos ? 'escpos' : 'pdf',
            'prefer_escpos' => $escpos,
            'copies' => $config['copies'],
            'created_by' => Auth::id(),
            'generated_by' => Auth::user()?->name,
            'title' => __('filament.equb_report.title').' — '.$this->report()['meta']['range_label'],
        ]);

        $result = app(ReportPrintService::class)->deliverToPrinter($job, $config);

        Notification::make()
            ->title($result['message'])
            ->body($result['detail'] ?? null)
            ->status($result['ok'] ? 'success' : 'danger')
            ->persistent()
            ->send();
    }

    /** @param  array<string, mixed>  $data */
    public function saveSchedule(array $data): void
    {
        // Strip the window: a schedule stores intent ("the daily report"), and
        // resolves its own dates at run time. Freezing today's from/to here
        // would make every future run reprint today.
        $filters = ($data['inherit_filters'] ?? true)
            ? collect($this->filters)
                ->except(['period', 'from', 'to'])
                ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
                ->all()
            : [];

        $connection = $data['printer_connection'] ?? PrinterService::CONNECTION_SYSTEM;
        $network = ($data['delivery'] ?? 'agent') === 'network';

        $schedule = ReportPrintSchedule::create([
            'name' => $data['name'],
            'period' => $data['period'] ?? 'daily',
            'filters' => $filters,
            'frequency' => $data['frequency'] ?? 'daily',
            'run_at' => substr((string) ($data['run_at'] ?? '08:00'), 0, 5),
            'day_of_week' => $data['frequency'] === 'weekly' ? (int) ($data['day_of_week'] ?? 1) : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? (int) ($data['day_of_month'] ?? 1) : null,
            'timezone' => config('app.timezone', 'Africa/Addis_Ababa'),
            'delivery' => $data['delivery'] ?? 'agent',
            'format' => $network ? ($data['format'] ?? 'pdf') : 'html',
            'paper' => $data['paper'] ?? 'a4',
            'copies' => (int) ($data['copies'] ?? 1),
            'printer_connection' => $network ? $connection : PrinterService::CONNECTION_SYSTEM,
            // One column holds whichever identifier this connection uses: a
            // Windows/CUPS printer name, or a \\PC\Share path.
            'printer_name' => $connection === PrinterService::CONNECTION_SHARE
                ? ($data['printer_share'] ?? null)
                : ($data['printer_name'] ?? null),
            'printer_host' => $data['printer_host'] ?? null,
            'printer_port' => $data['printer_port'] ?? null,
            'printer_protocol' => in_array($connection, [PrinterService::CONNECTION_RAW, PrinterService::CONNECTION_IPP], true)
                ? $connection
                : PrinterService::CONNECTION_RAW,
            'printer_queue' => $data['printer_queue'] ?? null,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        Notification::make()
            ->title(__('filament.equb_report.schedule_saved'))
            ->body(__('filament.equb_report.schedule_next_run', [
                'time' => $schedule->next_run_at
                    ?->timezone($schedule->timezone)
                    ->translatedFormat('l, j F Y').' '
                    .__('filament.equb_report.at').' '
                    .$schedule->next_run_at?->timezone($schedule->timezone)->format('g:i A') ?? '—',
            ]))
            ->success()
            ->send();
    }

    public function toggleSchedule(int $id): void
    {
        $schedule = ReportPrintSchedule::find($id);

        if (! $schedule || ! $this->canManageSchedules()) {
            return;
        }

        $schedule->update(['is_active' => ! $schedule->is_active]);

        Notification::make()
            ->title($schedule->is_active
                ? __('filament.equb_report.schedule_enabled')
                : __('filament.equb_report.schedule_disabled'))
            ->success()
            ->send();
    }

    public function runScheduleNow(int $id): void
    {
        $schedule = ReportPrintSchedule::find($id);

        if (! $schedule || ! $this->canManageSchedules()) {
            return;
        }

        $result = app(ReportPrintService::class)->runSchedule($schedule);

        Notification::make()
            ->title($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->send();
    }

    public function deleteSchedule(int $id): void
    {
        if (! $this->canManageSchedules()) {
            return;
        }

        ReportPrintSchedule::whereKey($id)->delete();

        Notification::make()
            ->title(__('filament.equb_report.schedule_deleted'))
            ->success()
            ->send();
    }

    public function retryPrintJob(int $id): void
    {
        $job = ReportPrintJob::find($id);

        if (! $job || ! $this->canManageSchedules()) {
            return;
        }

        $job->requeue();

        Notification::make()
            ->title(__('filament.equb_report.job_requeued'))
            ->success()
            ->send();
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\EqubPayment;
use App\Models\ReconciliationDay;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentSettlementService;
use App\Services\Reconciliation\BankStatementImporter;
use App\Services\Reconciliation\ReconciliationMatcher;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\StatementColumnMapper;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Does the money in the bank equal the money we told members we received?
 *
 * WHY THE PAYMENTS TABLE CANNOT ANSWER THAT
 *
 * Our records say what we believe. Reconciliation is the act of testing that
 * belief against an independent account — the bank's — and the only findings
 * that matter are the ones neither side can produce alone:
 *
 *   money in our account that nobody was credited for. A member paid, and the
 *   Equb is still chasing them for it. Invisible from our data, because from
 *   our side nothing happened.
 *
 *   contributions we credited that the bank has no record of. The books
 *   overstate cash. Invisible from the statement, because from the bank's side
 *   nothing happened.
 *
 * So this page always shows two columns and the gap between them, and it
 * refuses to let a day be signed off before a statement has been loaded —
 * comparing our figures with themselves always balances and means nothing.
 */
class Reconciliation extends Page
{
    protected string $view = 'filament.pages.reconciliation';

    protected static ?int $navigationSort = 6;

    #[Url(as: 'bank', history: true)]
    public string $gateway = '';

    #[Url(as: 'd', history: true)]
    public string $date = '';

    /** Which exception queue is open, if any. */
    #[Url(as: 'q', history: true)]
    public ?string $queue = null;

    /**
     * A file that has been read but not yet imported.
     *
     * Held in page state rather than imported straight away so the operator
     * confirms the column mapping against real values from their own file
     * first. A mapping that is merely plausible imports cleanly with every
     * amount read from the wrong column, and nothing downstream can detect it.
     *
     * @var array<string, mixed>
     */
    public array $import = [];

    /** @var array<string, int|string|null> field => column index */
    public array $mapping = [];

    protected ?array $cachedTotals = null;

    // -----------------------------------------------------------------
    // Navigation and access
    // -----------------------------------------------------------------

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-scale';
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.reconciliation.nav');
    }

    public function getTitle(): string
    {
        return __('filament.reconciliation.title');
    }

    public function getSubheading(): ?string
    {
        return __('filament.reconciliation.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.equb');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.reconciliation')
            || Auth::user()->can('equb-payments.index')
        );
    }

    protected function canSignOff(): bool
    {
        return Auth::check() && (
            Auth::user()->hasRole('Super Admin')
            || Auth::user()->can('admin.pages.reconciliation.sign-off')
        );
    }

    public function mount(): void
    {
        if ($this->gateway === '') {
            $this->gateway = (string) (array_key_first($this->gateways()) ?? '');
        }

        if ($this->date === '') {
            // Yesterday, not today. A day is reconciled once it is over;
            // opening on a half-finished day shows a variance that is simply
            // the rest of the day not having happened yet.
            $this->date = CarbonImmutable::now()->subDay()->toDateString();
        }
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    /** @return array<string, string> */
    public function gateways(): array
    {
        return collect(app(PaymentGatewayManager::class)->all())
            ->mapWithKeys(fn ($gateway, string $slug): array => [
                $slug => config("payments.gateways.{$slug}.name", Str::title($slug)),
            ])
            ->all();
    }

    public function businessDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->date)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now()->subDay()->startOfDay();
        }
    }

    /** @return array<string, mixed> */
    public function totals(): array
    {
        return $this->cachedTotals ??= app(ReconciliationService::class)
            ->compute($this->businessDate(), $this->gateway);
    }

    public function day(): ?ReconciliationDay
    {
        return ReconciliationDay::query()
            ->where('gateway', $this->gateway)
            ->whereDate('business_date', $this->businessDate()->toDateString())
            ->first();
    }

    /**
     * Has a signed-off day moved since it was signed?
     *
     * The most important thing this page can say. A late settlement or a
     * re-imported statement can change a day that somebody already put their
     * name to, and that must surface rather than quietly replacing the record.
     */
    public function drift(): ?array
    {
        $day = $this->day();

        if (! $day || ! $day->isSignedOff()) {
            return null;
        }

        $fresh = $this->totals();

        if (! $day->hasDriftedFrom($fresh)) {
            return null;
        }

        return [
            'signed_ours' => (float) $day->our_amount,
            'signed_bank' => (float) $day->bank_amount,
            'now_ours' => $fresh['our_amount'],
            'now_bank' => $fresh['bank_amount'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function exceptions(): array
    {
        return app(ReconciliationService::class)->exceptionSummary($this->gateway);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function queueRows(): Collection
    {
        if (! $this->queue) {
            return collect();
        }

        return app(ReconciliationService::class)->exceptionRows($this->queue, $this->gateway);
    }

    public function queueDirection(): string
    {
        return $this->queue
            ? app(ReconciliationService::class)->direction($this->queue)
            : 'bank';
    }

    /** @return Collection<int, ReconciliationDay> */
    public function recentDays(): Collection
    {
        return app(ReconciliationService::class)->recentDays($this->gateway, 14);
    }

    /** @return Collection<int, BankStatement> */
    public function statements(): Collection
    {
        return BankStatement::query()
            ->where('gateway', $this->gateway)
            ->with('importer')
            ->latest()
            ->limit(6)
            ->get();
    }

    public function openQueue(?string $key): void
    {
        $this->queue = $this->queue === $key ? null : $key;
    }

    public function updatedGateway(): void
    {
        $this->cachedTotals = null;
        $this->queue = null;
    }

    public function updatedDate(): void
    {
        $this->cachedTotals = null;
    }

    public function shiftDay(int $days): void
    {
        $this->date = $this->businessDate()->addDays($days)->toDateString();
        $this->cachedTotals = null;
    }

    // -----------------------------------------------------------------
    // Header actions
    // -----------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            $this->importStatementAction(),
            $this->rematchAction(),
            $this->signOffAction(),
            $this->reopenAction(),
        ];
    }

    /**
     * Read a statement file and show what it would import.
     *
     * Nothing is written here. The file is read, the columns are guessed, and
     * the mapping panel opens for confirmation.
     */
    public function importStatementAction(): Action
    {
        return Action::make('importStatement')
            ->label(__('filament.reconciliation.import'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading(__('filament.reconciliation.import_heading'))
            ->modalDescription(__('filament.reconciliation.import_description'))
            ->modalSubmitActionLabel(__('filament.reconciliation.import_read'))
            ->schema([
                Select::make('gateway')
                    ->label(__('filament.reconciliation.bank'))
                    ->options($this->gateways())
                    ->default($this->gateway)
                    ->native(false)
                    ->required(),

                FileUpload::make('file')
                    ->label(__('filament.reconciliation.statement_file'))
                    ->helperText(__('filament.reconciliation.statement_file_helper'))
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain', 'application/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    // Kept out of the permanent store until it has been read:
                    // a file that turns out to be unreadable should leave
                    // nothing behind.
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->readStatement($data);
            });
    }

    /** @param  array<string, mixed>  $data */
    protected function readStatement(array $data): void
    {
        $upload = is_array($data['file']) ? reset($data['file']) : $data['file'];

        if (! $upload) {
            return;
        }

        $gateway = (string) ($data['gateway'] ?? $this->gateway);
        $originalName = method_exists($upload, 'getClientOriginalName')
            ? $upload->getClientOriginalName()
            : 'statement.csv';

        // Copied out of Livewire's temporary store: that directory is swept
        // on a schedule, and the preview has to survive however long the
        // operator takes over the mapping.
        $incoming = 'bank-statements/incoming/'.Str::random(24).'.'.pathinfo($originalName, PATHINFO_EXTENSION);
        Storage::disk('local')->put($incoming, file_get_contents($upload->getRealPath()));

        try {
            $preview = app(BankStatementImporter::class)->preview(
                Storage::disk('local')->path($incoming),
                $originalName,
                $gateway,
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($incoming);

            Notification::make()
                ->title(__('filament.reconciliation.import_failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->import = [
            'path' => $incoming,
            'name' => $originalName,
            'gateway' => $gateway,
            'header_row' => $preview['header_row'],
            'headers' => $preview['headers'],
            'preview' => $preview['preview'],
            'total_rows' => $preview['total_rows'],
            'confidence' => $preview['confidence'],
            'missing' => $preview['missing'],
        ];

        $this->mapping = $preview['map'];
    }

    /** Commit the import using the mapping now on screen. */
    public function confirmImport(): void
    {
        if ($this->import === []) {
            return;
        }

        $map = collect($this->mapping)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value): int => (int) $value)
            ->all();

        try {
            $statement = app(BankStatementImporter::class)->import(
                Storage::disk('local')->path($this->import['path']),
                (string) $this->import['name'],
                (string) $this->import['gateway'],
                $map,
                $this->import['header_row'] === null ? null : (int) $this->import['header_row'],
                Auth::id(),
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('filament.reconciliation.import_failed'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Storage::disk('local')->delete($this->import['path']);

        // Match immediately. Waiting for the hourly sweep would leave the
        // operator staring at a statement that imported and appears to have
        // done nothing.
        $result = app(ReconciliationMatcher::class)->matchAll($statement->gateway, $statement->id);

        // And rebuild the control totals for the days the file covers.
        $this->closeDaysFor($statement);

        $this->gateway = $statement->gateway;
        $this->import = [];
        $this->mapping = [];
        $this->cachedTotals = null;

        Notification::make()
            ->title(__('filament.reconciliation.import_done', [
                'count' => $statement->imported_count,
            ]))
            ->body(__('filament.reconciliation.import_done_body', [
                'matched' => $result['matched'],
                'duplicates' => $statement->duplicate_count,
                'unmatched' => $result['unmatched'] + $result['ambiguous'],
            ]))
            ->success()
            ->persistent()
            ->send();
    }

    public function cancelImport(): void
    {
        if (filled($this->import['path'] ?? null)) {
            Storage::disk('local')->delete($this->import['path']);
        }

        $this->import = [];
        $this->mapping = [];
    }

    protected function closeDaysFor(BankStatement $statement): void
    {
        $service = app(ReconciliationService::class);
        $start = $statement->period_start
            ? CarbonImmutable::parse($statement->period_start)
            : CarbonImmutable::now()->subDays(7);
        $end = $statement->period_end
            ? CarbonImmutable::parse($statement->period_end)
            : CarbonImmutable::now();

        // Bounded: a mis-parsed date column could otherwise produce a range of
        // years and spend the request rebuilding empty days.
        $cursor = $start;
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($end) && $guard++ < 400) {
            $service->close($cursor, $statement->gateway);
            $cursor = $cursor->addDay();
        }
    }

    public function rematchAction(): Action
    {
        return Action::make('rematch')
            ->label(__('filament.reconciliation.rematch'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->action(function (): void {
                $result = app(ReconciliationMatcher::class)->matchAll($this->gateway);
                app(ReconciliationService::class)->close($this->businessDate(), $this->gateway);
                $this->cachedTotals = null;

                Notification::make()
                    ->title(__('filament.reconciliation.rematch_done', ['count' => $result['matched']]))
                    ->body(__('filament.reconciliation.rematch_body', [
                        'examined' => $result['examined'],
                        'ambiguous' => $result['ambiguous'],
                        'unmatched' => $result['unmatched'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    public function signOffAction(): Action
    {
        return Action::make('signOff')
            ->label(__('filament.reconciliation.sign_off'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => $this->canSignOff()
                && ! ($this->day()?->isSignedOff() ?? false))
            ->modalHeading(fn (): string => __('filament.reconciliation.sign_off_heading', [
                'date' => $this->businessDate()->translatedFormat('l, d F Y'),
            ]))
            ->modalDescription(__('filament.reconciliation.sign_off_description'))
            ->schema([
                Textarea::make('note')
                    ->label(__('filament.reconciliation.sign_off_note'))
                    ->helperText(__('filament.reconciliation.sign_off_note_helper'))
                    ->rows(3)
                    // Required only when the day does not balance: signing off
                    // a gap without saying why is a gap with a signature on it.
                    ->required(fn (): bool => abs((float) ($this->totals()['variance'] ?? 0)) >= 0.01),
            ])
            ->action(function (array $data): void {
                $day = app(ReconciliationService::class)->close($this->businessDate(), $this->gateway);
                $result = app(ReconciliationService::class)->signOff($day, Auth::id(), $data['note'] ?? null);

                $this->cachedTotals = null;

                Notification::make()
                    ->title($result['message'])
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->send();
            });
    }

    public function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('filament.reconciliation.reopen'))
            ->icon('heroicon-o-lock-open')
            ->color('gray')
            ->visible(fn (): bool => $this->canSignOff() && ($this->day()?->isSignedOff() ?? false))
            ->requiresConfirmation()
            ->modalHeading(__('filament.reconciliation.reopen_heading'))
            ->modalDescription(__('filament.reconciliation.reopen_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('filament.reconciliation.reopen_reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $day = $this->day();

                if ($day) {
                    app(ReconciliationService::class)->reopen($day, Auth::id(), $data['reason']);
                    $this->cachedTotals = null;
                }

                Notification::make()->title(__('filament.reconciliation.reopened'))->success()->send();
            });
    }

    // -----------------------------------------------------------------
    // Row actions
    // -----------------------------------------------------------------

    /** Pair a bank credit with a contribution by hand. */
    public function matchLineAction(): Action
    {
        return Action::make('matchLine')
            ->label(__('filament.reconciliation.match_to'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->size('sm')
            ->link()
            ->modalHeading(__('filament.reconciliation.match_heading'))
            ->modalDescription(__('filament.reconciliation.match_description'))
            ->schema(fn (array $arguments): array => [
                Select::make('payment_id')
                    ->label(__('filament.reconciliation.contribution'))
                    ->options(fn (): array => $this->candidatesFor((int) $arguments['line']))
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->helperText(__('filament.reconciliation.match_search_helper')),

                Textarea::make('note')
                    ->label(__('filament.reconciliation.why'))
                    ->rows(2)
                    ->helperText(__('filament.reconciliation.why_helper')),
            ])
            ->action(function (array $arguments, array $data): void {
                $line = BankStatementLine::find($arguments['line']);
                $payment = EqubPayment::find($data['payment_id']);

                if (! $line || ! $payment) {
                    return;
                }

                app(ReconciliationMatcher::class)->matchManually(
                    $line,
                    $payment,
                    Auth::id(),
                    $data['note'] ?? null,
                );

                $this->cachedTotals = null;

                Notification::make()
                    ->title(__('filament.reconciliation.matched_manually'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Contributions a given credit could belong to.
     *
     * Deliberately wide — the same amount within a fortnight, plus anything
     * whose reference contains what the bank wrote. The operator is doing this
     * precisely because the automatic rules found nothing, so a narrow list
     * would just be the empty result that sent them here.
     *
     * @return array<int, string>
     */
    protected function candidatesFor(int $lineId): array
    {
        $line = BankStatementLine::find($lineId);

        if (! $line) {
            return [];
        }

        $anchor = $line->posted_at ?? now();

        return EqubPayment::query()
            ->where('payment_method', $line->gateway)
            ->whereBetween('created_at', [
                $anchor->copy()->subDays(14),
                $anchor->copy()->addDays(14),
            ])
            ->with(['membership.member', 'membership.equbGroup'])
            ->orderByRaw('ABS(amount - ?) asc', [(float) $line->amount])
            ->limit(60)
            ->get()
            ->mapWithKeys(fn (EqubPayment $payment): array => [
                $payment->id => sprintf(
                    '#%d · %s · %s ETB · %s · %s',
                    $payment->id,
                    $payment->membership?->displayName() ?? '—',
                    number_format((float) $payment->amount, 2),
                    $payment->status?->value ?? '',
                    ($payment->bank_paid_at ?? $payment->created_at)?->format('d M H:i') ?? '',
                ),
            ])
            ->all();
    }

    /** Set a credit aside: a fee, a reversal, an internal transfer. */
    public function ignoreLineAction(): Action
    {
        return Action::make('ignoreLine')
            ->label(__('filament.reconciliation.set_aside'))
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->size('sm')
            ->link()
            ->modalHeading(__('filament.reconciliation.set_aside_heading'))
            ->modalDescription(__('filament.reconciliation.set_aside_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('filament.reconciliation.reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $line = BankStatementLine::find($arguments['line']);

                if (! $line) {
                    return;
                }

                app(ReconciliationMatcher::class)->ignore($line, Auth::id(), $data['reason']);
                $this->cachedTotals = null;

                Notification::make()->title(__('filament.reconciliation.set_aside_done'))->success()->send();
            });
    }

    public function unlinkLineAction(): Action
    {
        return Action::make('unlinkLine')
            ->label(__('filament.reconciliation.unlink'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->size('sm')
            ->link()
            ->requiresConfirmation()
            ->modalHeading(__('filament.reconciliation.unlink_heading'))
            ->modalDescription(__('filament.reconciliation.unlink_description'))
            ->action(function (array $arguments): void {
                BankStatementLine::find($arguments['line'])?->unlink();
                $this->cachedTotals = null;

                Notification::make()->title(__('filament.reconciliation.unlinked'))->success()->send();
            });
    }

    /**
     * Bless a contribution on a person's authority.
     *
     * The escape hatch for money no rule will ever match: cash over a counter,
     * a transfer that arrived under a relative's name. A note is required
     * because this is the one path where a figure is accepted without
     * independent evidence, and the trail has to say who accepted it.
     */
    public function acceptPaymentAction(): Action
    {
        return Action::make('acceptPayment')
            ->label(__('filament.reconciliation.accept'))
            ->icon('heroicon-o-check')
            ->color('warning')
            ->size('sm')
            ->link()
            ->modalHeading(__('filament.reconciliation.accept_heading'))
            ->modalDescription(__('filament.reconciliation.accept_description'))
            ->schema([
                Textarea::make('note')
                    ->label(__('filament.reconciliation.evidence'))
                    ->helperText(__('filament.reconciliation.evidence_helper'))
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $payment = EqubPayment::find($arguments['payment']);

                if (! $payment) {
                    return;
                }

                app(ReconciliationService::class)->acceptManually($payment, Auth::id(), $data['note']);
                $this->cachedTotals = null;

                Notification::make()->title(__('filament.reconciliation.accepted'))->success()->send();
            });
    }

    /** Ask the bank about one contribution, now. */
    public function askBankAction(): Action
    {
        return Action::make('askBank')
            ->label(__('filament.reconciliation.ask_bank'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->size('sm')
            ->link()
            ->action(function (array $arguments): void {
                $payment = EqubPayment::find($arguments['payment']);

                if (! $payment) {
                    return;
                }

                $gateway = app(PaymentGatewayManager::class)
                    ->tryGet($payment->payment_method?->value ?? '');

                if (! $gateway) {
                    Notification::make()
                        ->title(__('filament.reconciliation.bank_not_configured'))
                        ->danger()
                        ->send();

                    return;
                }

                // The batch reference is the one the bank knows when a member
                // settled several places in one charge. Asking with this row's
                // own reference returns "not found" and means nothing.
                $reference = $payment->batch_reference ?: $payment->reference;

                if (blank($reference)) {
                    Notification::make()
                        ->title(__('filament.reconciliation.no_reference'))
                        ->warning()
                        ->send();

                    return;
                }

                $result = app(PaymentSettlementService::class)->reconcile($gateway, $reference);
                $this->cachedTotals = null;

                Notification::make()
                    ->title($result['success'] ? __('filament.reconciliation.bank_confirmed') : __('filament.reconciliation.bank_not_confirmed'))
                    ->body($result['message'] ?? '')
                    ->status($result['success'] ? 'success' : 'warning')
                    ->send();
            });
    }

    // -----------------------------------------------------------------
    // Helpers for the view
    // -----------------------------------------------------------------

    /** @return array<string, string> */
    public function fieldLabels(): array
    {
        return StatementColumnMapper::fieldLabels();
    }

    /**
     * Column choices for the mapping panel, labelled with the header text and
     * a sample value — a column called "REF2" means nothing until you can see
     * that it holds EQUB-7F3K2M9QX1BV.
     *
     * @return array<int, string>
     */
    public function columnOptions(): array
    {
        $headers = $this->import['headers'] ?? [];
        $sample = $this->import['preview'][0] ?? [];

        if ($headers === []) {
            return [];
        }

        $options = [];

        foreach ($headers as $index => $header) {
            $label = trim((string) $header) !== ''
                ? (string) $header
                : __('filament.reconciliation.column_n', ['n' => $index + 1]);

            $options[$index] = $label;
        }

        return $options;
    }

    public function requiredFields(): array
    {
        return StatementColumnMapper::REQUIRED;
    }
}

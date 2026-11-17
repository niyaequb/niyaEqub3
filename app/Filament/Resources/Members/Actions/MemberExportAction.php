<?php

namespace App\Filament\Resources\Members\Actions;

use App\Models\Member;
use Dompdf\Dompdf;
use Dompdf\Options;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Members export: lets the admin pick exactly which fields to export
 * and the output format (CSV or PDF). Respects the table's current
 * search, filters and sort, or exports only the selected rows.
 */
class MemberExportAction
{
    /** Hard cap for PDF rendering (Dompdf gets slow beyond this). CSV has no cap. */
    public const PDF_MAX_ROWS = 2000;

    protected const PDF_SESSION_PREFIX = 'exports.members.pdf.';

    protected const SESSION_KEY = 'exports.members';

    protected const DEFAULT_COLUMNS = ['id', 'full_name', 'phone', 'address'];

    /**
     * Exportable fields: key => [label, value resolver].
     *
     * @return array<string, array{0: string, 1: callable(Member): ?string}>
     */
    public static function fields(): array
    {
        $date = fn ($value, string $format = 'M j, Y'): ?string => $value?->format($format);

        return [
            'id' => ['ID', fn (Member $m) => (string) $m->id],
            'full_name' => ['Full Name', fn (Member $m) => $m->full_name],
            'phone' => ['Phone', fn (Member $m) => $m->user?->phone],
            'email' => ['Email', fn (Member $m) => $m->user?->email],
            'gender' => ['Gender', fn (Member $m) => $m->gender ? ucfirst($m->gender) : null],
            'date_of_birth' => ['Date of Birth', fn (Member $m) => $date($m->date_of_birth)],
            'address' => ['Address', fn (Member $m) => $m->address],
            'city' => ['City', fn (Member $m) => $m->city],
            'agent' => ['Agent', fn (Member $m) => $m->agent?->user?->name],
            'registered_via' => ['Registered Via', fn (Member $m) => $m->registered_via ? ucfirst((string) $m->registered_via->value) : null],
            'referral_code' => ['Referral Code', fn (Member $m) => $m->referral_code_used],
            'is_active' => ['Active', fn (Member $m) => $m->user?->is_active ? 'Yes' : 'No'],
            'phone_verified' => ['Phone Verified', fn (Member $m) => $m->user?->phone_verified_at ? 'Yes' : 'No'],
            'registered_at' => ['Registered At', fn (Member $m) => $date($m->registered_at, 'M j, Y H:i')],
            'created_at' => ['Created At', fn (Member $m) => $date($m->created_at, 'M j, Y H:i')],
        ];
    }

    /**
     * Header action: exports everything matching the current search / filters / sort.
     */
    public static function make(): Action
    {
        return Action::make('exportMembers')
            ->label('Export')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Export members')
            ->modalDescription(function ($livewire): string {
                $count = number_format((clone $livewire->getFilteredTableQuery())->count());

                return "{$count} member(s) match the current search and filters. Choose the fields and format to export.";
            })
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Export')
            ->schema(static::formSchema())
            ->fillForm(static::rememberedState())
            ->action(function (array $data, $livewire) {
                $query = $livewire->getFilteredSortedTableQuery();

                return static::export($query, $data, static::describeFilters($livewire), $livewire);
            });
    }

    /**
     * Bulk action: exports only the rows the admin ticked.
     */
    public static function makeBulk(): BulkAction
    {
        return BulkAction::make('exportSelectedMembers')
            ->label('Export selected')
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Export selected members')
            ->modalDescription(fn (Collection $records): string => number_format($records->count()).' selected member(s) will be exported. Choose the fields and format.')
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Export')
            ->schema(static::formSchema())
            ->fillForm(static::rememberedState())
            ->deselectRecordsAfterCompletion()
            ->action(function (array $data, Collection $records, $livewire) {
                $query = Member::query()->whereKey($records->modelKeys())->orderBy('id');

                return static::export($query, $data, ['Selection' => number_format($records->count()).' selected row(s)'], $livewire);
            });
    }

    protected static function formSchema(): array
    {
        $options = collect(static::fields())->map(fn (array $field) => $field[0])->all();

        return [
            Section::make('Columns')
                ->description('Tick the fields to include, in the order shown.')
                ->icon('heroicon-o-view-columns')
                ->schema([
                    CheckboxList::make('columns')
                        ->hiddenLabel()
                        ->options($options)
                        ->columns(3)
                        ->bulkToggleable()
                        ->required()
                        ->minItems(1)
                        ->validationMessages([
                            'required' => 'Select at least one column to export.',
                            'min' => 'Select at least one column to export.',
                        ]),
                ]),
            Section::make('Format')
                ->icon('heroicon-o-document-arrow-down')
                ->schema([
                    Grid::make(2)->schema([
                        ToggleButtons::make('format')
                            ->label('File type')
                            ->options([
                                'csv' => 'CSV',
                                'pdf' => 'PDF',
                            ])
                            ->icons([
                                'csv' => 'heroicon-o-table-cells',
                                'pdf' => 'heroicon-o-document-text',
                            ])
                            ->colors([
                                'csv' => 'success',
                                'pdf' => 'danger',
                            ])
                            ->inline()
                            ->required()
                            ->live(),
                        Select::make('orientation')
                            ->label('Page orientation')
                            ->options([
                                'auto' => 'Automatic (by column count)',
                                'portrait' => 'Portrait',
                                'landscape' => 'Landscape',
                            ])
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => $get('format') === 'pdf'),
                    ]),
                ]),
        ];
    }

    /** Pre-fills the form with the admin's last choices (per session). */
    protected static function rememberedState(): array
    {
        $saved = session(static::SESSION_KEY, []);
        $valid = array_keys(static::fields());

        $columns = array_values(array_intersect($saved['columns'] ?? static::DEFAULT_COLUMNS, $valid));

        return [
            'columns' => $columns ?: static::DEFAULT_COLUMNS,
            'format' => in_array($saved['format'] ?? null, ['csv', 'pdf'], true) ? $saved['format'] : 'csv',
            'orientation' => $saved['orientation'] ?? 'auto',
        ];
    }

    protected static function export(Builder $query, array $data, array $filters, $livewire): ?StreamedResponse
    {
        $fields = static::fields();

        // Keep the field order defined above, regardless of click order.
        $selected = array_values(array_filter(array_keys($fields), fn (string $key) => in_array($key, $data['columns'] ?? [], true)));

        if ($selected === []) {
            Notification::make()->title('Select at least one column to export.')->danger()->send();

            return null;
        }

        session([static::SESSION_KEY => [
            'columns' => $selected,
            'format' => $data['format'] ?? 'csv',
            'orientation' => $data['orientation'] ?? 'auto',
        ]]);

        $query = (clone $query)->with(['user', 'agent.user']);

        if (empty($query->getQuery()->orders)) {
            $query->orderBy($query->getModel()->getQualifiedKeyName());
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->body('No members match the current search and filters.')
                ->warning()
                ->send();

            return null;
        }

        $columns = array_intersect_key($fields, array_flip($selected));

        $filename = 'members_'.now()->format('Y-m-d_His');

        if (($data['format'] ?? 'csv') === 'pdf') {
            static::queuePdfDownload($query, $selected, $total, $filters, $data['orientation'] ?? 'auto', $livewire);

            return null;
        }

        return static::exportCsv($query, $columns, $filename.'.csv');
    }

    protected static function exportCsv(Builder $query, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $columns): void {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel shows Amharic correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_map(fn (array $c) => $c[0], $columns));

            foreach ($query->lazy(500) as $member) {
                fputcsv($handle, array_map(fn (array $c) => static::clean(($c[1])($member)), $columns));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * PDFs are rendered on a dedicated HTTP route rather than inside the Livewire
     * request: Dompdf needs more time/memory than a Livewire call allows, and
     * Livewire would otherwise base64-embed the whole file in its JSON response.
     * The request details are parked in the admin's own session under a random
     * token, and the browser is sent to a short-lived signed URL.
     */
    protected static function queuePdfDownload(Builder $query, array $selected, int $total, array $filters, string $orientation, $livewire): void
    {
        $keyName = $query->getModel()->getQualifiedKeyName();

        $ids = (clone $query)->limit(static::PDF_MAX_ROWS)->pluck($keyName)->all();

        $token = Str::random(40);

        session()->put(static::PDF_SESSION_PREFIX.$token, [
            'ids' => $ids,
            'columns' => $selected,
            'total' => $total,
            'filters' => $filters,
            'orientation' => $orientation,
        ]);

        // Relative signature: immune to http/https or host differences behind a proxy.
        $url = URL::temporarySignedRoute('admin.members.export-pdf', now()->addMinutes(10), ['token' => $token], absolute: false);

        if ($total > static::PDF_MAX_ROWS) {
            Notification::make()
                ->title('PDF limited to '.number_format(static::PDF_MAX_ROWS).' rows')
                ->body('Only the first '.number_format(static::PDF_MAX_ROWS).' of '.number_format($total).' members are in the PDF. Use CSV for the full list, or narrow the filters.')
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title('Preparing your PDF…')
                ->body('The download will start in a few seconds.')
                ->info()
                ->send();
        }

        $livewire->js('window.location.href = '.json_encode($url).';');
    }

    /**
     * Called by the download route. Builds and returns the PDF for a queued export.
     */
    public static function pdfResponse(string $token): Response
    {
        $payload = session()->pull(static::PDF_SESSION_PREFIX.$token);

        abort_if(! is_array($payload), 410, 'This export link has expired. Please run the export again.');

        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $fields = static::fields();
        $columns = array_intersect_key($fields, array_flip($payload['columns']));

        $ids = $payload['ids'];

        // Fetch in the exact order the admin saw in the table.
        $members = collect($ids)->chunk(1000)->flatMap(fn ($chunk) => Member::query()
            ->with(['user', 'agent.user'])
            ->whereKey($chunk->all())
            ->get())
            ->keyBy('id');

        $rows = collect($ids)
            ->map(fn ($id) => $members->get($id))
            ->filter()
            ->values()
            ->map(fn (Member $m) => array_values(array_map(fn (array $c) => static::clean(($c[1])($m)), $columns)));

        $orientation = $payload['orientation'];
        if (! in_array($orientation, ['portrait', 'landscape'], true)) {
            $orientation = count($columns) > 6 ? 'landscape' : 'portrait';
        }

        // Only embed the (large) Ethiopic font when the data actually needs it.
        $needsEthiopic = $rows->contains(fn (array $row) => preg_match('/\p{Ethiopic}/u', implode(' ', $row)) === 1);

        $html = view('exports.members-pdf', [
            'brand' => (string) (filament()->getBrandName() ?: config('app.name')),
            'columns' => array_values(array_map(fn (array $c) => $c[0], $columns)),
            'rows' => $rows,
            'total' => $payload['total'],
            'shown' => $rows->count(),
            'filters' => $payload['filters'],
            'generatedAt' => now()->format('M j, Y \a\t H:i'),
            'generatedBy' => Auth::user()?->name,
            'ethiopicFont' => $needsEthiopic ? static::ethiopicFontPaths() : null,
        ])->render();

        // Dompdf caches font metrics in its font dir. The default lives in vendor/,
        // which usually isn't writable on the server — and then custom fonts are
        // silently ignored. Use a writable storage folder instead.
        $fontCache = storage_path('fonts');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);
        $options->set('chroot', [base_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        // Page numbers in the footer.
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 90,
            $canvas->get_height() - 24,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            7,
            [0.45, 0.45, 0.45],
        );

        $filename = 'members_'.now()->format('Y-m-d_His').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Human-readable summary of the active search / filters for the PDF header. */
    protected static function describeFilters($livewire): array
    {
        $summary = [];

        if (filled($search = $livewire->tableSearch ?? null)) {
            $summary['Search'] = $search;
        }

        $filters = $livewire->tableFilters ?? [];

        if (filled($address = data_get($filters, 'address.address'))) {
            $summary['Address'] = $address;
        }

        if (filled($city = data_get($filters, 'city.value'))) {
            $summary['City'] = $city;
        }

        return $summary;
    }

    protected static function clean(?string $value): string
    {
        return trim((string) $value);
    }

    /** Bundled Noto Sans Ethiopic font files (resources/fonts), or null if missing. */
    protected static function ethiopicFontPaths(): ?array
    {
        $regular = resource_path('fonts/NotoSansEthiopic-Regular.ttf');
        $bold = resource_path('fonts/NotoSansEthiopic-Bold.ttf');

        if (! is_file($regular)) {
            return null;
        }

        return [
            'regular' => $regular,
            'bold' => is_file($bold) ? $bold : $regular,
        ];
    }

    /**
     * Escapes a value for the PDF and wraps Amharic runs in <span class="et">.
     *
     * Noto Sans Ethiopic has no Latin letters or digits, and Dompdf does not
     * reliably fall back per character, so only the Ethiopic text gets that
     * font; everything else stays in DejaVu Sans.
     */
    public static function pdfText(?string $value): string
    {
        $escaped = e((string) $value);

        return preg_replace(
            '/\p{Ethiopic}+(?:[ \x{00A0}]+\p{Ethiopic}+)*/u',
            '<span class="et">$0</span>',
            $escaped,
        ) ?? $escaped;
    }
}

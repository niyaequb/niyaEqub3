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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Members export: lets the admin pick exactly which fields to export
 * and the output format (CSV or PDF). Respects the table's current
 * search, filters and sort, or exports only the selected rows.
 */
class MemberExportAction
{
    /** Hard cap for PDF rendering (Dompdf gets slow beyond this). CSV has no cap. */
    protected const PDF_MAX_ROWS = 2000;

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

                return static::export($query, $data, static::describeFilters($livewire));
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
            ->action(function (array $data, Collection $records) {
                $query = Member::query()->whereKey($records->modelKeys())->orderBy('id');

                return static::export($query, $data, ['Selection' => number_format($records->count()).' selected row(s)']);
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

    protected static function export(Builder $query, array $data, array $filters): ?StreamedResponse
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

        return ($data['format'] ?? 'csv') === 'pdf'
            ? static::exportPdf($query, $columns, $total, $filters, $data['orientation'] ?? 'auto', $filename.'.pdf')
            : static::exportCsv($query, $columns, $filename.'.csv');
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

    protected static function exportPdf(Builder $query, array $columns, int $total, array $filters, string $orientation, string $filename): StreamedResponse
    {
        $members = (clone $query)->limit(static::PDF_MAX_ROWS)->get();

        if ($total > static::PDF_MAX_ROWS) {
            Notification::make()
                ->title('PDF limited to '.number_format(static::PDF_MAX_ROWS).' rows')
                ->body('Only the first '.number_format(static::PDF_MAX_ROWS).' of '.number_format($total).' members are in the PDF. Use CSV for the full list, or narrow the filters.')
                ->warning()
                ->persistent()
                ->send();
        }

        if ($orientation === 'auto') {
            $orientation = count($columns) > 6 ? 'landscape' : 'portrait';
        }

        $html = view('exports.members-pdf', [
            'brand' => filament()->getBrandName() ?: config('app.name'),
            'columns' => array_map(fn (array $c) => $c[0], $columns),
            'rows' => $members->map(fn (Member $m) => array_map(fn (array $c) => static::clean(($c[1])($m)), $columns)),
            'total' => $total,
            'shown' => $members->count(),
            'filters' => $filters,
            'generatedAt' => now()->format('M j, Y \a\t H:i'),
            'generatedBy' => Auth::user()?->name,
            'fontBase64' => static::ethiopicFont(),
        ])->render();

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        // Page numbers in the footer.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 90,
            $canvas->get_height() - 24,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7,
            [0.45, 0.45, 0.45],
        );

        $output = $dompdf->output();

        return response()->streamDownload(function () use ($output): void {
            echo $output;
        }, $filename, [
            'Content-Type' => 'application/pdf',
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

    protected static function ethiopicFont(): ?string
    {
        $path = storage_path('app/public/fonts/NotoSansEthiopic-Regular.ttf');

        return is_file($path) ? base64_encode(file_get_contents($path)) : null;
    }
}

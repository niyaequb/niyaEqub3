<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Dompdf\Dompdf;
use Dompdf\Options;

class TableExportHelper
{
    public static function attach(Table $table, string $title = 'Export'): void
    {
        $table->pushHeaderActions([
            ActionGroup::make([
                Action::make('export_csv')
                    ->label('Download CSV')
                    ->icon('heroicon-o-document-text')
                    ->action(fn ($livewire) => self::exportCsv($livewire, $title)),
                
                Action::make('export_json')
                    ->label('Download JSON')
                    ->icon('heroicon-o-code-bracket')
                    ->action(fn ($livewire) => self::exportJson($livewire, $title)),

                Action::make('export_pdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn ($livewire) => self::exportPdf($livewire, $title)),
            ])
            ->label($title)
            ->icon('heroicon-m-arrow-down-tray')
            ->button()
            ->color('gray'),
        ]);
    }

    protected static function exportCsv($livewire, $title): mixed
    {
        $columns = $livewire->getTable()->getColumns();
        $query = $livewire->getFilteredTableQuery()->offset(0)->limit(10000);
        $records = $query->get();
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . now()->format('Ymd_His') . '.csv';

        return Response::streamDownload(function () use ($records, $columns) {
            $handle = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for Excel/Amharic support
            fputs($handle, "\xEF\xBB\xBF");

            // Write Headers
            $firstRecord = $records->first();
            $headers = [];
            $activeColumns = [];
            foreach ($columns as $column) {
                if (self::shouldExportColumn($column, $firstRecord)) {
                    $activeColumns[] = $column;
                    $headers[] = $column->getLabel() ?: ucwords(str_replace('_', ' ', $column->getName()));
                }
            }
            fputcsv($handle, $headers);

            foreach ($records as $record) {
                $row = self::getRecordData($record, $activeColumns);
                fputcsv($handle, $row);
            }
            
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected static function exportJson($livewire, $title): mixed
    {
        $query = $livewire->getFilteredTableQuery()->offset(0)->limit(10000);
        $records = $query->get();
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . now()->format('Ymd_His') . '.json';

        return Response::streamDownload(function () use ($records, $livewire) {
            $columns = $livewire->getTable()->getColumns();
            $firstRecord = $records->first();
            
            $activeColumns = [];
            foreach ($columns as $column) {
                if (self::shouldExportColumn($column, $firstRecord)) {
                    $activeColumns[] = $column;
                }
            }

            $jsonData = [];
            foreach ($records as $record) {
                $jsonData[] = self::getRecordData($record, $activeColumns, true);
            }
            echo json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    protected static function exportPdf($livewire, $title): mixed
    {
        $columns = $livewire->getTable()->getColumns();
        $query = $livewire->getFilteredTableQuery()->offset(0)->limit(10000);
        // Limit PDF to first 1000 records for stability
        $records = $query->limit(1000)->get();
        
        $filename = strtolower(str_replace(' ', '_', $title)) . '_' . now()->format('Ymd_His') . '.pdf';

        $html = self::generatePdfHtml($title, $records, $columns);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $output = $dompdf->output();

        return Response::streamDownload(function () use ($output) {
            echo $output;
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    protected static function generatePdfHtml(string $title, Collection $records, array $columns): string
    {
        if ($records->isEmpty()) {
            return "<h1>$title</h1><p>No data available.</p>";
        }

        $fontPath = storage_path('app/public/fonts/NotoSansEthiopic-Regular.ttf');
        $fontBase64 = '';
        if (file_exists($fontPath)) {
            $fontData = file_get_contents($fontPath);
            $fontBase64 = base64_encode($fontData);
        }

        $html = "
        <html>
        <head>
        <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
        <style>
            @font-face {
                font-family: 'Noto Sans Ethiopic';
                src: url(data:font/truetype;charset=utf-8;base64,$fontBase64) format('truetype');
                font-weight: normal;
                font-style: normal;
            }
            @page { margin: 1cm; }
            body { font-family: 'Noto Sans Ethiopic', 'DejaVu Sans', sans-serif; font-size: 8px; color: #333; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th, td { border: 0.1pt solid #aaa; padding: 4px; text-align: left; word-wrap: break-word; }
            th { background-color: #f5f5f5; font-weight: bold; }
            h1 { font-size: 16px; margin-bottom: 10px; }
        </style>
        </head>
        <body>
        <h1>$title Export</h1>
        <table>
            <thead>
                <tr>";
        
        $firstRecord = $records->first();
        $activeColumns = [];
        foreach ($columns as $column) {
            if (self::shouldExportColumn($column, $firstRecord)) {
                $activeColumns[] = $column;
                $html .= "<th>" . ($column->getLabel() ?: ucwords(str_replace('_', ' ', $column->getName()))) . "</th>";
            }
        }

        $html .= "</tr></thead><tbody>";

        foreach ($records as $record) {
            $html .= "<tr>";
            $rowData = self::getRecordData($record, $activeColumns);
            foreach ($rowData as $val) {
                $html .= "<td>" . htmlspecialchars($val) . "</td>";
            }
            $html .= "</tr>";
        }

        $html .= "</tbody></table>
        </body>
        </html>";

        return $html;
    }

    protected static function shouldExportColumn($column, $record = null): bool
    {
        $name = $column->getName();
        
        // Skip purely visual columns
        if ($column instanceof \Filament\Tables\Columns\ImageColumn) {
            return false;
        }

        // If it's a toggleable column (like the hidden raw fields I added), always include it
        if ($column->isToggleable()) {
            return true;
        }

        // If it's hidden and not toggleable, skip it (unless it's ID)
        if ($column->isHidden() && $name !== 'id') {
            return false;
        }

        // Real data check (if record is provided)
        if ($record) {
            $state = data_get($record, $name);
            if ($state === null) {
                $isReal = array_key_exists($name, $record->getAttributes()) || 
                          (method_exists($record, 'hasGetMutator') && $record->hasGetMutator($name)) ||
                          str_contains($name, '.');
                
                if (!$isReal) {
                    return false;
                }
            }
        }

        return true;
    }

    protected static function getRecordData($record, $columns, bool $withKeys = false): array
    {
        $row = [];
        foreach ($columns as $column) {
            // Note: columns passed here should already be filtered by shouldExportColumn
            $name = $column->getName();
            $state = data_get($record, $name);
            
            if ($state === null) {
                 $state = $column->record($record)->getState();
            }

            $val = self::formatState($state);
            if ($withKeys) {
                $label = $column->getLabel() ?: ucwords(str_replace('_', ' ', $name));
                $row[$label] = $val;
            } else {
                $row[] = $val;
            }
        }
        return $row;
    }

    protected static function formatState($state): string
    {
        if ($state === null) {
            return '';
        }

        if ($state instanceof \DateTimeInterface) {
            return $state->format('M j, Y');
        }
        
        if ($state instanceof \BackedEnum) {
            return (string)$state->value;
        }

        if ($state instanceof \UnitEnum) {
            return $state->name;
        }

        if (is_array($state) || is_object($state)) {
            return json_encode($state, JSON_UNESCAPED_UNICODE);
        }
        
        // Strip HTML if it looks like HTML
        if (is_string($state) && $state !== strip_tags($state)) {
            $clean = str_replace(['<br>', '<br/>', '<p>'], ["\n", "\n", "\n"], $state);
            return trim(strip_tags($clean));
        }

        return (string)$state;
    }
}

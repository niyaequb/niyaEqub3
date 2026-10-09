<?php

namespace App\Filament\Resources\EqubJoinLogs\Pages;

use App\Filament\Resources\EqubJoinLogs\EqubJoinLogResource;
use App\Models\EqubJoinLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEqubJoinLogs extends ListRecords
{
    protected static string $resource = EqubJoinLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_excel')
                ->label(__('Download Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn (): StreamedResponse => $this->downloadExcel()),
        ];
    }

    /**
     * The rows the table is showing right now — same search, same filters —
     * as an .xlsx file, newest first.
     *
     * Written with OpenSpout, which Filament already depends on, so this adds
     * nothing to composer.json. Rows are streamed from the database one at a
     * time, so a long log does not have to fit in memory.
     */
    public function downloadExcel(): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'equb-join-log-');

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues([
            'Joined (date)',
            'Joined (time)',
            'Name',
            'Phone',
            'Equb',
            'Package',
            'Amount (ETB)',
            'Cycle',
            'From',
            'Added by',
            'Added by (phone)',
            'Group (Family) Equb',
            'Membership #',
        ]));

        $query = ($this->getFilteredTableQuery() ?? EqubJoinLog::query())
            ->reorder()
            ->orderByDesc('joined_at')
            ->orderByDesc('id');

        foreach ($query->cursor() as $log) {
            /** @var EqubJoinLog $log */
            $writer->addRow(Row::fromValues([
                $log->joined_at?->format('Y-m-d') ?? '',
                $log->joined_at?->format('H:i:s') ?? '',
                (string) $log->member_name,
                (string) $log->member_phone,
                (string) $log->equb_name,
                (string) $log->package_name,
                $log->contribution_amount !== null ? (float) $log->contribution_amount : '',
                $log->cycleLabel(),
                $log->sourceLabel(),
                (string) $log->sponsor_name,
                (string) $log->sponsor_phone,
                $log->is_group_equb ? 'Yes' : 'No',
                $log->equb_membership_id !== null ? (int) $log->equb_membership_id : '',
            ]));
        }

        $writer->close();

        // Streamed rather than sent as a file response so the temporary file
        // is removed as soon as it has been read, whatever Livewire does with
        // the response afterwards.
        return response()->streamDownload(
            function () use ($path): void {
                readfile($path);
                @unlink($path);
            },
            'equb-join-log-'.now()->format('Y-m-d_His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}

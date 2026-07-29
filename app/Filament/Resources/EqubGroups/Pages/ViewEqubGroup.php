<?php

namespace App\Filament\Resources\EqubGroups\Pages;

use App\Enums\EqubGroupStatus;
use App\Filament\Resources\EqubGroups\EqubGroupResource;
use App\Models\EqubGroup;
use App\Services\EqubDrawService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;


class ViewEqubGroup extends ViewRecord
{
    protected static string $resource = EqubGroupResource::class;

    protected function getHeaderActions(): array
    {
        /** @var EqubGroup $record */
        $record = $this->record;

        return [
            Action::make('open_registration')
                ->label('Open Registration')
                ->color('success')
                ->icon('heroicon-o-lock-open')
                ->visible(fn (): bool => $record->status === EqubGroupStatus::Draft)
                ->action(function (): void {
                    $this->record->update(['status' => EqubGroupStatus::Registration,'registration_open_at'=>now()]);
                    Notification::make()->title('Registration opened.')->success()->send();
                }),
            Action::make('close_registration')
                ->label('Close Registration')
                ->color('warning')
                ->icon('heroicon-o-lock-closed')
                ->visible(fn (): bool => $record->status === EqubGroupStatus::Registration)
                ->action(function (): void {
                    $this->record->update([
                        'status' => EqubGroupStatus::Draft,
                        'registration_close_at' => now(),
                    ]);
                    Notification::make()->title('Registration closed.')->success()->send();
                }),
            Action::make('start_equb')
                ->label('Start Equb')
                ->color('success')
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => $record->status === EqubGroupStatus::Registration)
                ->action(function (): void {
                    try {
                        app(\App\Services\EqubGroupService::class)->initialize($this->record);
                        Notification::make()->title('Equb started and initialized.')->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('run_draw')
                ->label('Run Draw')
                ->color('primary')
                ->icon('heroicon-o-ticket')
                ->visible(fn (): bool => $record->status === EqubGroupStatus::Running)
                ->requiresConfirmation()
                ->modalHeading('Confirm Manual Draw')
                ->modalDescription('This will launch the official draw process with a 30-second anticipation for all users.')
                ->modalSubmitActionLabel('Launch Winner Search')
                ->modalSubmitAction(fn (Action $action) => $action->extraAttributes([
                    'x-on:click' => "window.dispatchEvent(new CustomEvent('start-manual-draw', { detail: { equbGroupId: {$record->id} } }))"
                ]))
                ->action(function ($record) {
                    $this->dispatch('start-manual-draw', equbGroupId: $record->id);
                }),
            EditAction::make(),
        ];
    }
}

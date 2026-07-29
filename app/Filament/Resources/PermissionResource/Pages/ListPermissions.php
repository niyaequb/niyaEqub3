<?php

namespace App\Filament\Resources\PermissionResource\Pages;

use App\Filament\Resources\PermissionResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make()
            // ->visible(fn () => Auth::check() && (Auth::user()->can('permissions.create') ?? true))
            // ,
            Actions\Action::make('generate_from_routes')
                ->label('Generate from Routes')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn () => Auth::check() && ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('permissions.create') ?? true))
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('permissions:generate');
                    Notification::make()
                        ->title('Permissions Generated')
                        ->success()
                        ->send();
                }),
        ];
    }
}


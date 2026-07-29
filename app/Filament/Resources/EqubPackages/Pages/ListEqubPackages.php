<?php

namespace App\Filament\Resources\EqubPackages\Pages;

use App\Filament\Resources\EqubPackages\EqubPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEqubPackages extends ListRecords
{
    protected static string $resource = EqubPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool =>
                    Auth::check() &&
                     ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-packages.create'))),
        ];
    }
}

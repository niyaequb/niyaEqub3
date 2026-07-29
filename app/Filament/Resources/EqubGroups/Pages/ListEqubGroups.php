<?php

namespace App\Filament\Resources\EqubGroups\Pages;

use App\Filament\Resources\EqubGroups\EqubGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEqubGroups extends ListRecords
{
    protected static string $resource = EqubGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool =>
                    Auth::check() &&
                     ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-groups.create'))),
        ];
    }
}

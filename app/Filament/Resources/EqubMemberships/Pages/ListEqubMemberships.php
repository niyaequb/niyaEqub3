<?php

namespace App\Filament\Resources\EqubMemberships\Pages;

use App\Filament\Resources\EqubMemberships\EqubMembershipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEqubMemberships extends ListRecords
{
    protected static string $resource = EqubMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool =>
                    Auth::check() &&
                     ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-memberships.create'))),
        ];
    }
}

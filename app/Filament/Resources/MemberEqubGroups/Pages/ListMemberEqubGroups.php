<?php

namespace App\Filament\Resources\MemberEqubGroups\Pages;

use App\Filament\Resources\MemberEqubGroups\MemberEqubGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListMemberEqubGroups extends ListRecords
{
    protected static string $resource = MemberEqubGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('filament.member_equb_group.create'))
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => Auth::check() && (
                    Auth::user()->hasRole('Super Admin')
                    || Auth::user()->can('member-equb-groups.create')
                )),
        ];
    }
}

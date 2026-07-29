<?php

namespace App\Filament\Resources\EqubMemberships\Pages;

use App\Filament\Resources\EqubMemberships\EqubMembershipResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEqubMembership extends EditRecord
{
    protected static string $resource = EqubMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

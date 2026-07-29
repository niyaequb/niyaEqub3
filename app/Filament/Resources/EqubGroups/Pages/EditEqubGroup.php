<?php

namespace App\Filament\Resources\EqubGroups\Pages;

use App\Filament\Resources\EqubGroups\EqubGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEqubGroup extends EditRecord
{
    protected static string $resource = EqubGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\EqubPackages\Pages;

use App\Filament\Resources\EqubPackages\EqubPackageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEqubPackage extends EditRecord
{
    protected static string $resource = EqubPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

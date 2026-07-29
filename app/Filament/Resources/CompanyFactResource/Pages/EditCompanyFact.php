<?php

namespace App\Filament\Resources\CompanyFactResource\Pages;

use App\Filament\Resources\CompanyFactResource;
use Filament\Resources\Pages\EditRecord;

class EditCompanyFact extends EditRecord
{
    protected static string $resource = CompanyFactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}

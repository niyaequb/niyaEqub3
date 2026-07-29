<?php

namespace App\Filament\Resources\CompanyFactResource\Pages;

use App\Filament\Resources\CompanyFactResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCompanyFacts extends ListRecords
{
    protected static string $resource = CompanyFactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

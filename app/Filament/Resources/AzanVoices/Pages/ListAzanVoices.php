<?php

namespace App\Filament\Resources\AzanVoices\Pages;

use App\Filament\Resources\AzanVoices\AzanVoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAzanVoices extends ListRecords
{
    protected static string $resource = AzanVoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

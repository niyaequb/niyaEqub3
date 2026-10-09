<?php

namespace App\Filament\Resources\AzanVoices\Pages;

use App\Filament\Resources\AzanVoices\AzanVoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAzanVoice extends EditRecord
{
    protected static string $resource = AzanVoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace App\Filament\Resources\AzanVoices\Pages;

use App\Filament\Resources\AzanVoices\AzanVoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAzanVoice extends CreateRecord
{
    protected static string $resource = AzanVoiceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

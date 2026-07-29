<?php

namespace App\Filament\Resources\AgentPayouts\Pages;

use App\Filament\Resources\AgentPayouts\AgentPayoutResource;
use Filament\Resources\Pages\EditRecord;

class EditAgentPayout extends EditRecord
{
    protected static string $resource = AgentPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}

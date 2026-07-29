<?php

namespace App\Filament\Resources\AgentCommissions\Pages;

use App\Filament\Resources\AgentCommissions\AgentCommissionResource;
use Filament\Resources\Pages\EditRecord;

class EditAgentCommission extends EditRecord
{
    protected static string $resource = AgentCommissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}

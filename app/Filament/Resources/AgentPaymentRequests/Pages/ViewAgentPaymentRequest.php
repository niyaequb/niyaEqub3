<?php

namespace App\Filament\Resources\AgentPaymentRequests\Pages;

use App\Filament\Resources\AgentPaymentRequests\AgentPaymentRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentPaymentRequest extends ViewRecord
{
    protected static string $resource = AgentPaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

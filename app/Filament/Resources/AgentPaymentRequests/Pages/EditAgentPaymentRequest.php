<?php

namespace App\Filament\Resources\AgentPaymentRequests\Pages;

use App\Filament\Resources\AgentPaymentRequests\AgentPaymentRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAgentPaymentRequest extends EditRecord
{
    protected static string $resource = AgentPaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

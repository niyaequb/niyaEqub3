<?php

namespace App\Filament\Resources\AgentPaymentRequests\Pages;

use App\Filament\Resources\AgentPaymentRequests\AgentPaymentRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentPaymentRequests extends ListRecords
{
    protected static string $resource = AgentPaymentRequestResource::class;
}

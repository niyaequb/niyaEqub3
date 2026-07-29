<?php

namespace App\Filament\Resources\AgentPayouts\Pages;

use App\Filament\Resources\AgentPayouts\AgentPayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListAgentPayouts extends ListRecords
{
    protected static string $resource = AgentPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => Auth::check() && (Auth::user()->hasRole('Super Admin') || Auth::user()->can('agent-payouts.create') ?? true)),
        ];
    }
}

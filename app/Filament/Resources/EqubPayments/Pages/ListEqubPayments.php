<?php

namespace App\Filament\Resources\EqubPayments\Pages;

use App\Filament\Resources\EqubPayments\EqubPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListEqubPayments extends ListRecords
{
    protected static string $resource = EqubPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool =>
                    Auth::check() &&
                     ( Auth::user()->hasRole('Super Admin') || Auth::user()->can('equb-payments.create'))),
        ];
    }
}

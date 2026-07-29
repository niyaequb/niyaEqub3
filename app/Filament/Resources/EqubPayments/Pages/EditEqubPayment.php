<?php

namespace App\Filament\Resources\EqubPayments\Pages;

use App\Filament\Resources\EqubPayments\EqubPaymentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEqubPayment extends EditRecord
{
    protected static string $resource = EqubPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

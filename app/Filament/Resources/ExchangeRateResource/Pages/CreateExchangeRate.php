<?php

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Filament\Resources\ExchangeRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExchangeRate extends CreateRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function beforeCreate(): void
    {
        if ($this->data['is_active']) {
            \App\Models\ExchangeRate::query()
                ->where('currency_from', $this->data['currency_from'])
                ->where('currency_to', $this->data['currency_to'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
    }
}

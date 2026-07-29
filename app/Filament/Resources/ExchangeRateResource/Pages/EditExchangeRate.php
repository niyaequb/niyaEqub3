<?php

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Filament\Resources\ExchangeRateResource;
use Filament\Resources\Pages\EditRecord;

class EditExchangeRate extends EditRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function beforeSave(): void
    {
        if ($this->data['is_active']) {
            \App\Models\ExchangeRate::query()
                ->where('id', '!=', $this->record->id)
                ->where('currency_from', $this->data['currency_from'])
                ->where('currency_to', $this->data['currency_to'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
    }
}

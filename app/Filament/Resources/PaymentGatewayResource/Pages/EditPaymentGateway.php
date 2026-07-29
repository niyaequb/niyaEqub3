<?php

namespace App\Filament\Resources\PaymentGatewayResource\Pages;

use App\Filament\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Ensure config fields are accessible
        if (isset($data['config']) && is_array($data['config'])) {
            $data['config']['secret_key'] = $data['config']['secret_key'] ?? '';
            $data['config']['public_key'] = $data['config']['public_key'] ?? '';
            $data['config']['webhook_secret'] = $data['config']['webhook_secret'] ?? '';
            $data['config']['webhook_url'] = $data['config']['webhook_url'] ?? route('api.payment.chapa.webhook');
            $data['config']['return_url'] = $data['config']['return_url'] ?? url('/payment/chapa/return/{reference}');
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // If activating this gateway, deactivate others
        if (isset($data['is_active']) && $data['is_active']) {
            PaymentGateway::where('id', '!=', $this->record->id)
                ->update(['is_active' => false]);
        }

        // Ensure config is properly structured
        if (isset($data['config']) && is_array($data['config'])) {
            $data['config'] = [
                'secret_key' => $data['config']['secret_key'] ?? '',
                'public_key' => $data['config']['public_key'] ?? '',
                'webhook_secret' => $data['config']['webhook_secret'] ?? '',
                'webhook_url' => $data['config']['webhook_url'] ?? route('api.payment.chapa.webhook'),
                'return_url' => $data['config']['return_url'] ?? url('/payment/chapa/return/{reference}'),
            ];
        }

        return $data;
    }
}


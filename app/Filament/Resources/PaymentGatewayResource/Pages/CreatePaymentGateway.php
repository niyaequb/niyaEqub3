<?php

namespace App\Filament\Resources\PaymentGatewayResource\Pages;

use App\Filament\Resources\PaymentGatewayResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentGateway extends CreateRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure config is properly structured
        $data['config'] = [
            'secret_key' => $data['config']['secret_key'] ?? '',
            'public_key' => $data['config']['public_key'] ?? '',
            'webhook_secret' => $data['config']['webhook_secret'] ?? '',
            'webhook_url' => $data['config']['webhook_url'] ?? route('api.payment.chapa.webhook'),
            'return_url' => $data['config']['return_url'] ?? url('/payment/chapa/return/{reference}'),
        ];

        return $data;
    }
}


<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency_from' => $this->currency_from,
            'currency_to' => $this->currency_to,
            'rate' => $this->rate,
            'is_active' => $this->is_active,
        ];
    }
}

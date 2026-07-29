<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentCommissionResource extends JsonResource
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
            'agent_id' => $this->agent_id,
            'member_id' => $this->member_id,
            'commission_rule_id' => $this->commission_rule_id,
            'source' => $this->source,
            'reference_id' => $this->reference_id,
            'base_amount' => $this->base_amount,
            'commission_amount' => $this->commission_amount,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'member' => $this->whenLoaded('member', function (): array {
                return [
                    'id' => $this->member?->id,
                    'full_name' => $this->member?->full_name,
                ];
            }),
        ];
    }
}

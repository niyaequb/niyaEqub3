<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
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
            'full_name' => $this->full_name,
            'registered_via' => $this->registered_via,
            'referral_code_used' => $this->referral_code_used,
            'registered_at' => ($this->registered_at ?? $this->created_at)?->toIso8601String(),
            // Only present when the query counted it (the agent members list).
            'active_equbs_count' => $this->when(isset($this->active_equbs_count), fn () => (int) $this->active_equbs_count),
            'user' => $this->whenLoaded('user', function (): array {
                return [
                    'id' => $this->user?->id,
                    'phone' => $this->user?->phone,
                    'name' => $this->user?->name,
                ];
            }),
            'agent' => $this->whenLoaded('agent', function (): array {
                return [
                    'id' => $this->agent?->id,
                    'referral_code' => $this->agent?->referral_code,
                ];
            }),
        ];
    }
}

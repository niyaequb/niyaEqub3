<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'fixed_contribution_amount' => $this->fixed_contribution_amount,
            'min_contribution_amount' => $this->min_contribution_amount,
            'max_contribution_amount' => $this->max_contribution_amount,
            'contribution_frequency_days' => $this->contribution_frequency_days,
            'duration_type' => $this->duration_type?->value,
            'duration_days' => $this->duration_days,
            'max_members' => $this->max_members,
            'terms_content' => $this->terms_content,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'groups' => EqubGroupResource::collection($this->groups),
        ];
    }
}

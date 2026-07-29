<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equb_package_id' => $this->equb_package_id,
            'package_name' => $this->package?->name,
            'name' => $this->name,
            'fixed_contribution_amount' => $this->fixed_contribution_amount,
            'contribution_frequency_days' => $this->contribution_frequency_days,
            'duration_type' => $this->duration_type?->value,
            'duration_days' => $this->package->duration_days,
            'terms_and_conditions' => $this->terms_content ?? $this->package?->terms_content,
            'registration_open_at' => $this->registration_open_at?->toIso8601String(),
            'registration_close_at' => $this->registration_close_at?->toIso8601String(),
            'equb_start_date' => $this->equb_start_date?->toIso8601String(),
            'equb_end_date' => $this->equb_end_date?->toIso8601String(),
            'max_members' => $this->max_members,
            'status' => $this->status?->value,
            'duration' => $this->duration_value . ' ' . $this->duration_unit?->value,
            'is_locked' => $this->is_locked,
            'current_members_count' => $this->current_members_count,
            'draw_type' => $this->draw_type?->value,
            // 'package' => new EqubPackageResource($this->whenLoaded('package')),
            'terms_and_conditions' => $this->package?->terms_content,
            'equb-memberships' => EqubMembershipResource::collection($this->whenLoaded('memberships')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

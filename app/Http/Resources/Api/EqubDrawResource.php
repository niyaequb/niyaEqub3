<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubDrawResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equb_group_name' => $this->equbGroup?->name,
            'equb_package_name' => $this->equbGroup?->package?->name,
            'equb_group_id' => $this->equb_group_id,
            'draw_date' => $this->draw_date?->toIso8601String(),
            'executed_by_admin_id' => $this->executed_by_admin_id,
            'winner_membership_id' => $this->winner_membership_id,
            'winner_expected_total_amount' => $this->winnerMembership?->expected_total_amount,
            'total_won' => $this->equbGroup?->total_amount_per_draw,
            'winner_total_paid' => $this->winnerMembership?->total_paid,
            'winner_remaining_amount' => $this->winnerMembership?->remaining_amount,
            // 'winner_membership' => new EqubMembershipResource($this->whenLoaded('winnerMembership')),
            'winner_member_name' => $this->winnerMembership?->member?->full_name,
            'winner_member_id' => $this->winnerMembership?->member_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A draw round together with its whole winner group.
 */
class GroupDrawResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myMemberId = $request->user()?->member?->id;

        $winners = $this->whenLoaded('winners', fn () => $this->winners->map(fn ($winner): array => [
            'membership_id' => $winner->equb_membership_id,
            'member_id' => $winner->membership?->member_id,
            'name' => $winner->membership?->member?->full_name,
            'phone' => $winner->membership?->member?->user?->phone,
            'profile_picture_url' => $winner->membership?->member?->user?->profile_picture_url,
            'position' => $winner->position,
            'amount_won' => (float) $winner->amount_won,
            'is_me' => $myMemberId !== null && (int) $winner->membership?->member_id === (int) $myMemberId,
        ])->values());

        return [
            'id' => $this->id,
            'equb_group_id' => $this->equb_group_id,
            'equb_group_name' => $this->equbGroup?->name,
            'round_number' => $this->round_number,
            'winners_count' => (int) ($this->winners_count ?? 1),
            'mode' => $this->mode,
            'draw_date' => $this->draw_date?->toIso8601String(),
            'executed_by_admin_id' => $this->executed_by_admin_id,
            'total_pot' => round((float) ($this->equbGroup?->total_amount_per_draw ?? 0), 2),
            'winners' => $winners,
            // Back-compat with the single-winner screens already shipped.
            'winner_membership_id' => $this->winner_membership_id,
            'winner_member_name' => $this->winnerMembership?->member?->full_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

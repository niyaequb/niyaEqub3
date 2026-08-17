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

        $winners = $this->whenLoaded('winners', fn () => $this->winners->map(function ($winner) use ($myMemberId): array {
            $membership = $winner->membership;
            $isSeat = (bool) $membership?->isResponsibilitySeat();

            return [
                'membership_id' => $winner->equb_membership_id,
                'member_id' => $membership?->member_id,
                // displayName() covers a place held for someone with no Niya
                // account, where there is no member row to read a name from.
                'name' => $membership?->displayName() ?? 'Member',
                'phone' => $isSeat
                    ? $membership?->responsibility_phone
                    : $membership?->member?->user?->phone,
                'profile_picture_url' => $isSeat ? null : $membership?->member?->user?->profile_picture_url,
                'position' => $winner->position,
                'amount_won' => (float) $winner->amount_won,

                // --- My Responsibility People -------------------------
                // The share for a held place is settled with the sponsor who
                // has been paying it, so `is_me` follows the money rather than
                // the name: without that, a payout the sponsor is owed shows
                // up in their own rounds list as somebody else's win.
                'is_responsibility_seat' => $isSeat,
                'sponsor_member_id' => $membership?->sponsor_member_id,
                'sponsor_name' => $isSeat
                    ? ($membership?->sponsor?->full_name ?? $membership?->sponsor?->user?->name)
                    : null,
                'is_me' => $myMemberId !== null
                    && (int) $membership?->payerMemberId() === (int) $myMemberId,
                'is_mine_directly' => $myMemberId !== null
                    && (int) $membership?->member_id === (int) $myMemberId,
            ];
        })->values());

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
            'winner_member_name' => $this->winnerMembership?->displayName(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

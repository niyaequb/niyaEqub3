<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberEqubGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myMemberId = $request->user()?->member?->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility?->value,
            'moderation_status' => $this->moderation_status?->value,
            'rejection_reason' => $this->rejection_reason,
            'invite_code' => $this->invite_code,
            'status' => $this->status?->value,
            'is_locked' => (bool) $this->is_locked,

            'equb_package_id' => $this->equb_package_id,
            'package_name' => $this->package?->name,
            'contribution_amount' => (float) $this->fixed_contribution_amount,
            'contribution_frequency_days' => (int) $this->contribution_frequency_days,
            'duration_type' => $this->duration_type?->value,
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit?->value,
            'terms_and_conditions' => $this->terms_content ?? $this->package?->terms_content,

            'max_members' => $this->max_members,
            'current_members_count' => (int) $this->current_members_count,
            'rounds_total' => $this->totalRounds(),
            'rounds_completed' => $this->draws_count ?? $this->draws()->count(),
            'pot_per_round' => round($this->potPerRound(), 2),
            'payout_per_winner' => $this->payout_per_winner !== null ? (float) $this->payout_per_winner : null,

            'winner_selection_mode' => $this->winner_selection_mode?->value,
            'winners_per_draw' => $this->winners_per_draw,
            'min_winners_per_draw' => $this->min_winners_per_draw,
            'max_winners_per_draw' => $this->max_winners_per_draw,
            'winner_split_plan' => $this->winner_split_plan,
            'split_plan_cursor' => (int) $this->split_plan_cursor,
            'next_round_winners' => $this->winnersForNextRound(),
            'draw_requires_up_to_date' => (bool) $this->draw_requires_up_to_date,
            'allow_member_invites' => (bool) $this->allow_member_invites,

            'registration_open_at' => $this->registration_open_at?->toIso8601String(),
            'registration_close_at' => $this->registration_close_at?->toIso8601String(),
            'equb_start_date' => $this->equb_start_date?->toIso8601String(),
            'equb_end_date' => $this->equb_end_date?->toIso8601String(),

            'owner' => [
                'member_id' => $this->owner_member_id,
                'name' => $this->owner?->full_name,
                'phone' => $this->owner?->user?->phone,
            ],
            'is_owner' => $myMemberId !== null && (int) $this->owner_member_id === (int) $myMemberId,

            // The controller eager-loads `memberships` scoped to the caller.
            'my_membership' => $this->relationLoaded('memberships') && $this->memberships->isNotEmpty()
                ? new EqubMembershipResource($this->memberships->first())
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person a member is responsible for inside a Group Equb.
 *
 * The underlying row is an EqubMembership with no member_id — see the
 * "My Responsibility People" section on that model. This resource deliberately
 * exposes both halves of the arrangement side by side: the place in the circle
 * (rounds, contribution, whether it has won) and who is answerable for it,
 * because the whole point is that those are two different people.
 *
 * @mixin \App\Models\EqubMembership
 */
class GroupResponsibilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myMemberId = $request->user()?->member?->id;

        return [
            // The membership id is the handle for every action on this person:
            // paying their round, editing them, taking them out.
            'membership_id' => $this->id,
            'equb_group_id' => $this->equb_group_id,

            'name' => $this->displayName(),
            'phone' => $this->responsibility_phone,
            'relation' => $this->responsibility_relation,
            'note' => $this->responsibility_note,

            'sponsor_member_id' => $this->sponsor_member_id,
            'sponsor_name' => $this->sponsor?->full_name ?? $this->sponsor?->user?->name,
            // Whether the caller is the one paying for this person, which is
            // what decides if the app shows a pay button or just a name.
            'is_mine' => $myMemberId !== null && (int) $this->sponsor_member_id === (int) $myMemberId,

            'contribution_amount' => (float) $this->contribution_amount,
            'contribution_frequency_days' => (int) $this->contribution_frequency_days,
            'joined_at' => $this->join_date?->toIso8601String(),
            'status' => $this->status?->value,

            // Present when the caller came through the responsibility list,
            // which loads these two aggregates. Absent elsewhere rather than
            // silently zero, so the app never shows "0 paid" for a figure it
            // was simply not sent.
            'rounds_paid' => $this->paid_rounds !== null ? (int) $this->paid_rounds : null,
            'total_paid' => $this->paid_total !== null ? round((float) $this->paid_total, 2) : null,

            'has_won' => (bool) $this->has_won,
            'win_date' => $this->win_date?->toIso8601String(),

            // Same rule as a member leaving: once this place has contributed or
            // been paid out, the money belongs to the circle and the place
            // cannot simply be deleted. Sent down so the app can hide the
            // remove button instead of offering one that fails.
            'can_remove' => $this->canExit(),
            'remove_block_reason' => $this->exitBlockReason(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

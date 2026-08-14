<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubMembershipResource extends JsonResource
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
            'member_id' => $this->member_id,
            'contribution_amount' => $this->equbGroup->fixed_contribution_amount,
            'contribution_frequency_days' => $this->equbGroup?->contribution_frequency_days,
            'join_date' => $this->join_date?->toIso8601String(),
            'next_draw_date' => $this->next_draw_date?->toIso8601String(),
            'calculated_end_date' => $this->calculated_end_date?->toIso8601String(),
            'duration' => $this->equbGroup?->duration_value . ' ' . $this->equbGroup?->duration_unit?->value,
            'draw_position' => $this->draw_position,
            'has_won' => $this->has_won,
            'win_date' => $this->win_date?->toIso8601String(),
            // Whether this membership may be left, and why not if not.
            //
            // Computed server-side and sent down rather than re-derived in the
            // app: the app cannot see the draw tables, and a client that works
            // the rule out for itself will eventually disagree with the server
            // — showing a Leave button that then fails is worse than not
            // showing one. See EqubMembership::exitBlockReason().
            'can_leave' => $this->canExit(),
            'exit_block_reason' => $this->exitBlockReason(),
            'has_received_payout' => $this->hasReceivedPayout(),
            'total_won_amount' => $this->totalWonAmount(),
            'is_settled' => $this->isSettled(),
            'status' => $this->status?->value,
            'total_paid' => $this->total_paid,
            'contributed_amount' => $this->total_paid,
            'expected_total_amount' => $this->expected_total_amount,
            'remaining_amount' => $this->remaining_amount,
            'amount_left' => $this->remaining_amount,
            // 'equb_group' => new EqubGroupResource($this->whenLoaded('equbGroup')),
            'equb_group_name'=> $this->equbGroup?->name,
            'equb_group_package_name' => $this->equbGroup?->package?->name,
            'member' => new MemberResource($this->whenLoaded('member')),
                    'payments' => EqubPaymentResource::collection(
                        $this->whenLoaded('payments', function () {
                            return $this->payments->where('status', 'paid');
                        })
                    ),            'payment_schedule' => $this->payment_schedule,
            'draw_info' => new EqubDrawResource($this->winsAsWinner->first()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubGroupInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'message' => $this->message,
            'phone' => $this->phone,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'invited_by' => [
                'member_id' => $this->invited_by_member_id,
                'name' => $this->invitedBy?->full_name,
                'phone' => $this->invitedBy?->user?->phone,
            ],
            'member' => [
                'member_id' => $this->member_id,
                'name' => $this->member?->full_name,
            ],
            'equb_group' => [
                'id' => $this->equb_group_id,
                'name' => $this->equbGroup?->name,
                'invite_code' => $this->equbGroup?->invite_code,
                'package_name' => $this->equbGroup?->package?->name,
                'contribution_amount' => (float) ($this->equbGroup?->fixed_contribution_amount ?? 0),
                'contribution_frequency_days' => (int) ($this->equbGroup?->contribution_frequency_days ?? 0),
                'max_members' => $this->equbGroup?->max_members,
                'current_members_count' => (int) ($this->equbGroup?->current_members_count ?? 0),
                'status' => $this->equbGroup?->status?->value,
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equb_group_name' => $this->membership?->equbGroup?->name,
            'equb_package_name' => $this->membership?->equbGroup?->package?->name,
            'equb_membership_id' => $this->equb_membership_id,
            // Which Equb to open for this payment. Read through the same
            // membership the two name fields above already load.
            'equb_group_id' => $this->membership?->equb_group_id,
            'amount' => $this->amount,
            'payment_date' => $this->payment_date?->toIso8601String(),
            'payment_method' => $this->payment_method?->value,
            'status' => $this->status?->value,
            'reference' => $this->reference,
            // The charge this contribution was settled in, when it was paid
            // together with other places. The app matches a just-made payment
            // by whichever of the two the bank was sent.
            'batch_reference' => $this->batch_reference,
            // 'membership' => new EqubMembershipResource($this->whenLoaded('membership')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentPaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof \BackedEnum ? $this->status->value : $this->status;

        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'account_holder_name' => $this->account_holder_name,
            // "cancelled" is shown to the agent; it is stored as failed + cancelled_at.
            'status' => $this->cancelled_at ? 'cancelled' : $status,
            'note' => $this->note,
            'admin_note' => $this->admin_note,
            'transaction_reference' => $this->transaction_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

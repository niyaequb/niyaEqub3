<?php

namespace App\Http\Requests\Api\Member;

use Illuminate\Foundation\Http\FormRequest;

class RunGroupDrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Manual mode: the exact winner group for this round.
            'membership_ids' => ['nullable', 'array', 'max:200'],
            'membership_ids.*' => ['integer', 'exists:equb_memberships,id'],
            // Automatic mode: override how many winners this round produces.
            'winners_count' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}

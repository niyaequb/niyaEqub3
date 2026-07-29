<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreEqubMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'equb_group_id' => ['required', 'exists:equb_groups,id'],
            'contribution_amount' => ['required', 'numeric', 'min:0'],
            'contribution_frequency_days' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\EqubMembershipStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEqubMembershipRequest extends FormRequest
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
            'contribution_amount' => ['sometimes', 'numeric', 'min:0'],
            'contribution_frequency_days' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(EqubMembershipStatus::class)],
        ];
    }
}

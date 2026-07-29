<?php

namespace App\Http\Requests\Admin;

use App\Enums\EqubDurationType;
use App\Enums\EqubPackageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEqubPackageRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(EqubPackageType::class)],
            'fixed_contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'min_contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'max_contribution_amount' => ['nullable', 'numeric', 'min:0'],
            'contribution_frequency_days' => ['sometimes', 'integer', 'min:1'],
            'duration_type' => ['sometimes', Rule::enum(EqubDurationType::class)],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'terms_content' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

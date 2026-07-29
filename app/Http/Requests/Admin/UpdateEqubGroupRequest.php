<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEqubGroupRequest extends FormRequest
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
            'equb_package_id' => ['sometimes', 'exists:equb_packages,id'],
            'registration_open_at' => ['sometimes', 'date'],
            'registration_close_at' => ['nullable', 'date'],
            'equb_start_date' => ['nullable', 'date'],
            'equb_end_date' => ['nullable', 'date'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:draft,registration,running,completed,cancelled'],
            'is_locked' => ['nullable', 'boolean'],
            'draw_type' => ['sometimes', 'in:manual,automatic,both'],
        ];
    }
}

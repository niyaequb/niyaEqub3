<?php

namespace App\Http\Requests\Api\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting the details of someone in "My Responsibility People".
 *
 * Only the descriptive fields can change. The contribution, the frequency and
 * the join date are all fixed by the Equb and have already been counted into
 * the pot, so there is nothing financial to edit — a typo in a name should
 * never be a way to alter money.
 */
class UpdateGroupResponsibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->member !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'relation' => ['sometimes', 'nullable', 'string', 'max:40'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['name', 'phone', 'relation', 'note'])) {
                $validator->errors()->add('name', 'There is nothing to update.');
            }
        });
    }
}

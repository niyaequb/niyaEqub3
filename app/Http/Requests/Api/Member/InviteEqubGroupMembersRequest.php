<?php

namespace App\Http\Requests\Api\Member;

use Illuminate\Foundation\Http\FormRequest;

class InviteEqubGroupMembersRequest extends FormRequest
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
            'member_ids' => ['nullable', 'array', 'max:100'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'phones' => ['nullable', 'array', 'max:100'],
            'phones.*' => ['string', 'max:20'],
            'message' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (empty($this->input('member_ids')) && empty($this->input('phones'))) {
                $validator->errors()->add('member_ids', 'Pick at least one member or phone number to invite.');
            }
        });
    }
}

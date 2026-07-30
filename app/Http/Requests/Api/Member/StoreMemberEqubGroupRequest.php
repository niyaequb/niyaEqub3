<?php

namespace App\Http\Requests\Api\Member;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberEqubGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->member !== null;
    }

    /**
     * A Group Equb only needs a parent Equb, a name and the people you want in
     * it. The contribution comes from the parent's package and the winner rules
     * belong to the admin panel, so neither is accepted here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_equb_group_id' => ['required', 'integer', 'exists:equb_groups,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'allow_member_invites' => ['nullable', 'boolean'],

            'invite_member_ids' => ['nullable', 'array', 'max:100'],
            'invite_member_ids.*' => ['integer', 'exists:members,id'],
            'invite_phones' => ['nullable', 'array', 'max:100'],
            'invite_phones.*' => ['string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_equb_group_id.required' => 'Choose which Equb this group is joining.',
            'name.required' => 'Give your group a name.',
        ];
    }
}

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
     * Two different kinds of people can be added, and the difference is who
     * pays:
     *
     *   invite_member_ids / invite_phones
     *       Niya members (or numbers that will become members). They are
     *       invited, they accept, and each one pays their own contributions.
     *
     *   responsibility_people
     *       Places held for someone with no Niya account — a child, a parent,
     *       a neighbour. Nobody is invited and nobody accepts, because the
     *       creator is taking the obligation on themselves. Each one still
     *       counts as a member of the circle.
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

            // The array cap is deliberately low and the service applies the
            // real per-sponsor limit on top of it: every entry here is a
            // contribution the creator alone will owe, every single round.
            'responsibility_people' => ['nullable', 'array', 'max:20'],
            'responsibility_people.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'responsibility_people.*.phone' => ['nullable', 'string', 'max:20'],
            'responsibility_people.*.relation' => ['nullable', 'string', 'max:40'],
            'responsibility_people.*.note' => ['nullable', 'string', 'max:200'],
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
            'responsibility_people.*.name.required' => 'Every person you are responsible for needs a name.',
        ];
    }
}

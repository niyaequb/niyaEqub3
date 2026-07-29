<?php

namespace App\Http\Requests\Api\Member;

use App\Enums\WinnerSelectionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberEqubGroupRequest extends FormRequest
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
            'equb_package_id' => ['required', 'integer', 'exists:equb_packages,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'contribution_amount' => ['nullable', 'numeric', 'min:1'],
            'max_members' => ['required', 'integer', 'min:2', 'max:500'],
            'duration_value' => ['nullable', 'integer', 'min:1', 'max:500'],
            'equb_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'allow_member_invites' => ['nullable', 'boolean'],
            'draw_requires_up_to_date' => ['nullable', 'boolean'],

            'winner_selection_mode' => ['required', Rule::enum(WinnerSelectionMode::class)],
            'winners_per_draw' => [
                'nullable', 'integer', 'min:1', 'lte:max_members',
                Rule::requiredIf(fn (): bool => $this->input('winner_selection_mode') === WinnerSelectionMode::FixedSize->value),
            ],
            'min_winners_per_draw' => [
                'nullable', 'integer', 'min:1', 'lte:max_members',
                Rule::requiredIf(fn (): bool => $this->input('winner_selection_mode') === WinnerSelectionMode::RandomSplit->value),
            ],
            'max_winners_per_draw' => [
                'nullable', 'integer', 'min:1', 'lte:max_members', 'gte:min_winners_per_draw',
                Rule::requiredIf(fn (): bool => $this->input('winner_selection_mode') === WinnerSelectionMode::RandomSplit->value),
            ],

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
            'max_members.min' => 'A group Equb needs room for at least 2 members.',
            'max_winners_per_draw.gte' => 'The largest winner group cannot be smaller than the smallest one.',
            'winners_per_draw.lte' => 'You cannot have more winners than members.',
        ];
    }
}

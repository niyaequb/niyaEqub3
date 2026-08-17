<?php

namespace App\Http\Requests\Api\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding someone to "My Responsibility People".
 *
 * A name is the only thing required. The phone is a contact detail the sponsor
 * keeps for their own convenience — it never creates an account, never receives
 * an invitation and never has to be verified, which is exactly why this feature
 * exists for children and for people who are not on Niya.
 *
 * Nothing about money is accepted here. The contribution comes from the Equb
 * and the sponsor pays it, so there is no amount for the client to send.
 */
class StoreGroupResponsibilityRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'relation' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name of the person you are responsible for.',
            'name.min' => 'That name looks too short.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->member?->user_id;

        return [
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => [
                'nullable',
                'string',
                'regex:/^\+?[1-9]\d{1,14}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'agent_id' => [
                'nullable',
                Rule::exists('agents', 'id')->where('is_active', true),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid phone number.',
            'phone.unique' => 'This phone number is already registered.',
            'agent_id.exists' => 'Agent is invalid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->phone) {
            $this->merge([
                'phone' => $this->formatPhoneNumber($this->phone),
            ]);
        }
    }

    private function formatPhoneNumber(string $number): string
    {
        $number = preg_replace('/\D/', '', $number);
        $number = ltrim($number, '0');
        if (strpos($number, '251') !== 0) {
            $number = '251'.$number;
        }

        return '+'.$number;
    }
}

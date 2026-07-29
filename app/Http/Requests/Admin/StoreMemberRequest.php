<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
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
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[1-9]\d{1,14}$/',
                'unique:users,phone',
            ],
            'password' => ['required', 'string', 'min:8'],
            'referral_code' => [
                'nullable',
                'string',
                Rule::exists('agents', 'referral_code')->where('is_active', true),
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
            'full_name.required' => 'Full name is required.',
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid phone number.',
            'phone.unique' => 'This phone number is already registered.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'referral_code.exists' => 'Referral code is invalid.',
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

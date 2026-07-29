<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:member,agent'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[1-9]\d{1,14}$/',
                'unique:users,phone',
            ],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'city' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'bank_name' => 'sometimes|nullable|string|max:255',
            'account_number' => 'sometimes|nullable|string|max:50',
            'account_holder_name' => 'sometimes|nullable|string|max:255',
            'referral_code' => [
                'nullable',
                'string',
                Rule::when(
                    $this->input('type', 'member') !== 'agent',
                    [Rule::exists('agents', 'referral_code')->where('is_active', true)]
                ),
            ],
        ];
    }

    /**
     * Custom messages for validation errors
     */
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
            'type.in' => 'Type must be member or agent.',
        ];
    }

    /**
     * Prepare the data for validation (format phone numbers)
     */
    protected function prepareForValidation(): void
    {
        if ($this->phone) {
            $this->merge([
                'phone' => $this->formatPhoneNumber($this->phone),
            ]);
        }
    }

    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber(string $number): string
    {
        $number = preg_replace('/\D/', '', $number); // remove non-digits
        $number = ltrim($number, '0'); // remove leading zero
        if (strpos($number, '251') !== 0) {
            $number = '251'.$number; // add Ethiopia country code if missing
        }

        return '+'.$number;
    }

    /**
     * Override failedValidation to return JSON response for API
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'errors' => $validator->errors(),
        ], 422));
    }
}

<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResetPasswordRequest extends FormRequest
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
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid phone number.',
            'code.required' => 'Verification code is required.',
            'code.digits' => 'Verification code must be 6 digits.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->phone) {
            // Format phone number to international format
            $this->merge([
                'phone' => $this->formatPhoneNumber($this->phone),
            ]);
        }
    }

    /**
     * Format phone number to international format.
     */
    private function formatPhoneNumber(string $number): string
    {
        // Remove all non-digit characters
        $number = preg_replace('/\D/', '', $number);

        // Remove leading zero
        $number = ltrim($number, '0');

        // Add Ethiopia country code if missing
        if (strpos($number, '251') !== 0) {
            $number = '251'.$number;
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

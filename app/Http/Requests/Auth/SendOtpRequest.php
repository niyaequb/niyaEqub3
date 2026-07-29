<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
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
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[1-9]\d{1,14}$/',
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

        // Add country code if not present
        if (strpos($number, '251') !== 0) {
            $number = '251'.$number;
        }

        return '+'.$number;
    }
}

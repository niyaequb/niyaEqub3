<?php

namespace App\Http\Requests\Api\Agent;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:completed,pending,failed'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $agent = $this->user()?->agentProfile;
            $hasRequestBankInfo = $this->filled('bank_name')
                && $this->filled('account_number')
                && $this->filled('account_holder_name');
            $hasProfileBankInfo = $agent
                && filled($agent->bank_name)
                && filled($agent->account_number)
                && filled($agent->account_holder_name);

            if (! $hasRequestBankInfo && ! $hasProfileBankInfo) {
                $validator->errors()->add(
                    'bank_information',
                    'Bank information is required. Either provide bank_name, account_number, and account_holder_name in the request, or save them in your agent profile.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Payment amount is required.',
            'amount.numeric' => 'Payment amount must be a number.',
            'amount.min' => 'Payment amount must be at least 1.',
            'status.in' => 'Payment status is invalid.',
            'paid_at.date' => 'Paid at must be a valid date.',
        ];
    }
}

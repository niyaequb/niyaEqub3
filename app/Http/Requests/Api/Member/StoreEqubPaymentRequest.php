<?php

namespace App\Http\Requests\Api\Member;

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEqubPaymentRequest extends FormRequest
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
            'equb_membership_id' => ['required', 'exists:equb_memberships,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_date' => ['required', 'date'],

            // Built from the gateway register rather than hardcoded, so a bank
            // becomes payable the moment its credentials are in place and stops
            // being payable the moment they are removed — no deploy either way.
            //
            // Only ENABLED banks are accepted. A bank that is registered but
            // unconfigured is rejected here with a validation error rather than
            // creating a pending contribution against a bank that was never
            // going to be asked for the money.
            'payment_method' => [
                'required',
                Rule::in(app(PaymentGatewayManager::class)->acceptedMethods()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.in' => 'That payment method is not available right now.',
        ];
    }
}

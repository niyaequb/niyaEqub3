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

            // Who the bank's host app says is using it, when the mini app was
            // able to ask. The server mints this order's access token from it
            // and never stores it — so it is a pass-through, not identity.
            // Nothing is authorised on its strength, and changing it does not
            // let a member pay for a place that is not theirs, because the
            // membership is resolved from the authenticated user.
            //
            // Optional: it does not exist outside the host app, and
            // {PREFIX}_CUSTOMER_IDENTIFIER stands in during UAT. Constrained
            // to the shape these banks issue so a client cannot push arbitrary
            // content into a request this server then makes to a bank.
            'customer_identifier' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@:-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $accepted = app(PaymentGatewayManager::class)->acceptedMethods();

        // Two very different faults reject a payment method here, and they need
        // opposite fixes, so the message has to tell them apart. "That payment
        // method is not available right now" — which this used to say — was
        // true of both and useful for neither.
        //
        //   * EMPTY list  — no bank is configured on THIS server, so every
        //     value fails, including the correct one. Fix the credentials.
        //   * non-empty   — the client sent something outside it, which in
        //     practice means an app build older than the bank it is paying
        //     through. Rebuild the client.
        //
        // Naming the banks is safe: the same list is already public at
        // GET /api/payments/providers, because the apps need it to draw a
        // bank picker.
        return [
            'payment_method.in' => $accepted === []
                ? 'No bank is available for payments on this server right now — its payment credentials are missing or incomplete.'
                : 'That payment method is not available. Payments can currently go through: '.implode(', ', $accepted).'.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\EqubPaymentMethod;
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
            // selectable(), not the whole enum. Offline and manual are retired
            // and their cases only survive so historical rows still cast —
            // Rule::enum would happily accept them and let an operator create a
            // contribution through a route that no longer exists.
            'payment_method' => [
                'required',
                Rule::in(array_map(
                    fn (EqubPaymentMethod $m): string => $m->value,
                    EqubPaymentMethod::selectable()
                )),
            ],
        ];
    }
}

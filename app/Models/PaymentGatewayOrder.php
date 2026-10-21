<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bank's own id for an order we registered with a hosted-checkout gateway.
 *
 * Knows nothing about Equb. One row per (gateway, reference), where reference
 * is the same EQUB-... / EQUB-B... value equb_payments carries, so settlement
 * keeps resolving contributions exactly as it always has.
 */
class PaymentGatewayOrder extends Model
{
    protected $fillable = [
        'gateway',
        'reference',
        'external_order_id',
        'checkout_url',
        'amount_minor',
        'currency',
        'status',
        'last_checked_at',
        'register_response',
        'status_response',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'last_checked_at' => 'datetime',
            'register_response' => 'array',
            'status_response' => 'array',
        ];
    }
}

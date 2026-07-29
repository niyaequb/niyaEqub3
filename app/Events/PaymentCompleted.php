<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentCompleted
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Payment $payment) {}
}

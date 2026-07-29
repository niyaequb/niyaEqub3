<?php

namespace App\Observers;

use App\Enums\PaymentStatus;
use App\Events\PaymentCompleted;
use App\Models\Payment;

class PaymentObserver
{
    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Completed) {
            PaymentCompleted::dispatch($payment);
        }
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        if ($payment->wasChanged('status') && $payment->status === PaymentStatus::Completed) {
            PaymentCompleted::dispatch($payment);
        }
    }
}

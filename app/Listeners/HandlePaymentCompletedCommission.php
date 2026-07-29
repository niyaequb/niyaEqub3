<?php

namespace App\Listeners;

use App\Events\PaymentCompleted;
use App\Services\CommissionService;

class HandlePaymentCompletedCommission
{
    /**
     * Create the event listener.
     */
    public function __construct(public CommissionService $commissionService) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentCompleted $event): void
    {
        $this->commissionService->recordPaymentCommission($event->payment);
    }
}

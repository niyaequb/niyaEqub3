<?php

namespace App\Observers;

use App\Models\EqubPayment;
use App\Enums\EqubPaymentStatus;
use App\Services\EqubMembershipService;

class EqubPaymentObserver
{
    /**
     * Handle the EqubPayment "updated" event.
     */
    public function updated(EqubPayment $equbPayment): void
    {
        if ($equbPayment->wasChanged('status') && $equbPayment->status === EqubPaymentStatus::Paid) {
            if ($equbPayment->membership) {
                app(EqubMembershipService::class)->completeIfEligible($equbPayment->membership);
            }
        }
    }
}

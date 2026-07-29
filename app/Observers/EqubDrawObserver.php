<?php

namespace App\Observers;

use App\Models\EqubDraw;
use App\Services\EqubMembershipService;

class EqubDrawObserver
{
    /**
     * Handle the EqubDraw "created" event.
     */
    public function created(EqubDraw $equbDraw): void
    {
        if ($equbDraw->winnerMembership) {
            app(EqubMembershipService::class)->completeIfEligible($equbDraw->winnerMembership);
        }
    }
}

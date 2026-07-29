<?php

namespace App\Listeners;

use App\Events\MemberRegistered;
use App\Services\CommissionService;

class HandleMemberRegisteredCommission
{
    /**
     * Create the event listener.
     */
    public function __construct(public CommissionService $commissionService) {}

    /**
     * Handle the event.
     */
    public function handle(MemberRegistered $event): void
    {
        $this->commissionService->recordSignupCommission($event->member);
    }
}

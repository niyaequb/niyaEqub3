<?php

namespace App\Observers;

use App\Events\MemberRegistered;
use App\Models\Member;

class MemberObserver
{
    /**
     * Handle the Member "created" event.
     */
    public function created(Member $member): void
    {
        MemberRegistered::dispatch($member);
    }
}

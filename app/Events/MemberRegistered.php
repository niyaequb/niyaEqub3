<?php

namespace App\Events;

use App\Models\Member;
use Illuminate\Foundation\Events\Dispatchable;

class MemberRegistered
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Member $member) {}
}

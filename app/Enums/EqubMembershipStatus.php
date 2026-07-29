<?php

namespace App\Enums;

enum EqubMembershipStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

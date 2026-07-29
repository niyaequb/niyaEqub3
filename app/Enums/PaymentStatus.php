<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Completed = 'completed';
    case Pending = 'pending';
    case Failed = 'failed';
}

<?php

namespace App\Enums;

enum CommissionType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}

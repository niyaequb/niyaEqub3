<?php

namespace App\Enums;

enum EqubPaymentMethod: string
{
    case Chapa = 'chapa';
    case Offline = 'offline';
    case Manual = 'manual';
}

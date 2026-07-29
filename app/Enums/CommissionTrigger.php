<?php

namespace App\Enums;

enum CommissionTrigger: string
{
    case Signup = 'signup';
    case FirstPayment = 'first_payment';
    case Payment = 'payment';
}

<?php

namespace App\Enums;

enum RegisteredVia: string
{
    case Direct = 'direct';
    case Agent = 'agent';
}

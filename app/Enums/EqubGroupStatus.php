<?php

namespace App\Enums;

enum EqubGroupStatus: string
{
    case Draft = 'draft';
    case Registration = 'registration';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}

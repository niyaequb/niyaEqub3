<?php

namespace App\Enums;

enum EqubMembershipRole: string
{
    /** Created the group: can invite, remove, start and run draws. */
    case Owner = 'owner';

    /** Ordinary participant. */
    case Member = 'member';
}

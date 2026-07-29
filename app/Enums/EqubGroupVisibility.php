<?php

namespace App\Enums;

enum EqubGroupVisibility: string
{
    /** Listed publicly in the app for anyone to join. */
    case Public = 'public';

    /** Member-created group. Only invited members can see or join it. */
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => __('filament.member_equb_group.visibility_public'),
            self::Private => __('filament.member_equb_group.visibility_private'),
        };
    }
}

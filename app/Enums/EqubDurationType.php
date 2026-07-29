<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EqubDurationType: string implements HasLabel
{
    case Fixed = 'fixed';
    case PerMember = 'per_member';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Fixed => __('filament.equb_group.duration_type_fixed'),
            self::PerMember => __('filament.equb_group.duration_type_per_member'),
        };
    }
}

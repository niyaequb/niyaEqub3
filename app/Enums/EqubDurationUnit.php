<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EqubDurationUnit: string implements HasLabel
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Days => __('filament.equb_group.duration_unit_days'),
            self::Weeks => __('filament.equb_group.duration_unit_weeks'),
            self::Months => __('filament.equb_group.duration_unit_months'),
        };
    }
}

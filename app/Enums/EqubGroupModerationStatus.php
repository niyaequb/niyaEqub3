<?php

namespace App\Enums;

enum EqubGroupModerationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('filament.member_equb_group.moderation_pending'),
            self::Approved => __('filament.member_equb_group.moderation_approved'),
            self::Rejected => __('filament.member_equb_group.moderation_rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}

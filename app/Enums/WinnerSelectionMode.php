<?php

namespace App\Enums;

enum WinnerSelectionMode: string
{
    /** One winner per round. Default: how every existing platform group behaves. */
    case Single = 'single';

    /** Exactly N winners every round, e.g. always 2. */
    case FixedSize = 'fixed_size';

    /**
     * The system splits the group into winner sub-groups of random size within
     * [min_winners_per_draw, max_winners_per_draw]. 7 members => 3 + 4, or 2 + 5,
     * or 2 + 2 + 3. The split is drawn once and frozen when the group starts.
     */
    case RandomSplit = 'random_split';

    /** The owner (or an admin) picks the winner group by hand each round. */
    case Manual = 'manual';

    public function isMultiWinner(): bool
    {
        return $this !== self::Single;
    }

    public function label(): string
    {
        return match ($this) {
            self::Single => __('filament.member_equb_group.winner_mode_single'),
            self::FixedSize => __('filament.member_equb_group.winner_mode_fixed'),
            self::RandomSplit => __('filament.member_equb_group.winner_mode_random_split'),
            self::Manual => __('filament.member_equb_group.winner_mode_manual'),
        };
    }
}

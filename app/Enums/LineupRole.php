<?php

namespace App\Enums;

/**
 * Seat of a player in a lineup, the fourth position of a `p` tag in the
 * lineup event (kind 32151). Substitutes are active players too; they do not
 * count towards the lineup's minimum size (NIP rule 8).
 */
enum LineupRole: string
{
    case Captain = 'captain';
    case Player = 'player';
    case Substitute = 'substitute';

    public function label(): string
    {
        return match ($this) {
            self::Captain => __('Captain'),
            self::Player => __('Player'),
            self::Substitute => __('Sub'),
        };
    }

    public function countsTowardsMinimum(): bool
    {
        return $this !== self::Substitute;
    }
}

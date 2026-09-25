<?php

namespace App\Enums;

/**
 * Role of a clan member, the fourth position of a `p` tag in the clan event
 * (kind 32150). The owner is always a captain.
 */
enum ClanRole: string
{
    case Captain = 'captain';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Captain => __('Captain'),
            self::Member => __('Player'),
        };
    }
}

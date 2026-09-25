<?php

namespace App\Enums;

/**
 * How a series result was reached (NIP tag `resolution` in 2154): both
 * captains agreed, or an admin decided (a result, a forfeit, or a void).
 */
enum SeriesResolution: string
{
    case Confirmed = 'confirmed';
    case Admin = 'admin';
    case Forfeit = 'forfeit';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => __('both captains'),
            self::Admin => __('decided by admin'),
            self::Forfeit => __('forfeit'),
            self::Void => __('void'),
        };
    }
}

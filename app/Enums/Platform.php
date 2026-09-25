<?php

namespace App\Enums;

/**
 * The platform a player mainly plays on. Shown on the gaming profile so
 * opponents know whether a cross-play lobby is needed.
 */
enum Platform: string
{
    case Pc = 'pc';
    case PlayStation = 'playstation';
    case Xbox = 'xbox';
    case Switch = 'switch';

    public function label(): string
    {
        return match ($this) {
            self::Pc => __('PC'),
            self::PlayStation => __('PlayStation'),
            self::Xbox => __('Xbox'),
            self::Switch => __('Nintendo Switch'),
        };
    }
}

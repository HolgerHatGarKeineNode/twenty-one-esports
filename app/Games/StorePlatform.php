<?php

namespace App\Games;

/**
 * Where a player gets their own copy of a game that is not played on the
 * league's site (PlayedOnOwnCopy): a PC store or a console. The label is the
 * brand name, the same in every language; the icon is a simple glyph of
 * <x-icon>, no trademarked logo file.
 */
enum StorePlatform: string
{
    case Steam = 'steam';
    case Epic = 'epic';
    case PlayStation = 'playstation';
    case Xbox = 'xbox';
    case NintendoSwitch = 'switch';

    public function label(): string
    {
        return match ($this) {
            self::Steam => 'Steam',
            self::Epic => 'Epic Games',
            self::PlayStation => 'PlayStation',
            self::Xbox => 'Xbox',
            self::NintendoSwitch => 'Nintendo Switch',
        };
    }

    /**
     * The x-icon name of its glyph.
     */
    public function icon(): string
    {
        return 'store-'.$this->value;
    }
}

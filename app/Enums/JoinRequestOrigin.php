<?php

namespace App\Enums;

/**
 * How a join request reached the clan.
 *
 *  link         the player took a clan join link (P6b)
 *  application  the player applied on the clan page, with the short form
 *               (games, platforms, time zone, message)
 */
enum JoinRequestOrigin: string
{
    case Link = 'link';
    case Application = 'application';
}

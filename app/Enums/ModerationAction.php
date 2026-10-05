<?php

namespace App\Enums;

/**
 * What an admin did to a Nostr key site-wide (App\Support\Moderation\SiteModeration).
 * A mute hides the key's messages and name everywhere on the site; a ban
 * does the same and takes away every way to take part (login included).
 */
enum ModerationAction: string
{
    case Mute = 'mute';
    case Ban = 'ban';
}

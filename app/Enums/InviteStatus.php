<?php

namespace App\Enums;

/**
 * State of a clan invite. Only `accepted` has a signed counterpart on Nostr
 * (the invitee's Clan Membership, kind 12150); declining is league data.
 */
enum InviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}

<?php

namespace App\Enums;

/**
 * State of a blitz invite to a friend who is online. Invites are league
 * data only (no Nostr event); an unanswered one expires.
 */
enum ChessInviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}

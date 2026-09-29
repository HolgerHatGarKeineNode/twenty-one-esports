<?php

namespace App\Enums;

/**
 * State of an invite to a board game other than chess (plan "Mühle und
 * Dame", P5), as ChessInviteStatus for chess. League data only (no Nostr
 * event); an unanswered one expires.
 */
enum BoardInviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}

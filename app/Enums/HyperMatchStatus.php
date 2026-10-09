<?php

namespace App\Enums;

/**
 * Lifecycle of a Hyperbitcoinization match (App\Models\HyperMatch). A match starts active with every seat
 * taken (the lobby of P3 adds the time before that) and finishes when the rules core declares a winner. A
 * tournament match the league voided (P5c, HyperMatches::void()) is aborted: no winner, no places, never rated.
 */
enum HyperMatchStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
    case Aborted = 'aborted';
}

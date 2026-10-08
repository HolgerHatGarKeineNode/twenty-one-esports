<?php

namespace App\Enums;

/**
 * Lifecycle of a Hyperbitcoinization match (App\Models\HyperMatch). A match starts active with every seat
 * taken (the lobby of P3 adds the time before that) and finishes when the rules core declares a winner.
 */
enum HyperMatchStatus: string
{
    case Active = 'active';
    case Finished = 'finished';
}

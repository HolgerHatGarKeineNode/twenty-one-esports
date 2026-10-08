<?php

namespace App\Enums;

/**
 * How a Hyperbitcoinization match ended: the last seat standing (conquest), or the most central banks
 * when the round limit was reached (limit).
 */
enum HyperEndReason: string
{
    case Conquest = 'conquest';
    case Limit = 'limit';
}

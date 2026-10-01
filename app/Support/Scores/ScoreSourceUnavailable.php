<?php

namespace App\Support\Scores;

use RuntimeException;
use Throwable;

/**
 * A score source could not be asked (down, rate limited, refused, an answer
 * it could not read). The caller keeps what it read before; nothing about
 * the player is concluded from it.
 *
 * `$sourceDown`: the source as a whole cannot be asked (no connection, a
 * 5xx, a 429, an answer it could not read) and is not asked again in this
 * snapshot. False: it refused this one player (a 4xx, round-3 S3), and the
 * next player is asked.
 */
final class ScoreSourceUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $sourceDown = true, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

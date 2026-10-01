<?php

namespace App\Support\Scores;

use RuntimeException;

/**
 * A score source could not be asked (down, rate limited, refused, an answer
 * it could not read). The caller keeps what it read before; nothing about
 * the player is concluded from it.
 */
final class ScoreSourceUnavailable extends RuntimeException {}

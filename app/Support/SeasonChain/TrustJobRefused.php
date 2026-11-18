<?php

namespace App\Support\SeasonChain;

use RuntimeException;

/**
 * The trust job did not run (a key or the member list is missing, or
 * another run holds the lock). The message names what is missing, never a
 * secret; the last ranks stay.
 */
final class TrustJobRefused extends RuntimeException
{
    public const RUNNING = 'A trust run is already running; the last ranks stay.';

    /** Another run holds the lock: nothing is wrong, that run publishes the ranks. */
    public function alreadyRunning(): bool
    {
        return $this->getMessage() === self::RUNNING;
    }
}

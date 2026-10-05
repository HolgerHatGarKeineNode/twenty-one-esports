<?php

namespace App\Support\Moderation;

use RuntimeException;

/**
 * A site-wide mute, ban or undo the league refuses before anything is
 * stored. `reason` is a short machine code (tests, logs); the message is
 * translated and meant for the admin who tried.
 */
final class SiteModerationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Support\Invites;

use RuntimeException;

/**
 * The league refuses an invite link action. `reason` is a stable code (tests
 * and the landing page state), the message is translated for the player.
 */
final class InviteLinkRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

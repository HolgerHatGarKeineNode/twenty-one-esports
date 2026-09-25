<?php

namespace App\Support\Nostr;

use RuntimeException;

/**
 * A signed event the league refused. `reason` is a short machine code
 * (logged, used in tests); the message shown to people is generic on purpose.
 */
final class RejectedEvent extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Signed event refused: {$reason}");
    }
}

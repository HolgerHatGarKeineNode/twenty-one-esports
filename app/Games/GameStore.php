<?php

namespace App\Games;

/**
 * One platform a game is sold (or given away) on, with the official store
 * page when one was checked; null leaves the platform unlinked rather than
 * pointing at a guessed address.
 */
final readonly class GameStore
{
    public function __construct(
        public StorePlatform $platform,
        public ?string $url = null,
    ) {}
}

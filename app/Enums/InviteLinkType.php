<?php

namespace App\Enums;

/**
 * What an invite link `/i/{code}` opens (P6b). Game links are open: whoever
 * accepts plays. A clan link only sends a join request that a captain
 * confirms. Named invites (a player picked by name) are not links of this
 * kind and stay direct.
 */
enum InviteLinkType: string
{
    case Blitz = 'blitz';
    case Daily = 'daily';
    case Series = 'series';
    case Clan = 'clan';

    public function isGame(): bool
    {
        return $this !== self::Clan;
    }

    public function isChess(): bool
    {
        return $this === self::Blitz || $this === self::Daily;
    }

    /**
     * The expiry choices offered when the link is made, in hours.
     *
     * @return list<int>
     */
    public function expiryChoices(): array
    {
        return match ($this) {
            self::Blitz => [1, 24, 48],
            self::Daily, self::Series => [24, 48, 168],
            self::Clan => [24, 168, 720],
        };
    }

    public function defaultExpiryHours(): int
    {
        return match ($this) {
            self::Blitz => 24,
            self::Daily, self::Series => 48,
            self::Clan => 168,
        };
    }
}

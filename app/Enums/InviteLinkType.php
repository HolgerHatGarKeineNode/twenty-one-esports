<?php

namespace App\Enums;

/**
 * What an invite link `/i/{code}` opens (P6b). Game links are open: whoever
 * accepts plays. A clan link only sends a join request that a captain
 * confirms. A tournament link (P47) is a player's personal link to a
 * tournament: it opens the tournament page and credits the player as the
 * referrer when the invited player signs up. Named invites (a player picked
 * by name) are not links of this kind and stay direct.
 */
enum InviteLinkType: string
{
    case Blitz = 'blitz';
    case Daily = 'daily';
    case Series = 'series';
    case Clan = 'clan';
    case Tournament = 'tournament';

    public function isGame(): bool
    {
        return $this !== self::Clan && $this !== self::Tournament;
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
            // Open until the tournament's sign-up closes (InviteLinks::forTournament()).
            self::Tournament => [],
        };
    }

    public function defaultExpiryHours(): int
    {
        return match ($this) {
            self::Blitz => 24,
            self::Daily, self::Series => 48,
            self::Clan, self::Tournament => 168,
        };
    }
}

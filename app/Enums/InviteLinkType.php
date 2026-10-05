<?php

namespace App\Enums;

use App\Models\ChessGame;

/**
 * What an invite link `/i/{code}` opens (P6b). Game links are open: whoever
 * accepts plays. A clan link only sends a join request that a captain
 * confirms. A tournament link (P47) is a player's personal link to a
 * tournament: it opens the tournament page and credits the player as the
 * referrer when the invited player signs up. Named invites (a player picked
 * by name) are not links of this kind and stay direct.
 *
 * A rapid link (plan "Schach Rapid und Clan", P2) is a blitz link in Rapid
 * 10+5: live chess, colours drawn at random.
 *
 * A board link is a game link to a board game other than chess (nine men's
 * morris, checkers): `options.game` and `options.mode` (blitz or
 * correspondence). A score link is no game at all: "beat my time" in a
 * score game such as Blockfill (`options.game`). It opens the game's page
 * with the inviter's best of the week; nobody takes a seat, nothing starts.
 */
enum InviteLinkType: string
{
    case Blitz = 'blitz';
    case Rapid = 'rapid';
    case Daily = 'daily';
    case Series = 'series';
    case Clan = 'clan';
    case Tournament = 'tournament';
    case Board = 'board';
    case Score = 'score';

    public function isGame(): bool
    {
        return $this !== self::Clan && $this !== self::Tournament && $this !== self::Score;
    }

    public function isChess(): bool
    {
        return $this->isLiveChess() || $this === self::Daily;
    }

    /** A live chess link on a clock (blitz, rapid): one live game at a time, colours at random. */
    public function isLiveChess(): bool
    {
        return $this === self::Blitz || $this === self::Rapid;
    }

    /** The chess mode the link starts, or null for a link that is no chess game. */
    public function chessMode(): ?string
    {
        return match ($this) {
            self::Blitz => 'blitz',
            self::Rapid => 'rapid',
            self::Daily => ChessGame::CORRESPONDENCE,
            default => null,
        };
    }

    /**
     * The expiry choices offered when the link is made, in hours.
     *
     * @return list<int>
     */
    public function expiryChoices(): array
    {
        return match ($this) {
            self::Blitz, self::Rapid => [1, 24, 48],
            self::Daily, self::Series, self::Board => [24, 48, 168],
            self::Clan => [24, 168, 720],
            // A week: the inviter's best is the week's (InviteLinks::create() keeps one open per game).
            self::Score => [168],
            // Open until the tournament's sign-up closes (InviteLinks::forTournament()).
            self::Tournament => [],
        };
    }

    public function defaultExpiryHours(): int
    {
        return match ($this) {
            self::Blitz, self::Rapid => 24,
            self::Daily, self::Series, self::Board => 48,
            self::Clan, self::Tournament, self::Score => 168,
        };
    }
}

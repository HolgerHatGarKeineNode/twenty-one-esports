<?php

namespace App\Support\Scores;

use App\Games\ScoreGame;
use App\Models\User;

/**
 * Whose best a source reads: the league player and, for a source that reads
 * by game account, the player's private account id in that game
 * (users.gamer_tags under ScoreGame::accountService()) when the id is theirs
 * (ScoreAccounts::userFor()). The account id never leaves the server: it is
 * sent to the game's own API only.
 */
final readonly class ScoreAccount
{
    public function __construct(public int $userId, public ?string $accountId = null) {}

    public static function of(User $user, ScoreGame $game): self
    {
        $service = $game->accountService();
        $accountId = $service === null ? '' : trim((string) ($user->gamer_tags[$service] ?? ''));

        // Only an id that is this player's (confirmed, or stored by nobody else) is read for them (security gate F4):
        // a player who stores another's id gets no records of it.
        return new self($user->id, $accountId !== '' && ScoreAccounts::userFor($game, $accountId) === $user->id ? $accountId : null);
    }
}

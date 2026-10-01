<?php

namespace App\Support\Scores;

use App\Games\ScoreGame;
use App\Models\User;

/**
 * Whose best a source reads: the league player and, for a source that reads
 * by game account, the player's private account id in that game
 * (users.gamer_tags under ScoreGame::accountService()). The account id never
 * leaves the server: it is sent to the game's own API only.
 */
final readonly class ScoreAccount
{
    public function __construct(public int $userId, public ?string $accountId = null) {}

    public static function of(User $user, ScoreGame $game): self
    {
        $service = $game->accountService();
        $accountId = $service === null ? null : trim((string) ($user->gamer_tags[$service] ?? ''));

        return new self($user->id, $accountId === '' ? null : $accountId);
    }
}

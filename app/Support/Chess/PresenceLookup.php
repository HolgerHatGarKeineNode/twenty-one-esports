<?php

namespace App\Support\Chess;

use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Throwable;

/**
 * Who is on a live game's presence channel `game.{id}.players`, asked of the
 * websocket server itself (Reverb speaks the Pusher HTTP API:
 * `GET /apps/{app}/channels/{channel}/users`). The browser's "opponent left"
 * is only a hint; a claim-win rests on this answer.
 *
 * `absent()` answers true, false, or null when it cannot tell (no websocket
 * server configured, not reachable, unexpected answer). Callers treat null
 * as "no claim": a server that cannot see its websocket must not hand out
 * wins (fail closed).
 */
class PresenceLookup
{
    public function __construct(private BroadcastManager $broadcast) {}

    public function absent(ChessGame $game, User $user): ?bool
    {
        try {
            $broadcaster = $this->broadcast->connection();

            if (! $broadcaster instanceof PusherBroadcaster) {
                return null;
            }

            $answer = $broadcaster->getPusher()->get('/channels/presence-game.'.$game->id.'.players/users', [], true);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($answer) || ! is_array($answer['users'] ?? null)) {
            return null;
        }

        foreach ($answer['users'] as $member) {
            if ((string) ($member['id'] ?? '') === (string) $user->id) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Support\Chess;

use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Pusher\ApiErrorException;
use Throwable;

/**
 * Who is on a presence channel, asked of the websocket server itself
 * (Reverb speaks the Pusher HTTP API: `GET /apps/{app}/channels/{channel}/users`).
 *
 * `absent()`: a live game's channel `game.{id}.players`. The browser's
 * "opponent left" is only a hint; a claim-win rests on this answer. It
 * answers true, false, or null when it cannot tell (no websocket server
 * configured, not reachable, unexpected answer). Callers treat null as
 * "no claim": a server that cannot see its websocket must not hand out
 * wins (fail closed).
 *
 * `online()`: the global channel `online` every logged-in page joins. Same
 * three answers; what null means is the caller's decision.
 */
class PresenceLookup
{
    public function __construct(private BroadcastManager $broadcast) {}

    public function absent(ChessGame $game, User $user): ?bool
    {
        $members = $this->members('presence-game.'.$game->id.'.players');

        return $members === null ? null : ! in_array((string) $user->id, $members, true);
    }

    public function online(User $user): ?bool
    {
        $members = $this->members('presence-online');

        return $members === null ? null : in_array((string) $user->id, $members, true);
    }

    /**
     * @return list<string>|null member ids, null when the websocket server cannot tell
     */
    private function members(string $channel): ?array
    {
        try {
            $broadcaster = $this->broadcast->connection();

            if (! $broadcaster instanceof PusherBroadcaster) {
                return null;
            }

            $answer = $broadcaster->getPusher()->get('/channels/'.$channel.'/users', [], true);
        } catch (ApiErrorException $exception) {
            // Reverb answers 404 for a presence channel nobody is on right now:
            // an ordinary state, not an error worth a log line. Still "cannot
            // tell" (null), so a claim-win stays refused.
            if ($exception->getCode() !== 404) {
                report($exception);
            }

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($answer) || ! is_array($answer['users'] ?? null)) {
            return null;
        }

        return array_values(array_map(fn ($member): string => (string) (is_array($member) ? ($member['id'] ?? '') : ''), $answer['users']));
    }
}

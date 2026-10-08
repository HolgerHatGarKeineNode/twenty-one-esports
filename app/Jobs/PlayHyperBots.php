<?php

namespace App\Jobs;

use App\Models\HyperMatch;
use App\Support\Hyper\HyperMatches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plays the bot seats of a Hyperbitcoinization match on the server: every bot turn in a row until a player
 * is to move or the match is over, each turn stored and broadcast like a player's. Dispatched after a
 * change that hands the turn to a bot; running twice is harmless, HyperMatches only plays a bot whose turn
 * it is at the ply the job was sent for. The `hyper:check-clocks` sweep sends it again for a bot turn
 * whose deadline passed, should a worker have lost it.
 */
class PlayHyperBots implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $matchId, public int $ply)
    {
        $this->afterCommit();
    }

    public function handle(HyperMatches $matches): void
    {
        $match = HyperMatch::query()->find($this->matchId);

        if ($match !== null && $match->isActive()) {
            $matches->playBots($match, $this->ply);
        }
    }
}

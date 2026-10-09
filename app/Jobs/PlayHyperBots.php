<?php

namespace App\Jobs;

use App\Models\HyperMatch;
use App\Support\Hyper\HyperMatches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plays the bot seats of a Hyperbitcoinization match on the server: every bot turn in a row until a player
 * is to move or the match is over, each turn stored and broadcast like a player's; a long chain in runs of
 * TURNS_PER_RUN turns, each run sending the next (P6). Dispatched after a
 * change that hands the turn to a bot; running twice is harmless, HyperMatches only plays a bot whose turn
 * it is at the ply the job was sent for. The `hyper:check-clocks` sweep sends it again for a bot turn
 * whose deadline passed, should a worker have lost it.
 */
class PlayHyperBots implements ShouldQueue
{
    use Queueable;

    /**
     * Bot turns one run plays at most (P6): a bots-only match runs up to `esports.hyper.bot_round_cap` rounds of
     * up to six seats, so one run never holds a worker for the whole chain; the rest goes on in a new run.
     */
    public const TURNS_PER_RUN = 50;

    /** Seconds a run may take: 50 bot turns take a few seconds; below the queues' `retry_after` (90), so a slow run is never reserved twice. */
    public int $timeout = 80;

    /** A run that failed is tried again; the sweep (`hyper:check-clocks`) sends a lost chain again besides. */
    public int $tries = 3;

    public function __construct(public int $matchId, public int $ply)
    {
        $this->afterCommit();
    }

    public function handle(HyperMatches $matches): void
    {
        $match = HyperMatch::query()->find($this->matchId);

        if ($match === null || ! $match->isActive()) {
            return;
        }

        $next = $matches->playBots($match, $this->ply, self::TURNS_PER_RUN);

        if ($next !== null) {
            self::dispatch($this->matchId, $next);
        }
    }
}

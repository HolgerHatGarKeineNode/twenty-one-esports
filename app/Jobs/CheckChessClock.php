<?php

namespace App\Jobs;

use App\Models\ChessGame;
use App\Support\Chess\ChessGameService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at a game's clock once its deadline has passed, so a flag falls
 * even when nobody moves again. Dispatched (delayed) after every change of a
 * live game; running early or twice is harmless, ChessGameService only acts
 * on the server clock. The scheduled `chess:check-clocks` sweep catches
 * whatever a stopped worker misses.
 */
class CheckChessClock implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $gameId)
    {
        $this->afterCommit();
    }

    public function handle(ChessGameService $games): void
    {
        $game = ChessGame::query()->find($this->gameId);

        if ($game !== null && $game->isActive()) {
            $games->checkClock($game);
        }
    }
}

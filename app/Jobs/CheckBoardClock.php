<?php

namespace App\Jobs;

use App\Models\BoardGame;
use App\Support\Board\BoardGameService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at a board game's clock once its deadline has passed, so a flag
 * falls even when nobody moves again. Dispatched (delayed) after every change
 * of a live game; running early or twice is harmless, BoardGameService only
 * acts on the server clock. The scheduled `board:check-clocks` sweep catches
 * whatever a stopped worker misses. The board game twin of CheckChessClock.
 */
class CheckBoardClock implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $gameId)
    {
        $this->afterCommit();
    }

    public function handle(BoardGameService $games): void
    {
        $game = BoardGame::query()->find($this->gameId);

        if ($game !== null && $game->isActive()) {
            $games->checkClock($game);
        }
    }
}

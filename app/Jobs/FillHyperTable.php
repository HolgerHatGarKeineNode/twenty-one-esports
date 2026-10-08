<?php

namespace App\Jobs;

use App\Support\Hyper\HyperLobby;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives the free seats of a live Hyperbitcoinization lobby table to bots once its wait is over
 * (`esports.hyper.lobby_fill_seconds`), and so starts it. Dispatched (delayed) when the table opens;
 * running early, twice, or for a table that started or closed meanwhile does nothing. The
 * `hyper:check-clocks` sweep catches what a stopped worker misses.
 */
class FillHyperTable implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tableId)
    {
        $this->afterCommit();
    }

    public function handle(HyperLobby $lobby): void
    {
        $lobby->fillDue($this->tableId);
    }
}

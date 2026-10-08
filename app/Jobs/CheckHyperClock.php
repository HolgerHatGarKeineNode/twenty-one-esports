<?php

namespace App\Jobs;

use App\Models\HyperMatch;
use App\Support\Hyper\HyperMatches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at a Hyperbitcoinization turn's clock once its deadline has passed, so a turn ends even when its
 * player does nothing. Dispatched (delayed) whenever a turn starts; running early or twice is harmless,
 * HyperMatches only acts on the server clock. The `hyper:check-clocks` sweep catches what a stopped worker
 * misses. The twin of CheckBoardClock.
 */
class CheckHyperClock implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $matchId)
    {
        $this->afterCommit();
    }

    public function handle(HyperMatches $matches): void
    {
        $match = HyperMatch::query()->find($this->matchId);

        if ($match !== null && $match->isActive()) {
            $matches->checkClock($match);
        }
    }
}

<?php

namespace App\Jobs;

use App\Support\Badges\RankBadges;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After a rated result moved the ratings: bring the rank badges of the rated
 * entities in line with the ladder (NIP "Rank badges"). Dispatched after the
 * commit, so a rolled-back result never signs a badge; a failed badge never
 * takes the result with it. Idempotent ({@see RankBadges::sync()}).
 */
class SyncRankBadges implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $subjects  `user:<id>` or `lineup:<id>`
     */
    public function __construct(public string $game, public string $mode, public array $subjects)
    {
        $this->afterCommit();
    }

    public function handle(RankBadges $badges): void
    {
        $badges->syncSubjects($this->game, $this->mode, $this->subjects);
    }
}

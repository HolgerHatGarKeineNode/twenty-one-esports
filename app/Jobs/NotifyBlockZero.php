<?php

namespace App\Jobs;

use App\Support\Notifications\BlockZeroNotifications;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells every player who asked for "Notify me at Block 0" (BlockZeroNotifications):
 * `released` after the board released Block 0 (SeasonRelease), `dated` when
 * a planned Block 0 date is on (`esports:block0-heads-up`). While a run of
 * the same moment is queued, further dispatches coalesce into it; the
 * per-player claim makes any second run harmless anyway. Dispatched after
 * the commit, so it reads the season as committed.
 */
class NotifyBlockZero implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const RELEASED = 'released';

    public const DATED = 'dated';

    /**
     * @param  self::RELEASED|self::DATED  $moment
     */
    public function __construct(public string $moment)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'block0-'.$this->moment;
    }

    public function handle(BlockZeroNotifications $notifications): void
    {
        $this->moment === self::RELEASED ? $notifications->released() : $notifications->dated();
    }
}

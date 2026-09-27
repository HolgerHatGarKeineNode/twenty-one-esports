<?php

namespace App\Jobs;

use App\Support\Tournaments\TournamentPublisher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A new version of the league's tournament calendar (31924, `d` =
 * `tournaments`), after a tournament was published or changed. Every
 * tournament shares this one address, so the calendar has one writer:
 * while a run is queued, further dispatches coalesce into it (unique until
 * it starts), and the writer itself is serialized by a lock
 * (TournamentPublisher::publishCalendar), so no two versions are ever signed
 * in the same second or ahead of the clock. Dispatched after the commit, so
 * it reads the tournaments as committed.
 */
class PublishTournamentCalendar implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'tournament-calendar';
    }

    public function handle(TournamentPublisher $publisher): void
    {
        $publisher->publishCalendar();
    }
}

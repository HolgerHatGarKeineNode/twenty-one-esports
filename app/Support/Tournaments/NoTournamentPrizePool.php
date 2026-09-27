<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;

/**
 * No tournament has a prize pool before P9: the page leaves the section out.
 */
final class NoTournamentPrizePool implements TournamentPrizePool
{
    public function for(Tournament $tournament): ?array
    {
        return null;
    }
}

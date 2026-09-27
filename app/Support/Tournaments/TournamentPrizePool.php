<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;
use Illuminate\Container\Attributes\Bind;

/**
 * The prize pool of a tournament as its public page shows it (P9): the pot,
 * the split per place and the sponsors. The page renders the pool section
 * only when this returns a pool, so it never shows a number the league does
 * not have.
 *
 * Until P9 lands the league has no pools, and {@see NoTournamentPrizePool}
 * answers null for every tournament; P9 binds its own implementation.
 */
#[Bind(NoTournamentPrizePool::class)]
interface TournamentPrizePool
{
    /**
     * @return array{sats: int, split: list<array{place: int, percent: int, sats: int}>, sponsors: list<array{name: string, logo: string|null, url: string|null}>}|null
     */
    public function for(Tournament $tournament): ?array;
}

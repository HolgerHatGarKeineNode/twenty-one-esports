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
 * {@see NoTournamentPrizePool} answers null for every tournament; the app
 * binds App\Support\Prizes\LeaguePrizePool (P9), which answers once a
 * tournament's pool was opened.
 */
#[Bind(NoTournamentPrizePool::class)]
interface TournamentPrizePool
{
    /**
     * `sats` is the pot, `split` what each place gets from it now. The
     * optional keys (P9 scope addition): `target` the organizer's goal in
     * sats (null = none) and `funded` whether the pot reached it; `as_of`
     * when an own-wallet pot's balance was read and `stale` when that read is
     * old or the wallet stopped answering (the last good value is shown with
     * its time, never a guess). No Lightning address: public screens never
     * show one as text.
     *
     * @return array{sats: int, split: list<array{place: int, percent: int, sats: int}>, sponsors: list<array{name: string, logo: string|null, url: string|null}>, target?: int|null, funded?: bool, as_of?: \DateTimeInterface|null, stale?: bool}|null
     */
    public function for(Tournament $tournament): ?array;
}

<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Container\Attributes\Bind;

/**
 * The prize pool of a tournament as its public page shows it (P9): the pot,
 * the split per place and the sponsors. The page renders the pool section
 * only when this returns a pool, so it never shows a number the league does
 * not have.
 *
 * {@see NoTournamentPrizePool} answers null for every tournament; the app
 * binds App\Support\Prizes\WalletPrizePool (P9), which answers once a
 * tournament's pool was opened.
 */
#[Bind(NoTournamentPrizePool::class)]
interface TournamentPrizePool
{
    /**
     * `sats` is the pot (its wallet's balance); `mode` is `percent` or
     * `fixed`; `split` what each place wins now, in sats in both modes
     * (`percent` null in fixed mode). `target` is the goal: the organizer's
     * target (percent mode, null without one) or the sum of the fixed prizes;
     * `have` what the pot can pay towards it (the balance, less the fee
     * reserve in fixed mode) and `funded` whether it reached it. `as_of` is
     * when the balance was read and `stale` when that read is old or the
     * wallet stopped answering (the last good value is shown with its time,
     * never a guess). No Lightning address: public screens never show one
     * as text. `base` is the pot as set and `zaps` the sats zapped on top of
     * it (`sats` = base + zaps); `zappers` the zap sponsors' wall, biggest
     * first (App\Support\Prizes\ZapSponsors).
     *
     * @return array{sats: int, base?: int, zaps?: int, zappers?: list<array{pubkey: string, npub: string, sats: int, zaps: int, first: int, user: User|null}>, mode?: string, split: list<array{place: int, percent: int|null, sats: int}>, sponsors: list<array{name: string, logo: string|null, url: string|null}>, target?: int|null, have?: int, funded?: bool, as_of?: \DateTimeInterface|null, stale?: bool}|null
     */
    public function for(Tournament $tournament): ?array;
}

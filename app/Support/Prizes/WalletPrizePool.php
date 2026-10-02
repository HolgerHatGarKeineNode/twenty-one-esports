<?php

namespace App\Support\Prizes;

use App\Models\Tournament;
use App\Support\Tournaments\TournamentPrizePool;

/**
 * The prize pot wherever a tournament shows (P9), behind the seam the pages
 * and the TV were built with. A pot is booked in the league wallet (user,
 * 2026-10-02): its sats are the pot as the tournament sets it (the fixed
 * prizes' sum or the target; what came in only without either, user
 * 2026-09-28). The prizes are the percents of that pot (less the fee
 * reserve) or the fixed amounts; what came in only feeds the funding bar.
 * The sponsors are the ones who paid, by invoice or outside the wallet (a
 * pledge alone shows nothing).
 */
final class WalletPrizePool implements TournamentPrizePool
{
    public function __construct(private PrizePool $pool) {}

    public function for(Tournament $tournament): ?array
    {
        $sats = $this->pool->potSats($tournament);

        if ($tournament->pool_opened_at === null || $sats === null) {
            return null;
        }

        $sponsors = [];

        foreach ($tournament->sponsors()->get() as $sponsor) {
            if ($sponsor->isPaid()) {
                $sponsors[] = ['name' => $sponsor->name, 'logo' => $sponsor->logoUrl(), 'url' => null];
            }
        }

        $funding = $this->pool->funding($tournament);

        return [
            'sats' => $sats,
            'left' => (int) $this->pool->remainingSats($tournament),
            'mode' => $tournament->prizeMode(),
            'split' => $this->pool->projection($tournament),
            'sponsors' => $sponsors,
            'target' => $funding['goal'],
            'have' => $funding['have'],
            'funded' => $funding['funded'],
            // A league pot is booked exactly; only a legacy own wallet had a balance read with a time.
            'as_of' => $tournament->hasOwnWallet() ? $tournament->pot_balance_at : null,
            'stale' => $tournament->pool_closed_at === null && PrizePool::isBalanceStale($tournament),
        ];
    }
}

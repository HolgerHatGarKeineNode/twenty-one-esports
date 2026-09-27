<?php

namespace App\Support\Prizes;

use App\Models\Tournament;
use App\Support\Tournaments\TournamentPrizePool;

/**
 * The league's prize pools wherever a tournament shows (P9), behind the seam
 * the page was built with: a pool exists once it was opened. A league pot is
 * what paid invoices put into it ({@see PrizePool::fundedSats()}); a pot in
 * the tournament's own wallet is that wallet's last balance read, with its
 * time, and is left out until the first read succeeded (never a guess). The
 * split is what each place would get from it now, and the sponsors are the
 * ones whose invoice is paid (a pledge alone shows nothing).
 */
final class LeaguePrizePool implements TournamentPrizePool
{
    public function __construct(private PrizePool $pool) {}

    public function for(Tournament $tournament): ?array
    {
        if ($tournament->pool_opened_at === null) {
            return null;
        }

        $sats = $this->pool->potSats($tournament);

        if ($sats === null) {
            return null;
        }

        $sponsors = [];

        foreach ($tournament->sponsors()->get() as $sponsor) {
            if ($sponsor->isPaid()) {
                $sponsors[] = ['name' => $sponsor->name, 'logo' => $sponsor->logoUrl(), 'url' => null];
            }
        }

        $own = $tournament->hasOwnWallet();
        $target = $tournament->prize_target_sats;

        return [
            'sats' => $sats,
            'split' => $this->pool->projection($tournament, (int) $this->pool->payableSats($tournament)),
            'sponsors' => $sponsors,
            'target' => $target,
            'funded' => $target !== null && $sats >= $target,
            'as_of' => $own ? $tournament->pot_balance_at : null,
            'stale' => $own && $tournament->pool_closed_at === null && PrizePool::isBalanceStale($tournament),
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Prizes\PotBalances;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reads the balance of every open pot held in a tournament's own NWC wallet
 * (P9 scope addition, scheduled every two minutes). A wallet that does not
 * answer keeps its last good balance with its time; the pages show how old
 * it is ({@see PotBalances}).
 */
#[Signature('wallet:read-pots')]
#[Description('Read the balance of the prize pots held in tournaments\' own wallets')]
class WalletReadPotsCommand extends Command
{
    public function handle(PotBalances $balances): int
    {
        $read = 0;
        $pots = $balances->due();

        foreach ($pots as $tournament) {
            $read += $balances->read($tournament) ? 1 : 0;
        }

        $this->info("{$read} of {$pots->count()} pot(s) read.");

        return self::SUCCESS;
    }
}

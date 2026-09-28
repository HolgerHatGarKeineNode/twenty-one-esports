<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Models\SeasonPayout;
use App\Models\TournamentPayout;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PotTopUps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The wallet's routine (P9, scheduled every minute): payouts whose attempt
 * did not finish (a timeout, a crashed worker) are continued once their
 * lease has run out, then open invoices into the reserve and the
 * tournaments' pots are looked up and settled or expired; a wallet that
 * times out is skipped for the rest of the run. Continuing never starts a new payment: PayoutRunner looks the
 * stored invoice up first. Does nothing without the wallet connections.
 */
#[Signature('wallet:sync')]
#[Description('Settle paid pool invoices and continue unfinished payouts')]
class WalletSyncCommand extends Command
{
    public function handle(IncomingPayments $payments, PotTopUps $topUps, PayoutRunner $runner): int
    {
        // Payouts first, then the league reserve, then the tournaments' own wallets: a slow tournament
        // wallet's top-up lookups never hold up a payout or the reserve (gate F-B).
        // Tournament payouts, then season payouts (P37): the same runner, each from its own wallet.
        $unfinished = 0;

        foreach ([TournamentPayout::query(), SeasonPayout::query()] as $query) {
            $payouts = $query->where('status', PayoutStatus::Paying)
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
                ->where(fn ($query) => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<', now()->subMinute()))
                ->orderBy('id')->get();

            foreach ($payouts as $payout) {
                $runner->run($payout, false);
                $unfinished++;
            }
        }

        $settled = $payments->checkAll();
        $settled += $topUps->checkAll();

        $this->info("{$settled} invoice(s) settled, {$unfinished} unfinished payout(s) checked.");

        return self::SUCCESS;
    }
}

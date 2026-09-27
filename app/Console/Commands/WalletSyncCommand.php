<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Models\TournamentPayout;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The wallet's routine (P9, scheduled every minute): open invoices into the
 * pots are looked up and settled or expired, and payouts whose attempt did
 * not finish (a timeout, a crashed worker) are continued once their lease
 * has run out. Continuing never starts a new payment: PayoutRunner looks the
 * stored invoice up first. Does nothing without the wallet connections.
 */
#[Signature('wallet:sync')]
#[Description('Settle paid pool invoices and continue unfinished payouts')]
class WalletSyncCommand extends Command
{
    public function handle(IncomingPayments $payments, PayoutRunner $runner): int
    {
        $settled = $payments->checkAll();

        $unfinished = TournamentPayout::query()->where('status', PayoutStatus::Paying)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where(fn ($query) => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<', now()->subMinute()))
            ->orderBy('id')->get();

        foreach ($unfinished as $payout) {
            $runner->run($payout, false);
        }

        $this->info("{$settled} invoice(s) settled, {$unfinished->count()} unfinished payout(s) checked.");

        return self::SUCCESS;
    }
}

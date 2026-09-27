<?php

namespace App\Console\Commands;

use App\Models\TournamentPayout;
use App\Support\Payouts\PayoutRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One payment attempt from the command line, the same code path as the
 * admin's "Pay" (PayTournamentPayout). Without `--start` an unfinished
 * attempt is only continued. Running it twice at once pays once.
 */
#[Signature('payouts:run {payout : The payout id} {--start : Claim a pending or failed payout}')]
#[Description('Pay or continue one tournament payout')]
class PayoutRunCommand extends Command
{
    public function handle(PayoutRunner $runner): int
    {
        $payout = TournamentPayout::query()->find((int) $this->argument('payout'));

        if ($payout === null) {
            $this->error('No such payout.');

            return self::FAILURE;
        }

        $runner->run($payout, (bool) $this->option('start'));
        $payout->refresh();
        $this->info("Payout {$payout->id}: {$payout->status->value}".($payout->reason === null ? '' : " ({$payout->reason})"));

        return self::SUCCESS;
    }
}

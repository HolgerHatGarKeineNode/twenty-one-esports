<?php

namespace App\Console\Commands;

use App\Support\FairPlay\Checks;
use App\Support\FairPlay\ReceivedZaps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The received zaps of the accounts admins look at (ReceivedZaps, plan "Profile standard and impersonation", P3):
 * open holds and the players /admin/checks lists, each read at most once a day, 100 per run. Scheduled hourly. A
 * player no relay answered for is reported and read again on the next run.
 */
#[Signature('fair-play:received-zaps')]
#[Description('Read the zaps the accounts on /admin/checks received, for the admins\' soft marks')]
class ReceivedZapsCommand extends Command
{
    public function handle(ReceivedZaps $zaps, Checks $checks): int
    {
        $season = Checks::season();
        $listed = $season === null ? [] : array_column($checks->read($season)['rows'], 'pubkey');
        $read = $zaps->refresh($listed);

        $this->info("Read the zaps of {$read['players']} account(s).");

        if ($read['failed'] > 0) {
            $this->warn("{$read['failed']} account(s) found no relay answering; read again on the next run.");
        }

        return self::SUCCESS;
    }
}

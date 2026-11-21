<?php

namespace App\Console\Commands;

use App\Support\FairPlay\ListedProfiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The kind-0 profiles of accounts Verein members list on their league opponent lists (ListedProfiles, plan "Profile
 * standard and impersonation", P3, D13): the list sync is local, the profile read asks the profile relays for at
 * most 500 due keys. Scheduled hourly while the impersonation check is switched on. A batch no relay answered is
 * reported and read again on the next run; it never holds anybody.
 */
#[Signature('fair-play:listed-profiles')]
#[Description('Store the profiles of accounts on members\' league opponent lists for the impersonation check')]
class ListedProfilesCommand extends Command
{
    public function handle(ListedProfiles $profiles): int
    {
        $synced = ListedProfiles::sync();
        $read = $profiles->refresh();

        $this->info("Listed: {$synced['listed']} (new {$synced['added']}, unlisted {$synced['unlisted']}). Read {$read['asked']}, stored {$read['stored']}, skipped {$read['skipped']}, held {$read['held']}.");

        if ($read['failed'] > 0) {
            $this->warn("{$read['failed']} batch(es) found no relay answering; they are read again on the next run.");
        }

        return self::SUCCESS;
    }
}

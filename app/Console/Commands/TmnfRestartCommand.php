<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Asks a running `tmnf:listen` to stop, so its supervisor starts it again
 * on the code just deployed (plan "Restposten nach TMNF", P1), the way
 * `queue:restart` does for queue workers: the time of the request goes into
 * the cache, and the listener, which read the value at its start, stops
 * after the callback at hand once it changed (TmnfListenCommand). Run it in
 * the deploy script after the new code is in place.
 */
#[Signature('tmnf:restart')]
#[Description('Restart the TMNF listener after its current callback, so it runs the deployed code')]
class TmnfRestartCommand extends Command
{
    public function handle(): int
    {
        Cache::forever(TmnfListenCommand::RESTART_KEY, now()->getTimestamp());
        $this->info('The TMNF listener stops after its current callback; its supervisor starts it again.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Support\SeasonChain\TrustJob;
use App\Support\SeasonChain\TrustJobRefused;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One trust run right after Block 0 (SeasonRelease): rated play opens only
 * once the trust job ran inside the new season (AnchoredTrustFacts), so the
 * release starts a run instead of waiting for the next `esports:trust-run`
 * of the schedule. Dispatched after the commit, so the run sees the season
 * as live.
 *
 * Never fails: a refused run is logged, anything else is reported, and the
 * schedule (every 15 minutes) stays the fallback. So neither a sync queue
 * nor a broken relay can turn a committed release into an error.
 */
class RunTrustJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'trust-run';
    }

    public function handle(): void
    {
        try {
            // Resolved here, not injected: a job that cannot even be built must not fail either.
            app(TrustJob::class)->run();
        } catch (TrustJobRefused $refused) {
            if ($refused->alreadyRunning()) {
                // The schedule's run got there first: it runs inside the new season as well.
                Log::info('Trust run after Block 0 skipped: a trust run is already running');

                return;
            }

            Log::warning('Trust run after Block 0 refused; the schedule tries again', ['reason' => $refused->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

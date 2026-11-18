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
 * A trust run that holds the lock already (TrustJob: one run at a time) may
 * have started before the release committed and so ranks outside the new
 * season. This job then does not give up: it goes back onto the queue and
 * tries again RETRY_SECONDS later, `$tries` times in all (about five
 * minutes), until its own run happened inside the season. On a sync queue
 * nothing is released; the schedule is the fallback there.
 *
 * Never fails: a refused run is logged, anything else is reported, and the
 * schedule (every 15 minutes) stays the fallback. So neither a sync queue
 * nor a broken relay can turn a committed release into an error.
 */
class RunTrustJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** How long the job waits before it asks for the lock again. */
    public const RETRY_SECONDS = 20;

    /** The first attempt and 15 more behind a running trust run. */
    public int $tries = 16;

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
                // That run may have begun before Block 0: wait for it, then run inside the new season.
                if ($this->attempts() < $this->tries) {
                    $this->release(self::RETRY_SECONDS);

                    return;
                }

                Log::info('Trust run after Block 0 gave up: a trust run was running at every attempt; the schedule runs the next one');

                return;
            }

            Log::warning('Trust run after Block 0 refused; the schedule tries again', ['reason' => $refused->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

<?php

namespace App\Jobs;

use App\Enums\StackerRunStatus;
use App\Models\StackerRun;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerVerdict;
use App\Support\Stacker\Verifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Replays one submitted Blockfill run (plan "Blockfill", P2) on the
 * verifier's own queue (`esports.blockfill.verifier.queue`), so a slow
 * replay never holds a web worker or the default queue. One attempt; the
 * verifier runs outside any database transaction, and only the verdict is
 * written, by compare-and-set out of `verifying`. A job that fails or times
 * out leaves the run `pending`: no score without a verdict.
 */
class VerifyStackerRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue((string) config('esports.blockfill.verifier.queue'));
        $this->afterCommit();
    }

    public function handle(Verifier $verifier, StackerRuns $runs): void
    {
        $run = StackerRun::query()->find($this->runId);

        // a run the sweep gave up as stale is decided too: the backlog turns into verdicts
        $open = $run !== null && $run->replay !== null && ($run->status === StackerRunStatus::Verifying
            || ($run->status === StackerRunStatus::Pending && $run->reason === 'verifier-stale'));

        if (! $open) {
            return;
        }

        try {
            $verdict = $verifier->verify($run);
        } catch (Throwable $exception) {
            report($exception);
            $verdict = StackerVerdict::unavailable('verifier-failed');
        }

        $runs->finish($run, $verdict, now());
    }

    public function failed(?Throwable $exception): void
    {
        $run = StackerRun::query()->find($this->runId);

        if ($run !== null) {
            app(StackerRuns::class)->finish($run, StackerVerdict::unavailable('verifier-failed'), now());
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\SeasonPayout;
use App\Support\Payouts\PayoutRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One payment attempt for one season payout (P37), queued by an admin's
 * "Pay" so the page does not wait for the wallet. The payload is the payout
 * id and whether to start, never a secret. Safe to run twice at once and to
 * retry: PayoutRunner holds a lease, moves the state by compare-and-set and
 * never pays a second invoice while the first may be paid.
 */
class PaySeasonPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $payoutId, public bool $start = true)
    {
        $this->afterCommit();
    }

    public function handle(PayoutRunner $runner): void
    {
        $payout = SeasonPayout::query()->find($this->payoutId);

        if ($payout !== null) {
            $runner->run($payout, $this->start);
        }
    }
}

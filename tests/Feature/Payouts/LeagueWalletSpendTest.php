<?php

use App\Enums\PayoutStatus;
use App\Support\Payouts\PayoutRunner;
use App\Support\SeasonChain\SeasonSettlement;

/*
| Security gate on 8a171405, F1: the league wallet holds every tournament pot
| and the reserve, so every payment from it runs under one lock (payment and
| booking), never interleaved with another payout's on any worker. Its
| balance is never read (user, 2026-10-05).
*/

test('two season payouts at once never interleave: the second waits for the first and is then paid, without a balance read', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $season = settledSeason([[$alice, 1], [$bob, 2]]);
    app(SeasonSettlement::class)->approve($season, aBoardMember());
    $first = payoutOf($season, $alice);
    $second = payoutOf($season, $bob);
    config(['esports.wallet.spend_lock_wait_seconds' => 0]);
    $runner = app(PayoutRunner::class);
    $fired = false;
    $wallet->calls = [];

    // A second worker starts the other payout while the first holds the lock and is paying.
    $wallet->onRequest = function () use ($runner, $second, &$fired): void {
        if (! $fired) {
            $fired = true;
            $runner->run($second, true);
        }
    };

    $runner->run($first, true);
    $wallet->onRequest = null;

    expect($fired)->toBeTrue()
        ->and($first->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($second->refresh()->status)->toBe(PayoutStatus::Paying)->and($second->reason)->toBe('wallet_busy');

    // The schedule continues it once the lock is free: it is paid, once.
    $runner->run($second->refresh(), false);

    expect($second->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->payRequests())->toHaveCount(2)
        ->and(collect($wallet->calls)->where('method', 'get_balance')->all())->toBe([]);
});

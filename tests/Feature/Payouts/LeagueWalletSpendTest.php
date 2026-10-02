<?php

use App\Enums\PayoutStatus;
use App\Models\SeasonPayout;
use App\Support\Payouts\PayoutRunner;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\Wallet\Ledger;
use Tests\Support\FakeNwcWallet;

/*
| Security gate on 8a171405, F1: the league wallet holds every tournament pot
| and the reserve, so no payout may spend a pot's sats, not even two at once
| on two workers. Every payment from it runs under one lock (balance read,
| check, payment, booking), and the check keeps a fee allowance.
*/

/**
 * Two season payouts, and a tournament pot of 100 000 in the same wallet,
 * which holds the pot plus exactly one season payout and its fee allowance.
 *
 * @return array{0: FakeNwcWallet, 1: SeasonPayout, 2: SeasonPayout}
 */
function spendSetup(): array
{
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $season = settledSeason([[$alice, 1], [$bob, 2]]);
    app(SeasonSettlement::class)->approve($season, aBoardMember());
    $first = payoutOf($season, $alice);
    $second = payoutOf($season, $bob);

    fundPool($wallet, publishForPool(openTournament()), 100_000);
    $wallet->balanceMsats = (100_000 + $first->amount_sats + max(10, (int) ceil($first->amount_sats / 100))) * 1000;

    return [$wallet, $first, $second];
}

test('two season payouts at once never spend a tournament pot: the second waits for the first and is then refused', function () {
    [$wallet, $first, $second] = spendSetup();
    config(['esports.wallet.spend_lock_wait_seconds' => 0]);
    $runner = app(PayoutRunner::class);
    $fired = false;

    // A second worker starts the other payout while the first has read the balance and not yet paid.
    $wallet->onRequest = function () use ($wallet, $runner, $second, &$fired): void {
        $last = end($wallet->calls);

        if (! $fired && $last !== false && $last['method'] === 'get_balance') {
            $fired = true;
            $runner->run($second, true);
        }
    };

    $runner->run($first, true);
    $wallet->onRequest = null;

    expect($fired)->toBeTrue()
        ->and($first->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($second->refresh()->status)->toBe(PayoutStatus::Paying)->and($second->reason)->toBe('wallet_busy')
        ->and(intdiv($wallet->balanceMsats, 1000))->toBeGreaterThanOrEqual(app(Ledger::class)->heldForTournaments());

    // The schedule continues it once the lock is free: now it is not covered, and nothing is sent.
    $requests = count($wallet->payRequests());
    $runner->run($second->refresh(), false);

    expect($second->refresh()->status)->toBe(PayoutStatus::Failed)->and($second->reason)->toBe('insufficient_balance')
        ->and(count($wallet->payRequests()))->toBe($requests)
        ->and(intdiv($wallet->balanceMsats, 1000))->toBeGreaterThanOrEqual(app(Ledger::class)->heldForTournaments());
});

test('the check keeps a fee allowance: a payout whose fee could reach a pot’s sats is not sent', function () {
    [$wallet, $first] = spendSetup();
    // The wallet holds the pot and the payout, but not its fee allowance.
    $wallet->balanceMsats = (100_000 + $first->amount_sats) * 1000;

    app(PayoutRunner::class)->run($first, true);

    expect($first->refresh()->status)->toBe(PayoutStatus::Failed)->and($first->reason)->toBe('insufficient_balance')
        ->and($wallet->payRequests())->toBe([]);
});

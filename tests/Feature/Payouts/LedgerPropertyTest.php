<?php

use App\Models\LedgerTransfer;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\Ledger;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestSigner;

/*
| P9 DoD (wallet), as properties over random runs:
| - the league ledger: every booking moves a positive amount between two
|   accounts, all accounts sum to zero, and the book equals the league
|   wallet;
| - a tournament pot (booked in the league wallet since 2026-10-02): the
|   payouts never exceed the pot as set (the whole of it in percent mode, no
|   fee reserve; the fixed sum in fixed mode, user 2026-10-04), the wallet
|   loses exactly what was paid plus the routing fees, and the pot's account
|   loses exactly the same.
*/

test('every booking of the league reserve balances and the book equals the league wallet, over random runs', function () {
    mt_srand(2157);
    $wallet = fakeWallet();
    $start = $wallet->balanceMsats;

    foreach (range(1, 6) as $run) {
        $payment = $run % 2 === 0
            ? app(PoolInvoices::class)->forPlainPayment(mt_rand(1, 5_000), 'thanks')
            : app(PoolInvoices::class)->forZapRequest($zap = SignedEvent::fromInput((new TestSigner)->sign(9734, [['amount', (string) (($sats = mt_rand(1, 50_000)) * 1000)], ['p', (string) LeagueKey::poolPubkey()]], '', now()->getTimestamp())), $zap->toJson(), $sats);
        $wallet->settleIncoming($payment->payment_hash);
        app(IncomingPayments::class)->check($payment, 0);
    }

    foreach (LedgerTransfer::query()->get() as $booking) {
        expect($booking->sats)->toBeGreaterThan(0)->and($booking->from_account)->not->toBe($booking->to_account);
    }

    $accounts = LedgerTransfer::query()->pluck('from_account')->merge(LedgerTransfer::query()->pluck('to_account'))->unique();

    expect($accounts->sum(fn (string $account): int => app(Ledger::class)->balance($account)))->toBe(0)
        ->and(app(Ledger::class)->pots())->toBe(intdiv($wallet->balanceMsats - $start, 1000))
        ->and($accounts->filter(fn (string $account): bool => str_starts_with($account, 'tournament:')))->toBeEmpty();
});

test('a pot never pays more than the pot as set, in either mode, and the wallet and its account lose exactly the prizes and their fees', function () {
    mt_srand(2158);
    $wallet = fakeWallet();
    $ledger = app(Ledger::class);

    foreach (range(1, 4) as $run) {
        // A fresh HTTP fake per run: the first registered one would answer for every later pot.
        Http::swap(new HttpFactory);
        fakeLightningAddresses($wallet);
        $fixed = $run % 2 === 0 ? [mt_rand(1_000, 20_000), mt_rand(1_000, 10_000)] : null;
        $sats = $fixed === null ? mt_rand(1, 90_000) : array_sum($fixed) + PrizePool::feeReserve(array_sum($fixed)) + mt_rand(0, 5_000);
        $tournament = finishedPoolTournament($wallet, $sats, 4, split: [[50, 30, 20], [60, 40]][$run % 2], fixed: $fixed);
        $before = $wallet->balanceMsats;
        $requests = count($wallet->payRequests());

        app(PayoutApproval::class)->approve($tournament, anAdmin());

        foreach ($tournament->payouts()->get() as $payout) {
            app(PayoutRunner::class)->run($payout, true);
        }

        $paid = (int) $tournament->payouts()->where('status', 'paid')->sum('amount_sats');
        $payments = count($wallet->payRequests()) - $requests;
        $limit = $fixed === null ? $sats : array_sum($fixed);

        // Percent mode pays the whole pot but for rounding to whole sats (no fee reserve held back); fixed mode its sum.
        expect($paid)->toBeLessThanOrEqual($limit)
            ->and($limit - $paid)->toBeLessThan(10)
            ->and($before - $wallet->balanceMsats)->toBe($paid * 1000 + $payments * $wallet->feeMsats)
            ->and($ledger->balance($tournament->potAccount()))->toBe($sats - $paid - $payments * (int) ceil($wallet->feeMsats / 1000));
    }

    $accounts = LedgerTransfer::query()->pluck('from_account')->merge(LedgerTransfer::query()->pluck('to_account'))->unique();
    expect($accounts->sum(fn (string $account): int => $ledger->balance($account)))->toBe(0);
});

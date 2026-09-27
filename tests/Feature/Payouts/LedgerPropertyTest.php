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
| - the league ledger (the reserve, Season-Chain only): every booking moves
|   a positive amount between two accounts, all accounts sum to zero, and
|   the book equals the league wallet;
| - a tournament pot (its own wallet): the payouts never exceed what the pot
|   may pay, in either mode, and the wallet loses exactly what was paid plus
|   the routing fees, nothing booked in the league ledger.
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

test('a pot never pays more than it may, in either mode, and its wallet loses exactly the prizes and their fees', function () {
    mt_srand(2158);
    fakeWallet();

    foreach (range(1, 4) as $run) {
        $pot = ownPotWallet(0);
        // A fresh HTTP fake per run: the first registered one would answer for every later pot.
        Http::swap(new HttpFactory);
        fakeLightningAddresses($pot);
        $fixed = $run % 2 === 0 ? [mt_rand(1_000, 20_000), mt_rand(1_000, 10_000)] : null;
        $sats = $fixed === null ? mt_rand(1, 90_000) : array_sum($fixed) + PrizePool::feeReserve(array_sum($fixed)) + mt_rand(0, 5_000);
        $tournament = finishedPoolTournament($pot, $sats, 4, split: [[50, 30, 20], [60, 40]][$run % 2], fixed: $fixed);
        $before = $pot->balanceMsats;

        app(PayoutApproval::class)->approve($tournament, anAdmin());

        foreach ($tournament->payouts()->get() as $payout) {
            app(PayoutRunner::class)->run($payout, true);
        }

        $paid = (int) $tournament->payouts()->where('status', 'paid')->sum('amount_sats');
        $limit = $fixed === null ? PrizePool::afterFeeReserve($sats) : array_sum($fixed);

        expect($paid)->toBeLessThanOrEqual($limit)
            ->and($before - $pot->balanceMsats)->toBe($paid * 1000 + count($pot->payRequests()) * $pot->feeMsats)
            ->and(LedgerTransfer::query()->count())->toBe(0);
    }
});

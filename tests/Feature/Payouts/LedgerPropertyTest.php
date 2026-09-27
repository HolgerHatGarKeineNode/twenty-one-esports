<?php

use App\Models\LedgerTransfer;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Wallet\Ledger;

/*
| P9 DoD (wallet): bookings always balance. A property over random runs:
| random pools, splits, a late zap, the payouts with their fees, a plain
| LNURL payment; every booking moves a positive amount between two
| accounts, all accounts sum to zero, and the book equals the wallet.
*/

test('every booking balances and the book equals the wallet, over random runs', function () {
    mt_srand(2157);
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);

    foreach (range(1, 3) as $run) {
        $start = $wallet->balanceMsats;
        $tournament = finishedPoolTournament($wallet, mt_rand(1, 90_000), 4, split: [[50, 30, 20], [60, 40], [40, 25, 20, 15]][$run - 1]);

        // One more zap before the check (the first run through the real path), one after it (late: the reserve).
        fundPool($wallet, $tournament, mt_rand(1, 50_000), zap: $run === 1);

        $late = app(PoolInvoices::class)->anonymousZap($tournament, mt_rand(1, 9_999), '');
        app(PayoutApproval::class)->approve($tournament, anAdmin());
        $wallet->settleIncoming($late->payment_hash);
        app(IncomingPayments::class)->check($late, 0);

        foreach ($tournament->payouts()->get() as $payout) {
            app(PayoutRunner::class)->run($payout, true);
        }

        // A plain LNURL payment for the reserve.
        $plain = app(PoolInvoices::class)->forPlainPayment(mt_rand(1, 5_000), 'thanks');
        $wallet->settleIncoming($plain->payment_hash);
        app(IncomingPayments::class)->check($plain, 0);

        foreach (LedgerTransfer::query()->get() as $booking) {
            expect($booking->sats)->toBeGreaterThan(0)->and($booking->from_account)->not->toBe($booking->to_account);
        }

        $accounts = LedgerTransfer::query()->pluck('from_account')->merge(LedgerTransfer::query()->pluck('to_account'))->unique();
        $sum = $accounts->sum(fn (string $account): int => app(Ledger::class)->balance($account));

        expect($sum)->toBe(0)
            ->and(app(Ledger::class)->pots())->toBe(intdiv($wallet->balanceMsats - $start, 1000))
            ->and(app(Ledger::class)->balance('tournament:'.$tournament->id))->toBe(0);

        LedgerTransfer::query()->delete();
    }
});

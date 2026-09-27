<?php

use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\WalletReconciliation;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\PreSeason;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PrizePool;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\WalletTransaction;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
| P9 DoD (wallet): bookings always balance; every payment lands in its pot
| by payment hash; the daily reconciliation reports a deviation; the paying
| connection is used by the payouts only.
*/

/**
 * A zap request to the pool key, signed by `$signer`, with these extra tags.
 *
 * @param  list<list<string>>  $tags
 */
function zapRequest(TestSigner $signer, int $msats, array $tags): SignedEvent
{
    return SignedEvent::fromInput($signer->sign(9734, [['relays', 'wss://relay.example.org'], ['amount', (string) $msats], ['p', (string) PrizePool::poolPubkey()], ...$tags], '', now()->getTimestamp()));
}

test('each payment lands in the pot its zap request names, and a request that names none of ours is refused', function () {
    $wallet = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $closed = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $closed->forceFill(['pool_closed_at' => now()])->save();
    $signer = new TestSigner;
    $invoices = app(PoolInvoices::class);

    // Tournament pot, by `a`.
    $zap = zapRequest($signer, 21_000_000, [['a', (string) $tournament->address()], ['k', '31923']]);
    $payment = $invoices->forZapRequest($zap, $zap->toJson(), 21_000);
    expect($payment->pot)->toBe('tournament:'.$tournament->id);

    // The reserve: no `a`, no `e`.
    $toReserve = zapRequest($signer, 1_000_000, []);
    expect($invoices->forZapRequest($toReserve, $toReserve->toJson(), 1_000)->pot)->toBe(IncomingPayment::RESERVE);

    // Refused: a pot we do not run yet (`e`), two pots, a closed pool, another recipient, another amount.
    $refused = [
        zapRequest($signer, 1_000_000, [['e', str_repeat('ab', 32)]]),
        zapRequest($signer, 1_000_000, [['a', (string) $tournament->address()], ['a', (string) $closed->address()]]),
        zapRequest($signer, 1_000_000, [['a', (string) $closed->address()]]),
        SignedEvent::fromInput($signer->sign(9734, [['p', (new TestSigner)->pubkey], ['amount', '1000000']], '', now()->getTimestamp())),
        zapRequest($signer, 2_000_000, [['a', (string) $tournament->address()]]),
    ];

    foreach ($refused as $request) {
        expect(fn () => $invoices->forZapRequest($request, $request->toJson(), 1_000))->toThrow(PoolRefusal::class);
    }

    // Settled: booked by payment hash into its pot, once, however often it is checked.
    $wallet->settleIncoming($payment->payment_hash);
    app(IncomingPayments::class)->check($payment, 0);
    app(IncomingPayments::class)->settle($payment->refresh(), WalletTransaction::fromResult(['state' => 'settled']));

    expect(IncomingPayment::query()->count())->toBe(2)
        ->and(LedgerTransfer::query()->where('reason', 'contribution')->count())->toBe(1)
        ->and(app(Ledger::class)->balance('tournament:'.$tournament->id))->toBe(21_000)
        ->and(app(PrizePool::class)->fundedSats($tournament))->toBe(21_000);
});

test('the daily reconciliation agrees with the wallet and reports a deviation', function () {
    $wallet = fakeWallet();
    $wallet->balanceMsats = 0;
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    fundPool($wallet, $tournament, 12_345, zap: true);

    $this->artisan('wallet:reconcile')->assertSuccessful();
    expect(WalletReconciliation::query()->latest('id')->first())->deviation_sats->toBe(0)->ledger_sats->toBe(12_345);

    // Sats that no booking explains: a deposit outside the endpoint.
    $wallet->balanceMsats += 5_000_000;
    $this->artisan('wallet:reconcile')->assertFailed();
    $run = WalletReconciliation::query()->latest('id')->first();
    expect($run->deviation_sats)->toBe(5_000)->and($run->agrees())->toBeFalse();

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts')->assertSee(PreSeason::formatSats(17_345));

    // A wallet that does not answer is a failed run, not a clean one.
    $wallet->ignoreNextRequest = true;
    $this->artisan('wallet:reconcile')->assertFailed();
    expect(WalletReconciliation::query()->latest('id')->first())->error->toBe('TIMEOUT');
});

test('the paying connection is used by the payouts only', function () {
    // In the code: only the payout runner names the paying wallet (besides the class itself) …
    $allowed = ['app/Support/Payouts/PayoutRunner.php', 'app/Support/Wallet/PayingWallet.php'];
    $naming = [];

    foreach ([app_path(), resource_path('views'), base_path('routes'), base_path('config')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            if (str_contains($file->getContents(), 'PayingWallet')) {
                $naming[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($naming);
    expect($naming)->toBe($allowed);

    // … and at run time zaps, checks and the reconciliation never touch it.
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 30_000, 2);
    fundPool($wallet, $tournament, 1_000, zap: true);
    $this->artisan('wallet:sync')->assertSuccessful();
    $this->artisan('wallet:reconcile');
    app(PayoutApproval::class)->approve($tournament, anAdmin());

    expect(collect($wallet->calls)->where('client', 'pay')->all())->toBe([]);

    foreach ($tournament->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect(collect($wallet->calls)->where('client', 'pay')->pluck('method')->unique()->values()->all())->toBe(['pay_invoice'])
        ->and(collect($wallet->calls)->where('client', 'receive')->pluck('method')->unique()->sort()->values()->all())->toBe(['get_balance', 'lookup_invoice', 'make_invoice']);
});

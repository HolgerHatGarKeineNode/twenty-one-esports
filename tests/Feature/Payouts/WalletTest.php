<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\WalletReconciliation;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\WalletTransaction;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

/*
| P9 DoD (wallet). The league wallet and its ledger are the Season-Chain's
| only (user, 2026-09-27: "Jedes Turnier bekommt seine eigene NWC und
| eigenen Pot. niemals einen fremden oder von der Season"): its bookings
| balance, a zap lands in the reserve by payment hash, the daily
| reconciliation reports a deviation, the paying connection is named by the
| payout runner only, and no tournament flow ever reaches the league wallet
| or the ledger.
*/

/**
 * A zap request to the pool key, signed by `$signer`, with these extra tags.
 *
 * @param  list<list<string>>  $tags
 */
function zapRequest(TestSigner $signer, int $msats, array $tags): SignedEvent
{
    return SignedEvent::fromInput($signer->sign(9734, [['relays', 'wss://relay.example.org'], ['amount', (string) $msats], ['p', (string) LeagueKey::poolPubkey()], ...$tags], '', now()->getTimestamp()));
}

test('a zap lands in the league reserve once, and a request for any other pot is refused', function () {
    $wallet = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $invoices = app(PoolInvoices::class);

    $zap = zapRequest($signer, 21_000_000, []);
    $payment = $invoices->forZapRequest($zap, $zap->toJson(), 21_000);
    expect($payment->pot)->toBe(IncomingPayment::RESERVE);

    // Refused: a tournament (its pot is its own wallet), a pot we do not run yet (`e`), another recipient, another amount.
    $refused = [
        zapRequest($signer, 1_000_000, [['a', (string) $tournament->address()], ['k', '31923']]),
        zapRequest($signer, 1_000_000, [['e', str_repeat('ab', 32)]]),
        SignedEvent::fromInput($signer->sign(9734, [['p', (new TestSigner)->pubkey], ['amount', '1000000']], '', now()->getTimestamp())),
        zapRequest($signer, 2_000_000, []),
    ];

    foreach ($refused as $request) {
        expect(fn () => $invoices->forZapRequest($request, $request->toJson(), 1_000))->toThrow(PoolRefusal::class);
    }

    // Settled: booked by payment hash into the reserve, once, however often it is checked.
    $wallet->settleIncoming($payment->payment_hash);
    app(IncomingPayments::class)->check($payment, 0);
    app(IncomingPayments::class)->settle($payment->refresh(), WalletTransaction::fromResult(['state' => 'settled']));

    expect(IncomingPayment::query()->count())->toBe(1)
        ->and(LedgerTransfer::query()->where('reason', 'contribution')->count())->toBe(1)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(21_000)
        ->and(fn () => app(Ledger::class)->contribution($payment, IncomingPayment::tournamentPot($tournament->id)))->toThrow(InvalidArgumentException::class);
});

test('the daily reconciliation of the league wallet agrees with the reserve and reports a deviation', function () {
    $wallet = fakeWallet();
    $wallet->balanceMsats = 0;
    $payment = app(PoolInvoices::class)->forPlainPayment(12_345, 'for the season');
    $wallet->settleIncoming($payment->payment_hash);
    app(IncomingPayments::class)->check($payment, 0);

    $this->artisan('wallet:reconcile')->assertSuccessful();
    expect(WalletReconciliation::query()->latest('id')->first())->deviation_sats->toBe(0)->ledger_sats->toBe(12_345);

    // Sats that no booking explains: a deposit outside the endpoint.
    $wallet->balanceMsats += 5_000_000;
    $this->artisan('wallet:reconcile')->assertFailed();
    $run = WalletReconciliation::query()->latest('id')->first();
    expect($run->deviation_sats)->toBe(5_000)->and($run->agrees())->toBeFalse();

    // A wallet that does not answer is a failed run, not a clean one.
    $wallet->ignoreNextRequest = true;
    $this->artisan('wallet:reconcile')->assertFailed();
    expect(WalletReconciliation::query()->latest('id')->first())->error->toBe('TIMEOUT');
});

test('the paying connection is named by the payout runner only', function () {
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
});

test('no tournament flow touches the league wallet, its ledger or its LNURL key', function () {
    // The league wallet is set up and answers; tournaments must still never call it.
    $league = fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = openTournament();
    $organizer = $tournament->creator;

    // Pot settings with a live check, a top-up and a sponsor invoice, both settled, the schedule, a balance read.
    app(PrizePool::class)->configurePot($tournament, $organizer, true, $pot->uri('pay'), null, Tournament::PRIZES_PERCENT, [60, 40]);
    $tournament->refresh();
    $topUp = app(PotTopUps::class)->invoice($tournament, 30_000);
    $sponsor = app(PrizePool::class)->addSponsor($tournament, $organizer, 'Satoshi’s Pizza', 10_000, null);
    $sponsorInvoice = app(PotTopUps::class)->sponsorInvoice($sponsor, $organizer);
    $pot->settleIncoming($topUp->payment_hash);
    $pot->settleIncoming($sponsorInvoice->payment_hash);
    $this->travel(1)->minutes();
    $this->artisan('wallet:sync')->assertSuccessful();
    $this->artisan('wallet:read-pots')->assertSuccessful();

    expect($topUp->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($sponsor->refresh()->isPaid())->toBeTrue()
        ->and($tournament->refresh()->pot_balance_sats)->toBe(40_000);

    // A tournament with a pot in the same wallet plays out; the admin approves and pays; the schedule continues what is open.
    $finished = finishedPoolTournament($pot, 0, 4);
    app(PayoutApproval::class)->approve($finished, anAdmin());

    foreach ($finished->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    $this->artisan('wallet:sync')->assertSuccessful();

    expect($finished->payouts()->where('status', 'paid')->count())->toBe(4)
        ->and($pot->payRequests())->toHaveCount(4)
        ->and($league->calls)->toBe([])
        ->and(LedgerTransfer::query()->count())->toBe(0)
        ->and(IncomingPayment::query()->where('pot', IncomingPayment::RESERVE)->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', 9735)->count())->toBe(0);
});

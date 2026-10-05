<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\Tournament;
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
| P9 DoD (wallet). The league wallet and its ledger hold the reserve and,
| since 2026-10-02, every tournament pot, each in its own account (user:
| „das landet eh alles in eine Wallet von wo aus ausgezahlt werden kann"):
| its bookings balance, a zap lands in its pot by payment hash, the paying
| connection is named by the payout runner only, and a tournament's flows book into its own account,
| never the reserve.
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

test('a zap lands in the league reserve once, a tournament’s in its pot, and a request for any other pot is refused', function () {
    $wallet = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $invoices = app(PoolInvoices::class);

    $zap = zapRequest($signer, 21_000_000, []);
    $payment = $invoices->forZapRequest($zap, $zap->toJson(), 21_000);
    expect($payment->pot)->toBe(IncomingPayment::RESERVE);

    // A tournament's calendar event by `a`: its pot (user, 2026-10-02).
    $toTournament = zapRequest($signer, 1_000_000, [['a', (string) $tournament->address()], ['k', '31923']]);
    expect($invoices->forZapRequest($toTournament, $toTournament->toJson(), 1_000)->pot)->toBe($tournament->potAccount());

    // Refused: a pot we do not run yet (`e`), another recipient, another amount.
    $refused = [
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

    expect(IncomingPayment::query()->count())->toBe(2)
        ->and(LedgerTransfer::query()->where('reason', 'contribution')->count())->toBe(1)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(21_000)
        ->and(fn () => app(Ledger::class)->contribution($payment, 'tournament:x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(Ledger::class)->contribution($payment, 'outside'))->toThrow(InvalidArgumentException::class);
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

test('every tournament flow runs on the league wallet and books only into the tournament’s own account', function () {
    $league = fakeWallet();
    fakeLightningAddresses($league);
    $tournament = openTournament();
    $organizer = $tournament->creator;
    $ledger = app(Ledger::class);

    // Pot settings, a top-up and a sponsor invoice, both settled by the schedule.
    app(PrizePool::class)->configurePot($tournament, $organizer, true, null, Tournament::PRIZES_PERCENT, [60, 40]);
    $tournament->refresh();
    $topUp = app(PotTopUps::class)->invoice($tournament, 30_000);
    $sponsor = app(PrizePool::class)->addSponsor($tournament, $organizer, 'Satoshi’s Pizza', 10_000, null);
    $sponsorInvoice = app(PotTopUps::class)->sponsorInvoice($sponsor, $organizer);
    $league->settleIncoming($topUp->payment_hash);
    $league->settleIncoming($sponsorInvoice->payment_hash);
    $this->travel(1)->minutes();
    $this->artisan('wallet:sync')->assertSuccessful();

    expect($topUp->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($sponsor->refresh()->isPaid())->toBeTrue()
        ->and(app(PrizePool::class)->fundedSats($tournament))->toBe(40_000)
        ->and($ledger->balance(Ledger::RESERVE))->toBe(0);

    // A tournament with a pot plays out; the admin approves and pays from the league wallet; the schedule continues what is open.
    $finished = finishedPoolTournament($league, 20_000, 4);
    app(PayoutApproval::class)->approve($finished, anAdmin());

    foreach ($finished->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    $this->artisan('wallet:sync')->assertSuccessful();
    $paid = (int) $finished->payouts()->where('status', 'paid')->sum('amount_sats');

    expect($finished->payouts()->where('status', 'paid')->count())->toBe(4)
        ->and($ledger->balance($finished->potAccount()))->toBe(20_000 - $paid - 4)
        ->and($ledger->balance($tournament->potAccount()))->toBe(40_000)
        ->and($ledger->balance(Ledger::RESERVE))->toBe(0)
        ->and(IncomingPayment::query()->where('pot', IncomingPayment::RESERVE)->count())->toBe(0)
        // Plain top-ups and sponsor invoices carry no zap request, so no receipt.
        ->and(NostrEvent::query()->where('kind', 9735)->count())->toBe(0);
});

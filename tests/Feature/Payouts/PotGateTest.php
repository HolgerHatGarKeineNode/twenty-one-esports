<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Support\Lightning\Bolt11;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| Security gate on 55ef30e and its re-gate, on the league wallet since
| 2026-10-02 (every pot is booked in it, each in its own ledger account).
| F-B: sponsor invoices have caps of their own, an invoice counts as open no
| longer than the league asked and is expired locally once its expiry and a
| grace passed, and wallet:sync pays first and asks a wallet that timed out
| only once per run. O1: the create page asks no wallet at all. Notes: the
| pot cannot be switched off or on after sign-up closed, and a cancelled
| tournament closes its pot.
*/

test('every pot is booked in the league wallet: each tournament counts only what was paid for it', function () {
    $league = fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $first = publishForPool(openTournament());
    $second = publishForPool(openTournament());
    $invoicesBefore = count($league->invoices);

    fundPool($league, $first, 30_000, topUp: true);
    fundPool($league, $second, 5_000, topUp: true);
    $reserve = app(PoolInvoices::class)->forPlainPayment(2_100, 'thanks');
    $league->settleIncoming($reserve->payment_hash);
    app(IncomingPayments::class)->check($reserve, 0);

    $pool = app(PrizePool::class);
    $ledger = app(Ledger::class);

    // All three invoices were made by the one league wallet; each is booked to its own pot.
    expect(count($league->invoices) - $invoicesBefore)->toBe(3)
        ->and(IncomingPayment::query()->where('tournament_id', $first->id)->sole()->pot)->toBe($first->potAccount())
        ->and($pool->fundedSats($first))->toBe(30_000)
        ->and($pool->heldSats($first))->toBe(30_000)
        ->and($pool->fundedSats($second))->toBe(5_000)
        ->and($ledger->balance(Ledger::RESERVE))->toBe(2_100)
        ->and($ledger->heldForTournaments())->toBe(35_000)
        ->and($ledger->heldForTournaments($first->potAccount()))->toBe(5_000)
        ->and($ledger->pots())->toBe(37_100);
});

test('F-B: sponsor invoices have caps of their own and never use up the organizer’s top-ups', function () {
    fakeWallet();
    $tournament = publishForPool(openTournament(), ownPotWallet(0));
    $organizer = $tournament->creator;
    $this->actingAs($organizer);
    config(['esports.wallet.open_invoices_per_user' => 2]);
    $sponsor = fn (int $i): TournamentSponsor => TournamentSponsor::query()->create(['tournament_id' => $tournament->id, 'name' => 'Sponsor '.$i, 'pledged_sats' => 1_000, 'created_by_id' => $organizer->id]);

    foreach (range(1, 3) as $i) {
        app(PotTopUps::class)->sponsorInvoice($sponsor($i), $organizer);
    }

    expect(fn () => app(PotTopUps::class)->sponsorInvoice($sponsor(4), $organizer))->toThrow(PoolRefusal::class, 'unpaid sponsor invoices');

    // Three open sponsor invoices, and the organizer's own two top-ups are still there.
    app(PotTopUps::class)->invoice($tournament, 2_100);
    app(PotTopUps::class)->invoice($tournament, 2_100);
    expect(fn () => app(PotTopUps::class)->invoice($tournament, 2_100))->toThrow(PoolRefusal::class, 'too many unpaid invoices');

    // Per organizer and hour, with the open cap out of the way: 3 made above, 7 more, then no.
    config(['esports.wallet.sponsor_invoices_open_per_tournament' => 100]);

    foreach (range(5, 11) as $i) {
        app(PotTopUps::class)->sponsorInvoice($sponsor($i), $organizer);
    }

    expect(fn () => app(PotTopUps::class)->sponsorInvoice($sponsor(12), $organizer))->toThrow(PoolRefusal::class, 'this hour')
        ->and(IncomingPayment::query()->where('source', 'sponsor')->count())->toBe(10);
});

test('F-B: an invoice counts as open no longer than the league asked, whatever expiry the wallet wrote', function () {
    $this->freezeTime();
    $league = fakeWallet();
    $pot = ownPotWallet(0);
    $pot->invoiceExpiry = $league->invoiceExpiry = 30 * 86_400;
    $tournament = publishForPool(openTournament(), $pot);
    $limit = now()->getTimestamp() + (int) config('esports.wallet.invoice_expiry_seconds');

    foreach ([app(PotTopUps::class)->invoice($tournament, 2_100), app(PoolInvoices::class)->forPlainPayment(2_100, 'thanks')] as $payment) {
        expect(Bolt11::decode($payment->bolt11)?->expiresAt())->toBeGreaterThan(now()->getTimestamp() + 86_400)
            ->and($payment->expires_at->getTimestamp())->toBeLessThanOrEqual($limit);
    }
});

test('F-B: wallet:sync continues payouts first, asks a wallet that timed out once per run, and still reaches the others', function () {
    $league = fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $transport = app(FakeNwcTransport::class);

    // A payout whose answer got lost: it stays paying until wallet:sync looks its invoice up.
    fakeLightningAddresses($league);
    $finished = finishedPoolTournament($league, 10_000, 2);
    app(PayoutApproval::class)->approve($finished, anAdmin());
    $paying = $finished->payouts()->where('place', 1)->sole();
    $league->loseNextAnswer = true;
    app(PayoutRunner::class)->run($paying, true);
    $paying->refresh()->forceFill(['lease_until' => now()->subMinute(), 'last_attempt_at' => now()->subMinutes(2)])->save();
    expect($paying->status)->toBe(PayoutStatus::Paying);

    $pot = publishForPool(openTournament());
    $settled = app(PotTopUps::class)->invoice($pot, 2_100);
    $league->settleIncoming($settled->payment_hash);
    $transport->requestsTo = [];

    Artisan::call('wallet:sync');

    // The payout first, then the invoice.
    expect($transport->requestsTo[0])->toBe($league->pubkey)
        ->and($paying->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($settled->refresh()->status)->toBe(IncomingPaymentStatus::Settled);

    // A wallet that times out is asked once per run, however many invoices wait.
    foreach (range(1, 3) as $i) {
        app(PotTopUps::class)->invoice($pot, 2_100);
    }

    app(PoolInvoices::class)->forPlainPayment(2_100, 'one');
    $transport->offline[$league->pubkey] = true;
    $transport->requestsTo = [];

    Artisan::call('wallet:sync');

    expect(array_count_values(array_filter($transport->requestsTo))[$league->pubkey])->toBe(1);
});

test('F-B: an invoice whose wallet says pending forever or never answers is expired locally once its expiry and the grace passed', function () {
    $league = fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $transport = app(FakeNwcTransport::class);
    $stuck = app(PotTopUps::class)->invoice(publishForPool(openTournament()), 2_100);
    $reserve = app(PoolInvoices::class)->forPlainPayment(2_100, 'thanks');

    // Past its expiry, inside the grace: still asked, still open (a late settle can land).
    $this->travelTo($stuck->expires_at->copy()->addSeconds(IncomingPayments::EXPIRY_GRACE_SECONDS - 60));
    Artisan::call('wallet:sync');
    expect($stuck->refresh()->status)->toBe(IncomingPaymentStatus::Pending)
        ->and($reserve->refresh()->status)->toBe(IncomingPaymentStatus::Pending);

    // Past the grace, the wallet silent: expired here, without asking it about them.
    $this->travelTo($stuck->expires_at->copy()->addSeconds(IncomingPayments::EXPIRY_GRACE_SECONDS + 60));
    $transport->offline[$league->pubkey] = true;
    $transport->requestsTo = [];
    Artisan::call('wallet:sync');
    expect($stuck->refresh()->status)->toBe(IncomingPaymentStatus::Expired)
        ->and($reserve->refresh()->status)->toBe(IncomingPaymentStatus::Expired)
        ->and(array_filter($transport->requestsTo))->toBe([]);
});

test('after sign-up closed the pot can be neither switched off nor switched on', function () {
    fakeWallet();
    $tournament = openTournament();
    $configure = fn (Tournament $t, bool $on, array $split) => app(PrizePool::class)->configurePot($t->refresh(), $t->creator, $on, null, Tournament::PRIZES_PERCENT, $split);
    $configure($tournament, true, [70, 30]);
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();

    // The bypass: off, then on again with the default split.
    expect(fn () => $configure($tournament, false, []))->toThrow(TournamentRuleViolation::class, 'switched on or off')
        ->and($tournament->refresh()->prizeSplit())->toBe([70, 30])
        ->and($tournament->hasLeaguePot())->toBeTrue();

    $none = openTournament();
    $none->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    expect(fn () => $configure($none, true, [50, 30, 20]))->toThrow(TournamentRuleViolation::class, 'switched on or off')
        ->and($none->refresh()->hasPot())->toBeFalse();
});

test('a cancelled tournament closes its pot: no top-up and no sponsor invoice any more', function () {
    fakeWallet();
    $tournament = publishForPool(openTournament(), ownPotWallet(0));
    $sponsor = TournamentSponsor::query()->create(['tournament_id' => $tournament->id, 'name' => 'Pizza', 'pledged_sats' => 1_000, 'created_by_id' => $tournament->created_by_id]);

    app(TournamentControl::class)->abort($tournament, anAdmin(), 'The venue is flooded.');

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Cancelled)
        ->and($tournament->pool_closed_at)->not->toBeNull()
        ->and(fn () => app(PotTopUps::class)->invoice($tournament, 2_100))->toThrow(PoolRefusal::class)
        ->and(fn () => app(PotTopUps::class)->sponsorInvoice($sponsor->refresh(), $tournament->creator))->toThrow(PoolRefusal::class)
        ->and(IncomingPayment::query()->count())->toBe(0);

    // The draw's automatic cancel saves the status the same way.
    $drawn = publishForPool(openTournament(), ownPotWallet(0));
    $drawn->forceFill(['status' => TournamentStatus::Cancelled])->save();
    expect($drawn->refresh()->pool_closed_at)->not->toBeNull();

    // And a cancelled row that somehow kept an open pot still takes nothing.
    DB::table('tournaments')->where('id', $drawn->id)->update(['pool_closed_at' => null]);
    expect(PotTopUps::enabled($drawn->refresh()))->toBeFalse();
});

test('O1: the create page asks no wallet: a draft with a pot is created without a single wallet call', function () {
    fakeWallet();
    $transport = app(FakeNwcTransport::class);
    $calls = $transport->calls;

    Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('name', 'Lock Cup')
        ->set('potEnabled', true)->set('potTarget', '50000')
        ->call('create')->assertSet('potError', '')->assertHasNoErrors();

    $tournament = Tournament::query()->where('name', 'Lock Cup')->sole();
    expect($tournament->hasLeaguePot())->toBeTrue()
        ->and($tournament->prize_target_sats)->toBe(50_000)
        ->and($tournament->pot_nwc_uri)->toBeNull()
        ->and($transport->calls)->toBe($calls);
});

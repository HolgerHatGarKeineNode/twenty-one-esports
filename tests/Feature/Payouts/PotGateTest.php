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
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| Security gate on 55ef30e and its re-gate. One wallet may back several pots
| (user, 2026-09-27: the organizer caps each connection's budget or uses a
| sub-wallet; the balance is a visual check only). F-B: sponsor invoices
| have caps of their own, an invoice counts as open no longer than the
| league asked and is expired locally once its expiry and a grace passed,
| and wallet:sync pays first and asks a wallet that timed out only once per
| run. O1: the create page checks the wallet before its transaction. Notes:
| the pot cannot be switched off or on after sign-up closed, and a
| cancelled tournament closes its pot.
*/

test('one wallet may back the pots of several tournaments: nothing is refused', function () {
    fakeWallet();
    $shared = ownPotWallet(100_000);
    $first = openTournament();
    $second = openTournament();
    app(PrizePool::class)->configurePot($first, $first->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]);
    app(PrizePool::class)->configurePot($second, $second->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]);

    expect($first->refresh()->hasOwnWallet())->toBeTrue()->and($second->refresh()->hasOwnWallet())->toBeTrue();

    // The form's live check says the balance, with the hint on budgets and sub-wallets.
    Livewire::actingAs($second->creator)->test('pages::admin.tournament-edit', ['tournament' => $second])
        ->set('potEnabled', true)->call('replacePotWallet')->set('potUri', $shared->uri('pay'))->call('checkPotConnection')
        ->assertSet('potError', '')->assertSet('potCheckedSats', 100_000)
        ->assertSeeHtml('data-test="pot-own-wallet-hint"')
        ->assertSee(__('Set this connection’s budget in the wallet to the pot size, or use an Alby Hub sub-wallet with its own balance.'));
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
    $payWallet = ownPotWallet(0);
    fakeLightningAddresses($payWallet);
    $finished = finishedPoolTournament($payWallet, 10_000, 2);
    app(PayoutApproval::class)->approve($finished, anAdmin());
    $paying = $finished->payouts()->where('place', 1)->sole();
    $payWallet->loseNextAnswer = true;
    app(PayoutRunner::class)->run($paying, true);
    $paying->refresh()->forceFill(['lease_until' => now()->subMinute(), 'last_attempt_at' => now()->subMinutes(2)])->save();
    expect($paying->status)->toBe(PayoutStatus::Paying);

    $slow = ownPotWallet(0);
    $fast = ownPotWallet(0);
    $slowPot = publishForPool(openTournament(), $slow);
    $fastPot = publishForPool(openTournament(), $fast);

    foreach (range(1, 3) as $i) {
        app(PotTopUps::class)->invoice($slowPot, 2_100);
    }

    $late = app(PotTopUps::class)->invoice($fastPot, 2_100);
    $fast->settleIncoming($late->payment_hash);
    app(PoolInvoices::class)->forPlainPayment(2_100, 'one');
    app(PoolInvoices::class)->forPlainPayment(2_100, 'two');
    $transport->offline[$slow->pubkey] = $transport->offline[$league->pubkey] = true;
    $transport->requestsTo = [];

    Artisan::call('wallet:sync');

    expect($transport->requestsTo[0])->toBe($payWallet->pubkey)
        ->and($paying->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and(array_count_values(array_filter($transport->requestsTo))[$slow->pubkey])->toBe(1)
        ->and(array_count_values(array_filter($transport->requestsTo))[$league->pubkey])->toBe(1)
        ->and($late->refresh()->status)->toBe(IncomingPaymentStatus::Settled);
});

test('F-B: an invoice whose wallet says pending forever or never answers is expired locally once its expiry and the grace passed', function () {
    fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $transport = app(FakeNwcTransport::class);
    $pending = ownPotWallet(0);
    $silent = ownPotWallet(0);
    $stuck = app(PotTopUps::class)->invoice(publishForPool(openTournament(), $pending), 2_100);
    $lost = app(PotTopUps::class)->invoice(publishForPool(openTournament(), $silent), 2_100);
    $transport->offline[$silent->pubkey] = true;

    // Past its expiry, inside the grace: still asked, still open (a late settle can land).
    $this->travelTo($stuck->expires_at->copy()->addSeconds(PotTopUps::EXPIRY_GRACE_SECONDS - 60));
    Artisan::call('wallet:sync');
    expect($stuck->refresh()->status)->toBe(IncomingPaymentStatus::Pending)
        ->and($lost->refresh()->status)->toBe(IncomingPaymentStatus::Pending);

    // Past the grace: expired here, and the wallets are not asked about them any more.
    $this->travelTo($stuck->expires_at->copy()->addSeconds(PotTopUps::EXPIRY_GRACE_SECONDS + 60));
    $transport->requestsTo = [];
    Artisan::call('wallet:sync');
    expect($stuck->refresh()->status)->toBe(IncomingPaymentStatus::Expired)
        ->and($lost->refresh()->status)->toBe(IncomingPaymentStatus::Expired)
        ->and(array_intersect(array_filter($transport->requestsTo), [$pending->pubkey, $silent->pubkey]))->toBe([]);
});

test('after sign-up closed the pot can be neither switched off nor switched on, but its wallet can be replaced', function () {
    fakeWallet();
    $pot = ownPotWallet(10_000);
    $tournament = openTournament();
    $configure = fn (Tournament $t, bool $on, ?string $uri, array $split) => app(PrizePool::class)->configurePot($t->refresh(), $t->creator, $on, $uri, null, Tournament::PRIZES_PERCENT, $split);
    $configure($tournament, true, $pot->uri('pay'), [70, 30]);
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();

    // The bypass: off, then on again with the default split.
    expect(fn () => $configure($tournament, false, null, []))->toThrow(TournamentRuleViolation::class, 'switched on or off')
        ->and($tournament->refresh()->prizeSplit())->toBe([70, 30]);

    $none = openTournament();
    $none->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    expect(fn () => $configure($none, true, ownPotWallet(0)->uri('pay'), [50, 30, 20]))->toThrow(TournamentRuleViolation::class, 'switched on or off')
        ->and($none->refresh()->hasOwnWallet())->toBeFalse();

    $replacement = ownPotWallet(5_000);
    $configure($tournament, true, $replacement->uri('pay'), [70, 30]);
    expect($tournament->refresh()->pot_nwc_uri)->toBe($replacement->uri('pay'))
        ->and($tournament->prizeSplit())->toBe([70, 30]);
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

test('O1: the create page asks the wallet before its transaction, never while holding the write lock', function () {
    fakeWallet();
    $transport = app(FakeNwcTransport::class);
    $own = ownPotWallet(50_000);
    $base = DB::transactionLevel();
    $transport->transactionLevels = [];

    Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('name', 'Lock Cup')
        ->set('potEnabled', true)->set('potUri', $own->uri('pay'))
        ->call('create')->assertSet('potError', '')->assertHasNoErrors();

    $tournament = Tournament::query()->where('name', 'Lock Cup')->sole();
    expect($tournament->hasOwnWallet())->toBeTrue()->and($tournament->pot_balance_sats)->toBe(50_000)
        ->and($transport->transactionLevels)->not->toBeEmpty()
        ->and(array_values(array_unique($transport->transactionLevels)))->toBe([$base]);
});

test('O1: a wallet refused before the transaction creates nothing', function () {
    fakeWallet();
    $own = ownPotWallet(50_000);

    Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('name', 'Refused Cup')
        ->set('potEnabled', true)->set('potUri', $own->uri('receive'))
        ->call('create')->assertNotSet('potError', '');

    expect(Tournament::query()->where('name', 'Refused Cup')->exists())->toBeFalse();
});

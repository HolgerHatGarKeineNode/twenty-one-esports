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
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| Security gate on 55ef30e. F-A: one wallet backs one open pot (a pot is its
| wallet's whole balance). F-B: sponsor invoices have caps of their own, an
| invoice counts as open no longer than the league asked, and wallet:sync
| pays first and asks a wallet that timed out only once per run. Notes: the
| pot cannot be switched off or on after sign-up closed, and a cancelled
| tournament closes its pot.
*/

const SHARED_WALLET = 'This wallet already holds the pot of another tournament. Use a separate wallet or sub-wallet for each tournament. Its whole balance is the pot.';

test('F-A: a wallet that backs one open pot cannot back a second one, and only its keyed fingerprint is stored', function () {
    fakeWallet();
    $shared = ownPotWallet(100_000);
    $first = openTournament();
    $second = openTournament();
    app(PrizePool::class)->configurePot($first, $first->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]);

    expect(fn () => app(PrizePool::class)->configurePot($second, $second->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]))
        ->toThrow(TournamentRuleViolation::class, __(SHARED_WALLET))
        ->and($second->refresh()->hasOwnWallet())->toBeFalse();

    // The form says so at the live check already, next to the rule it states.
    Livewire::actingAs($second->creator)->test('pages::admin.tournament-edit', ['tournament' => $second])
        ->set('potEnabled', true)->set('potUri', $shared->uri('pay'))->call('checkPotConnection')
        ->assertSet('potError', __(SHARED_WALLET))
        ->assertSeeHtml('data-test="pot-own-wallet-hint"');

    // HMAC under app.key, never the pubkey or the connection string in clear.
    $row = (array) DB::table('tournaments')->where('id', $first->id)->first();
    expect($row['pot_wallet_hash'])->toBe(hash_hmac('sha256', 'pot-wallet:'.$shared->pubkey, (string) config('app.key')));

    foreach ($row as $value) {
        expect((string) $value)->not->toContain($shared->pubkey)->not->toContain($shared->clients['pay']['secret']);
    }
});

test('F-A: the wallet is bound until its pot closed and every prize from it is paid', function () {
    fakeWallet();
    $shared = ownPotWallet(0);
    fakeLightningAddresses($shared);
    $finished = finishedPoolTournament($shared, 10_000, 2);
    $next = openTournament();
    $connect = fn () => app(PrizePool::class)->configurePot($next->refresh(), $next->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]);

    // Open pot.
    expect($connect)->toThrow(TournamentRuleViolation::class, __(SHARED_WALLET));

    // Closed, prizes approved but not paid: the wallet still holds them.
    app(PayoutApproval::class)->approve($finished, anAdmin());
    expect($connect)->toThrow(TournamentRuleViolation::class, __(SHARED_WALLET));

    foreach ($finished->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect($finished->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid]);
    expect($connect()->hasOwnWallet())->toBeTrue();
});

test('F-A: a pot bound to the wallet between the live check and the lock is still refused', function () {
    fakeWallet();
    $shared = ownPotWallet(100_000);
    $first = openTournament();
    $second = openTournament();

    // Another page saves the same wallet for the first tournament while the second one's check runs.
    $bound = false;
    Event::listen(TransactionBeginning::class, function () use ($first, $shared, &$bound): void {
        if (! $bound) {
            $bound = true;
            Tournament::query()->whereKey($first->id)->update(['pot_source' => Tournament::POT_WALLET, 'pot_wallet_hash' => PrizePool::walletFingerprint($shared->pubkey)]);
        }
    });

    expect(fn () => app(PrizePool::class)->configurePot($second, $second->creator, true, $shared->uri('pay'), null, Tournament::PRIZES_PERCENT, [50, 30, 20]))
        ->toThrow(TournamentRuleViolation::class, __(SHARED_WALLET))
        ->and($bound)->toBeTrue()
        ->and($second->refresh()->hasOwnWallet())->toBeFalse();
});

test('F-A: the migration fingerprints the wallets of existing pots', function () {
    fakeWallet();
    $pot = ownPotWallet(0);
    $tournament = publishForPool(openTournament(), $pot);
    $migration = require database_path('migrations/2026_09_27_190622_add_pot_wallet_hash_to_tournaments.php');
    $migration->down();
    $migration->up();

    expect(DB::table('tournaments')->where('id', $tournament->id)->value('pot_wallet_hash'))->toBe(PrizePool::walletFingerprint($pot->pubkey));
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
    expect($tournament->refresh()->pot_wallet_hash)->toBe(PrizePool::walletFingerprint($replacement->pubkey))
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

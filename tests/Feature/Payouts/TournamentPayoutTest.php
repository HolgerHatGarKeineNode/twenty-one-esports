<?php

use App\Enums\PayoutStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\TournamentPayout;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\PayoutRunner;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| P9 DoD: a payout from the league's fake NWC wallet pays exactly once, also
| on a double click and on a retry; a missing Lightning address keeps it
| open. Every pot is booked in the league wallet (2026-10-02). The pot as set
| is paid out by share (user, 2026-10-04: "Was eingestellt ist im Topf wird
| anteilig ausgezahlt, fertig … Wenn die Wallet nicht genug Sats hat, muss
| extern halt einer aufladen"): percent prizes split the whole pot, no fee
| reserve; fixed prizes are paid exactly. No check of the wallet's or the
| pot's balance blocks the approval or a payment; a wallet that is short
| fails the payment, which stays retryable.
*/

test('the pool is split by place, ties share, and every winner is paid once with a published payout', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);

    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payouts = $tournament->payouts()->get();

    // 50/30/20 of the whole 100 000 (no fee reserve is held back) over four players, single elimination without a
    // match for third: the two losing semi-finalists share places 3 and 4, that is 20 % between them.
    expect($payouts->map(fn (TournamentPayout $p) => [$p->place, $p->amount_sats, $p->status])->all())->toBe([
        [1, 50_000, PayoutStatus::Pending], [2, 30_000, PayoutStatus::Pending], [3, 10_000, PayoutStatus::Pending], [3, 10_000, PayoutStatus::Pending],
    ]);

    foreach ($payouts as $payout) {
        PayTournamentPayout::dispatch($payout->id);
    }

    // Each prize and its fee (1 sat in the fake wallet) leave the pot's account, booked once: the whole pot is paid
    // out, so the fees take the account 4 sats below zero (they are the league wallet's, not a reserve of the pot).
    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($wallet->paid)->toHaveCount(4)
        ->and($wallet->payRequests())->toHaveCount(4)
        ->and(LedgerTransfer::query()->where('reason', 'tournament_payout')->count())->toBe(4)
        ->and(LedgerTransfer::query()->where('reason', 'tournament_payout_fee')->count())->toBe(4)
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(100_000 - 100_000 - 4);

    $payout = $tournament->payouts()->where('place', 1)->sole();
    $event = SignedEvent::fromInput(json_decode(NostrEvent::query()->findOrFail($payout->event_id)->raw, true));
    expect($event->kind)->toBe(2157)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tag('a'))->toBe($tournament->address())
        ->and($event->tag('p'))->toBe($payout->pubkey)
        ->and($event->tag('bolt11'))->toBe($payout->bolt11)
        ->and(hash('sha256', hex2bin((string) $event->tag('preimage'))))->toBe($payout->payment_hash);
});

test('a double click and a retried job pay once', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();

    // Two clicks, two jobs; then the queue retries the first one.
    PayTournamentPayout::dispatch($payout->id);
    PayTournamentPayout::dispatch($payout->id);
    (new PayTournamentPayout($payout->id))->handle(app(PayoutRunner::class));
    (new PayTournamentPayout($payout->id, false))->handle(app(PayoutRunner::class));

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->payRequests())->toBe([$payout->payment_hash])
        ->and(NostrEvent::query()->where('kind', 2157)->count())->toBe(1);
});

test('an attempt that holds the payout keeps a second one out', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();
    // The approval read the pot's balance fresh; only what the second click asks counts here.
    $wallet->calls = [];

    // A first attempt is still running (its lease is fresh) when the second click arrives.
    $payout->forceFill(['lease_until' => now()->addMinute(), 'lease_owner' => 'first'])->save();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Pending)
        ->and(collect($wallet->calls)->where('client', 'pay')->all())->toBe([]);
});

test('an answer that never came is looked up, not paid again', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();

    // The wallet pays, but its answer is lost: the league cannot know.
    $wallet->loseNextAnswer = true;
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paying)
        ->and($payout->reason)->toBe('unconfirmed')
        ->and($wallet->paid)->toHaveCount(1);

    // A retry (the scheduler's wallet:sync, or the job again) asks first and finds it paid.
    $this->artisan('wallet:sync')->assertSuccessful();
    $this->travel(2)->minutes();
    $this->artisan('wallet:sync')->assertSuccessful();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->payRequests())->toHaveCount(1)
        ->and(collect($wallet->calls)->where('method', 'lookup_invoice')->where('client', 'pay'))->not->toBeEmpty();
});

test('a refused payment stays retryable and is paid once on retry', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();

    $wallet->failNext = 'INSUFFICIENT_BALANCE';
    app(PayoutRunner::class)->run($payout, true);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed)
        ->and($payout->reason)->toBe('insufficient_balance');

    // The scheduler never starts a failed payout by itself.
    $this->travel(2)->minutes();
    $this->artisan('wallet:sync')->assertSuccessful();
    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed);

    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->paid)->toHaveCount(1)
        ->and($payout->attempts)->toBe(2);
});

test('a player without a Lightning address keeps the payout open with its reason until an admin approves the one they add', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2, withoutAddress: [1]);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();

    expect($payout->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('no_lud16')
        ->and($payout->reasonText())->toContain('Lightning address');

    app(PayoutRunner::class)->run($payout, true);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)
        ->and($wallet->payRequests())->toBe([]);

    // Adding one is not enough: an open payout is paid only after an admin approved its address.
    $payout->user->forceFill(['lud16' => 'late@wallet.example'])->save();
    app(PayoutRunner::class)->run($payout, true);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)->and($wallet->payRequests())->toBe([]);

    app(PayoutApproval::class)->approveAddress($payout, anAdmin(), 'late@wallet.example');
    app(PayoutRunner::class)->run($payout->refresh(), true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->lud16)->toBe('late@wallet.example');
});

test('without the league wallet’s paying connection nothing is approved or attempted', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    config(['esports.wallet.nwc_uri' => 'broken']);

    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'league wallet is not connected')
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull()
        ->and($tournament->payouts()->count())->toBe(0);
});

test('a tournament prize is paid as set whatever the league wallet or the other pots hold; a short wallet fails the payment, which stays retryable', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $other = publishForPool(openTournament());
    fundPool($wallet, $other, 50_000);

    // The wallet holds less than this pot's prizes (and the other pot's 50 000 are in it): the approval does not
    // look at the balance, not even once, and splits the pot as set.
    $wallet->balanceMsats = 1_000_000;
    $wallet->calls = [];
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $first = $tournament->payouts()->where('place', 1)->sole();
    $second = $tournament->payouts()->where('place', 2)->sole();

    expect($tournament->refresh()->payouts_approved_at)->not->toBeNull()
        ->and(collect($wallet->calls)->where('method', 'get_balance')->all())->toBe([])
        // 50/30 of 10 000, no fee reserve held back; the other pot's sats are none of its business.
        ->and([$first->amount_sats, $second->amount_sats])->toBe([5_000, 3_000]);

    // Paying: the wallet cannot cover it, so the wallet itself refuses; nothing is booked and it stays retryable.
    app(PayoutRunner::class)->run($first, true);
    expect($first->refresh()->status)->toBe(PayoutStatus::Failed)->and($first->reason)->toBe('insufficient_balance')
        ->and($wallet->paid)->toBe([])
        ->and(LedgerTransfer::query()->where('reason', 'tournament_payout')->count())->toBe(0);

    // Someone tops the wallet up, the balance is unreadable on top: the retry pays once, booked out of the pot's account.
    $wallet->balanceMsats = 50_000_000_000;
    $wallet->hideBalance = true;
    $wallet->calls = [];
    app(PayoutRunner::class)->run($first->refresh(), true);
    app(PayoutRunner::class)->run($second->refresh(), true);

    expect($first->refresh()->status)->toBe(PayoutStatus::Paid)->and($first->attempts)->toBe(2)
        ->and($second->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->paid)->toHaveCount(2)
        ->and(collect($wallet->calls)->where('method', 'get_balance')->all())->toBe([])
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(10_000 - 5_000 - 3_000 - 2);
});

test('only an admin approves payouts, and approving twice changes nothing', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);

    expect(fn () => app(PayoutApproval::class)->approve($tournament, $tournament->creator ?? organizer()))->toThrow(TournamentRuleViolation::class);

    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    expect(fn () => app(PayoutApproval::class)->approve($tournament->refresh(), $admin))->toThrow(TournamentRuleViolation::class)
        ->and($tournament->payouts()->count())->toBe(2);
});

test('fixed prizes: a pot that received less than they sum up to is still approved and paid as set', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    // 50 000 + 30 000 are set; the pot received only 60 000 (user, 2026-10-04: what is set is paid, whoever tops up is
    // none of the approval's business).
    $tournament = finishedPoolTournament($wallet, 60_000, 2, fixed: [50_000, 30_000]);
    $admin = anAdmin();

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertDontSeeHtml('data-test="payouts-underfunded"')->assertDontSeeHtml('data-test="payouts-blocker"')
        ->assertSeeHtml('data-test="approve-payouts"')
        ->call('approve')->assertHasNoErrors();

    expect($tournament->refresh()->payouts_approved_at)->not->toBeNull();

    foreach ($tournament->payouts()->orderBy('place')->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 50_000, 2 => 30_000])
        ->and($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        // The pot's account is booked below zero by the 20 000 it never received and the two fees.
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(60_000 - 80_000 - 2);
});

test('an unreadable league wallet does not block the approval, in either mode', function () {
    fakeWallet();
    config(['esports.wallet.nwc_timeout_seconds' => 1]);
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $fixed = finishedPoolTournament($wallet, 85_000, 2, fixed: [50_000, 30_000]);
    $percent = finishedPoolTournament($wallet, 40_000, 2);
    app(FakeNwcTransport::class)->offline[$wallet->pubkey] = true;

    foreach ([$fixed, $percent] as $tournament) {
        app(PayoutApproval::class)->approve($tournament, anAdmin());

        expect($tournament->refresh()->payouts_approved_at)->not->toBeNull()->and($tournament->payouts()->count())->toBe(2);
    }

    expect($fixed->payouts()->orderBy('place')->pluck('amount_sats')->all())->toBe([50_000, 30_000])
        ->and($percent->payouts()->orderBy('place')->pluck('amount_sats')->all())->toBe([20_000, 12_000]);
});

test('fixed prizes: a covered balance shows no warning', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 85_000, 2, fixed: [50_000, 30_000]);

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertDontSeeHtml('data-test="payouts-underfunded"')->assertSeeHtml('data-test="approve-payouts"');
});

test('fixed prizes: tied places share the sum of their amounts, rounded down, the rest stays in the pot', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    // Four players without a match for third: both losing semi-finalists hold places 3 and 4.
    $tournament = finishedPoolTournament($wallet, 200_000, 4, fixed: [50_000, 30_000, 15_001, 5_000]);
    app(PayoutApproval::class)->approve($tournament, anAdmin());

    expect($tournament->payouts()->orderBy('place')->orderBy('id')->get()->map(fn (TournamentPayout $p) => [$p->place, $p->amount_sats])->all())
        ->toBe([[1, 50_000], [2, 30_000], [3, 10_000], [3, 10_000]])
        ->and(app(PayoutPlan::class)->compute($tournament, 100_001)['remainder'])->toBe(1);
});

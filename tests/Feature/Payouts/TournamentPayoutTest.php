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
use App\Support\PreSeason;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| P9 DoD: a payout from the league's fake NWC wallet pays exactly once, also
| on a double click and on a retry; a missing Lightning address keeps it
| open. Every pot is booked in the league wallet (2026-10-02): percent prizes
| split what came into the pot less the fee reserve; fixed prizes are paid
| exactly, and a pot short of them is not approved. A payout never takes
| more than its pot's account holds, nor the sats the wallet keeps for the
| other pots.
*/

test('the pool is split by place, ties share, and every winner is paid once with a published payout', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);

    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payouts = $tournament->payouts()->get();

    // 50/30/20 of 99 000 (100 000 less the 1 % fee reserve) over four players, single elimination without a
    // match for third: the two losing semi-finalists share places 3 and 4, that is 20 % between them.
    expect($payouts->map(fn (TournamentPayout $p) => [$p->place, $p->amount_sats, $p->status])->all())->toBe([
        [1, 49_500, PayoutStatus::Pending], [2, 29_700, PayoutStatus::Pending], [3, 9_900, PayoutStatus::Pending], [3, 9_900, PayoutStatus::Pending],
    ]);

    foreach ($payouts as $payout) {
        PayTournamentPayout::dispatch($payout->id);
    }

    // Each prize and its fee (1 sat in the fake wallet) leave the pot's account, booked once.
    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($wallet->paid)->toHaveCount(4)
        ->and($wallet->payRequests())->toHaveCount(4)
        ->and(LedgerTransfer::query()->where('reason', 'tournament_payout')->count())->toBe(4)
        ->and(LedgerTransfer::query()->where('reason', 'tournament_payout_fee')->count())->toBe(4)
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(100_000 - 99_000 - 4);

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

test('a payout never takes more than its pot holds, nor the sats the league wallet keeps for other pots', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $other = publishForPool(openTournament());
    fundPool($wallet, $other, 50_000);

    // The wallet holds less than the prizes beyond the other pot's 50 000: nothing is approved.
    $wallet->balanceMsats = 55_000_000;
    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'holds less than these prizes')
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull();

    $wallet->balanceMsats = 60_000_000;
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $first = $tournament->payouts()->where('place', 1)->sole();
    $second = $tournament->payouts()->where('place', 2)->sole();
    $requests = count($wallet->payRequests());

    // Another pot's payout or the reserve drained the wallet since: the prize is not covered, nothing is sent.
    $wallet->balanceMsats = 53_000_000;
    app(PayoutRunner::class)->run($first, true);
    expect($first->refresh()->status)->toBe(PayoutStatus::Failed)->and($first->reason)->toBe('insufficient_balance')
        ->and(count($wallet->payRequests()))->toBe($requests);

    // A payout above what its pot's account holds is never sent either, however full the wallet is.
    $wallet->balanceMsats = 50_000_000_000;
    TournamentPayout::query()->whereKey($second->id)->update(['amount_sats' => 10_001]);
    app(PayoutRunner::class)->run($second->refresh(), true);
    expect($second->refresh()->status)->toBe(PayoutStatus::Failed)->and($second->reason)->toBe('insufficient_balance')
        ->and(count($wallet->payRequests()))->toBe($requests);

    // A balance the wallet does not tell sends nothing either.
    $wallet->hideBalance = true;
    app(PayoutRunner::class)->run($first->refresh(), true);
    expect($first->refresh()->reason)->toBe('balance_unread')->and(count($wallet->payRequests()))->toBe($requests);

    // Covered again: paid once, booked out of the pot's account.
    $wallet->hideBalance = false;
    app(PayoutRunner::class)->run($first->refresh(), false);
    expect($first->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(10_000 - $first->amount_sats - 1);
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

test('fixed prizes: a pot short of them is not approved until it is topped up', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    // 50 000 + 30 000 need 80 800 sats with the 1 % fee reserve; the pot received 60 000 (coordinator, 2026-10-02: a block, the wallet is shared).
    $tournament = finishedPoolTournament($wallet, 60_000, 2, fixed: [50_000, 30_000]);
    $admin = anAdmin();

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="payouts-underfunded"')->assertSeeHtml('data-test="payouts-blocker"')
        ->assertSee(PreSeason::formatSats(20_800))->assertSee(PreSeason::formatSats(80_800))
        ->assertDontSeeHtml('data-test="approve-payouts"')
        ->call('approve')->assertHasErrors('payouts');

    expect($tournament->refresh()->payouts_approved_at)->toBeNull()->and($tournament->payouts()->count())->toBe(0);

    fundPool($wallet, $tournament, 20_800);
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertDontSeeHtml('data-test="payouts-underfunded"')->call('approve')->assertHasNoErrors();

    foreach ($tournament->payouts()->orderBy('place')->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 50_000, 2 => 30_000])
        ->and($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid]);
});

test('an unreadable league wallet approves nothing, in either mode', function () {
    fakeWallet();
    config(['esports.wallet.nwc_timeout_seconds' => 1]);
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $fixed = finishedPoolTournament($wallet, 85_000, 2, fixed: [50_000, 30_000]);
    $percent = finishedPoolTournament($wallet, 40_000, 2);
    app(FakeNwcTransport::class)->offline[$wallet->pubkey] = true;

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $fixed->id])
        ->call('readBalance')->assertHasErrors('payouts');

    foreach ([$fixed, $percent] as $tournament) {
        expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'did not tell its balance just now')
            ->and($tournament->refresh()->payouts_approved_at)->toBeNull()->and($tournament->payouts()->count())->toBe(0);
    }
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

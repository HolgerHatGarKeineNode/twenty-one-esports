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
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| P9 DoD: a payout from the pot's own fake NWC wallet pays exactly once,
| also on a double click and on a retry; a missing Lightning address keeps
| it open. Percent prizes split the balance less the fee reserve; fixed
| prizes are paid exactly, and a balance short of them is only a warning.
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

    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($wallet->paid)->toHaveCount(4)
        ->and($wallet->payRequests())->toHaveCount(4)
        ->and(LedgerTransfer::query()->count())->toBe(0);

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

test('without the pot’s wallet connection nothing is approved or attempted', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $tournament->forceFill(['pot_nwc_uri' => 'broken'])->save();

    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'own wallet')
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull()
        ->and($tournament->payouts()->count())->toBe(0);
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

test('fixed prizes: a short balance is a warning next to them, the approval still writes them, and a failed payment pays on retry after a top-up', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    // 50 000 + 30 000 need 80 800 sats with the 1 % fee reserve; the pot holds 60 000 (user, 2026-09-27: a warning, never a block).
    $tournament = finishedPoolTournament($wallet, 60_000, 2, fixed: [50_000, 30_000]);
    $admin = anAdmin();

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="payouts-underfunded"')
        ->assertSee(PreSeason::formatSats(20_800))->assertSee(PreSeason::formatSats(80_800))
        ->assertSeeHtml('data-test="approve-payouts"')
        ->call('approve')->assertHasNoErrors();

    expect($tournament->refresh()->payouts_approved_at)->not->toBeNull()
        ->and($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 50_000, 2 => 30_000]);

    foreach ($tournament->payouts()->orderBy('place')->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    // The first prize fits, the second does not: it fails as the wallet said, nothing is invented.
    $second = $tournament->payouts()->where('place', 2)->sole();
    expect($tournament->payouts()->where('place', 1)->sole()->status)->toBe(PayoutStatus::Paid)
        ->and($second->status)->toBe(PayoutStatus::Failed);

    $wallet->balanceMsats += 30_000_000;
    app(PayoutRunner::class)->run($second->refresh(), true);
    expect($second->refresh()->status)->toBe(PayoutStatus::Paid);
});

test('fixed prizes: an unreadable balance is a warning and the approval goes through; percent prizes are refused without a read', function () {
    fakeWallet();
    config(['esports.wallet.nwc_timeout_seconds' => 1]);
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 85_000, 2, fixed: [50_000, 30_000]);
    app(FakeNwcTransport::class)->offline[$wallet->pubkey] = true;

    // The read fails: the warning says so next to the prizes, and the approval still writes them (coordinator, 2026-09-27).
    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->call('readBalance')->assertHasErrors('payouts')
        ->assertSeeHtml('data-test="payouts-unread"')->assertSee(__('Balance could not be read; you can still approve, the admin is responsible.'))
        ->assertSeeHtml('data-test="approve-payouts"');
    $page->call('approve')->assertHasNoErrors();

    expect($tournament->refresh()->payouts_approved_at)->not->toBeNull()
        ->and($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 50_000, 2 => 30_000]);

    // Percent prizes are a share of the balance read now: no read, no approval.
    $percentWallet = ownPotWallet(0);
    fakeLightningAddresses($percentWallet);
    $percent = finishedPoolTournament($percentWallet, 40_000, 2);
    app(FakeNwcTransport::class)->offline[$percentWallet->pubkey] = true;

    expect(fn () => app(PayoutApproval::class)->approve($percent, anAdmin()))->toThrow(TournamentRuleViolation::class, 'did not tell its balance just now')
        ->and($percent->refresh()->payouts_approved_at)->toBeNull()->and($percent->payouts()->count())->toBe(0);
    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $percent->id])->assertDontSeeHtml('data-test="payouts-unread"');
});

test('fixed prizes: a covered balance shows no warning', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 85_000, 2, fixed: [50_000, 30_000]);

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertDontSeeHtml('data-test="payouts-underfunded"')->assertSeeHtml('data-test="approve-payouts"');
});

test('fixed prizes: tied places share the sum of their amounts, rounded down, the rest stays in the wallet', function () {
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

<?php

use App\Enums\PayoutStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\NostrEvent;
use App\Models\TournamentPayout;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;

/*
| P9 DoD: a payout against the fake NWC wallet pays exactly once, also on a
| double click and on a retry; a missing Lightning address keeps it open.
*/

test('the pool is split by place, ties share, and every winner is paid once with a published payout', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);

    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payouts = $tournament->payouts()->get();

    // 50/30/20 over four players, single elimination without a match for third: the two
    // losing semi-finalists share places 3 and 4, that is 20 % between them.
    expect($payouts->map(fn (TournamentPayout $p) => [$p->place, $p->amount_sats, $p->status])->all())->toBe([
        [1, 50_000, PayoutStatus::Pending], [2, 30_000, PayoutStatus::Pending], [3, 10_000, PayoutStatus::Pending], [3, 10_000, PayoutStatus::Pending],
    ]);

    foreach ($payouts as $payout) {
        PayTournamentPayout::dispatch($payout->id);
    }

    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($wallet->paid)->toHaveCount(4)
        ->and($wallet->payRequests())->toHaveCount(4)
        ->and(app(Ledger::class)->balance('tournament:'.$tournament->id))->toBe(0);

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
    $wallet = fakeWallet();
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
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();

    // A first attempt is still running (its lease is fresh) when the second click arrives.
    $payout->forceFill(['lease_until' => now()->addMinute(), 'lease_owner' => 'first'])->save();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Pending)
        ->and(collect($wallet->calls)->where('client', 'pay')->all())->toBe([]);
});

test('an answer that never came is looked up, not paid again', function () {
    $wallet = fakeWallet();
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
    $wallet = fakeWallet();
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
    $wallet = fakeWallet();
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

test('without a paying wallet connection nothing is approved or attempted', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    config(['esports.wallet.nwc_uri' => null]);

    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'ESPORTS_NWC_URI')
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull()
        ->and($tournament->payouts()->count())->toBe(0);
});

test('only an admin approves payouts, and approving twice changes nothing', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);

    expect(fn () => app(PayoutApproval::class)->approve($tournament, $tournament->creator ?? organizer()))->toThrow(TournamentRuleViolation::class);

    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    expect(fn () => app(PayoutApproval::class)->approve($tournament->refresh(), $admin))->toThrow(TournamentRuleViolation::class)
        ->and($tournament->payouts()->count())->toBe(2);
});

<?php

use App\Enums\PayoutStatus;
use App\Jobs\PayTournamentPayout;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\PayoutRunner;
use App\Support\PreSeason;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;

/*
| P9 DoD: a payout from the pot's own fake NWC wallet pays exactly once,
| also on a double click and on a retry; a missing Lightning address keeps
| it open. Percent prizes split the balance less the fee reserve; fixed
| prizes are paid exactly, and only when the balance covers them.
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

test('fixed prizes: approval is refused while the pot does not cover them, and pays exactly those amounts once it does', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    // 50 000 + 30 000 need 80 800 sats with the 1 % fee reserve; the pot holds 80 000.
    $tournament = finishedPoolTournament($wallet, 80_000, 2, fixed: [50_000, 30_000]);

    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, PreSeason::formatSats(80_800))
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull()
        ->and($tournament->payouts()->count())->toBe(0);

    // Someone adds sats; the approval reads the balance again.
    $wallet->balanceMsats += 5_000_000;
    app(PayoutApproval::class)->approve($tournament, anAdmin());

    expect($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 50_000, 2 => 30_000]);

    foreach ($tournament->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    // What the pot held beyond the prizes and their fees stays in it.
    expect($tournament->payouts()->where('status', 'paid')->count())->toBe(2)
        ->and(intdiv($wallet->balanceMsats, 1000))->toBe(85_000 - 80_000 - 2);
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

test('fixed prizes: a balance that drops between the check and the lock still refuses the approval', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000, 2, fixed: [50_000, 30_000]);

    // A balance read of another process lands after the first check, before the row is locked.
    $dropped = false;
    Event::listen(TransactionBeginning::class, function () use ($tournament, &$dropped): void {
        if (! $dropped) {
            $dropped = true;
            Tournament::query()->whereKey($tournament->id)->update(['pot_balance_sats' => 1_000]);
        }
    });

    expect(fn () => app(PayoutApproval::class)->approve($tournament, anAdmin()))->toThrow(TournamentRuleViolation::class, 'no longer covers')
        ->and($dropped)->toBeTrue()
        ->and($tournament->refresh()->payouts_approved_at)->toBeNull()
        ->and($tournament->payouts()->count())->toBe(0);
});

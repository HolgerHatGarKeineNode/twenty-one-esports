<?php

use App\Enums\TournamentStatus;
use App\Models\LedgerTransfer;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\User;
use App\Support\Payouts\PayoutApproval;
use App\Support\PreSeason;
use App\Support\Prizes\PotRelease;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;

/*
| A cancelled tournament's pot, or a pot switched off, whose account in the
| league ledger still holds sats (plan "Restposten nach TMNF", P2; user,
| 2026-10-03: an admin button, logged, nothing automatic): an admin releases
| the whole balance to the league reserve, with a note and a confirm step.
| The pot closes, so later payments go to the reserve; it books once, and
| never once payouts are approved.
*/

/** A published tournament whose pot received `$sats` in the league wallet, then was cancelled. */
function cancelledPot(int $sats = 12_000): Tournament
{
    $wallet = fakeWallet();
    $tournament = publishForPool(openTournament());
    fundPool($wallet, $tournament, $sats);
    $tournament->forceFill(['status' => TournamentStatus::Cancelled])->save();

    return $tournament->refresh();
}

/** A tournament open for sign-up whose pot received `$sats`, then was switched off by its organizer. */
function switchedOffPot(int $sats = 7_000): Tournament
{
    $wallet = fakeWallet();
    $tournament = publishForPool(openTournament());
    fundPool($wallet, $tournament, $sats);
    app(PrizePool::class)->configurePot($tournament->refresh(), $tournament->creator, false, null, Tournament::PRIZES_PERCENT, [50, 30, 20]);

    return $tournament->refresh();
}

test('only an admin releases a pot: the organizer and a player are refused, and only the admin sees the button', function () {
    $tournament = cancelledPot();
    $release = app(PotRelease::class);

    foreach ([$tournament->creator, User::factory()->create()] as $refused) {
        expect(fn () => $release->release($tournament, $refused, 12_000, 'mine now'))->toThrow(TournamentRuleViolation::class);
    }

    expect(LedgerTransfer::query()->where('to_account', Ledger::RESERVE)->exists())->toBeFalse()
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(12_000);

    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertDontSeeHtml('data-test="pool-release"')
        ->call('openRelease')->assertForbidden();

    Livewire::actingAs(anAdmin())->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pool-release-button"')
        ->assertSee('Release '.PreSeason::formatSats(12_000).' sats to the league reserve');
});

test('only a cancelled tournament or a switched-off pot can be released', function () {
    $wallet = fakeWallet();
    $live = publishForPool(openTournament());
    fundPool($wallet, $live, 5_000);
    $admin = anAdmin();
    $release = app(PotRelease::class);

    expect($release->releasableSats($live->refresh()))->toBe(0)
        ->and(fn () => $release->release($live, $admin, 5_000, 'too early'))->toThrow(TournamentRuleViolation::class);

    // A finished tournament with its pot on waits for the payout check instead.
    $live->forceFill(['status' => TournamentStatus::Finished])->save();
    expect($release->releasableSats($live->refresh()))->toBe(0)
        ->and(fn () => $release->release($live, $admin, 5_000, 'too early'))->toThrow(TournamentRuleViolation::class)
        ->and(app(Ledger::class)->balance($live->potAccount()))->toBe(5_000);

    Livewire::actingAs($admin)->test('pages::tournaments.pool', ['tournament' => $live])->assertDontSeeHtml('data-test="pool-release"');

    expect($release->releasableSats(cancelledPot(3_000)))->toBe(3_000)
        ->and($release->releasableSats(switchedOffPot(4_000)))->toBe(4_000);
});

test('a pot whose payouts are approved is never released', function () {
    $wallet = fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = finishedPoolTournament($pot, 20_000, 2);
    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    // Even cancelled after the approval: the approved prizes are paid from this account.
    $tournament->refresh()->forceFill(['status' => TournamentStatus::Cancelled])->save();
    $held = app(Ledger::class)->balance($tournament->potAccount());

    expect($held)->toBeGreaterThan(0)
        ->and(app(PotRelease::class)->releasableSats($tournament->refresh()))->toBe(0)
        ->and(fn () => app(PotRelease::class)->release($tournament, $admin, $held, 'leftover'))->toThrow(TournamentRuleViolation::class)
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe($held);
});

test('the admin confirms with a note: one booking of the whole balance to the reserve, the pot closes, one log line', function () {
    $tournament = switchedOffPot(7_000);
    $admin = anAdmin();
    $ledger = app(Ledger::class);
    $reserve = $ledger->balance(Ledger::RESERVE);

    expect($tournament->pool_closed_at)->toBeNull();

    $page = Livewire::actingAs($admin)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertDontSeeHtml('data-test="pool-release-confirm"')
        ->call('openRelease')
        ->assertSeeHtml('data-test="pool-release-confirm"')
        ->assertSee('This cannot be undone.')
        ->call('releasePot')->assertHasErrors(['releaseNote' => 'required']);

    expect(LedgerTransfer::query()->where('to_account', Ledger::RESERVE)->exists())->toBeFalse();

    $page->set('releaseNote', '  pot switched off,   sponsor agreed ')
        ->call('releasePot')->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="pool-release"')
        ->assertSee('Released '.PreSeason::formatSats(7_000).' sats to the league reserve.');

    $transfers = LedgerTransfer::query()->where('reason', 'pot_release')->get();
    expect($transfers)->toHaveCount(1)
        ->and($transfers[0]->from_account)->toBe($tournament->potAccount())
        ->and($transfers[0]->to_account)->toBe(Ledger::RESERVE)
        ->and($transfers[0]->sats)->toBe(7_000)
        ->and($transfers[0]->tournament_id)->toBe($tournament->id)
        ->and($ledger->balance($tournament->potAccount()))->toBe(0)
        ->and($ledger->balance(Ledger::RESERVE))->toBe($reserve + 7_000)
        ->and($ledger->pots())->toBe(7_000)
        ->and($tournament->refresh()->pool_closed_at)->not->toBeNull();

    $log = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->sole();
    expect($log->user_id)->toBe($admin->id)
        ->and($log->user_name)->toBe($admin->displayName())
        ->and($log->action)->toBe('pot_released')
        ->and($log->reason)->toBe('pot switched off, sponsor agreed')
        ->and($log->details)->toBe(['pot_sats' => [7_000, 0]])
        ->and($log->created_at)->not->toBeNull();

    // The log shows on the tournament's admin page.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('released to the league reserve')->assertSee('pot switched off, sponsor agreed');
});

test('a release names the amount the admin confirmed: if the pot moved since, nothing is released', function () {
    $tournament = cancelledPot(12_000);

    expect(fn () => app(PotRelease::class)->release($tournament, anAdmin(), 11_000, 'stale page'))->toThrow(TournamentRuleViolation::class)
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(12_000);
});

test('releasing twice books nothing the second time', function () {
    $tournament = cancelledPot(12_000);
    $admin = anAdmin();
    $release = app(PotRelease::class);

    expect($release->release($tournament, $admin, 12_000, 'cancelled'))->toBe(12_000)
        ->and($release->release($tournament, anAdmin(), 12_000, 'double click'))->toBe(0)
        ->and(LedgerTransfer::query()->where('reason', 'pot_release')->count())->toBe(1)
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'pot_released')->count())->toBe(1)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(12_000);
});

test('after the release the league wallet no longer holds anything for that tournament', function () {
    $tournament = cancelledPot(12_000);
    $other = publishForPool(openTournament());
    fundPool(ownPotWallet(0), $other, 3_000);
    $ledger = app(Ledger::class);

    expect($ledger->heldForTournaments())->toBe(15_000);

    app(PotRelease::class)->release($tournament, anAdmin(), 12_000, 'cancelled');

    expect($ledger->heldForTournaments())->toBe(3_000)
        ->and($ledger->heldForTournaments($other->potAccount()))->toBe(0);
});

test('a payment to the pot settled after the release goes to the reserve, even one the wallet received before it', function () {
    $wallet = fakeWallet();
    $tournament = publishForPool(openTournament());
    $topUps = app(PotTopUps::class);
    $before = $topUps->invoice($tournament->refresh(), 2_000);
    $after = $topUps->invoice($tournament->refresh(), 3_000);
    fundPool($wallet, $tournament, 7_000);
    app(PrizePool::class)->configurePot($tournament->refresh(), $tournament->creator, false, null, Tournament::PRIZES_PERCENT, [50, 30, 20]);
    $ledger = app(Ledger::class);

    // The wallet received the first invoice a minute before the release; the league reads it only afterwards.
    $wallet->settleIncoming($before->payment_hash, now()->subMinute()->getTimestamp());
    app(PotRelease::class)->release($tournament->refresh(), anAdmin(), 7_000, 'pot switched off');
    $this->travel(1)->minutes();
    $wallet->settleIncoming($after->payment_hash);
    $topUps->check($before, 0);
    $topUps->check($after, 0);

    expect($before->refresh()->late)->toBeTrue()
        ->and($after->refresh()->late)->toBeTrue()
        ->and($ledger->balance($tournament->potAccount()))->toBe(0)
        ->and($ledger->balance(Ledger::RESERVE))->toBe(12_000)
        ->and($ledger->heldForTournaments())->toBe(0);
});

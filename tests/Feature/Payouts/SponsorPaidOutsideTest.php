<?php

use App\Enums\PayoutStatus;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
| A sponsor's pledge paid some other way than the pot's invoice (user,
| 2026-10-02: "Rechnung wurde anders gezahlt"): whoever manages the pot marks
| it as paid outside, with an amount (the open rest by default) and a note.
| It counts toward the pot like a paid invoice and shows the logo. Since
| 2026-10-04 it is part of what the payouts split, like everything set in the
| pot (user: "Sponsoren usw und Prüfung der Wallet ist vollkommen EGAL … Was
| eingestellt ist im Topf wird anteilig ausgezahlt"): whoever holds the money
| tops the wallet up. Undone until the payouts are approved.
*/

function sponsorOf(Tournament $tournament, int $pledge = 10_500, string $name = 'Hodl Bakery'): TournamentSponsor
{
    return TournamentSponsor::query()->create(['tournament_id' => $tournament->id, 'name' => $name, 'pledged_sats' => $pledge]);
}

test('the organizer marks a pledge as paid outside: the open rest by default, with a note, counted and logged', function () {
    Storage::fake('public');
    fakeWallet();
    $tournament = publishForPool(openTournament());
    $sponsor = sponsorOf($tournament);
    $organizer = $tournament->creator;

    $page = Livewire::actingAs($organizer)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertDontSeeHtml('data-test="sponsor-outside-form"')
        ->call('openPaidOutside', $sponsor->id)
        ->assertSeeHtml('data-test="sponsor-outside-form"')
        ->assertSet('outsideSats', 10_500)
        ->set('outsideSats', '8000')->set('outsideNote', 'bank transfer on 1 October')
        ->call('markPaidOutside')->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="sponsor-outside-form"')
        ->assertSeeHtml('data-test="sponsor-outside"')
        ->assertSee('bank transfer on 1 October')
        ->assertSeeHtml('data-test="pool-paid-outside"')
        ->assertSeeHtml('data-test="pool-outside-warning"')
        ->assertSeeHtml('data-test="sponsor-outside-undo"');

    $sponsor->refresh();
    expect($sponsor->paid_outside_sats)->toBe(8000)
        ->and($sponsor->paid_outside_by_id)->toBe($organizer->id)
        ->and($sponsor->paid_outside_at)->not->toBeNull()
        ->and($sponsor->paid_outside_note)->toBe('bank transfer on 1 October')
        ->and($sponsor->paidSats())->toBe(8000)
        ->and($sponsor->paidInWalletSats())->toBe(0)
        ->and($sponsor->openSats())->toBe(2500)
        ->and(PrizePool::paidOutsideSats($tournament))->toBe(8000);

    $log = TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->latest('id')->firstOrFail();
    expect($log->user_id)->toBe($organizer->id)
        ->and($log->reason)->toBe('bank transfer on 1 October')
        ->and($log->details)->toBe(['paid_outside_sats' => [null, 8000]]);

    // Paid counts like an invoice: the logo shows on the tournament page, and a paid sponsor cannot be removed.
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('Hodl Bakery');
    expect(fn () => app(PrizePool::class)->removeSponsor($sponsor, $organizer))->toThrow(TournamentRuleViolation::class);

    // A second mark is refused until the first is undone; the undo is logged and clears the audit.
    expect(fn () => app(PrizePool::class)->markPaidOutside($sponsor, $organizer, 100))->toThrow(TournamentRuleViolation::class);
    $page->call('undoPaidOutside', $sponsor->id)->assertHasNoErrors()->assertDontSeeHtml('data-test="pool-paid-outside"');

    expect($sponsor->refresh()->paid_outside_sats)->toBeNull()
        ->and($sponsor->paid_outside_by_id)->toBeNull()
        ->and($sponsor->paid_outside_note)->toBeNull()
        ->and($sponsor->paidSats())->toBe(0)
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->latest('id')->firstOrFail()->details)->toBe(['paid_outside_sats' => [8000, null]]);
    $this->get(route('tournaments.show', $tournament))->assertDontSee('Hodl Bakery');
});

test('only whoever manages the pot marks or undoes an outside payment, with a valid amount', function () {
    Storage::fake('public');
    fakeWallet();
    $tournament = publishForPool(openTournament());
    $sponsor = sponsorOf($tournament);
    $pool = app(PrizePool::class);

    foreach ([User::factory()->create(), organizer()] as $stranger) {
        expect(fn () => $pool->markPaidOutside($sponsor, $stranger, 500))->toThrow(TournamentRuleViolation::class);
    }

    expect(fn () => $pool->markPaidOutside($sponsor, $tournament->creator, 0))->toThrow(TournamentRuleViolation::class)
        ->and($sponsor->refresh()->paid_outside_sats)->toBeNull();

    $pool->markPaidOutside($sponsor, anAdmin(), 500);
    expect(fn () => $pool->undoPaidOutside($sponsor, organizer()))->toThrow(TournamentRuleViolation::class)
        ->and($sponsor->refresh()->paid_outside_sats)->toBe(500);

    // Another tournament's sponsor is not reachable from this page.
    $other = sponsorOf(publishForPool(openTournament()), 1000, 'Other');
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->call('openPaidOutside', $other->id)->assertNotFound();
});

test('a sponsor paid outside the wallet is part of the split, and nothing changes once approved', function () {
    Storage::fake('public');
    fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = finishedPoolTournament($pot, 20_000, 2);
    $sponsor = sponsorOf($tournament, 50_000);
    $pool = app(PrizePool::class);
    $pool->markPaidOutside($sponsor, anAdmin(), 50_000, 'cash at the meetup');
    $admin = anAdmin();

    // No warning about money outside the wallet any more: it is simply in the pot.
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertDontSeeHtml('data-test="payouts-outside-warning"')
        ->assertSeeHtml('data-test="approve-payouts"');

    app(PayoutApproval::class)->approve($tournament, $admin);

    // 20 000 in the wallet plus the 50 000 paid outside are 70 000; 50/30 of it, no fee reserve.
    expect($tournament->payouts()->orderBy('place')->pluck('amount_sats')->all())->toBe([35_000, 21_000])
        ->and(fn () => $pool->undoPaidOutside($sponsor, $admin))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $pool->markPaidOutside(sponsorOf(Tournament::query()->findOrFail($tournament->id), 100, 'Late'), $admin, 100))->toThrow(TournamentRuleViolation::class)
        ->and($sponsor->refresh()->paid_outside_sats)->toBe(50_000);

    Livewire::actingAs($admin)->test('pages::tournaments.pool', ['tournament' => $tournament->refresh()])
        ->assertDontSeeHtml('data-test="sponsor-outside-undo"')
        ->assertDontSeeHtml('data-test="sponsor-outside-button"')
        ->assertSee(ShareCard::sats(50_000));
});

test('21 000 paid outside plus 210 zapped are split 62.5 / 37.5 between the two players who played, though the wallet holds only the 210', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $tournament = finishedPoolTournament($wallet, 0, 4);
    $tournament->forceFill(['pool_closed_at' => null])->save();
    $admin = anAdmin();

    // The pot is what came in through the wallet (a zap of 210) plus the sponsor's pledge, paid outside.
    paidPotZap($wallet, new TestSigner, $tournament, 210);
    $pool = app(PrizePool::class);
    $pool->markPaidOutside(sponsorOf($tournament, 21_000), $admin, 21_000, 'bank transfer');
    // The semi-final losers (tied third) did not play on: only the finalists are eligible (62.5 / 37.5 of the pot).
    TournamentParticipant::query()->whereKey(app(TournamentPlacements::class)->of($tournament)[2]['participants'])->update(['disqualified_at' => now()]);
    $wallet->balanceMsats = 210_000;
    $wallet->calls = [];

    app(PayoutApproval::class)->approve($tournament->refresh(), $admin);

    // 21 210 in all, no fee reserve: 21 210 * 62.5 % = 13 256.25 and * 37.5 % = 7 953.75, each rounded down.
    expect($pool->potSats($tournament))->toBe(21_210)->and($pool->fundedSats($tournament))->toBe(210)
        ->and($tournament->payouts()->orderBy('place')->pluck('amount_sats', 'place')->all())->toBe([1 => 13_256, 2 => 7_953])
        ->and(collect($wallet->calls)->where('method', 'get_balance')->all())->toBe([]);

    // The wallet really holds less: the payment is refused and stays retryable; once someone topped it up it is paid.
    $first = $tournament->payouts()->where('place', 1)->sole();
    app(PayoutRunner::class)->run($first, true);
    expect($first->refresh()->status)->toBe(PayoutStatus::Failed)->and($first->reason)->toBe('insufficient_balance')->and($wallet->paid)->toBe([]);

    $wallet->balanceMsats = 50_000_000_000;
    app(PayoutRunner::class)->run($first->refresh(), true);
    expect($first->refresh()->status)->toBe(PayoutStatus::Paid)->and($wallet->paid)->toHaveCount(1);
});

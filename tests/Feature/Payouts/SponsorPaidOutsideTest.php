<?php

use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Payouts\PayoutApproval;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| A sponsor's pledge paid some other way than the pot's invoice (user,
| 2026-10-02: "Rechnung wurde anders gezahlt"): whoever manages the pot marks
| it as paid outside, with an amount (the open rest by default) and a note.
| It counts toward the pot like a paid invoice and shows the logo, but it is
| not in the wallet: the payout never takes it from there, and the pages say
| so. Undone until the payouts are approved.
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

test('outside money is never taken from the wallet: the payout splits only what the wallet holds, and nothing changes once approved', function () {
    Storage::fake('public');
    fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = finishedPoolTournament($pot, 20_000, 2);
    $sponsor = sponsorOf($tournament, 50_000);
    $pool = app(PrizePool::class);
    $pool->markPaidOutside($sponsor, anAdmin(), 50_000, 'cash at the meetup');
    $admin = anAdmin();

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="payouts-outside-warning"');

    app(PayoutApproval::class)->approve($tournament, $admin);

    // 20 000 in the wallet less the 1 % fee reserve; the 50 000 paid outside are not split.
    expect((int) $tournament->payouts()->sum('amount_sats'))->toBeLessThanOrEqual(PrizePool::afterFeeReserve(20_000))
        ->and(fn () => $pool->undoPaidOutside($sponsor, $admin))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => $pool->markPaidOutside(sponsorOf(Tournament::query()->findOrFail($tournament->id), 100, 'Late'), $admin, 100))->toThrow(TournamentRuleViolation::class)
        ->and($sponsor->refresh()->paid_outside_sats)->toBe(50_000);

    Livewire::actingAs($admin)->test('pages::tournaments.pool', ['tournament' => $tournament->refresh()])
        ->assertDontSeeHtml('data-test="sponsor-outside-undo"')
        ->assertDontSeeHtml('data-test="sponsor-outside-button"')
        ->assertSee(ShareCard::sats(50_000));
});

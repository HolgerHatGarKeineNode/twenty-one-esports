<?php

use App\Enums\PayoutStatus;
use App\Models\LedgerTransfer;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\PreSeason;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;
use Tests\Support\FakeNwcWallet;

/*
| P9 scope addition (user, 2026-09-27): an optional prize pot on the
| tournament create and edit pages, off by default; since 2026-10-02 booked
| in the league wallet, so no wallet is asked for; presets or a custom split
| previewed in sats; the pot wherever the tournament shows; and the payout
| from the league wallet, exactly once. A pot approved before the move
| finishes paying from its own wallet; a moved pot's old connection is never
| shown.
*/

/** Rows of the league's ledger that name this tournament or one of its payouts. */
function booked(Tournament $tournament): int
{
    return LedgerTransfer::query()->where(fn ($query) => $query->where('tournament_id', $tournament->id)
        ->orWhereIn('tournament_payout_id', $tournament->payouts()->pluck('id')))->count();
}

/** The create page with a valid draft filled in. */
function createPage(): Testable
{
    return Livewire::actingAs(organizer())->test('pages::admin.tournament-create')
        ->set('name', 'Pot Cup');
}

test('a tournament without a pot works as before: the section is off, asks for no wallet, and nothing shows', function () {
    fakeWallet();
    $page = createPage()->assertSet('potEnabled', false)->assertSeeHtml('data-test="prize-pot"')->assertDontSeeHtml('data-test="pot-league-wallet"')
        ->set('potEnabled', true)->assertSeeHtml('data-test="pot-league-wallet"')->assertDontSeeHtml('data-test="pot-uri"')
        ->set('potEnabled', false);
    $page->call('create')->assertHasNoErrors();
    $tournament = Tournament::query()->where('name', 'Pot Cup')->sole();
    expect($tournament->pot_source)->toBeNull()->and($tournament->prize_split)->toBeNull();

    $published = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addDay());
    expect($published->pool_opened_at)->toBeNull()
        ->and(collect($published->event->payload()['tags'])->where(0, 'zap'))->toBeEmpty();
    $this->get(route('tournaments.show', $published))->assertOk()->assertDontSeeHtml('data-test="prize-pool"');
    $this->get(route('tournaments.index'))->assertDontSeeHtml('data-test="prize-chip"');
});

test('a pot needs no wallet: switched on with a target and a preset, previewed from the target, and booked in the league wallet', function () {
    fakeWallet();
    $calls = app(FakeNwcTransport::class)->calls;

    $page = createPage()->set('potEnabled', true)->set('potTarget', '300000')
        ->call('usePotPreset', '60-30-10')->assertSet('potSplit', [60, 30, 10])
        ->assertSeeHtml('data-test="pot-preview"')->assertSee(__(':sats sats', ['sats' => PreSeason::formatSats(intdiv(300_000 * 60, 100))]));

    $page->call('create')->assertHasNoErrors();
    $tournament = Tournament::query()->where('name', 'Pot Cup')->sole();

    expect($tournament->pot_source)->toBe(Tournament::POT_LEAGUE)
        ->and($tournament->pot_nwc_uri)->toBeNull()
        ->and($tournament->prize_split)->toBe([60, 30, 10])
        ->and($tournament->prize_target_sats)->toBe(300_000)
        ->and(app(FakeNwcTransport::class)->calls)->toBe($calls);
});

test('a pot moved from its own wallet tells the organizer to add those sats, and never shows the old connection', function () {
    $logPath = storage_path('framework/testing/pot-secret-'.getmypid().'.log');
    File::delete($logPath);
    config(['logging.default' => 'pot_secret', 'logging.channels.pot_secret' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
    fakeWallet();
    $old = new FakeNwcWallet;
    $uri = $old->uri('pay', 'Pot@Wallet.example');
    $secret = $old->clients['pay']['secret'];
    $tournament = publishForPool(openTournament());
    // As the migration of 2026-10-02 leaves it: a league pot, the old connection kept (encrypted), unused.
    $tournament->forceFill(['pot_nwc_uri' => $uri])->save();
    $raw = (string) DB::table('tournaments')->where('id', $tournament->id)->value('pot_nwc_uri');
    expect($raw)->not->toContain($secret)->not->toContain('walletconnect')
        ->and($tournament->toArray())->not->toHaveKey('pot_nwc_uri');

    $seen = [];

    foreach (['pages::admin.tournament-edit', 'pages::tournaments.pool'] as $name) {
        $edit = Livewire::actingAs($tournament->creator)->test($name, ['tournament' => $tournament])
            ->assertSet('potEnabled', true)->assertDontSeeHtml('data-test="pot-uri"');
        $seen[] = $edit->html().json_encode($edit->snapshot);
    }

    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pool-moved-notice"');
    $seen[] = $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $tournament))->assertOk()->getContent();
    $seen[] = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();
    $seen[] = File::exists($logPath) ? File::get($logPath) : '';
    // Positive control: the secret is findable where it is.
    expect($uri)->toContain($secret);

    foreach ($seen as $surface) {
        expect($surface)->not->toContain($secret)->not->toContain(substr($secret, 0, 12));
    }
});

test('the split is 100 % in total, presets or custom, and frozen once sign-up closed', function () {
    fakeWallet();
    $tournament = openTournament();
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true);

    $page->set('potSplit', [50, 30, 10])->call('savePotSettings')->assertSet('potError', __('The shares add up to 100 percent.'));
    expect($tournament->refresh()->pot_source)->toBeNull();

    foreach (PrizePool::PRESETS as $key => $split) {
        expect(PrizePool::presetOf($split))->toBe($key)->and(PrizePool::splitError($split))->toBeNull();
    }

    $page->set('potSplit', [45, 35, 20])->call('savePotSettings')->assertSet('potError', '');
    $tournament->refresh();
    expect($tournament->prizeSplit())->toBe([45, 35, 20])
        ->and($tournament->pool_opened_at)->not->toBeNull()
        // The rules say where the pot is.
        ->and($tournament->event->payload()['content'])->toContain('place 1 45 %')->toContain('league wallet');

    $this->travel(3)->seconds();
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call('usePotPreset', 'winner')->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeSplit())->toBe([45, 35, 20]);
});

test('the pot shows as set wherever the tournament shows, and what came in only without a target', function () {
    $league = fakeWallet();
    $tournament = openTournament(rocketLeague: true);
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true)->set('potTarget', '100000')->call('usePotPreset', 'winner')->call('savePotSettings')->assertSet('potError', '');
    fundPool($league, $tournament->refresh(), 120_000);

    // The pot as the tournament sets it (the target), not the 120 000 that came in (user, 2026-09-28).
    $sats = ShareCard::sats(100_000);
    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeHtml('data-test="pool-sats">'.$sats.'<')
        ->assertSee(__(':left of :total sats still to be won', ['left' => $sats, 'total' => $sats]))
        ->assertDontSeeHtml('data-test="pool-as-of"')
        ->assertSeeHtml('data-test="pot-fill"');
    $this->get(route('tournaments.index'))->assertSeeHtml('data-test="prize-chip"')->assertSee(__(':sats of :target sats pot', ['sats' => $sats, 'target' => $sats]));
    // The game page's poster shows the pot as its big number (user, 2026-09-28), not as a chip.
    $this->get(route('games.rocket-league'))->assertSeeHtml('data-test="next-tournament-pot"')->assertSeeHtml('>'.$sats.'</span>');

    // Without a target the pot is what came in, booked exactly: never stale, never a guess.
    $tournament->forceFill(['prize_target_sats' => null])->save();
    $this->get(route('tournaments.show', $tournament))->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(120_000).'<');
});

test('a pot pays from the league wallet, exactly once, out of its own account, and a missing address stays open', function () {
    $league = fakeWallet();
    fakeLightningAddresses($league);
    $tournament = finishedPoolTournament($league, 41_000, 4, withoutAddress: [4]);
    $admin = anAdmin();

    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="pot-ledger"')
        ->assertSee(__('Remainder, stays in the pot'))->call('approve')->assertHasNoErrors();
    $tournament->refresh();
    $payouts = $tournament->payouts()->orderBy('place')->orderBy('id')->get();

    // Split from the whole pot as set (what came into it), no fee reserve held back.
    expect($payouts->sum('amount_sats'))->toBe(41_000)
        ->and($payouts->first()->amount_sats)->toBe(intdiv(41_000 * 50, 100))
        ->and($payouts->firstWhere('lud16', null)?->status)->toBe(PayoutStatus::Open);
    $requests = count($league->payRequests());

    // A double click and a retry: one payment per payout, each booked once out of the pot's account.
    foreach ($payouts->where('status', PayoutStatus::Pending) as $payout) {
        $page->call('pay', $payout->id)->call('pay', $payout->id);
    }

    $page->call('payAll');
    $paid = (int) $tournament->payouts()->where('status', PayoutStatus::Paid)->sum('amount_sats');
    expect(count($league->payRequests()) - $requests)->toBe(3)
        ->and(array_unique(array_slice($league->payRequests(), $requests)))->toHaveCount(3)
        ->and($tournament->payouts()->where('status', PayoutStatus::Paid)->count())->toBe(3)
        ->and($tournament->payouts()->where('status', PayoutStatus::Open)->count())->toBe(1)
        ->and(booked($tournament))->toBe(6)
        ->and(app(PrizePool::class)->heldSats($tournament))->toBe(41_000 - $paid - 3);
});

test('a pot approved before the league wallet took over finishes paying from its own wallet and books nothing', function () {
    $league = fakeWallet();
    $own = app(FakeNwcTransport::class)->add(new FakeNwcWallet);
    // The winners' addresses make invoices only the old wallet can pay.
    fakeLightningAddresses($own);
    $tournament = finishedPoolTournament($league, 41_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    // As the migration of 2026-10-02 leaves an approved own-wallet pot.
    $tournament->forceFill(['pot_source' => Tournament::POT_WALLET, 'pot_nwc_uri' => $own->uri('pay')])->save();
    $requests = count($league->payRequests());

    foreach ($tournament->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($own->payRequests())->toHaveCount(2)
        ->and(count($league->payRequests()))->toBe($requests)
        ->and(booked($tournament))->toBe(0);
});

test('fixed amounts: each place wins exactly its sats, the pot shows funded X of Y, and what is left over stays', function () {
    $league = fakeWallet();
    $tournament = openTournament(rocketLeague: true);
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true)
        ->call('usePotMode', 'fixed')->set('potFixed', [60_000, 25_000])
        ->assertSeeHtml('data-test="pot-fixed-sum"')->assertSeeHtml('data-test="pot-mode-fixed"');

    // Validation: positive whole sats, at most the configured maximum per place and in all.
    $page->set('potFixed', [60_000, 0])->call('savePotSettings')->assertNotSet('potError', '');
    $page->set('potFixed', [(int) config('esports.wallet.fixed_prize_max_sats') + 1])->call('savePotSettings')->assertNotSet('potError', '');
    config(['esports.wallet.fixed_prizes_max_total_sats' => 80_000]);
    $page->set('potFixed', [60_000, 25_000])->call('savePotSettings')->assertNotSet('potError', '');
    config(['esports.wallet.fixed_prizes_max_total_sats' => 50_000_000]);
    $page->call('savePotSettings')->assertSet('potError', '');
    fundPool($league, $tournament->refresh(), 50_000);
    // Saved although the pot received less than the prizes: they are paid as set (user, 2026-10-04), no funding warning.
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertDontSeeHtml('data-test="pot-fixed-funding"');

    $tournament->refresh();
    expect($tournament->prizeMode())->toBe(Tournament::PRIZES_FIXED)
        ->and($tournament->prizeFixed())->toBe([60_000, 25_000])
        ->and($tournament->event->payload()['content'])->toContain('place 1 60000 sats')->toContain('fixed');

    // 50 000 held of the 85 000 in prizes (less the fee reserve of 850): not funded yet.
    $pool = app(TournamentPrizePool::class)->for($tournament);
    expect($pool['mode'])->toBe('fixed')
        ->and($pool['split'])->toBe([['place' => 1, 'percent' => null, 'sats' => 60_000], ['place' => 2, 'percent' => null, 'sats' => 25_000]])
        ->and($pool['target'])->toBe(85_000)->and($pool['have'])->toBe(49_150)->and($pool['funded'])->toBeFalse();
    $this->get(route('tournaments.show', $tournament))->assertSee(__(':sats sats', ['sats' => ShareCard::sats(60_000)]))
        ->assertSee(__(':left of :total sats still to be won', ['left' => ShareCard::sats(85_000), 'total' => ShareCard::sats(85_000)]));
    $this->get(route('tournaments.index'))->assertSee(__(':sats sats pot', ['sats' => ShareCard::sats(85_000)]));
    // The TV lobby names each place's fixed sats, not a share of the balance.
    $this->get(route('tournaments.tv', $tournament))->assertOk()->assertSee('data-test="tv-pot"', false)
        ->assertSee(__(':sats sats', ['sats' => ShareCard::sats(25_000)]));

    // Funded; the organizer sees what is left over after prizes.
    fundPool($league, $tournament, 50_000);
    expect(app(PrizePool::class)->funding($tournament->refresh()))->toBe(['goal' => 85_000, 'have' => 99_150, 'funded' => true, 'leftover' => 14_150]);
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pool-leftover"')->assertSee(PreSeason::formatSats(14_150));
    $this->get(route('tournaments.index'))->assertSee(__(':sats of :target sats pot', ['sats' => ShareCard::sats(85_000), 'target' => ShareCard::sats(85_000)]));
});

test('the prize mode switches freely before sign-up closes and is frozen after, percent mode unchanged', function () {
    $league = fakeWallet();
    $tournament = openTournament();
    $edit = fn () => Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()]);

    $edit()->set('potEnabled', true)->call('usePotMode', 'fixed')->set('potFixed', [7_000])->call('savePotSettings')->assertSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_FIXED);
    fundPool($league, $tournament, 10_000);

    $this->travel(3)->seconds();
    $edit()->call('usePotMode', 'percent')->call('usePotPreset', '60-30-10')->call('savePotSettings')->assertSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_PERCENT)
        ->and($tournament->prizeSplit())->toBe([60, 30, 10])
        ->and($tournament->prize_fixed)->toBeNull()
        ->and(app(PrizePool::class)->projection($tournament)[0])->toBe(['place' => 1, 'percent' => 60, 'sats' => intdiv(10_000 * 60, 100)]);

    $this->travel(3)->seconds();
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    $edit()->call('usePotMode', 'fixed')->set('potFixed', [5_000])->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_PERCENT);
});

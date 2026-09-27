<?php

use App\Enums\PayoutStatus;
use App\Models\LedgerTransfer;
use App\Models\Tournament;
use App\Support\Cards\ShareCard;
use App\Support\PreSeason;
use App\Support\Prizes\PotBalances;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeNwcTransport;

/*
| P9 scope addition (user, 2026-09-27): an optional prize pot on the
| tournament create and edit pages, off by default; a pot held by the
| league or in the tournament's own NWC wallet, whose connection string is
| checked live, stored encrypted and never shown; its balance read on a
| schedule and on demand, shown with its time and never guessed; presets or
| a custom split previewed in sats; the pot wherever the tournament shows;
| and the payout from that wallet, exactly once.
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

test('a tournament without a pot works as before: the section is off, has no league option, and nothing shows', function () {
    fakeWallet();
    $page = createPage()->assertSet('potEnabled', false)->assertSeeHtml('data-test="prize-pot"')->assertDontSeeHtml('data-test="pot-uri"')
        ->set('potEnabled', true)->assertSeeHtml('data-test="pot-uri"')->assertDontSeeHtml('data-test="pot-source-league"')->assertDontSee('League pot')
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

test('an own wallet is checked live, stored encrypted and never rendered, logged or kept in the page state', function () {
    $logPath = storage_path('framework/testing/pot-secret-'.getmypid().'.log');
    File::delete($logPath);
    config(['logging.default' => 'pot_secret', 'logging.channels.pot_secret' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
    fakeWallet();
    $own = ownPotWallet(250_000);
    $uri = $own->uri('pay', 'Pot@Wallet.example');
    $secret = $own->clients['pay']['secret'];

    $page = createPage()->set('potEnabled', true);

    // Not a connection string; then the league's own wallet; then a connection that may not pay.
    $page->set('potUri', 'https://example.com')->call('checkPotConnection')->assertNotSet('potError', '');
    $page->set('potUri', (string) config('esports.wallet.nwc_receive_uri'))->call('checkPotConnection')->assertSet('potError', __('This is the league’s own wallet. A tournament pot needs a wallet of its own.'));
    $page->set('potUri', $own->uri('receive'))->call('create');
    expect(Tournament::query()->count())->toBe(0)->and($page->get('potError'))->toContain('pay invoice');

    // The live check: the balance, and the preview of the 60/30/10 preset in sats from it (after the 1 % fee reserve).
    $page->set('potUri', $uri)->call('checkPotConnection')->assertSet('potCheckedSats', 250_000)
        ->call('usePotPreset', '60-30-10')->assertSet('potSplit', [60, 30, 10])
        ->assertSeeHtml('data-test="pot-preview"')->assertSee(__(':sats sats', ['sats' => PreSeason::formatSats(intdiv(PrizePool::afterFeeReserve(250_000) * 60, 100))]));

    $page->set('potTarget', '300000')->call('create')->assertHasNoErrors()->assertSet('potUri', '');
    $tournament = Tournament::query()->where('name', 'Pot Cup')->sole();

    expect($tournament->pot_source)->toBe(Tournament::POT_WALLET)
        ->and($tournament->pot_nwc_uri)->toBe($uri)
        ->and($tournament->pot_lud16)->toBe('pot@wallet.example')
        ->and($tournament->pot_balance_sats)->toBe(250_000)
        ->and($tournament->prize_split)->toBe([60, 30, 10])
        ->and($tournament->prize_target_sats)->toBe(300_000)
        ->and($tournament->toArray())->not->toHaveKey('pot_nwc_uri');
    $raw = (string) DB::table('tournaments')->where('id', $tournament->id)->value('pot_nwc_uri');
    expect($raw)->not->toContain($secret)->not->toContain('walletconnect');

    // The edit and pool pages show "connected", never the string; their snapshots do not carry it either.
    $seen = [json_encode($page->snapshot)];

    foreach (['pages::admin.tournament-edit', 'pages::tournaments.pool'] as $name) {
        $edit = Livewire::actingAs($tournament->creator)->test($name, ['tournament' => $tournament])
            ->assertSet('potEnabled', true)->assertSet('potUri', '')
            ->assertSeeHtml('data-test="pot-connected"')->assertDontSeeHtml('data-test="pot-uri"');
        $seen[] = $edit->html().json_encode($edit->snapshot);
    }

    $seen[] = $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $tournament))->assertOk()->getContent();
    $seen[] = File::exists($logPath) ? File::get($logPath) : '';
    // Positive control: the secret is findable where it is.
    expect($uri)->toContain($secret);

    foreach ($seen as $surface) {
        expect($surface)->not->toContain($secret)->not->toContain(substr($secret, 0, 12));
    }
});

test('the split is 100 % in total, presets or custom, and frozen once sign-up closed', function () {
    fakeWallet();
    $own = ownPotWallet();
    $tournament = openTournament();
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true)->set('potUri', $own->uri('pay'));

    $page->set('potSplit', [50, 30, 10])->call('savePotSettings')->assertSet('potError', __('The shares add up to 100 percent.'));
    expect($tournament->refresh()->pot_source)->toBeNull();

    foreach (PrizePool::PRESETS as $key => $split) {
        expect(PrizePool::presetOf($split))->toBe($key)->and(PrizePool::splitError($split))->toBeNull();
    }

    $page->set('potSplit', [45, 35, 20])->call('savePotSettings')->assertSet('potError', '')->assertSet('potUri', '');
    $tournament->refresh();
    expect($tournament->prizeSplit())->toBe([45, 35, 20])
        ->and($tournament->pool_opened_at)->not->toBeNull()
        // An own-wallet pot is not zapped through the league: no `zap` tag, and the rules say where the pot is.
        ->and(collect($tournament->event->payload()['tags'])->where(0, 'zap'))->toBeEmpty()
        ->and($tournament->event->payload()['content'])->toContain('place 1 45 %')->toContain('own wallet');

    $this->travel(3)->seconds();
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call('usePotPreset', 'winner')->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeSplit())->toBe([45, 35, 20]);
});

test('the pot shows wherever the tournament shows, with the balance time, and a failed read never invents a number', function () {
    fakeWallet();
    $own = ownPotWallet(120_000);
    $tournament = openTournament(rocketLeague: true);
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true)->set('potUri', $own->uri('pay', 'pot@wallet.example'))
        ->set('potTarget', '100000')->call('usePotPreset', 'winner')->call('savePotSettings')->assertSet('potError', '');

    $sats = ShareCard::sats(120_000);
    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeHtml('data-test="pool-sats">'.$sats.'<')->assertSeeHtml('data-test="pool-funded"')
        ->assertSeeHtml('data-test="pool-as-of"')->assertDontSee('pot@wallet.example')
        ->assertSeeHtml('data-test="topup-panel"');
    $this->get(route('tournaments.index'))->assertSeeHtml('data-test="prize-chip"')->assertSee(__(':sats sats pot, funded', ['sats' => $sats]));
    $this->get(route('games.rocket-league'))->assertSeeHtml('data-test="prize-chip"');

    // The wallet goes offline: the schedule keeps the last balance and says so.
    app(FakeNwcTransport::class)->offline[$own->pubkey] = true;
    config(['esports.wallet.nwc_timeout_seconds' => 1]);
    $this->travel(11)->minutes();
    $this->artisan('wallet:read-pots')->assertSuccessful();
    $tournament->refresh();
    expect($tournament->pot_balance_sats)->toBe(120_000)->and($tournament->pot_balance_error)->not->toBeNull();
    $this->get(route('tournaments.show', $tournament))->assertSeeHtml('data-test="pool-sats">'.$sats.'<')
        ->assertSee(__('Wallet balance as of :time; the wallet has not answered since.', ['time' => $tournament->pot_balance_at->copy()->timezone((string) config('esports.preseason.display_timezone'))->format('Y-m-d H:i')]));

    // Back online with more sats: read on demand, the page follows.
    unset(app(FakeNwcTransport::class)->offline[$own->pubkey]);
    $own->balanceMsats = 150_000_000;
    $this->travel(11)->seconds();
    Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])->call('readPotBalance')->assertSet('potError', '');
    expect($tournament->refresh()->pot_balance_sats)->toBe(150_000)->and($tournament->pot_balance_error)->toBeNull();

    // Never read at all: no pot is shown rather than a guess.
    $tournament->forceFill(['pot_balance_sats' => null, 'pot_balance_at' => null])->save();
    $this->get(route('tournaments.show', $tournament))->assertDontSeeHtml('data-test="prize-pool"');
    $this->get(route('tournaments.index'))->assertDontSeeHtml('data-test="prize-chip"');
});

test('an own-wallet pot pays from that wallet, exactly once, and a missing address stays open', function () {
    $league = fakeWallet();
    $own = ownPotWallet(40_000);
    fakeLightningAddresses($own);
    $tournament = finishedPoolTournament($own, 0, 4, withoutAddress: [4]);
    $admin = anAdmin();
    $own->balanceMsats = 41_000_000;

    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSee(__('Remainder, stays in the pot’s wallet'))->call('approve')->assertHasNoErrors();
    $tournament->refresh();
    $payable = PrizePool::afterFeeReserve(41_000);
    $payouts = $tournament->payouts()->orderBy('place')->orderBy('id')->get();

    // Split from the balance read at the approval, less the fee reserve; nothing booked in the league's ledger.
    expect($tournament->pot_balance_sats)->toBe(41_000)
        ->and($payouts->sum('amount_sats'))->toBeLessThanOrEqual($payable)
        ->and($payouts->first()->amount_sats)->toBe(intdiv($payable * 50, 100))
        ->and($payouts->firstWhere('lud16', null)?->status)->toBe(PayoutStatus::Open)
        ->and(booked($tournament))->toBe(0);

    // A double click and a retry: one payment per payout, all from the own wallet.
    foreach ($payouts->where('status', PayoutStatus::Pending) as $payout) {
        $page->call('pay', $payout->id)->call('pay', $payout->id);
    }

    $page->call('payAll');
    expect($own->payRequests())->toHaveCount(3)
        ->and(array_unique($own->payRequests()))->toHaveCount(3)
        ->and($league->payRequests())->toBe([])
        ->and($tournament->payouts()->where('status', PayoutStatus::Paid)->count())->toBe(3)
        ->and($tournament->payouts()->where('status', PayoutStatus::Open)->count())->toBe(1)
        ->and(booked($tournament))->toBe(0);
});

test('an own-wallet pot is not approved when its wallet does not answer', function () {
    fakeWallet();
    $own = ownPotWallet(40_000);
    $tournament = finishedPoolTournament($own, 0);
    app(FakeNwcTransport::class)->offline[$own->pubkey] = true;
    config(['esports.wallet.nwc_timeout_seconds' => 1]);

    Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('approve')->assertHasErrors('payouts');
    expect($tournament->refresh()->payouts_approved_at)->toBeNull()->and($tournament->payouts()->count())->toBe(0);
});

test('fixed amounts: each place wins exactly its sats, the pot shows funded X of Y, and what is left over stays', function () {
    fakeWallet();
    $own = ownPotWallet(50_000);
    $tournament = openTournament(rocketLeague: true);
    $page = Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->set('potEnabled', true)->set('potUri', $own->uri('pay'))
        ->call('usePotMode', 'fixed')->set('potFixed', [60_000, 25_000])
        ->assertSeeHtml('data-test="pot-fixed-sum"')->assertSeeHtml('data-test="pot-mode-fixed"');

    // Validation: positive whole sats, at most the configured maximum per place and in all.
    $page->set('potFixed', [60_000, 0])->call('savePotSettings')->assertNotSet('potError', '');
    $page->set('potFixed', [(int) config('esports.wallet.fixed_prize_max_sats') + 1])->call('savePotSettings')->assertNotSet('potError', '');
    config(['esports.wallet.fixed_prizes_max_total_sats' => 80_000]);
    $page->set('potFixed', [60_000, 25_000])->call('savePotSettings')->assertNotSet('potError', '');
    config(['esports.wallet.fixed_prizes_max_total_sats' => 50_000_000]);
    $page->call('savePotSettings')->assertSet('potError', '');

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
        ->assertSee(__('sats in the pot, funded :have of :target sats for the prizes', ['have' => ShareCard::sats(49_150), 'target' => ShareCard::sats(85_000)]));
    $this->get(route('tournaments.index'))->assertSee(__(':sats of :target sats pot', ['sats' => ShareCard::sats(50_000), 'target' => ShareCard::sats(85_000)]));
    // The TV lobby names each place's fixed sats, not a share of the balance.
    $this->get(route('tournaments.tv', $tournament))->assertOk()->assertSee('data-test="tv-pot"', false)
        ->assertSee(__(':sats sats', ['sats' => ShareCard::sats(25_000)]));

    // Funded; the organizer sees what is left over after prizes.
    $own->balanceMsats = 100_000_000;
    app(PotBalances::class)->read($tournament);
    expect(app(PrizePool::class)->funding($tournament->refresh()))->toBe(['goal' => 85_000, 'have' => 99_150, 'funded' => true, 'leftover' => 14_150]);
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pool-leftover"')->assertSee(PreSeason::formatSats(14_150));
    $this->get(route('tournaments.index'))->assertSee(__(':sats sats pot, funded', ['sats' => ShareCard::sats(100_000)]));
});

test('the prize mode switches freely before sign-up closes and is frozen after, percent mode unchanged', function () {
    fakeWallet();
    $own = ownPotWallet(10_000);
    $tournament = openTournament();
    $edit = fn () => Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()]);

    $edit()->set('potEnabled', true)->set('potUri', $own->uri('pay'))->call('usePotMode', 'fixed')->set('potFixed', [7_000])->call('savePotSettings')->assertSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_FIXED);

    $this->travel(3)->seconds();
    $edit()->call('usePotMode', 'percent')->call('usePotPreset', '60-30-10')->call('savePotSettings')->assertSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_PERCENT)
        ->and($tournament->prizeSplit())->toBe([60, 30, 10])
        ->and($tournament->prize_fixed)->toBeNull()
        ->and(app(PrizePool::class)->projection($tournament)[0])->toBe(['place' => 1, 'percent' => 60, 'sats' => intdiv(PrizePool::afterFeeReserve(10_000) * 60, 100)]);

    $this->travel(3)->seconds();
    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    $edit()->call('usePotMode', 'fixed')->set('potFixed', [5_000])->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeMode())->toBe(Tournament::PRIZES_PERCENT);
});

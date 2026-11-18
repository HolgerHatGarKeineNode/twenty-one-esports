<?php

use App\Models\Admin;
use App\Models\FairPlayBar;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\Bars;
use App\Support\FairPlay\FairPlay;
use App\Support\FairPlay\FairPlayRefused;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\LeagueTime;
use App\Support\Payouts\PayoutPlan;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\HyperOn;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Fair play: a bar set by hand
|--------------------------------------------------------------------------
|
| An admin bars one player from rated play with an internal reason and an
| end (a time, or the end of the live season) and lifts the bar again. The
| barred player is refused new rated play in every game and reads only that
| the league locked rated play and until when. Blocks and prizes stay.
|
*/

const BAR_REASON = 'Agreed results with clan mate, ticket 4711';

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    HyperOn::play();
});

function fbAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Referee']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** Rated chess and rated tables open: a live season, every player Trusted and connected. */
function fbRated(): void
{
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
}

test('only admins bar and lift, never for their own account, and a bar needs a reason and an end ahead', function () {
    $this->freezeSecond();
    [$player, $other] = User::factory()->count(2)->create();
    $admin = fbAdmin();
    $bars = app(Bars::class);
    $week = CarbonImmutable::now()->addWeek();

    expect(fn () => $bars->set($other, $player->pubkey, BAR_REASON, $week))->toThrow(FairPlayRefused::class, 'Only admins can bar players from rated play.')
        ->and(fn () => $bars->set($admin, $admin->pubkey, BAR_REASON, $week))->toThrow(FairPlayRefused::class, 'your own accounts')
        ->and(fn () => $bars->set($admin, $player->pubkey, '   ', $week))->toThrow(FairPlayRefused::class, 'Give a reason, up to 280 characters.')
        ->and(fn () => $bars->set($admin, $player->pubkey, str_repeat('a', 281), $week))->toThrow(FairPlayRefused::class, 'Give a reason')
        ->and(fn () => $bars->set($admin, 'not a key', BAR_REASON, $week))->toThrow(FairPlayRefused::class, 'Enter an npub')
        ->and(fn () => $bars->set($admin, $player->pubkey, BAR_REASON, CarbonImmutable::now()))->toThrow(FairPlayRefused::class, 'The end of the bar has to lie in the future.')
        // No season is live: "until the end of the season" has no end.
        ->and(fn () => $bars->set($admin, $player->pubkey, BAR_REASON))->toThrow(FairPlayRefused::class, 'No season is live')
        ->and(FairPlayBar::query()->count())->toBe(0);

    $bar = $bars->set($admin, $player->pubkey, '  '.BAR_REASON.' ', $week);

    expect($bar->only(['user_id', 'pubkey', 'reason', 'barred_by_id', 'barred_by_pubkey', 'lifted_at']))->toBe(['user_id' => $player->id, 'pubkey' => $player->pubkey, 'reason' => BAR_REASON, 'barred_by_id' => $admin->id, 'barred_by_pubkey' => $admin->pubkey, 'lifted_at' => null])
        ->and($bar->until->equalTo($week))->toBeTrue()
        ->and($bar->created_at?->equalTo(now()))->toBeTrue()
        ->and(fn () => $bars->set($admin, $player->pubkey, 'Again', $week))->toThrow(FairPlayRefused::class, 'is barred already, until '.LeagueTime::stamp($week))
        ->and(fn () => $bars->lift($other, $bar))->toThrow(FairPlayRefused::class, 'Only admins can bar players from rated play.')
        ->and(FairPlay::isBarred($player->pubkey))->toBeTrue();

    // The page and its actions are the admins' alone, also for an admin who lost the role with the page open.
    $this->actingAs($other)->get(route('admin.fair-play'))->assertForbidden();
    Livewire::actingAs($other)->test('pages::admin.fair-play')->assertForbidden();
    $page = Livewire::actingAs($admin)->test('pages::admin.fair-play');
    Admin::query()->where('pubkey', $admin->pubkey)->delete();
    $page->call('liftBar', $bar->id)->assertForbidden();
    Livewire::actingAs($admin->fresh())->test('pages::admin.fair-play')->assertForbidden();

    expect($bar->refresh()->lifted_at)->toBeNull();
});

test('a bar runs until its end to the second, and one without a date runs until the live season ends', function () {
    $this->freezeSecond();
    $season = openSeason(['slug' => 'season-1']);
    [$dated, $seasonal] = User::factory()->count(2)->create();
    $admin = fbAdmin();
    $until = CarbonImmutable::now()->addDays(3);

    app(Bars::class)->set($admin, $dated->pubkey, BAR_REASON, $until);
    $whole = app(Bars::class)->set($admin, $seasonal->pubkey, BAR_REASON);

    $barred = FairPlay::barred([$dated->pubkey, $seasonal->pubkey]);

    expect($barred[$dated->pubkey]['kind'])->toBe('barred')
        ->and($barred[$dated->pubkey]['until']?->equalTo($until))->toBeTrue()
        ->and($whole->until->equalTo($season->ends_at))->toBeTrue()
        ->and($barred[$seasonal->pubkey]['until']?->equalTo($season->ends_at))->toBeTrue();

    $this->travelTo($until->subSecond());
    expect(FairPlay::isBarred($dated->pubkey))->toBeTrue();

    // A bar whose end has passed bars nobody; the row stays in the record.
    $this->travelTo($until);
    expect(FairPlay::isBarred($dated->pubkey))->toBeFalse()
        ->and(FairPlay::isBarred($seasonal->pubkey))->toBeTrue()
        ->and(FairPlayBar::query()->count())->toBe(2);
});

test('a barred player is refused rated chess, rated tables and the gate with the neutral sentence; no page shows the internal reason; lifting restores play', function () {
    $this->freezeSecond();
    fbRated();
    [$player, $other] = User::factory()->count(2)->create();
    $admin = fbAdmin();

    expect(app(RatedChess::class)->refusal($player, 'blitz'))->toBeNull();

    $until = CarbonImmutable::now()->addDays(10);
    $bar = app(Bars::class)->set($admin, $player->pubkey, BAR_REASON, $until);
    $own = 'The league has locked rated play for your account until '.LeagueTime::stamp($until).'. Casual play stays open.';
    $this->actingAs($player);

    expect(app(RatedChess::class)->refusal($player, 'blitz'))->toBe($own)
        ->and(app(RatedTrustGate::class)->refusal([$player->pubkey, $other->pubkey], [$player->pubkey, $other->pubkey]))->toBe(RatedTrustGate::FAIR_PLAY)
        ->and(fn () => app(HyperLobby::class)->open($player, 2, HyperMatch::LIVE, 0))->toThrow(HyperRuleViolation::class, $own)
        ->and(fn () => app(HyperLobby::class)->join(app(HyperLobby::class)->open($other, 2, HyperMatch::LIVE, 0), $player))->toThrow(HyperRuleViolation::class, $own);

    // What the player is shown: the neutral sentence, and the reason nowhere.
    $this->get(route('chess.lobby'))->assertOk()->assertSee($own)->assertDontSee(BAR_REASON)->assertDontSee('4711');
    Livewire::actingAs($player)->test('hyper-lobby')->call('openTable')->assertSee($own)->assertDontSee(BAR_REASON)->assertDontSee('4711');
    $this->get(route('hyper.index'))->assertOk()->assertDontSee('4711');
    $this->get(route('home'))->assertOk()->assertDontSee('4711');

    // Another player reads that and until when, nothing else.
    $this->actingAs($other);
    expect(FairPlay::message($player->pubkey))->toBe($player->displayName().' cannot play rated until '.LeagueTime::stamp($until).'.');

    app(Bars::class)->lift($admin, $bar);

    expect(FairPlay::isBarred($player->pubkey))->toBeFalse()
        ->and(app(RatedChess::class)->refusal($player, 'blitz'))->toBeNull()
        ->and(app(HyperLobby::class)->open($player, 2, HyperMatch::LIVE, 0)->exists)->toBeTrue()
        ->and($bar->refresh()->only(['lifted_by_id', 'lifted_by_pubkey']))->toBe(['lifted_by_id' => $admin->id, 'lifted_by_pubkey' => $admin->pubkey])
        ->and($bar->lifted_at?->equalTo(now()))->toBeTrue()
        ->and(fn () => app(Bars::class)->lift($admin, $bar))->toThrow(FairPlayRefused::class, 'ended or was lifted already');
});

test('the admin page writes nothing until its button is pressed, then bars with the reason and the end, and lifts', function () {
    $this->freezeSecond();
    $season = openSeason(['slug' => 'season-1']);
    $player = User::factory()->create(['name' => 'Tilted']);
    $admin = fbAdmin();
    $until = CarbonImmutable::now()->addDays(5)->startOfMinute();

    $page = Livewire::actingAs($admin)->test('pages::admin.fair-play')
        ->assertSee('Nobody is barred by hand.')
        ->assertSee('Empty: until the end of the live season, '.LeagueTime::stamp($season->ends_at).'.')
        ->set('barPlayer', $player->pubkey)->set('barReason', BAR_REASON)->set('barUntil', LeagueTime::input($until))
        ->call('$refresh');

    // Filling the form and a roundtrip save nothing: only the button writes.
    expect(FairPlayBar::query()->count())->toBe(0)
        ->and(FairPlay::isBarred($player->pubkey))->toBeFalse();

    $page->set('barReason', ' ')->call('bar')
        ->assertSeeInOrder(['fair-play-bar-error', 'Give a reason, up to 280 characters.'], false)
        ->set('barReason', BAR_REASON)->set('barUntil', 'tomorrow-ish')->call('bar')
        ->assertSeeInOrder(['fair-play-bar-error', 'Enter a date and a time.'], false)
        ->set('barUntil', LeagueTime::input($until))->set('barPlayer', null)->call('bar')
        ->assertSeeInOrder(['fair-play-bar-player-error', 'Pick the player.'], false);

    expect(FairPlayBar::query()->count())->toBe(0);

    $page->set('barPlayer', $player->pubkey)->call('bar')
        ->assertSet('notice', 'Barred from rated play until '.LeagueTime::stamp($until).'.')
        ->assertSet('barReason', '')->assertSet('barPlayer', null)->assertSet('barUntil', '')
        ->assertSee('data-test="fair-play-bar-row" data-running="1"', false)
        ->assertSeeInOrder(['fair-play-bar-row', 'Tilted', 'no rated play until '.LeagueTime::stamp($until), 'Barred by Referee on '.LeagueTime::stamp(now()).': '.BAR_REASON, 'fair-play-bar-lift'], false);

    $bar = FairPlayBar::query()->sole();
    expect($bar->until->equalTo($until))->toBeTrue()
        ->and(FairPlay::isBarred($player->pubkey))->toBeTrue();

    $page->call('liftBar', $bar->id)
        ->assertSet('notice', 'Bar lifted. Rated play is open for this player again.')
        ->assertSee('data-test="fair-play-bar-row" data-running="0"', false)
        ->assertSeeInOrder(['fair-play-bar-row', 'Tilted', 'Lifted by Referee on '.LeagueTime::stamp(now())], false)
        ->assertDontSee('data-test="fair-play-bar-lift"', false)
        ->call('$refresh')->assertOk();

    expect(FairPlay::isBarred($player->pubkey))->toBeFalse();

    // An empty end bars until the live season ends.
    $page->set('barPlayer', $player->pubkey)->set('barReason', BAR_REASON)->call('bar')
        ->assertSet('notice', 'Barred from rated play until '.LeagueTime::stamp($season->ends_at).'.');
});

test('the admin page reads in German', function () {
    openSeason(['slug' => 'season-1']);

    $this->actingAs(fbAdmin())->get(route('admin.fair-play', ['lang' => 'de']))->assertOk()
        ->assertSee('Spieler für gewertete Partien sperren')
        ->assertSee('Grund (Pflicht, intern: der Spieler sieht ihn nie)')
        ->assertSee('Für gewertete Partien sperren')
        ->assertSee('Niemand ist von Hand gesperrt.');
});

test('a player barred by hand keeps his prize: payout planning leaves him in, with the same amounts', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    $plan = app(PayoutPlan::class)->compute($tournament, 99_000);
    $winner = $plan['rows'][0]['user'];

    app(Bars::class)->set(fbAdmin(), $winner->pubkey, BAR_REASON, CarbonImmutable::now()->addWeek());
    $after = app(PayoutPlan::class)->compute($tournament, 99_000);

    expect(FairPlay::isBarred($winner->pubkey))->toBeTrue()
        ->and(collect($after['rows'])->map(fn (array $row): array => [$row['user']->id, $row['place'], $row['amount']])->all())
        ->toBe(collect($plan['rows'])->map(fn (array $row): array => [$row['user']->id, $row['place'], $row['amount']])->all())
        ->and($after['remainder'])->toBe($plan['remainder']);
});

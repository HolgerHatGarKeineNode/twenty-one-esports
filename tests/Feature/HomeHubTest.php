<?php

use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Tournaments\NoTournamentPrizePool;
use App\Support\Tournaments\TournamentPrizePool;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSigner;

/*
 * Home as the league's hub (2026-09-27): the hero is the soonest open
 * tournament with its real entrants and, only when the league has one, its
 * pot; without an open tournament the games are the hero. Guests and
 * players see their own calls to action, and the number of queries does not
 * grow with the number of entrants, games, results or ladder rows.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** `$count` active solo entries, written as the sign-up writes them. */
function homeEntrants(Tournament $tournament, int $count): void
{
    foreach (User::factory()->count($count)->create() as $user) {
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }
}

/** The number of occurrences of an attribute value in the page. */
function homeCount(string $html, string $needle): int
{
    return substr_count($html, $needle);
}

test('the hero is the soonest open tournament, the others open ones sit under it', function () {
    $later = openTournament(['name' => 'Later Cup', 'starts_at' => now()->addDays(9)]);
    $soonest = openTournament(['name' => 'Soonest Cup', 'starts_at' => now()->addDays(2)]);
    $middle = openTournament(['name' => 'Middle Cup', 'starts_at' => now()->addDays(5)]);
    // Sooner, but not open: a draft, a closed sign-up, a running and a cancelled tournament.
    Tournament::factory()->create(['name' => 'Draft Cup', 'starts_at' => now()->addDay()->addHour()]);
    openTournament(['name' => 'Closed Cup', 'starts_at' => now()->addDay()->addHour()])->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    openTournament(['name' => 'Cancelled Cup', 'starts_at' => now()->addDay()->addHour()])->forceFill(['status' => TournamentStatus::Cancelled])->save();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('data-test="home-hero" data-tournament="'.$soonest->id.'"')
        ->toContain(route('tournaments.signup', $soonest))
        ->toContain('data-test="play-now"')
        ->not->toContain('Draft Cup')
        ->not->toContain('Closed Cup')
        ->not->toContain('Cancelled Cup')
        ->not->toContain('data-stage')
        ->and(homeCount($html, 'data-test="hero-more-cup"'))->toBe(2)
        ->and(strpos($html, 'Middle Cup'))->toBeLessThan(strpos($html, 'Later Cup'))
        ->and(strpos($html, 'Soonest Cup'))->toBeLessThan(strpos($html, 'Middle Cup'));
});

test('the seats show only the real entrants, withdrawn ones not', function () {
    $tournament = openTournament(['capacity' => 12]);
    homeEntrants($tournament, 5);
    TournamentSignup::query()->latest('id')->first()->forceFill(['withdrawn_at' => now()])->save();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(homeCount($html, 'data-test="hero-seat"'))->toBe(4)
        ->and($html)->toContain('data-count="4" data-test="hero-taken">4</b>')
        ->toContain(__(':taken of :places spots taken', ['taken' => 4, 'places' => 12]))
        // Eight open seats, the first of them the way in.
        ->and(homeCount($html, 'class="hh-seat is-open"'))->toBe(8)
        ->and(homeCount($html, 'data-test="hero-open-seat"'))->toBe(1);
});

test('a big field ends in one "+N" seat', function () {
    $tournament = openTournament(['capacity' => 40]);
    homeEntrants($tournament, 30);

    $html = $this->get(route('home'))->assertOk()->getContent();

    // 24 tiles: 23 faces and "+17" (7 more entrants, 10 open places).
    expect(homeCount($html, 'data-test="hero-seat"'))->toBe(23)
        ->and($html)->toContain('data-test="hero-seats-rest">+17</li>');
});

test('the pot shows only when the league has a pool for the tournament', function () {
    $tournament = openTournament();

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-test="hero-pot"');

    $tournament->forceFill([
        'pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 210_000,
        'pot_balance_at' => now(), 'prize_target_sats' => 500_000,
    ])->save();

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="hero-pot"', false)
        ->assertSee(__(':sats of :target sats pot', ['sats' => ShareCard::sats(210_000), 'target' => ShareCard::sats(500_000)]));

    // The seam decides: a pool the league does not report is not shown.
    app()->bind(TournamentPrizePool::class, NoTournamentPrizePool::class);

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-test="hero-pot"');
});

test('without an open tournament the games are the hero and Block 0 is a strip under them', function () {
    Tournament::factory()->create(['name' => 'Draft Cup']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-test="home-hero"')
        ->toMatch('/data-test="play-now"\s+data-stage/')
        ->toContain('data-test="block0-strip"')
        ->and(homeCount($html, 'data-test="play-tile"'))->toBe(4)
        ->and(strpos($html, 'data-test="play-now"'))->toBeLessThan(strpos($html, 'data-test="block0-strip"'));
});

test('a guest gets the logins, a player their matches and their seat', function () {
    $tournament = openTournament();
    [$player, $signer] = keyedPlayer();
    soloSignup($tournament, $player, $signer);
    $rival = User::factory()->create();
    ChessGame::factory()->create(['white_id' => $player->id, 'black_id' => $rival->id]);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="play-login"', false)
        ->assertSee('href="'.route('login').'" class="btn-p', false)
        ->assertSee(__('Sign up'))
        ->assertDontSee('data-test="your-next"', false);

    $this->actingAs($player)->get(route('home'))->assertOk()
        ->assertDontSee('data-test="play-login"', false)
        ->assertSee('data-test="your-next"', false)
        ->assertSee(__('You’re in'))
        ->assertSee('hh-seat is-taken is-you', false)
        ->assertSee('action="'.route('notify.block0').'"', false);
});

test('the parts with nothing live say so', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('data-test="live-empty"')
        ->toContain(__('No results yet. The first win lands here.'))
        ->toContain(__('Nobody on it yet. One result puts you on top.'))
        ->not->toContain('data-test="featured-board"')
        ->not->toContain('data-test="running-tournament"');
});

test('results, live boards, newcomers and the top of the ladders come from the league', function () {
    $winner = User::factory()->create(['name' => 'zapmaster']);
    $loser = User::factory()->create(['name' => 'kempten.k']);
    ChessGame::factory()->finished('0-1')->create(['white_id' => $loser->id, 'black_id' => $winner->id]);
    ChessGame::factory()->create(['white_id' => $winner->id, 'black_id' => $loser->id]);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$winner->id, 'user_id' => $winner->id, 'rating' => 1234, 'results' => 3]);
    Clan::factory()->create(['name' => 'Laser Eyes']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('<b>zapmaster</b> '.e(__('beat :name', ['name' => 'kempten.k'])))
        ->toContain('data-test="featured-board"')
        ->toContain('Laser Eyes')
        ->and(homeCount($html, 'data-test="ladder-row"'))->toBe(1)
        ->and($html)->toContain('1234');
});

test('the queries do not grow with entrants, boards, results, newcomers or ladder rows', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('home'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $grow = function (int $size): void {
        foreach (Tournament::query()->get() as $tournament) {
            homeEntrants($tournament, $size);
        }

        foreach (range(1, $size) as $index) {
            $white = User::factory()->create();
            $black = User::factory()->create();
            ChessGame::factory()->create(['white_id' => $white->id, 'black_id' => $black->id]);
            ChessGame::factory()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]);
            Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$white->id, 'user_id' => $white->id, 'rating' => 1000 + $index, 'results' => 1]);
            Clan::factory()->create();
        }
    };

    openTournament(['capacity' => 32, 'starts_at' => now()->addDays(2)]);
    openTournament(['capacity' => 32, 'starts_at' => now()->addDays(3)]);
    openTournament(['capacity' => 32, 'starts_at' => now()->addDays(4)])->forceFill([
        'pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 1_000, 'pot_balance_at' => now(),
    ])->save();

    $grow(2);
    $this->get(route('home'))->assertOk();
    $few = $queries();

    $grow(8);
    $many = $queries();

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(HOME_QUERY_BUDGET);
});

/** Measured 2026-09-27 for a guest with three open tournaments (one with a pot): 46 queries, shell included; the rest is headroom for the shell. */
const HOME_QUERY_BUDGET = 50;

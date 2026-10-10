<?php

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
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

test('the hero is the soonest open tournament, the others open ones follow it in this week\'s list', function () {
    $later = openTournament(['name' => 'Later Cup', 'starts_at' => now()->addDays(9)]);
    $soonest = openTournament(['name' => 'Soonest Cup', 'starts_at' => now()->addDays(2)]);
    $middle = openTournament(['name' => 'Middle Cup', 'starts_at' => now()->addDays(5)]);
    // Sooner, but not open: a draft, a closed sign-up, a running and a cancelled tournament.
    Tournament::factory()->create(['name' => 'Draft Cup', 'starts_at' => now()->addDay()->addHour()]);
    openTournament(['name' => 'Closed Cup', 'starts_at' => now()->addDay()->addHour()])->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    openTournament(['name' => 'Cancelled Cup', 'starts_at' => now()->addDay()->addHour()])->forceFill(['status' => TournamentStatus::Cancelled])->save();

    $html = $this->get(route('home'))->assertOk()->getContent();
    $week = str($html)->after('data-test="home-week"')->toString();

    expect($html)->toContain('data-test="home-hero" data-tournament="'.$soonest->id.'"')
        ->toContain(route('tournaments.signup', $soonest))
        ->not->toContain('Draft Cup')
        ->not->toContain('Closed Cup')
        ->not->toContain('Cancelled Cup')
        ->and(homeCount($week, 'data-featured'))->toBe(3)
        ->and(strpos($week, 'Soonest Cup'))->toBeLessThan(strpos($week, 'Middle Cup'))
        ->and(strpos($week, 'Middle Cup'))->toBeLessThan(strpos($week, 'Later Cup'));
});

test('the seats show only the real entrants, withdrawn ones not', function () {
    $tournament = openTournament(['capacity' => 12]);
    homeEntrants($tournament, 5);
    TournamentSignup::query()->latest('id')->first()->forceFill(['withdrawn_at' => now()])->save();

    $hero = str($this->get(route('home'))->assertOk()->getContent())->after('data-test="home-hero"')->before('data-test="live-bar"')->toString();

    // One square per place (Main.dc.html `.seg`): four taken, eight free, and the numbers beside them.
    expect($hero)->toContain('aria-label="'.__(':taken of :places places taken', ['taken' => 4, 'places' => 12]).'"')
        ->toContain('data-test="hero-taken">4/12</span>')
        ->and(homeCount(str($hero)->after('data-test="seats"')->before('</span>')->toString(), '<i class="is-on">'))->toBe(4);
});

test('a big field keeps sixteen squares, filled in proportion', function () {
    $tournament = openTournament(['capacity' => 40]);
    homeEntrants($tournament, 30);

    $hero = str($this->get(route('home'))->assertOk()->getContent())->after('data-test="home-hero"')->toString();
    $seats = str($hero)->after('data-test="seats"')->before('</span>')->toString();

    expect(homeCount($seats, '<i'))->toBe(16)
        ->and(homeCount($seats, '<i class="is-on">'))->toBe(12)
        ->and($hero)->toContain('data-test="hero-taken">30/40</span>');
});

test('the pot shows only when the league has a pool for the tournament', function () {
    $tournament = openTournament();

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-test="hero-pot"');

    $tournament->forceFill([
        'pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 210_000,
        'pot_balance_at' => now(), 'prize_target_sats' => 500_000,
    ])->save();

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="hero-pot">500,000 Sats</span>', false);

    // The seam decides: a pool the league does not report is not shown.
    app()->bind(TournamentPrizePool::class, NoTournamentPrizePool::class);

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-test="hero-pot"');
});

test('without an open tournament or a Blockfill week there is no band; the mempool, the games and the season lead', function () {
    Tournament::factory()->create(['name' => 'Draft Cup']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-test="home-hero"')
        ->toContain('data-test="home-mempool"')
        ->toContain('data-test="home-season"')
        ->and(homeCount($html, 'data-test="game-tile"'))->toBe(count(app(GameRegistry::class)->all()))
        ->and(strpos($html, 'data-test="home-mempool"'))->toBeLessThan(strpos($html, 'data-test="home-games"'));
});

test('a guest gets the logins, a player their seat, their week and the Block 0 notice', function () {
    $tournament = openTournament();
    [$player, $signer] = keyedPlayer();
    soloSignup($tournament, $player, $signer);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="home-join"', false)
        ->assertSee('x-on:click="loginWithGoogle()"', false)
        ->assertSee(__('Enter the tournament'))
        ->assertDontSee('data-test="home-your-week"', false);

    $this->actingAs($player)->get(route('home'))->assertOk()
        ->assertDontSee('data-test="home-join"', false)
        ->assertSee('data-test="home-your-week"', false)
        ->assertSee(__('You are entered'))
        ->assertSee('action="'.route('notify.block0').'"', false);
});

test('with nothing played yet the page says so and leaves the bar and the proud moments out', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain(__('No match in the mempool yet.'))
        ->not->toContain('data-test="live-bar"')
        ->not->toContain('data-test="pride-row"')
        ->not->toContain('data-test="mempool-chain"');
});

test('the bar and the chain come from the league: a result, a move and the games behind them', function () {
    $winner = User::factory()->create(['name' => 'zapmaster']);
    $loser = User::factory()->create(['name' => 'kempten.k']);
    ChessGame::factory()->finished('0-1')->create(['white_id' => $loser->id, 'black_id' => $winner->id, 'ended_at' => now()->subMinutes(5)]);
    $running = ChessGame::factory()->create(['white_id' => $winner->id, 'black_id' => $loser->id, 'ply' => 1]);
    ChessMove::query()->create(['chess_game_id' => $running->id, 'ply' => 1, 'uci' => 'e2e4', 'san' => 'e4', 'fen' => ChessGame::START_FEN, 'spent_ms' => 900, 'clock_ms' => 300_000, 'created_at' => now()]);

    $html = $this->get(route('home'))->assertOk()->getContent();
    $bar = str($html)->after('data-test="live-bar"')->before('data-test="home-grid"')->toString();

    expect($bar)->toContain(e(__(':player played :move in :game', ['player' => 'zapmaster', 'move' => '1. e4', 'game' => $running->number === null ? 'Chess' : $running->number()])))
        ->toContain('zapmaster beats kempten.k')
        ->and(homeCount($html, 'data-test="chain-cube"'))->toBe(2);
});

test('the queries do not grow with entrants, boards, results, newcomers or ladder rows', function () {
    $queries = function (): int {
        // Warm: a new player or clan forgets the cached newcomers (HomeHub::newcomers()), the next visit refills it.
        test()->get(route('home'))->assertOk();
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

    // One game less must not be one query less: the live season is kept per request (Seasons::live()).
    app()->instance(GameRegistry::class, new GameRegistry(array_slice(array_values(app(GameRegistry::class)->all()), 0, -1)));
    $fewerGames = $queries();
    app()->forgetInstance(GameRegistry::class);

    expect($many)->toBe($few)->and($fewerGames)->toBe($many)->and($few)->toBeLessThanOrEqual(HOME_QUERY_BUDGET);
});

/**
 * Measured 2026-09-27 for a guest with three open tournaments (one with a pot): 46 queries, shell included; the rest is headroom for the shell.
 * 51 since 2026-09-28 (P44): the league settings in force are one more query per request (LeagueSettings::overrides()), the headroom was used up.
 * 52 on 2026-09-30 (Age of Empires II): the strongest players asked Seasons::live() once per game. Back to 51 on
 * 2026-10-01: the live season is kept per request, so a new game adds no query.
 */
const HOME_QUERY_BUDGET = 51;

test('home and the login render twice from a cache that unserializes no objects, as production\'s Redis does', function () {
    // config/cache.php sets serializable_classes = false: a cached Carbon comes back as __PHP_Incomplete_Class.
    // The test store keeps values unserialized; this one serializes like Redis, so the second request reads it back.
    config(['cache.stores.array.serialize' => true, 'cache.serializable_classes' => false]);
    app('cache')->forgetDriver('array');
    planBlock0(now()->addDays(5)->setTime(21, 0)->toIso8601String());

    foreach ([1, 2] as $request) {
        $this->get(route('home'))->assertOk()->assertSee('data-test="home-season"', false);
        $this->get(route('login'))->assertOk();
    }
});

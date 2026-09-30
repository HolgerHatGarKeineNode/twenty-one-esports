<?php

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Matches\MempoolStrip;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CheckersGame;
use Tests\Support\FakeGame;
use Tests\Support\NineMensMorrisOn;

/*
| The lists behind the shell navigation (header concept B): "your games"
| order, the active game of a page, the /play page and "Start playing".
| The rendered bars are measured in tests/Browser/ShellNavigationTest.php.
*/

test('your games come first, by the last match, then the registry order; guests get the registry order', function () {
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    ChessGame::factory()->create(['white_id' => $player->id, 'created_at' => now()->subDays(3)]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'created_at' => now()->subDay()]);
    $registryOrder = array_keys(app(GameRegistry::class)->all());

    $this->actingAs($player)->get('/clans')->assertOk()
        ->assertSeeInOrder(['data-test="game-tab-rocket-league"', 'data-test="game-tab-chess"'], false)
        ->assertSeeInOrder(['data-test="hub-game-rocket-league"', 'data-test="hub-yours"', 'data-test="hub-game-chess"', 'data-test="hub-yours"', 'data-test="hub-game-ea-sports-fc-27"'], false);

    auth()->logout();
    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toBe($registryOrder);
});

test('the viewer\'s games cost two queries, however many matches there are', function () {
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    SeriesMatch::factory()->accepted()->count(3)->create(['challenger_lineup_id' => $lineup->id]);
    ChessGame::factory()->count(3)->create(['white_id' => $player->id]);
    $this->actingAs($player);

    DB::flushQueryLog();
    DB::enableQueryLog();
    ShellNavigation::current()->games();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count(array_filter(array_column($queries, 'query'), fn (string $sql) => str_contains($sql, 'chess_games') || str_contains($sql, 'series_matches'))))->toBe(2);
});

test('row 2 names the game of the page, else the game opened last, else the first registered game', function () {
    $player = User::factory()->create();
    $this->actingAs($player);
    $contextOf = fn (string $uri): string => str($this->get($uri)->assertOk()->getContent())->match('/data-test="context-bar" data-game="([^"]+)"/')->toString();

    expect($contextOf('/clans'))->toBe('chess')
        ->and($contextOf(route('games.series', 'ea-sports-fc-26')))->toBe('ea-sports-fc-26')
        ->and($contextOf('/clans'))->toBe('ea-sports-fc-26')
        ->and($contextOf(route('matches.index', ['game' => 'rocket-league'])))->toBe('rocket-league')
        ->and($contextOf(route('ladder.show', ['chess', 'blitz'])))->toBe('chess')
        ->and($contextOf(route('matches.index', ['game' => 'not-a-game'])))->toBe('chess');
});

test('a match page and its room belong to the game of the match, not to the game opened last', function () {
    $series = SeriesMatch::factory()->create();

    $this->get(route('matches.show', $series->number))->assertOk()
        ->assertSee('data-test="context-bar" data-game="'.$series->game.'"', false);

    // The room binds {match} to the model before the header reads it.
    foreach (['rocket-league' => '3v3', 'age-of-empires-2' => '1v1'] as $game => $mode) {
        $match = SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => Lineup::factory()->game($game, $mode)->ready()]);
        $this->actingAs($match->challengerLineup->clan->owner)->get(route('ladder.show', ['chess', 'blitz']))->assertOk();

        $this->get(route('matches.room', $match->number))->assertOk()
            ->assertSee('data-test="context-bar" data-game="'.$game.'"', false);
    }
});

test('the Season item carries the Block 0 tag before the first season', function () {
    $this->get('/rules')->assertOk()
        ->assertSeeInOrder(['data-test="nav-mining"', 'Season', 'data-test="season-tag"', 'Block 0 soon'], false);
});

/*
| The chain rail of row 1 (plan "Mempool-Streifen", P4): Mempool with how many
| matches wait in it, the season chain, the casual matches. Measured at every
| width in tests/Browser/ShellNavigationWidthsTest.php.
*/

test('row 1 and the phone\'s More sheet link the mempool with how many matches of every game wait in it, the season chain and the casual matches', function () {
    NineMensMorrisOn::play();
    // Waiting: scheduled or playing, reported and disputed series; running chess and board games.
    SeriesMatch::factory()->accepted()->create(['start_at' => now()->addHour()]);
    SeriesMatch::factory()->accepted()->create(['start_at' => now()->subMinutes(5)]);
    SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Reported]);
    SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Disputed]);
    ChessGame::factory()->create();
    mempoolBoard(NineMensMorris::SLUG, ['ply' => 4]);
    // Not waiting: an open challenge, a finished series, finished and aborted games.
    SeriesMatch::factory()->create();
    mempoolSeries();
    ChessGame::factory()->finished()->create();
    ChessGame::factory()->create(['status' => ChessGameStatus::Aborted]);
    mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()]);

    $html = $this->get('/rules')->assertOk()->getContent();

    expect($html)->toContain('aria-label="Mempool and chains"')
        ->toMatch('/href="'.preg_quote(route('matches.index'), '/').'"\s+aria-label="Mempool, 6 matches waiting"[^>]*data-test="nav-mempool"/')
        ->toMatch('/data-test="mempool-count">6</')
        ->toMatch('/href="'.preg_quote(route('mining'), '/').'"\s+aria-label="Season chain, Block 0 soon"[^>]*data-test="nav-mining"/')
        ->toMatch('/href="'.preg_quote(route('matches.index', ['chain' => 'casual']), '/').'"\s+aria-label="Casual chain"[^>]*data-test="nav-casual"/')
        // The same three under Everywhere on phones, in the same order after Clans.
        ->and(str($html)->after('data-test="more-sheet"')->toString())->toMatch('/data-test="mobile-clans".*data-test="mobile-mempool".*data-test="mobile-season".*data-test="mobile-casual"/s')
        ->toMatch('/data-test="mobile-mempool-count">6</')
        ->and(ShellNavigation::current()->chain()[0]['count'])->toBe(6)
        ->and(MempoolStrip::waiting())->toBe(6);
});

test('the mempool count is cached for a minute like the Tournaments badge, and shows no badge at zero', function () {
    $html = $this->get('/rules')->assertOk()->getContent();
    expect($html)->toContain('aria-label="Mempool"')->not->toContain('data-test="mempool-count"')->not->toContain('data-test="mobile-mempool-count"');

    // A new game shows once the cached count runs out, not before.
    ChessGame::factory()->count(2)->create();
    expect($this->get('/rules')->assertOk()->getContent())->not->toContain('data-test="mempool-count"')
        ->and(Cache::get(ShellNavigation::MEMPOOL_KEY))->toBe(0);

    $this->travel(61)->seconds();
    expect($this->get('/rules')->assertOk()->getContent())->toMatch('/data-test="mempool-count">2</');
});

test('the mempool count leaves out the board games while they are switched off or their route is missing', function () {
    ChessGame::factory()->create();
    NineMensMorrisOn::play();
    mempoolBoard(NineMensMorris::SLUG);
    expect(MempoolStrip::waiting())->toBe(2);

    // Switched off: the registry has no board game.
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    expect(MempoolStrip::waiting())->toBe(1);

    // Switched on, but routed nowhere (a route table cached with the switch off): still left out.
    config(['esports.board_games.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
    expect(MempoolStrip::waiting())->toBe(2);
    $routes = app('router')->getRoutes();
    $kept = new RouteCollection;
    foreach ($routes->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'board.')) {
            $kept->add($route);
        }
    }
    app('router')->setRoutes($kept);
    expect(Route::has('board.show'))->toBeFalse()
        ->and(MempoolStrip::waiting())->toBe(1);
    $this->get('/rules')->assertOk()->assertSee('aria-label="Mempool, 1 match waiting"', false);
});

test('the mempool count follows each board game\'s own switch: one on, one off', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    mempoolBoard(NineMensMorris::SLUG);
    mempoolBoard(Checkers::SLUG);
    mempoolBoard(Checkers::SLUG);
    expect(MempoolStrip::waiting())->toBe(3);

    // Checkers off, nine men's morris on: the checkers games stay in the table, but out of the count.
    config(['esports.board_games.games.'.Checkers::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    expect(MempoolStrip::boardSlugs())->toBe([NineMensMorris::SLUG])
        ->and(MempoolStrip::waiting())->toBe(1);
});

test('the mempool count costs the same queries for 1, 5 and 25 waiting matches of every kind', function () {
    NineMensMorrisOn::play();
    $seed = function (int $count): void {
        SeriesMatch::factory()->accepted()->count($count)->create();
        ChessGame::factory()->count($count)->create();
        foreach (range(1, $count) as $i) {
            mempoolBoard(NineMensMorris::SLUG, ['ply' => $i]);
        }
    };
    $count = function (): array {
        Cache::forget(ShellNavigation::MEMPOOL_KEY);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $waiting = ShellNavigation::current()->chain()[0]['count'];
        DB::disableQueryLog();

        return [$waiting, count(DB::getQueryLog())];
    };

    $seed(1);
    [$one, $queriesOne] = $count();
    $seed(4);
    [$five, $queriesFive] = $count();
    $seed(20);
    [$twentyFive, $queriesTwentyFive] = $count();

    // One count per kind (series, chess, board games), and the season state for the Season tag.
    expect([$one, $five, $twentyFive])->toBe([3, 15, 75])
        ->and($queriesFive)->toBe($queriesOne)
        ->and($queriesTwentyFive)->toBe($queriesOne)
        ->and($queriesOne)->toBeLessThanOrEqual(5);
});

test('the rail marks the page on screen: Mempool on /matches, Casual on the casual view, Season on /mining, none on a game\'s own list', function () {
    $current = fn (string $uri): array => array_values(array_filter(
        ['mempool', 'mining', 'casual'],
        fn (string $key): bool => (bool) preg_match('/aria-current="page"[^>]*data-test="nav-'.$key.'"/', $this->get($uri)->assertOk()->getContent()),
    ));

    expect($current(route('matches.index')))->toBe(['mempool'])
        ->and($current(route('matches.index', ['chain' => 'casual'])))->toBe(['casual'])
        ->and($current(route('mining')))->toBe(['mining'])
        ->and($current(route('matches.index', ['game' => 'chess'])))->toBe([])
        ->and($current('/rules'))->toBe([]);
});

test('/play lists every registered game with its modes, public, with "Log in to play" only for guests', function () {
    $games = app(GameRegistry::class)->all();

    $guest = $this->get(route('play'))->assertOk()->assertSee('data-test="play-login"', false);
    foreach ($games as $slug => $game) {
        $guest->assertSee('data-test="play-game-'.$slug.'"', false);
        foreach ($game->modes() as $mode) {
            $guest->assertSee(e($mode->name), false);
        }
    }

    $this->actingAs(User::factory()->create())->get(route('play'))->assertOk()
        ->assertDontSee('data-test="play-login"', false)
        ->assertSee('href="'.route('chess.challenge').'"', false);
});

test('"Start playing" logs a guest in and lands in the chess lobby; a plain login keeps its default', function () {
    $this->get(route('login', ['then' => 'play']))->assertOk()->assertSessionHas('url.intended', route('chess.lobby'));

    $this->flushSession();
    $this->get(route('login'))->assertOk()->assertSessionMissing('url.intended');
    $this->get(route('login', ['then' => 'https://evil.example']))->assertOk()->assertSessionMissing('url.intended');
});

test('a test-only registry of 12 games renders a hub tile for each, and the real registry has none of them', function () {
    app()->instance(GameRegistry::class, FakeGame::registry(12));
    $html = $this->get('/rules')->assertOk()->getContent();
    app()->forgetInstance(GameRegistry::class);

    expect(substr_count($html, 'data-test="hub-game-'))->toBe(12)
        ->and(array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug) => str_starts_with($slug, 'fake-')))->toBe([]);
});

test('Tournaments in row 1 and the tab bar counts the tournaments open for sign-up, and shows no badge when none is', function () {
    // Not open: a draft, and a sign-up whose deadline has passed.
    Tournament::factory()->create();
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->subHour()]);

    $this->get('/rules')->assertOk()
        ->assertSee('data-test="nav-tournaments"', false)
        ->assertDontSee('data-test="tournaments-open"', false)
        ->assertDontSee('data-test="tab-tournaments-dot"', false);

    Tournament::factory()->signup()->count(2)->create(['signup_closes_at' => now()->addDay()]);
    Cache::forget(ShellNavigation::OPEN_TOURNAMENTS_KEY);

    $html = $this->get('/rules')->assertOk()->getContent();
    expect($html)->toMatch('/data-test="tournaments-open"><span class="sr-only">, <\/span>2<span class="sr-only"> open for sign-up<\/span>/')
        ->toContain('aria-label="Tournaments, 2 open for sign-up"')
        ->toContain('data-test="tab-tournaments-dot"')
        ->and(ShellNavigation::current()->tournaments()['open'])->toBe(2);
});

test('the active game holds the first tab, so narrow widths that hide the last tabs still show it', function () {
    $html = $this->get(route('games.series', 'ea-sports-fc-26'))->assertOk()->getContent();

    preg_match_all('/data-test="game-tab-([a-z0-9-]+)"/', $html, $tabs);

    expect($tabs[1][0] ?? null)->toBe('ea-sports-fc-26')
        ->and(array_unique($tabs[1]))->toHaveCount(count($tabs[1]));
});

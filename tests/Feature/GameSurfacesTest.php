<?php

use App\Enums\BoardEndReason;
use App\Games\Blockfill;
use App\Games\Blockli;
use App\Games\Checkers;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\NineMensMorris;
use App\Games\ScoreGame;
use App\Games\TrackmaniaNationsForever;
use App\Models\BoardGame;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\CupBoard;
use App\Support\TwentyOne\Stream\MempoolLayout;
use App\Support\TwentyOne\Stream\MempoolSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeGame;
use Tests\Support\FakeScoreGame;
use Tests\Support\HyperOn;

/*
|--------------------------------------------------------------------------
| Every registered game on every surface that lists games
|--------------------------------------------------------------------------
|
| A new game is a class plus a registry entry (App\Games\GameRegistry); every
| page that lists games reads the registry, so the game shows everywhere with
| no further change. Three times on 2026-10-01 a new game (Blockfill) was
| missing from a surface that kept its own list: /matches, home and the
| player page. This guard turns every switch on (board games, Blockfill,
| TrackMania Nations Forever, the score demo), registers a made-up versus
| game and a made-up score game next to the real ones (Hyperbitcoinization
| among them since its P6), and asserts that each
| game shows on each surface.
| A surface that hard-codes its games misses at least the made-up ones and
| fails here, naming the surface and the games it lost.
|
| A surface that is legitimately about one game is listed in
| GAME_SURFACES_EXEMPT with the reason.
|
*/

/** Surfaces that are about one game by design, with the reason. */
const GAME_SURFACES_EXEMPT = [
    'games.index' => '/games is "Live games": the chess boards running now (blitz and daily) to watch. Other games have no live board there.',
];

beforeEach(function () {
    $this->withoutVite();

    config([
        'esports.board_games.enabled' => true,
        'esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => true,
        'esports.board_games.games.'.Checkers::SLUG.'.enabled' => true,
        'esports.board_games.games.'.Blockli::SLUG.'.enabled' => true,
        'esports.blockfill.enabled' => true,
        'esports.score_games.demo' => true,
        'esports.tmnf.enabled' => true,
        'esports.hyper.enabled' => true,
    ]);
    app()->forgetInstance(GameRegistry::class);

    // As routes/web.php routes them when the switches are on at boot (the test app boots with them off).
    foreach (['board.show' => 'board.php', 'stacker.runs.issue' => 'stacker.php', 'scores.show' => 'score.php', 'hyper.index' => 'hyper.php'] as $name => $file) {
        if (! Route::has($name)) {
            Route::middleware('web')->group(base_path('routes/'.$file));
        }
    }

    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();

    // A game added later: one versus game, one score game, registered after the real ones and ordered as
    // AppServiceProvider orders the registry, so they stand before the games the config keeps last.
    app()->instance(GameRegistry::class, new GameRegistry(GameRegistry::ordered([
        ...array_values(app(GameRegistry::class)->all()),
        new FakeGame('fake-arena', 'Fake Arena'),
        new FakeScoreGame,
    ], (array) config('esports.game_order.first'), (array) config('esports.game_order.last'))));
});

/**
 * A player with one result in every registered game: a casual rating in the
 * first mode of a versus game, a verified attempt in the first mode of a score game,
 * a finished Hyperbitcoinization match.
 */
function gameSurfacesPlayer(): User
{
    $player = User::factory()->create(['name' => 'Every Game Pleb']);

    foreach (app(GameRegistry::class)->all() as $slug => $game) {
        $mode = (string) array_key_first($game->modes());

        // Hyperbitcoinization (plan "Hyperbitcoinization", P6) keeps no Rating: a finished match puts it on the page.
        if ($game->kind() === GameKind::Strategy) {
            HyperOn::finishTable(HyperOn::versus($player, User::factory()->create()), [0, 1]);

            continue;
        }

        if ($game instanceof ScoreGame) {
            ScoreRun::query()->create([
                'user_id' => $player->id, 'game' => $slug, 'mode' => $mode, 'course' => $mode, 'value' => 15_966,
                'unit' => $game->metric($game->modes()[$mode])->unit, 'source' => ScoreRun::MANUAL,
                'achieved_at' => now()->subHour(), 'verified_at' => now()->subMinutes(30),
            ]);

            continue;
        }

        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $slug, 'mode' => $mode, 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
            'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
    }

    return $player;
}

/**
 * The registered games a surface does not show, by the slugs it carries.
 *
 * @param  list<string>  $shown
 * @return list<string>
 */
function gameSurfacesMissing(array $shown): array
{
    return array_values(array_diff(array_keys(app(GameRegistry::class)->all()), $shown));
}

/**
 * The values of `$attribute` on the elements whose `data-test` is one of `$tests`.
 *
 * @param  list<string>  $tests
 * @return list<string>
 */
function gameSurfacesAttribute(string $html, array $tests, string $attribute = 'data-game'): array
{
    preg_match_all('#<[a-z]+[^>]*data-test="(?:'.implode('|', array_map(preg_quote(...), $tests)).')"[^>]*>#', $html, $tags);
    $values = [];

    foreach ($tags[0] as $tag) {
        if (preg_match('#\s'.preg_quote($attribute).'="([^"]+)"#', $tag, $value) === 1) {
            $values[] = html_entity_decode($value[1]);
        }
    }

    return array_values(array_unique($values));
}

/**
 * Assert that a surface lists its games in the registry's display order
 * (user 2026-10-03: Nine Men's Morris and Checkers "überall ganz nach
 * hinten"): the slugs it shows, in page order, are the registry's slugs
 * that it shows, and the games the config keeps last close the list.
 *
 * @param  list<string>  $shown
 */
function gameSurfacesInOrder(array $shown, string $surface): void
{
    $registered = array_keys(app(GameRegistry::class)->all());
    $shown = array_values(array_intersect(array_values(array_unique($shown)), $registered));
    $last = array_values(array_intersect((array) config('esports.game_order.last'), $shown));

    expect($shown)->toBe(array_values(array_intersect($registered, $shown)), "{$surface} lists its games out of the registry order");

    if ($last !== []) {
        expect(array_slice($shown, -count($last)))->toBe($last, "{$surface} does not end with ".implode(', ', $last));
    }
}

/** A finished board game of the player, created at `$at`. */
function gameSurfacesBoardGame(string $game, User $player, CarbonInterface $at, ?User $rival = null): void
{
    BoardGame::query()->create(['game' => $game, 'mode' => 'blitz', 'white_id' => $player->id, 'black_id' => ($rival ?? User::factory()->create())->id, 'status' => 'finished', 'result' => '1-0',
        'end_reason' => BoardEndReason::Forfeit->value, 'position' => '-', 'turn' => 'w', 'ply' => 0, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1,
        'turn_started_ms' => 0, 'ended_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
}

test('the registry under test holds every kind of game, the made-up ones included', function () {
    $registry = app(GameRegistry::class);

    // Not a surface: proof that the switches above took, so a guard below cannot pass over an empty registry.
    expect(array_keys($registry->all()))->toContain('chess', 'rocket-league', NineMensMorris::SLUG, Checkers::SLUG, Blockli::SLUG, Blockfill::SLUG, TrackmaniaNationsForever::SLUG, Hyperbitcoinization::SLUG, 'score-demo', 'fake-arena', FakeScoreGame::SLUG)
        ->and($registry->scores())->toHaveKeys([Blockfill::SLUG, TrackmaniaNationsForever::SLUG, 'score-demo', FakeScoreGame::SLUG]);
});

test('the player page shows every game the player has a result in', function () {
    $player = gameSurfacesPlayer();

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['player-ladder', 'player-score', 'player-hyper'])))->toBe([]);
    gameSurfacesInOrder(gameSurfacesAttribute($html, ['player-ladder']), 'the player page ladders');
    gameSurfacesInOrder(gameSurfacesAttribute($html, ['player-score']), 'the player page highscores');
});

test('the ladder grid on home has a card for every game', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['ladder-top', 'score-top'])))->toBe([]);
    gameSurfacesInOrder(gameSurfacesAttribute($html, ['ladder-top']), 'the ladder grid on home');
    gameSurfacesInOrder(gameSurfacesAttribute($html, ['score-top']), 'the highscore grid on home');
});

test('the play tiles on home list every game', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['play-tile'])))->toBe([]);
    gameSurfacesInOrder(gameSurfacesAttribute($html, ['play-tile']), 'Play now on home');
});

test('/play lists every game', function () {
    $html = $this->get(route('play'))->assertOk()->getContent();
    preg_match_all('#data-test="play-game-([a-z0-9-]+)"#', $html, $shown);

    expect(gameSurfacesMissing($shown[1]))->toBe([]);
    gameSurfacesInOrder($shown[1], '/play');
});

test('the game filter on /matches offers every game', function () {
    $html = $this->get(route('matches.index'))->assertOk()->getContent();
    preg_match_all('#data-test="game-([a-z0-9-]+)"#', $html, $shown);

    expect(gameSurfacesMissing(array_values(array_diff($shown[1], ['all', 'filter-select']))))->toBe([]);
    gameSurfacesInOrder($shown[1], 'the game filter on /matches');
});

test('the stream\'s mempool slide shows a score game added later, with its name in the legend', function () {
    // Only the made-up game has an attempt: the slide shows at most two a side (MempoolSlides::ATTEMPTS).
    ScoreRun::query()->create([
        'user_id' => User::factory()->create(['name' => 'Sprint Pleb'])->id, 'game' => FakeScoreGame::SLUG, 'mode' => FakeScoreGame::MODE, 'course' => FakeScoreGame::MODE,
        'value' => 15_966, 'unit' => 'points', 'source' => ScoreRun::MANUAL, 'achieved_at' => now()->subHour(), 'verified_at' => now()->subMinutes(30),
    ]);
    Cache::forget(MempoolSlides::CACHE_KEY);

    $data = app(MempoolSlides::class)->all();
    $svg = SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation(MempoolSlides::SCENE, null, [], 0, 0, []), 'viewers' => null], RotationPlanner::VIEWS[MempoolSlides::SCENE]);

    expect(array_column($data['finished'], 'slug'))->toContain(FakeScoreGame::SLUG)
        ->and(array_column(MempoolLayout::layout($data)['legend'], 'name'))->toContain('Pixel Sprint')
        ->and($svg)->toContain('>Pixel Sprint<', '>Sprint Pleb<');
});

test('the sitemap has a page of every game', function () {
    $locs = function (string $xml): array {
        return array_map(fn (SimpleXMLElement $entry): string => (string) $entry->loc, iterator_to_array((new SimpleXMLElement($xml))->children(), false));
    };

    $pages = $locs($this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent());
    // A game's page or ladder carries its slug as a path segment: /ladder/<slug>/<mode>, /scores/<slug>, /board/<slug>, /games/<slug>.
    $shown = array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug): bool => collect($pages)->contains(fn (string $url): bool => in_array($slug, explode('/', (string) parse_url($url, PHP_URL_PATH)), true)));

    expect(gameSurfacesMissing(array_values($shown)))->toBe([]);

    // Each game's first page in the sitemap, in sitemap order.
    $first = [];
    foreach ($pages as $url) {
        foreach (explode('/', (string) parse_url($url, PHP_URL_PATH)) as $segment) {
            if (app(GameRegistry::class)->find($segment) !== null) {
                $first[] = $segment;
            }
        }
    }
    gameSurfacesInOrder($first, 'the sitemap');
});

test('/rules names every game', function () {
    $html = $this->get(route('rules'))->assertOk()->getContent();
    // The rules themselves only: the navigation names every game on every page.
    $rules = (string) str($html)->after('data-test="rules-page"')->before('</main>');
    $shown = array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug): bool => str_contains($rules, e(__(app(GameRegistry::class)->name($slug)))));

    expect(gameSurfacesMissing(array_values($shown)))->toBe([]);
});

test('/rules explains Nine Men\'s Morris, Checkers and Blockli last, and its games table follows the registry', function () {
    $html = $this->get(route('rules'))->assertOk()->getContent();
    preg_match_all('#data-test="doc-section-([a-z0-9-]+)"#', $html, $sections);
    $games = array_values(array_intersect($sections[1], array_keys(app(GameRegistry::class)->all())));

    // The sections group games by kind (the series games share one), so only the end is the registry's.
    expect($games)->toContain('chess', NineMensMorris::SLUG, Checkers::SLUG, Blockli::SLUG)
        ->and(array_slice($games, -3))->toBe([NineMensMorris::SLUG, Checkers::SLUG, Blockli::SLUG]);

    $table = (string) str($html)->after('data-test="doc-section-games"')->before('data-test="doc-section-casual-1v1"');
    $at = [];
    foreach (app(GameRegistry::class)->versus() as $slug => $game) {
        $position = strpos($table, e(__(app(GameRegistry::class)->name($slug))));
        if ($position !== false) {
            $at[$slug] = $position;
        }
    }
    asort($at);
    gameSurfacesInOrder(array_keys($at), 'the games table on /rules');
});

test('the header\'s game hub and game chips list the games in the registry order', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();
    preg_match_all('#data-test="hub-game-([a-z0-9-]+)"#', $html, $hub);
    preg_match_all('#data-test="mobile-([a-z0-9-]+)"#', $html, $chips);

    expect(gameSurfacesMissing($hub[1]))->toBe([]);
    gameSurfacesInOrder($hub[1], 'the game hub');
    gameSurfacesInOrder($chips[1], 'the game chips');
});

test('a player who played a board game last still sees it at the end of Play now and the hub', function () {
    $player = User::factory()->create();
    gameSurfacesBoardGame(Checkers::SLUG, $player, now());

    $html = $this->actingAs($player)->get(route('home'))->assertOk()->getContent();
    preg_match_all('#data-test="hub-game-([a-z0-9-]+)"#', $html, $hub);
    $tiles = gameSurfacesAttribute($html, ['play-tile']);

    // Blockli right after TMNF, then nine men's morris and checkers (user, 2026-10-07).
    expect(array_slice($tiles, -3))->toBe([Blockli::SLUG, NineMensMorris::SLUG, Checkers::SLUG])
        ->and(array_slice($hub[1], -3))->toBe([Blockli::SLUG, NineMensMorris::SLUG, Checkers::SLUG]);
});

test('the invite chooser lists the games in the registry order', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('invites.create'))->assertOk()->getContent();
    preg_match_all('#data-invite-game="([a-z0-9-]+)"#', $html, $shown);

    expect($shown[1])->toContain(NineMensMorris::SLUG, Checkers::SLUG);
    gameSurfacesInOrder($shown[1], 'the invite chooser');
});

test('the casual cups to come are grouped in the registry order', function () {
    // Opened in the order the env list names the games: AoE2 and Rocket League before chess (the board games run no cup since 2026-10-07).
    foreach (['age-of-empires-2', 'rocket-league', 'chess'] as $game) {
        Tournament::factory()->signup()->create(['game' => $game, 'mode' => CasualCups::setup($game)['mode'], 'cup_series' => $game.'-eu', 'cup_number' => 1, 'starts_at' => now()->addDay()]);
    }
    config(['esports.casual_cups.enabled' => ['age-of-empires-2', 'rocket-league', 'chess']]);

    gameSurfacesInOrder(array_column(app(CupBoard::class)->groups(), 'game'), 'the cup board');
});

test('the mempool strip on /matches names its games in the registry order', function () {
    $player = User::factory()->create();
    $rival = User::factory()->create();

    // Newest first: a made-up score game's attempt, Checkers, Nine Men's Morris; the order they appear in is not the registry's.
    gameSurfacesBoardGame(NineMensMorris::SLUG, $player, now()->subHours(2), $rival);
    gameSurfacesBoardGame(Checkers::SLUG, $player, now()->subHour(), $rival);
    ScoreRun::query()->create([
        'user_id' => $player->id, 'game' => FakeScoreGame::SLUG, 'mode' => FakeScoreGame::MODE, 'course' => FakeScoreGame::MODE,
        'value' => 15_966, 'unit' => 'points', 'source' => ScoreRun::MANUAL, 'achieved_at' => now()->subMinutes(10), 'verified_at' => now()->subMinutes(5),
    ]);

    $html = $this->get(route('matches.index'))->assertOk()->getContent();
    $legend = gameSurfacesAttribute($html, ['strip-legend-game']);

    expect(count($legend))->toBeGreaterThan(1);
    gameSurfacesInOrder($legend, 'the mempool strip legend');
});

test('the surfaces left out are about one game by design and still exist', function (string $route, string $reason) {
    expect(Route::has($route))->toBeTrue()
        ->and($reason)->not->toBeEmpty();
})->with(array_map(fn (string $route, string $reason): array => [$route, $reason], array_keys(GAME_SURFACES_EXEMPT), GAME_SURFACES_EXEMPT));

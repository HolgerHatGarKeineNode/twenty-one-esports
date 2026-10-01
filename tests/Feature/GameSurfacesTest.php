<?php

use App\Games\Blockfill;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Games\ScoreGame;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\User;
use App\Support\TwentyOne\Stream\MempoolLayout;
use App\Support\TwentyOne\Stream\MempoolSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeGame;
use Tests\Support\FakeScoreGame;

/*
|--------------------------------------------------------------------------
| Every registered game on every surface that lists games
|--------------------------------------------------------------------------
|
| A new game is a class plus a registry entry (App\Games\GameRegistry); every
| page that lists games reads the registry, so the game shows everywhere with
| no further change. Three times on 2026-10-01 a new game (Blockfill) was
| missing from a surface that kept its own list: /matches, home and the
| player page. This guard turns every switch on (board games, Blockfill, the
| score demo), registers a made-up versus game and a made-up score game
| next to the real ones, and asserts that each game shows on each surface.
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
        'esports.blockfill.enabled' => true,
        'esports.score_games.demo' => true,
    ]);
    app()->forgetInstance(GameRegistry::class);

    // As routes/web.php routes them when the switches are on at boot (the test app boots with them off).
    foreach (['board.show' => 'board.php', 'stacker.runs.issue' => 'stacker.php', 'scores.show' => 'score.php'] as $name => $file) {
        if (! Route::has($name)) {
            Route::middleware('web')->group(base_path('routes/'.$file));
        }
    }

    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();

    // A game added later: one versus game, one score game, registered after the real ones.
    app()->instance(GameRegistry::class, new GameRegistry([
        ...array_values(app(GameRegistry::class)->all()),
        new FakeGame('fake-arena', 'Fake Arena'),
        new FakeScoreGame,
    ]));
});

/**
 * A player with one result in every registered game: a casual rating in the
 * first mode of a versus game, a verified attempt in the first mode of a score game.
 */
function gameSurfacesPlayer(): User
{
    $player = User::factory()->create(['name' => 'Every Game Pleb']);

    foreach (app(GameRegistry::class)->all() as $slug => $game) {
        $mode = (string) array_key_first($game->modes());

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

test('the registry under test holds every kind of game, the made-up ones included', function () {
    $registry = app(GameRegistry::class);

    // Not a surface: proof that the switches above took, so a guard below cannot pass over an empty registry.
    expect(array_keys($registry->all()))->toContain('chess', 'rocket-league', NineMensMorris::SLUG, Checkers::SLUG, Blockfill::SLUG, 'score-demo', 'fake-arena', FakeScoreGame::SLUG)
        ->and($registry->scores())->toHaveKeys([Blockfill::SLUG, 'score-demo', FakeScoreGame::SLUG]);
});

test('the player page shows every game the player has a result in', function () {
    $player = gameSurfacesPlayer();

    $html = $this->get(route('players.show', $player->npub))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['player-ladder', 'player-score'])))->toBe([]);
});

test('the ladder grid on home has a card for every game', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['ladder-top', 'score-top'])))->toBe([]);
});

test('the play tiles on home list every game', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(gameSurfacesMissing(gameSurfacesAttribute($html, ['play-tile'])))->toBe([]);
});

test('/play lists every game', function () {
    $html = $this->get(route('play'))->assertOk()->getContent();
    preg_match_all('#data-test="play-game-([a-z0-9-]+)"#', $html, $shown);

    expect(gameSurfacesMissing($shown[1]))->toBe([]);
});

test('the game filter on /matches offers every game', function () {
    $html = $this->get(route('matches.index'))->assertOk()->getContent();
    preg_match_all('#data-test="game-([a-z0-9-]+)"#', $html, $shown);

    expect(gameSurfacesMissing(array_values(array_diff($shown[1], ['all', 'filter-select']))))->toBe([]);
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
});

test('/rules names every game', function () {
    $html = $this->get(route('rules'))->assertOk()->getContent();
    // The rules themselves only: the navigation names every game on every page.
    $rules = (string) str($html)->after('data-test="rules-page"')->before('</main>');
    $shown = array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug): bool => str_contains($rules, e(__(app(GameRegistry::class)->name($slug)))));

    expect(gameSurfacesMissing(array_values($shown)))->toBe([]);
});

test('the surfaces left out are about one game by design and still exist', function (string $route, string $reason) {
    expect(Route::has($route))->toBeTrue()
        ->and($reason)->not->toBeEmpty();
})->with(array_map(fn (string $route, string $reason): array => [$route, $reason], array_keys(GAME_SURFACES_EXEMPT), GAME_SURFACES_EXEMPT));

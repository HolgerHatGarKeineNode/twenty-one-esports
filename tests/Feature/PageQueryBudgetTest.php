<?php

use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\ScoreDemoOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Query budget of the shell and the busiest pages (P5g)
|--------------------------------------------------------------------------
|
| Counts the queries of one request by what they ask, so a count that grows
| back (a lookup per row, a gate asked per menu) turns red here instead of
| in production.
|
*/

/**
 * The SQL of every query one GET runs.
 *
 * @return list<string>
 */
function queriesOf(string $uri): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($uri)->assertOk();
    DB::disableQueryLog();

    return array_column(DB::getQueryLog(), 'query');
}

/**
 * @param  list<string>  $queries
 */
function countQueries(array $queries, string $needle): int
{
    return count(array_filter($queries, fn (string $sql) => str_contains($sql, $needle)));
}

test('the footer counters are counted once and then served from the cache', function () {
    User::factory()->count(2)->create();
    // The Mempool count of row 1 asks chess_games by status too, in its own cache (tests/Feature/ShellNavigationTest.php): served here, so only the footer counts.
    Cache::put(ShellNavigation::MEMPOOL_KEY, 0, 60);
    $counters = fn (array $queries) => countQueries($queries, 'count(*) as "aggregate" from "users"')
        + countQueries($queries, 'count(*) as "aggregate" from "clans"')
        + countQueries($queries, 'from "chess_games" where "status" = ?');

    expect($counters(queriesOf(route('rules'))))->toBe(3)
        ->and($counters(queriesOf(route('rules'))))->toBe(0)
        ->and(test()->get(route('rules'))->getContent())->toContain(__('Players').' <b class="text-ink">2</b>');
});

test('the header asks the admin gate once per page', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin);

    expect(countQueries(queriesOf(route('rules')), 'from "admins" where "pubkey"'))->toBe(1);
});

test('/matches looks up the clan of the filter once, and not at all without a filter', function () {
    $series = SeriesMatch::factory()->accepted()->create();
    $clan = $series->lineup('challenger')->clan;

    expect(countQueries(queriesOf(route('matches.index')), 'from "clans" where "slug" = ?'))->toBe(0)
        ->and(countQueries(queriesOf(route('matches.index', ['clan' => $clan->slug])), 'from "clans" where "slug" = ?'))->toBe(1);
});

test('/challenges/create lists the opponents without a query per opponent', function () {
    $captain = Lineup::factory()->ready()->create()->clan->owner;
    $this->actingAs($captain);
    $userLookups = fn () => countQueries(queriesOf(route('challenges.create')), 'from "users" where "users"."id" = ?');

    Lineup::factory()->ready()->count(2)->create();
    $few = $userLookups();

    Lineup::factory()->ready()->count(4)->create();
    $many = $userLookups();

    expect(Clan::query()->count())->toBe(7)
        ->and($many)->toBe($few);
});

test('a failing cache store does not take the pages down: the footer counts directly', function () {
    User::factory()->count(2)->create();
    // Only flexible() fails; every other cache call goes to the real manager.
    $cache = Mockery::mock(Cache::getFacadeRoot());
    $cache->shouldReceive('flexible')->andThrow(new RuntimeException('cache store down'));
    Cache::swap($cache);
    Exceptions::fake();

    $this->get(route('rules'))->assertOk()->assertSee(__('Players').' <b class="text-ink">2</b>', false);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache store down');
});

/*
|--------------------------------------------------------------------------
| Total query budget per route (performance plan P1a, 2026-10-04)
|--------------------------------------------------------------------------
|
| One data set, one test: the ~12 hot routes as a guest and as the fixed
| "active player" (budgetWorld()), each with the queries of ONE warm GET.
| Warm means the route was asked once before, so the caches it fills are
| filled and the number is what a visitor costs in steady state, whatever
| order the routes run in.
|
| THE LOAD IS THE GET. No page of this list fires a Livewire roundtrip on
| load: not one `lazy`/`defer` child, not one `wire:init`, and the only island
| is the match dock's `skip: true` one. The test below asserts that on every
| response (the `__lazyLoad` and `wire:init=` markers), so the day a page
| gets a lazy child this goes red and the roundtrip has to be counted here,
| not forgotten. Calls the page's own JS starts (the room's 8-second sync,
| the pollers, Echo-driven refreshes) are not part of the load; they are
| counted over time by tests/Browser/LivewireTrafficTest.php.
|
| The budgets are the numbers measured on 2026-10-04 and the assertion is
| `<=`: a route that costs more turns red, a route that got cheaper stays
| green and its budget is lowered by hand (P2 does that, with the saving
| named: docs/plans/…-performance/p2-ergebnis.md, one step per commit). Measured on SQLite, like the whole suite: the count of statements,
| not their cost on PostgreSQL.
|
| QUERY_BUDGET_REPORT=<file> writes the measured table there (markdown).
|
*/

/**
 * The fixed fixture: the player (a seat of a ready 3v3 lineup, so the room is theirs) with one chess game, one
 * series, one tournament signup and one clan invite; on top of that a running tournament, a running score board
 * and a live season, so every hot page has something to show.
 *
 * @return array{player: User, series: SeriesMatch, running: Tournament, scores: string}
 */
function budgetWorld(): array
{
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    openSeason();
    ScoreDemoOn::play();

    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $rival = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->seats()->where('user_id', '!=', $lineup->clan->owner_id)->firstOrFail()->user;

    ChessGame::factory()->create(['white_id' => $player->id]);
    $series = SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => $rival->id]);
    TournamentSignup::query()->create([
        'tournament_id' => openTournament(['name' => 'Halving Cup', 'starts_at' => now()->addDays(3)], rocketLeague: true)->id,
        'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id],
    ]);
    ClanInvite::query()->create(['clan_id' => $rival->clan_id, 'inviter_id' => $rival->clan->owner_id, 'invitee_id' => $player->id, 'status' => InviteStatus::Pending]);

    // Drawn from a block and published, as a real running tournament is: its page links the draw and polls.
    $running = runningChess(TournamentFormat::SingleElimination, 4);
    $running->forceFill(['draw_height' => 915000, 'draw_hash' => str_repeat('ab', 32), 'published_at' => now()->subDay()])->save();
    runningScoreBoard(3);

    return ['player' => $player, 'series' => $series, 'running' => $running, 'scores' => route('scores.show', 'score-demo')];
}

/**
 * The queries of one warm GET and its HTML.
 *
 * @return array{queries: int, html: string}
 */
function budgetLoad(string $uri): array
{
    test()->get($uri)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = (string) test()->get($uri)->assertOk()->getContent();
    DB::disableQueryLog();

    return ['queries' => count(DB::getQueryLog()), 'html' => $html];
}

/**
 * Budgets: queries of one warm GET, [guest, player]; null = the route is behind `auth`.
 *
 * @var array<string, array{0: int|null, 1: int}>
 */
const PAGE_BUDGETS = [
    'rules' => [5, 36],
    'home' => [29, 71],
    'play' => [3, 40],
    'tournaments.show' => [30, 82],
    'matches.room' => [null, 55],
    'live' => [14, 46],
    'clans.index' => [8, 43],
    'mining' => [17, 48],
    'games.index' => [5, 36],
    'matches.index' => [38, 69],
    'scores.show' => [7, 38],
    'dashboard' => [null, 66],
];

test('the hot routes stay within their total query budget, as a guest and as the active player, and load without a Livewire roundtrip', function () {
    $this->travelTo(now()->setTime(12, 0));
    $world = budgetWorld();
    $uris = [
        'rules' => route('rules'),
        'home' => route('home'),
        'play' => route('play'),
        'tournaments.show' => route('tournaments.show', $world['running']),
        'matches.room' => route('matches.room', $world['series']),
        'live' => route('live'),
        'clans.index' => route('clans.index'),
        'mining' => route('mining'),
        'games.index' => route('games.index'),
        'matches.index' => route('matches.index'),
        'scores.show' => $world['scores'],
        'dashboard' => route('dashboard'),
    ];

    expect(array_keys($uris))->toBe(array_keys(PAGE_BUDGETS));

    $measured = [];
    $over = [];
    $roundtrips = [];

    foreach (['guest', 'player'] as $viewer) {
        if ($viewer === 'player') {
            $this->actingAs($world['player']);
        }

        foreach ($uris as $name => $uri) {
            $budget = PAGE_BUDGETS[$name][$viewer === 'guest' ? 0 : 1];

            if ($budget === null) {
                continue;
            }

            $load = budgetLoad($uri);
            $measured[$name][$viewer] = $load['queries'];

            if ($load['queries'] > $budget) {
                $over[] = "{$name} as {$viewer}: {$load['queries']} queries, budget {$budget}";
            }

            foreach (['__lazyLoad', 'wire:init='] as $marker) {
                if (str_contains($load['html'], $marker)) {
                    $roundtrips[] = "{$name} as {$viewer}: carries `{$marker}`, a Livewire roundtrip on load";
                }
            }
        }
    }

    if (is_string($report = getenv('QUERY_BUDGET_REPORT')) && $report !== '') {
        $rows = ['| Route | Guest | Active player |', '|---|---:|---:|'];

        foreach ($measured as $name => $counts) {
            $rows[] = "| {$name} | ".($counts['guest'] ?? 'auth').' | '.$counts['player'].' |';
        }

        file_put_contents($report, implode("\n", $rows)."\n");
    }

    expect($over)->toBe([])
        ->and($roundtrips)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Lazy loading throws outside production (performance plan P1b, P2)
|--------------------------------------------------------------------------
|
| P1 logged every relation read on a model of a collection that was not
| eager loaded; P2 fixed what the suite logged, and now the guard throws in
| local and testing. In production the guard is off, so a missed eager load costs a query there
| and never a 500.
|
*/

test('a lazy-loaded relation throws outside production', function () {
    Clan::factory()->count(2)->create();
    $clans = Clan::query()->get();

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(fn () => $clans->first()->owner)->toThrow(LazyLoadingViolationException::class, 'Attempted to lazy load [owner] on model [App\\Models\\Clan]');
});

test('the lazy-loading guard is off in production', function () {
    $configure = new ReflectionMethod(AppServiceProvider::class, 'configureDefaults');
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $configure->invoke(new AppServiceProvider(app()));

        expect(Model::preventsLazyLoading())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
        DB::prohibitDestructiveCommands(false);
        Model::preventLazyLoading(true);
    }

    $configure->invoke(new AppServiceProvider(app()));

    expect(Model::preventsLazyLoading())->toBeTrue();
});

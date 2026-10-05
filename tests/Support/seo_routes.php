<?php

/*
 * The route crawl of tests/Feature/Seo/AllRoutesHaveOwnCardTest.php: every
 * GET route of the app, either crawled with a fixture or skipped with the
 * reason, and the search and preview tags read from a page's HTML. Required
 * by that file (not from tests/Pest.php, so no other test loads it).
 */

use App\Enums\BoardGameStatus;
use App\Enums\ChessEndReason;
use App\Enums\InviteLinkType;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Invites\InviteLinks;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\Support\BlockfillOn;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\ScoreDemoOn;

/** Every switch that adds public pages on: board games, Blockfill and the score demo, as routes/web.php loads them at boot. */
function seoEverySwitchOn(): void
{
    NineMensMorrisOn::play();
    CheckersGame::play();
    ScoreDemoOn::play();
    BlockfillOn::play();
    leagueWeeksApproved(Blockfill::SLUG);
}

/**
 * Why a GET route is not crawled, or null when it is a public page.
 */
function seoSkipReason(RouteDefinition $route): ?string
{
    $uri = $route->uri();
    $name = (string) $route->getName();
    // What the route really runs: `withoutMiddleware('web')` is an exclusion, gatherMiddleware() still lists the group.
    $middleware = array_values(array_diff($route->gatherMiddleware(), $route->excludedMiddleware()));

    return match (true) {
        str_starts_with($uri, 'horizon') => 'Horizon dashboard, behind the viewHorizon gate',
        str_starts_with($uri, 'livewire-') => 'framework asset (JS, CSS, previews)',
        str_starts_with($name, 'testing.') => 'fixture route of the test environment only',
        $uri === 'up' => 'health check',
        $uri === 'broadcasting/auth' => 'websocket channel authorization, not a page',
        $uri === '{fallbackPlaceholder}' => 'fallback: every unknown URL is the 404 page',
        $name === 'styleguide' => 'internal: 404 outside the local and testing environments (asserted below)',
        in_array('auth', $middleware, true) || collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'auth:')) => 'login required',
        collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'signed')) => 'signed personal link (a DM opt-out), no page to share',
        ! in_array('web', $middleware, true) => 'no HTML page: an image, JSON, XML or text for clients and crawlers',
        $name === 'locale.switch' => 'redirect that sets the language',
        $name === 'tournaments.calendar' => 'iCalendar file, not HTML',
        $name === 'players.card' => 'HTML fragment of the player popover, not a page',
        $name === 'invites.create' => 'the invite picker: an action page; what gets shared is the invite link with its own card',
        $name === 'stacker.replay' => 'a replay is access-checked per run (its player, admins, a finished week\'s first ten): noindex',
        $name === 'stacker.moment' => 'a player\'s Blockfill moment: its og:image is its share card (BlockfillShareTest)',
        $name === 'search' => 'search results per query: noindex by design (SearchController), no preview',
        default => null,
    };
}

/**
 * The public pages, by route name: each closure seeds what the page needs and
 * returns its URLs (a route with a parameter lists one URL per kind of page).
 *
 * @return array<string, Closure(): list<string>>
 */
function seoRouteFixtures(): array
{
    $registry = fn (): GameRegistry => app(GameRegistry::class);

    return [
        'home' => fn () => [route('home')],
        'login' => fn () => [route('login')],
        'play' => fn () => [route('play')],
        'rules' => fn () => [route('rules')],
        'protocol' => fn () => [route('protocol')],
        'live' => fn () => [route('live')],
        'mining' => fn () => [route('mining')],
        'clans.index' => fn () => [route('clans.index')],
        'clans.show' => fn () => [route('clans.show', Clan::factory()->create(['name' => 'Hodl Squad']))],
        'games.index' => fn () => [route('games.index')],
        'games.show' => fn () => [route('games.show', ChessGame::factory()->finished('0-1', ChessEndReason::Checkmate)->create(['number' => 24]))],
        'games.rocket-league' => fn () => [route('games.rocket-league')],
        'games.series' => fn () => array_map(fn (string $slug): string => route('games.series', $slug), array_values(array_diff(array_keys($registry()->series()), ['rocket-league']))),
        'chess.lobby' => fn () => [route('chess.lobby')],
        'matches.index' => fn () => [route('matches.index')],
        'matches.show' => fn () => [route('matches.show', SeriesMatch::factory()->accepted()->create()->number)],
        'ladder.strongest' => fn () => [route('ladder.strongest')],
        // Every ladder there is: each mode of each game but the score games (their ladder is the score page).
        'ladder.show' => function () use ($registry): array {
            $urls = [];

            foreach ($registry()->all() as $slug => $game) {
                if ($registry()->isScore($slug)) {
                    continue;
                }

                foreach ($game->modes() as $mode) {
                    $urls[] = route('ladder.show', [$slug, $mode->slug]);
                }
            }

            return $urls;
        },
        'players.show' => fn () => [route('players.show', User::factory()->create(['name' => 'satoshi'])->npub)],
        'tournaments.index' => fn () => [route('tournaments.index')],
        'tournaments.show' => fn () => [
            route('tournaments.show', openTournament(['name' => 'Halving Cup'])),
            route('tournaments.show', seoLobbyTournament()),
        ],
        'tournaments.draw' => fn () => [route('tournaments.draw', openTournament(['name' => 'Draw Cup']))],
        'tournaments.tv' => fn () => [route('tournaments.tv', openTournament(['name' => 'TV Cup']))],
        'invites.link' => fn () => [app(InviteLinks::class)->create(User::factory()->create(), InviteLinkType::Blitz)->url()],
        'board.lobby' => fn () => array_map(fn (string $slug): string => route('board.lobby', $slug), array_keys($registry()->boards())),
        'board.correspondence' => fn () => array_map(fn (string $slug): string => route('board.correspondence', $slug), array_keys($registry()->boards())),
        'board.show' => fn () => array_map(fn (string $slug): string => route('board.show', mempoolBoard($slug, ['status' => BoardGameStatus::Active])), array_keys($registry()->boards())),
        'stacker.play' => fn () => [route('stacker.play')],
        // The replays page with last week's first place on it: a verified run of an ended week, on its board.
        'stacker.replays' => function (): array {
            $at = now()->subWeek();
            $run = StackerRun::factory()->verified(958)->create(['submitted_at' => $at, 'week' => StackerRuns::weekOf($at), 'replay' => 'fixture']);
            app(BlockfillWeeks::class)->record($run, $at);

            return [route('stacker.replays')];
        },
        'scores.show' => fn () => array_map(fn (string $slug): string => route('scores.show', $slug), array_keys($registry()->scores())),
        'tournaments.scores' => fn () => [
            route('tournaments.scores', runningScoreBoard(3, attributes: ['published_at' => now()])[0]),
            route('tournaments.scores', app(BlockfillWeeks::class)->open()),
        ],
    ];
}

/** A published lobby tournament (P10): one lobby match for everyone. */
function seoLobbyTournament(): Tournament
{
    $tournament = openTournament(['name' => 'Lobby Night', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '1v1'))->toArray(), 'capacity' => 16, 'results_mode' => TournamentResultsMode::Players]);

    return $tournament->refresh();
}

/**
 * Every GET route with what happens to it: [route, reason] for a skipped one, [route, null] for a crawled one.
 *
 * @return list<array{0: RouteDefinition, 1: string|null}>
 */
function seoGetRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array('GET', $route->methods(), true)) {
            $routes[] = [$route, seoSkipReason($route)];
        }
    }

    return $routes;
}

/**
 * The search and preview tags of a page, as a crawler reads them.
 *
 * @return array{title: string, description: string, canonical: string, robots: string, og_title: string, og_image: string, twitter: string, jsonld: string}
 */
function seoTagsOf(string $html): array
{
    $tag = fn (string $pattern): string => preg_match($pattern, $html, $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5) : '';
    $types = [];

    if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match) === 1) {
        foreach ((array) (json_decode($match[1], true)['@graph'] ?? []) as $node) {
            $types[] = (string) ($node['@type'] ?? '?');
        }
    }

    return [
        'title' => $tag('#<title>(.*?)</title>#s'),
        'description' => $tag('#<meta name="description" content="([^"]*)">#'),
        'canonical' => $tag('#<link rel="canonical" href="([^"]*)">#'),
        'robots' => $tag('#<meta name="robots" content="([^"]*)">#'),
        'og_title' => $tag('#<meta property="og:title" content="([^"]*)">#'),
        'og_image' => $tag('#<meta property="og:image" content="([^"]*)">#'),
        'twitter' => $tag('#<meta name="twitter:card" content="([^"]*)">#'),
        'jsonld' => implode(',', $types),
    ];
}

/** Whether an og:image is the site's static brand card (App\Support\PageMeta::brandImage()). */
function seoIsBrandImage(string $url): bool
{
    return str_contains((string) parse_url($url, PHP_URL_PATH), '/images/og/fallback.png');
}

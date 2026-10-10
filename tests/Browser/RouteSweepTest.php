<?php

use App\Enums\ChessEndReason;
use App\Enums\InviteLinkType;
use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\DisputeEvidence;
use App\Models\Lineup;
use App\Models\RankBadge;
use App\Models\Rating;
use App\Models\ScoreRun;
use App\Models\Season;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use App\Support\Invites\InviteLinks;
use App\Support\StreamBot\PrideNotes;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\ScoreDemoOn;

pest()->group('browser');

beforeEach(function () {
    // A fresh authenticated user is always "stale" (member_checked_at is
    // null), so App\Http\Middleware\RefreshStaleMembership defers a real
    // call to the Verein API on the first authenticated request of the
    // sweep. Fake it — a browser test must never depend on the network.
    Http::fake(fn () => Http::response([]));
});

/*
|--------------------------------------------------------------------------
| Route discovery
|--------------------------------------------------------------------------
|
| Every GET route this app should render as a page, taken from the router
| itself rather than a hand list, so a new placeholder in routes/web.php is
| swept automatically the moment it exists.
|
*/

/**
 * URI prefixes that are vendor/asset/infra endpoints, not pages:
 * Livewire's asset + file-preview routes (the hash prefix is
 * config-driven, hence the "starts with" check, not an exact route name),
 * the storage disk, the broadcasting auth endpoint (JSON, not a page), and
 * this app's own testing-only fixtures below (the positive-control tests
 * visit those directly; sweeping them would always fail on purpose). Horizon
 * is a vendor dashboard; its admin gate is covered in tests/Feature/AdminTest.
 *
 * @var list<string>
 */
const SWEEP_VENDOR_PREFIXES = ['livewire-', 'storage/', 'broadcasting/', '__test/', 'horizon'];

/**
 * Files for crawlers, not pages (P14): robots.txt and the sitemap XML, which
 * a browser shows as raw text or an XML tree (wider than 375 px, no header).
 * tests/Feature/Seo/SitemapTest checks their status and content.
 *
 * @var list<string>
 */
const SWEEP_CRAWLER_FILES = ['robots', 'sitemap', 'sitemap.section'];

/**
 * Downloads, not pages: a browser saves them instead of showing them. The
 * tournament calendar file is checked in tests/Feature/Tournaments/
 * TournamentTimeZoneTest and fetched from its link in tests/Browser/
 * TournamentTimeTest. A lobby report's end screen (P10) is a private image
 * for the directors of a lobby tournament, fetched from its link in
 * tests/Browser/AoeLobbyTest; the sweep's fixtures have no lobby.
 *
 * @var list<string>
 */
const SWEEP_DOWNLOADS = ['tournaments.calendar', 'tournaments.lobby-screenshot'];

/**
 * JSON endpoints, not pages: the player picker's suggestions
 * (tests/Feature/PlayerPickerTest, tests/Browser/PlayerPickerTest) and the
 * league's Lightning address (tests/Feature/Payouts/LnurlEndpointTest), and
 * the live stream's status (tests/Feature/LiveStreamTest).
 *
 * @var list<string>
 */
const SWEEP_JSON_ENDPOINTS = ['players.search', 'lnurl.pay', 'lnurl.callback', 'stream.status', 'broadcast.snapshot', 'tournaments.bracket-data'];

/**
 * Pages behind a secret URL, not reachable by any link: an OBS overlay preset's browser source (plan
 * "OBS-Broadcast-Overlays", P2), a transparent full-screen stage measured by tests/Browser/BroadcastOverlayTest.
 *
 * @var list<string>
 */
const SWEEP_SECRET_URLS = ['broadcast.overlay'];

/**
 * @param  array<string, string>  $bound  route key per bound parameter, from sweepFixtures()
 * @return list<array{name: string, url: string}>
 */
function sweepRoutes(array $bound = []): array
{
    return collect(Route::getRoutes())
        ->reject(fn (RoutingRoute $route) => $route->isFallback)
        ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
        ->reject(fn (RoutingRoute $route) => $route->uri() === 'up')
        ->reject(fn (RoutingRoute $route) => in_array($route->getName(), SWEEP_CRAWLER_FILES, true))
        ->reject(fn (RoutingRoute $route) => in_array($route->getName(), SWEEP_DOWNLOADS, true))
        ->reject(fn (RoutingRoute $route) => in_array($route->getName(), SWEEP_JSON_ENDPOINTS, true))
        ->reject(fn (RoutingRoute $route) => in_array($route->getName(), SWEEP_SECRET_URLS, true))
        ->reject(function (RoutingRoute $route) {
            foreach (SWEEP_VENDOR_PREFIXES as $prefix) {
                if (str_starts_with($route->uri(), $prefix)) {
                    return true;
                }
            }

            return false;
        })
        ->map(fn (RoutingRoute $route) => [
            'name' => $route->getName() ?? $route->uri(),
            // A signed page (the DM opt-out) answers 403 without its signature.
            'url' => in_array('signed:relative', $route->gatherMiddleware(), true)
                ? URL::signedRoute((string) $route->getName(), array_intersect_key($bound, array_flip($route->parameterNames())), absolute: false)
                : fillRouteParameters($route, $bound),
        ])
        ->unique('url')
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| Fixtures for route-model-bound parameters
|--------------------------------------------------------------------------
|
| A bound parameter (`clans/{clan}`) 404s on a made-up value, so each one
| gets a real row, built for the swept user. Register a new bound parameter
| here: parameter name => closure(?User $user, array $made): Model. `$user`
| is the logged-in user of the sweep (null for the guest sweep), `$made` the
| models of the entries above it, so later fixtures can hang off earlier
| ones. The URL gets the model's route key (slug, ulid, ...). An unbound
| parameter keeps the plain `sweep-fixture` value.
|
*/

/**
 * @return array<string, Closure(?User, array<string, Model>): Model>
 */
function sweepFixtures(): array
{
    return [
        // Owned by the swept user, so the member sweep can open the captain-only manage page.
        'clan' => fn (?User $user, array $made): Model => Clan::factory()->create($user === null ? [] : ['owner_id' => $user->id]),

        // An open invite into that clan's roster; the clan owner may view it.
        'invite' => fn (?User $user, array $made): Model => ClanInvite::query()->create([
            'clan_id' => $made['clan']->getKey(),
            'inviter_id' => $made['clan']->getAttribute('owner_id'),
            'invitee_id' => User::factory()->create()->id,
            'status' => InviteStatus::Pending,
        ]),

        // A live blitz game; the member sweep plays White in it, so the board is playable.
        'game' => fn (?User $user, array $made): Model => ChessGame::factory()->create($user === null ? [] : ['white_id' => $user->id]),

        // A running Rocket League series (P6a). The swept member captains the
        // challenger lineup (a 2v2 of the `clan` fixture), so the match room
        // opens with the lobby, the score sheet and the chat; the guest gets
        // the public page and the login redirect.
        'match' => fn (?User $user, array $made): Model => SeriesMatch::factory()->accepted()->create($user === null ? [] : [
            'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $made['clan']->getKey()])->id,
            'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
            'lobby_name' => 'sweep-lobby',
            // One game entered, so the match page draws its flow and the room its score.
            'live_games' => [['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger']],
        ]),

        // A player with a full Nostr profile (P10a): the player page header with
        // every row, and the player card fragment. Pictures point at a closed
        // port, so the sweep also runs the broken-image fallback.
        'npub' => fn (?User $user, array $made): Model => User::factory()->member()->create([
            'name' => 'Mempool Max, captain of the longest clan name in the league',
            'about' => str_repeat('Reads fee charts for fun. ', 12),
            'picture' => 'https://127.0.0.1:9/max.png',
            'banner' => 'https://127.0.0.1:9/max-banner.png',
            'website' => 'https://www.example.com/a/rather/long/path/to/a/personal/page',
            'lud16' => 'mempoolmax-with-a-long-name@walletofsatoshi.com',
            'nip05' => 'mempoolmax-with-a-long-name@mempool.example',
            'nip05_verified_at' => now(),
            'nip05_checked_at' => now(),
            'profile_event_at' => now(),
            'profile_checked_at' => now(),
        ]),

        // The generated avatar of that player's key.
        'pubkey' => fn (?User $user, array $made): Model => $made['npub'],

        // An open daily-chess invite link of another player (P6b): the guest
        // sweep gets the landing with the login, the member sweep "Ready to
        // play?". The same code fills the preview image route.
        'link' => fn (?User $user, array $made): Model => app(InviteLinks::class)->create(User::factory()->create(['name' => 'satsjäger']), InviteLinkType::Daily),
        'code' => fn (?User $user, array $made): Model => $made['link'],

        // A Rocket League tournament with sign-up open (P8a): public, so the guest sweep sees it too.
        // Created by the member, who directs it: its director desk (P8b) is theirs to open.
        'tournament' => fn (?User $user, array $made): Model => Tournament::factory()->rocketLeague(TournamentFormat::DoubleElimination)->signup()
            ->create($user === null ? [] : ['created_by_id' => $user->id]),

        // The swept user's own DM opt-out page (signed, no login needed).
        'user' => fn (?User $user, array $made): Model => $user ?? User::factory()->create(),
        // Share cards (P11) of the `npub` player, in an ENDED season, so no ladder opens for the other
        // pages of the sweep: a mined block, a rank-up version of a badge, a won tournament.
        'season' => function (?User $user, array $made): Model {
            $season = Season::factory()->ended()->create(['slug' => 'sweep-season']);
            // Season Wrapped exists only for a player with rated results in the season (P11 gate F4).
            Rating::query()->create(['pool' => Rating::RATED, 'season' => 'sweep-season', 'game' => 'chess', 'mode' => 'blitz',
                'subject' => 'user:'.$made['npub']->getKey(), 'user_id' => $made['npub']->getKey(), 'rating' => 1061, 'results' => 9, 'wins' => 6]);

            return $season;
        },
        'block' => fn (?User $user, array $made): Model => shareBlock($made['season'], 1, $made['npub'], User::factory()->create()),
        'version' => function (?User $user, array $made): Model {
            $badge = RankBadge::query()->create([
                'user_id' => $made['npub']->getKey(), 'pubkey' => $made['npub']->getAttribute('pubkey'), 'game' => 'chess', 'mode' => 'blitz',
                'd' => 'rank/chess/blitz/'.$made['npub']->getAttribute('pubkey'), 'badge_pubkey' => str_repeat('b', 64), 'tier' => 'gold-2', 'season' => 'sweep-season',
            ]);

            return $badge->versions()->create(['tier' => 'gold-2', 'previous_tier' => 'gold-1', 'season' => 'sweep-season', 'rating' => 1061, 'signed_at' => now()->getTimestamp()]);
        },
        'finished' => fn (?User $user, array $made): Model => shareTournament($made['npub'], User::factory()->create()),
        // A Blockfill moment's card: the `npub` player's verified personal best (the card stays while Blockfill is off).
        'run' => fn (?User $user, array $made): Model => StackerRun::factory()->for($made['npub'])->verified(2400)->create(),

        // A dispute screenshot of that series, served to admins only.
        'evidence' => function (?User $user, array $made): Model {
            Storage::disk('local')->put('dispute-evidence/sweep.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

            return DisputeEvidence::query()->create(['series_match_id' => $made['match']->getKey(), 'path' => 'dispute-evidence/sweep.png', 'name' => 'sweep.png']);
        },
    ];
}

/**
 * Pages one route renders in more than one way: `games/{game}` is a live
 * blitz board with the `game` fixture above, and a daily game (P5b) or a
 * daily game lost on time with these. Each is swept like a route.
 *
 * @return list<array{name: string, url: string}>
 */
function sweepExtraPages(?User $user): array
{
    $daily = ChessGame::factory()->daily()->create($user === null ? [] : ['black_id' => $user->id]);
    $lost = ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Timeout)->create($user === null ? [] : ['black_id' => $user->id]);

    if ($user !== null) {
        app(DailyChallenges::class)->challenge(User::factory()->create(), $user, 'white', 'gl hf');
    }

    return [
        ['name' => 'games.show (daily)', 'url' => route('games.show', $daily, false)],
        ['name' => 'games.show (daily, lost on time)', 'url' => route('games.show', $lost, false)],
        ...sweepLadderPages($user),
    ];
}

/**
 * The ladder with rows (P7b): casual ratings for chess blitz (players) and
 * Rocket League 3v3 (lineups), the swept user among them. The bare route
 * shows the rated Pre-Season state.
 *
 * @return list<array{name: string, url: string}>
 */
function sweepLadderPages(?User $user): array
{
    $players = [...User::factory()->count(4)->create()->all(), ...($user === null ? [] : [$user])];

    foreach ($players as $index => $player) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id,
            'user_id' => $player->id, 'rating' => 960 + 45 * $index, 'results' => 2 + $index, 'wins' => 1 + $index, 'draws' => 1]);
    }

    foreach (range(1, 3) as $index) {
        $lineup = Lineup::factory()->ready()->create();
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id,
            'lineup_id' => $lineup->id, 'rating' => 1040 - 20 * $index, 'results' => 3 * $index, 'wins' => 2 * $index, 'losses' => $index]);
    }

    return [
        ['name' => 'ladder.show (chess casual)', 'url' => route('ladder.show', ['chess', 'blitz'], false).'?pool=casual'],
        ['name' => 'ladder.show (rocket league casual)', 'url' => route('ladder.show', ['rocket-league', '3v3'], false).'?pool=casual'],
    ];
}

/**
 * Build every registered fixture and return its route key per parameter name.
 *
 * @return array<string, string>
 */
function buildSweepFixtures(?User $user): array
{
    $made = [];

    foreach (sweepFixtures() as $name => $build) {
        $made[$name] = $build($user, $made);
    }

    $keys = [];

    foreach ($made as $name => $model) {
        $keys[$name] = match ($name) {
            'npub' => (string) $model->getAttribute('npub'),
            'pubkey' => (string) $model->getAttribute('pubkey'),
            'season' => (string) $model->getAttribute('slug'),
            default => (string) $model->getRouteKey(),
        };
    }

    return $keys;
}

/**
 * Parameters that are neither a model nor free text: the ladder's game and
 * mode must name a registered mode (its `game` is a slug, not the chess game
 * fixture that shares the parameter name).
 *
 * @var array<string, array<string, string>>
 */
const SWEEP_PRIDE_HASH = '5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed5eed';

const SWEEP_ROUTE_PARAMETERS = [
    'ladder.show' => ['game' => 'chess', 'mode' => 'blitz'],
    // The page of a series game: a registry slug, the newest game's (Age of Empires II), not free text.
    'games.series' => ['slug' => 'age-of-empires-2'],
    // Rank badge artwork (P11): a game slug and a tier, not the chess game fixture.
    'badges.rank' => ['game' => 'chess', 'tier' => 'gold-2', 'artwork' => '1'],
    'badges.rank.thumb' => ['game' => 'chess', 'tier' => 'gold-2', 'artwork' => '1', 'size' => '256'],
    // A pride note slide: served by its content hash, written in the sweep test below.
    'stream.pride-image' => ['hash' => SWEEP_PRIDE_HASH],
    // A page's link preview (P54): a card type and its key, here the home page's.
    'cards.page' => ['type' => 'page', 'key' => 'home'],
];

/**
 * Bound parameters take their fixture's route key; `locale` and the invite
 * card's `format` must satisfy their constraints; every other parameter belongs to a route that
 * does not read it (placeholders, redirects), so any value is enough.
 *
 * @param  array<string, string>  $bound
 */
function fillRouteParameters(RoutingRoute $route, array $bound = []): string
{
    $values = [
        'locale' => config('app.supported_locales')[0] ?? 'en',
        'format' => 'wide',
        ...$bound,
        ...(SWEEP_ROUTE_PARAMETERS[$route->getName()] ?? []),
    ];

    $uri = $route->uri();

    foreach ($route->parameterNames() as $name) {
        $uri = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', $values[$name] ?? 'sweep-fixture', $uri, 1);
    }

    return '/'.ltrim($uri, '/');
}

/*
|--------------------------------------------------------------------------
| Error/overflow collector
|--------------------------------------------------------------------------
|
| Pest's own javaScriptErrors()/consoleLogs() only override console.log and
| listen for window "error" — they miss console.error/warn, unhandled
| promise rejections and >=400 fetch responses entirely (verified by hand
| against vendor/pestphp/pest-plugin-browser/src/Playwright/InitScript.php).
| This is a wider collector registered the same way (Context::addInitScript),
| so it is active before any of the page's own scripts run.
|
*/

const SWEEP_COLLECTOR_SCRIPT = <<<'JS'
    window.__sweep = { console: [], errors: [], responses: [] };

    const wrap = (level) => {
        const original = console[level];
        console[level] = function (...args) {
            window.__sweep.console.push({ level, message: args.map(String).join(' ') });
            original.apply(console, args);
        };
    };
    wrap('error');
    wrap('warn');

    window.addEventListener('error', (event) => {
        window.__sweep.errors.push({ type: 'error', message: event.message });
    });
    window.addEventListener('unhandledrejection', (event) => {
        window.__sweep.errors.push({ type: 'unhandledrejection', message: String(event.reason) });
    });

    const originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (...args) {
            return originalFetch.apply(window, args).then((response) => {
                if (response.status >= 400) {
                    window.__sweep.responses.push({ url: response.url, status: response.status });
                }
                return response;
            });
        };
    }
    JS;

const SWEEP_READ_SCRIPT = <<<'JS'
    () => {
        const nav = performance.getEntriesByType('navigation')[0] || {};
        const sweep = window.__sweep || { console: [], errors: [], responses: [] };
        return {
            console: sweep.console,
            errors: sweep.errors,
            responses: sweep.responses,
            navStatus: nav.responseStatus ?? null,
            overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        };
    }
    JS;

/**
 * A fresh browser context with the collector active from the very first
 * navigation, on the given URL.
 */
function freshSweepPage(string $url): Page
{
    $page = visit(BrowserLogin::LANDING)->page();
    $page->context()->addInitScript(SWEEP_COLLECTOR_SCRIPT);
    // The context's init script only applies to navigations after it was
    // registered, and the visit() call above already navigated once without
    // it (to a static file, BrowserLogin::LANDING, so no page renders for
    // nothing) — navigate so the very first page is measured too.
    $page->goto(ComputeUrl::from($url));

    return $page;
}

/**
 * @param  list<string>  &$violations
 */
function recordSweepViolations(array $data, string $label, array &$violations): void
{
    if (($data['navStatus'] ?? null) !== null && $data['navStatus'] >= 400) {
        $violations[] = "{$label}: navigation responded {$data['navStatus']}";
    }

    foreach ($data['responses'] as $response) {
        $violations[] = "{$label}: {$response['status']} on {$response['url']}";
    }

    foreach ($data['errors'] as $error) {
        $violations[] = "{$label}: JS {$error['type']}: {$error['message']}";
    }

    foreach ($data['console'] as $entry) {
        $violations[] = "{$label}: console.{$entry['level']}: {$entry['message']}";
    }
}

/**
 * @param  list<string>  &$violations
 */
function recordOverflowViolation(array $data, string $label, array &$violations): void
{
    if ($data['overflow']) {
        $violations[] = "{$label}: horizontal overflow ({$data['scrollWidth']}px content, {$data['clientWidth']}px viewport)";
    }
}

/*
|--------------------------------------------------------------------------
| Top spacing under the header
|--------------------------------------------------------------------------
|
| Every page starts its content at least one page-top step below the
| header's bottom edge: 20px at 375 (MobileChessLobby/MobileLadder
| `padding-top: 20px`) and 32px at 1440 (Clans/ClanShow/Dashboard
| `padding: 32px 48px`). The value lives once, as --spacing-page-top*
| in resources/css/app.css, applied to <main> in layouts/app.blade.php.
|
| "First content" is the topmost visible element inside <main> that paints
| something: its own text, a replaced element (img/svg/canvas/form control),
| a background, a border or a box-shadow. Transparent wrappers do not count,
| because their padding is exactly the gap being measured. Screen-reader-only
| boxes (1px) and fixed overlays are skipped.
|
*/

const SWEEP_PAGE_TOP = [375 => 20, 1024 => 32, 1440 => 32];

/** Minimum distance of any non-full-bleed element from the viewport edges (px-4 on phones). */
const SWEEP_PAGE_SIDE = [375 => 16, 1024 => 16, 1440 => 16];

/**
 * Pages whose design starts flush under the header on purpose, by the path
 * the browser lands on (a redirect such as locale/{locale} is judged by its
 * target): home, which opens with its full-bleed band (the featured
 * tournament or the Blockfill week, pages/home.blade.php), and the login,
 * whose backdrop of covers runs edge to edge from lg (Login.dc.html, revamp
 * P3). The layout opt-out is `<x-layouts::app flush>`. The NIP-05 JSON endpoint has no header at all
 * and is not a page.
 *
 * @var list<string>
 */
const SWEEP_FLUSH_PATHS = ['/', '/login'];

/** @var list<string> Routes that answer without the app shell (JSON, images, the player card fragment, the full-screen tournament TV, the broadcast styleguide). */
const SWEEP_NO_HEADER_ROUTES = ['tournaments.tv', 'broadcast.styleguide', 'nostr.nip05', 'admin.disputes.evidence', 'players.card', 'avatars.generated', 'invites.card',
    'badges.rank', 'badges.rank.thumb', 'cards.rank-up', 'cards.block', 'cards.tournament', 'cards.tournament-invite', 'cards.wrapped', 'cards.blockfill', 'cards.page', 'stream.cover', 'stream.pride-image'];

const SWEEP_GAP_SCRIPT = <<<'JS'
    async () => {
        window.scrollTo(0, 0);
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const header = document.querySelector('body > header');
        const main = document.getElementById('content');
        if (!header || !main) {
            return { gap: null, path: location.pathname, first: !header ? 'no <header>' : 'no <main id="content">' };
        }

        const paints = (el, style) => {
            if ([...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '')) return true;
            if (['IMG', 'svg', 'CANVAS', 'VIDEO', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA'].includes(el.tagName)) return true;
            if (style.backgroundColor !== 'rgba(0, 0, 0, 0)' || style.backgroundImage !== 'none') return true;
            if (style.boxShadow !== 'none') return true;
            return ['Top', 'Right', 'Bottom', 'Left'].some((side) =>
                parseFloat(style[`border${side}Width`]) > 0 && style[`border${side}Style`] !== 'none');
        };

        // A guest's "New here?" strip sits under the sticky header, outside it (it scrolls away): the chrome ends below it.
        const steps = document.querySelector('[data-test=first-steps]');
        const headerBottom = Math.max(header.getBoundingClientRect().bottom, steps?.checkVisibility() ? steps.getBoundingClientRect().bottom : 0);
        const viewport = document.documentElement.clientWidth;
        let top = Infinity;
        let first = null;
        let side = Infinity;
        let sideFirst = null;

        for (const el of main.querySelectorAll('*')) {
            if (el.closest('svg') && el.tagName !== 'svg') continue;
            if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
            const rect = el.getBoundingClientRect();
            if (rect.width <= 1 || rect.height <= 1) continue;
            const style = getComputedStyle(el);
            if (style.position === 'fixed') continue;
            if (!paints(el, style)) continue;
            if (rect.top < top) {
                top = rect.top;
                first = el;
            }
            // Full-bleed bands (a tab bar's hairline, a hero, a phone chess
            // board marked data-bleed) may touch the edges; anything narrower
            // than the viewport needs a gutter. Measure what is actually
            // visible: clip against every clipping ancestor (sr-only, scrollers,
            // tickers), so hidden or scrolled-away parts don't count.
            if (rect.width >= viewport - 1 || el.parentElement.closest('[data-bleed]')) continue;
            let left = rect.left;
            let right = rect.right;
            let scrolledAway = false;
            for (let a = el.parentElement; a && a !== main; a = a.parentElement) {
                const as = getComputedStyle(a);
                if (as.overflowX === 'visible' && as.clip === 'auto' && as.clipPath === 'none') continue;
                const ar = a.getBoundingClientRect();
                // Inside a horizontal scroller that actually overflows (a tab
                // bar on phones): its edge is the scroll affordance.
                if (['auto', 'scroll'].includes(as.overflowX) && a.scrollWidth > a.clientWidth) scrolledAway = true;
                left = Math.max(left, ar.left);
                right = Math.min(right, ar.right);
            }
            if (scrolledAway || right - left <= 1) continue;
            const edge = Math.min(left, viewport - right);
            if (edge < side) {
                side = edge;
                sideFirst = el;
            }
        }

        if (first === null) {
            return { gap: null, path: location.pathname, first: 'nothing painted inside <main>' };
        }

        const name = first.tagName.toLowerCase() + (first.dataset.test ? `[data-test=${first.dataset.test}]` : '')
            + ' "' + (first.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';

        const describe = (el) => el === null ? 'nothing' : el.tagName.toLowerCase() + (el.dataset.test ? `[data-test=${el.dataset.test}]` : '')
            + ' "' + (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';

        return {
            gap: Math.round((top - headerBottom) * 100) / 100,
            side: sideFirst === null ? null : Math.round(side * 100) / 100,
            path: location.pathname,
            first: name,
            sideFirst: describe(sideFirst),
        };
    }
    JS;

/**
 * The collector's findings (SWEEP_READ_SCRIPT) and the top-gap probe
 * (SWEEP_GAP_SCRIPT) at the current viewport, in one round trip to the
 * browser: read first, as two separate calls did, then the probe.
 *
 * @return array{read: array<string, mixed>, gap: array<string, mixed>}
 */
function sweepProbe(Page $page): array
{
    // A navigation after load tears the probe's execution context down
    // mid-frame. Seen once in seven guest sweeps (the page was not
    // identified); retry on the page it lands on, rethrow anything else.
    $data = null;

    for ($attempt = 1; $data === null; $attempt++) {
        try {
            $data = $page->evaluate('async () => ({ read: ('.SWEEP_READ_SCRIPT.')(), gap: await ('.SWEEP_GAP_SCRIPT.')() })');
        } catch (Throwable $e) {
            if ($attempt === 3 || ! str_contains($e->getMessage(), 'Execution context was destroyed')) {
                throw $e;
            }

            Execution::instance()->wait(0.3);
        }
    }

    return $data;
}

/**
 * @param  array<string, mixed>  $data  the probe's answer (sweepProbe()['gap'])
 * @param  list<string>  &$violations
 */
function recordGapViolation(array $data, string $routeName, string $label, int $width, array &$violations): void
{
    if (in_array($routeName, SWEEP_NO_HEADER_ROUTES, true)) {
        return;
    }

    if ($data['gap'] === null) {
        $violations[] = "{$label} at {$width}px: cannot measure the top gap ({$data['first']})";

        return;
    }

    if (in_array($data['path'], SWEEP_FLUSH_PATHS, true)) {
        return;
    }

    if ($data['gap'] < SWEEP_PAGE_TOP[$width]) {
        $violations[] = "{$label} at {$width}px: content starts {$data['gap']}px under the header, needs >= ".SWEEP_PAGE_TOP[$width]."px (first: {$data['first']})";
    }

    if ($data['side'] !== null && $data['side'] < SWEEP_PAGE_SIDE[$width]) {
        $violations[] = "{$label} at {$width}px: content sits {$data['side']}px from the viewport edge, needs >= ".SWEEP_PAGE_SIDE[$width]."px (element: {$data['sideFirst']})";
    }
}

/*
|--------------------------------------------------------------------------
| The sweep
|--------------------------------------------------------------------------
|
| One context per (auth state), reused across every route: the collector is
| registered once, each goto() re-arms it fresh (Context::addInitScript runs
| on every subsequent document). Overflow is also checked at 1024/1440px without
| an extra navigation, by resizing in place.
|
*/

test('every route renders without console errors, page errors, bad responses or overflow', function (bool $authenticated) {
    $user = null;

    if ($authenticated) {
        $user = User::factory()->create();
        Admin::query()->create(['pubkey' => $user->pubkey]);
        test()->actingAs($user);
    }

    config(['esports.stream_bot.pride_notes.image_dir' => storage_path('framework/testing/pride')]);
    File::ensureDirectoryExists(storage_path('framework/testing/pride'));
    File::put(PrideNotes::imagePath(SWEEP_PRIDE_HASH), (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    // /stream/cover.png serves the file the stream daemon last wrote (storage/app/stream/cover.png, gitignored): a checkout that
    // never ran the daemon answered 404 and the sweep went red, one that had was green by accident. The sweep brings its own.
    config(['twentyone.stream.cover.path' => storage_path('framework/testing/sweep-stream-cover.png')]);
    File::put((string) config('twentyone.stream.cover.path'), (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    $routes = [...sweepRoutes(buildSweepFixtures($user)), ...sweepExtraPages($user)];
    expect($routes)->not->toBeEmpty();

    $page = freshSweepPage('/');
    $page->setViewportSize(375, 800);

    $violations = [];

    foreach ($routes as $route) {
        $label = "{$route['name']} ({$route['url']})";

        $page->goto(ComputeUrl::from($route['url']));
        $mobile = sweepProbe($page);

        recordSweepViolations($mobile['read'], $label, $violations);
        recordOverflowViolation($mobile['read'], "{$label} at 375px", $violations);
        recordGapViolation($mobile['gap'], $route['name'], $label, 375, $violations);

        // 1024 is the first desktop width (lg): the header is at its tightest there.
        foreach ([1024, 1440] as $width) {
            $page->setViewportSize($width, 900);
            $desktop = sweepProbe($page);
            recordOverflowViolation($desktop['read'], "{$label} at {$width}px", $violations);
            recordGapViolation($desktop['gap'], $route['name'], $label, $width, $violations);
        }
        $page->setViewportSize(375, 800);
    }

    expect($violations)->toBe([]);
})->with([
    'guest' => [false],
    'member' => [true],
]);

/*
|--------------------------------------------------------------------------
| Positive controls
|--------------------------------------------------------------------------
|
| Prove the collector actually catches something, using the exact assertion
| the sweep above makes (expect($violations)->toBe([])) — if this assertion
| never fires red, the sweep is decorative.
|
*/

test('positive control: the sweep fails on an injected JS error', function () {
    $page = freshSweepPage(route('testing.js-throw'));

    $violations = [];
    recordSweepViolations($page->evaluate(SWEEP_READ_SCRIPT), 'js-throw fixture', $violations);

    expect($violations)->not->toBeEmpty();
    expect(collect($violations)->contains(fn (string $v) => str_contains($v, 'injected JS error')))->toBeTrue();

    expect(fn () => expect($violations)->toBe([]))->toThrow(ExpectationFailedException::class);
});

test('positive control: the top-gap probe measures a page that starts flush', function () {
    // Home is an opt-out: its first band (hero, live bar or mempool) sits directly under the header. The probe must report that as ~0px, or a page that
    // loses its spacing would pass unnoticed.
    $page = freshSweepPage(route('home'));
    $page->setViewportSize(375, 800);

    $data = $page->evaluate(SWEEP_GAP_SCRIPT);

    expect($data['gap'])->toBeLessThan(SWEEP_PAGE_TOP[375])
        ->and($data['first'])->toContain('[data-test=home-');
});

test('positive control: the sweep fails on an injected 500', function () {
    $page = freshSweepPage(route('testing.server-error'));

    $data = $page->evaluate(SWEEP_READ_SCRIPT);
    $violations = [];
    recordSweepViolations($data, 'server-error fixture', $violations);

    expect($data['navStatus'])->toBe(500);
    expect($violations)->not->toBeEmpty();

    expect(fn () => expect($violations)->toBe([]))->toThrow(ExpectationFailedException::class);
});

/*
|--------------------------------------------------------------------------
| Livewire roundtrip
|--------------------------------------------------------------------------
*/

test('a Livewire roundtrip stays clean: submitting the admin form without a key', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    test()->actingAs($admin);

    $page = freshSweepPage(route('admin.admins'));

    $page->getByRole('button', ['name' => __('Add admin')])->click();
    BrowserWait::until($page, '() => document.body.innerText.includes("Pick a player from the suggestions or paste a full npub.")', 10_000);

    $violations = [];
    recordSweepViolations($page->evaluate(SWEEP_READ_SCRIPT), 'admin.admins Livewire roundtrip', $violations);

    expect($violations)->toBe([])
        // The roundtrip failed validation and did not write anything: only
        // the admin created in this test's setup exists.
        ->and(Admin::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Score games (plan "AoE2 und Trackmania", P4)
|--------------------------------------------------------------------------
|
| The score pages exist only while a score game is registered, so the sweep
| above (the app boots with the demo off) never sees them. Here the demo is
| switched on and its pages are swept the same way, at 375 and 1440, for a
| guest and for an admin who also plays the leaderboard (submission form,
| director part, review queue), with one Livewire roundtrip: a submission.
| The positive controls above prove the collector.
|
*/

test('the score pages render clean with the demo on, and a submission roundtrip stays clean', function (bool $authenticated) {
    ScoreDemoOn::play();
    $tournament = Tournament::factory()->scoreDemo()->create(['status' => TournamentStatus::Running, 'starts_at' => now()->subHours(2), 'published_at' => now()->subDay(), 'name' => 'Score Week With A Rather Long Name']);
    $players = User::factory()->count(5)->create();
    $admin = User::factory()->create(['name' => 'admin-and-racer', 'gamer_tags' => ['score-demo' => 'acct-browser-sweep']]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    foreach ([$admin, ...$players] as $player) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1500, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    foreach ($players->take(3)->values() as $index => $player) {
        ScoreRun::query()->create(['user_id' => $player->id, 'game' => 'score-demo', 'mode' => 'time-trial', 'course' => 'demo-1', 'unit' => 'ms',
            'source' => 'manual', 'tournament_id' => $tournament->id, 'value' => 61_000 + 1_234 * $index, 'achieved_at' => now()->subHour(),
            'proof_url' => 'https://example.org/proof/'.$index, 'verified_at' => $index < 2 ? now() : null]);
    }

    if ($authenticated) {
        test()->actingAs($admin);
    }

    $pages = [
        ['scores.show', route('scores.show', 'score-demo')],
        ['tournaments.show', route('tournaments.show', $tournament)],
        ['tournaments.scores', route('tournaments.scores', $tournament)],
        ['play', route('play')],
        ...($authenticated ? [['admin.scores', route('admin.scores')], ['gaming.edit', route('gaming.edit')]] : []),
    ];
    $page = freshSweepPage('/');
    $violations = [];

    foreach ($pages as [$name, $url]) {
        foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto(ComputeUrl::from($url));
            $data = sweepProbe($page);
            recordSweepViolations($data['read'], "{$name} at {$width}px", $violations);
            recordOverflowViolation($data['read'], "{$name} at {$width}px", $violations);
            recordGapViolation($data['gap'], $name, $name, $width, $violations);
            fwrite(STDERR, 'SCORE-MEASURE '.json_encode(['who' => $authenticated ? 'admin' : 'guest', 'page' => $name, 'width' => $width,
                'scrollWidth' => $data['read']['scrollWidth'], 'clientWidth' => $data['read']['clientWidth'], 'status' => $data['read']['navStatus'],
                'boxes' => $page->evaluate(SCORE_BOXES_SCRIPT)])."\n");

            // Never the account id on a page another player can open (the admin's own settings excepted).
            if ($name !== 'gaming.edit') {
                expect($page->content())->not->toContain('acct-browser-sweep');
            }
        }
    }

    if ($authenticated) {
        $page->setViewportSize(375, 800);
        $page->goto(ComputeUrl::from(route('tournaments.scores', $tournament)));
        $page->locator('[data-test="score-value-input"]')->fill('0:58.765');
        $page->locator('[data-test="score-proof-input"]')->fill('https://example.org/my-run');
        $page->locator('[data-test="score-submit-button"]')->click();
        BrowserWait::until($page, '() => (document.querySelector(\'[data-test="score-mine"]\')?.innerText ?? "").includes("0:58.765")', 10_000);
        recordSweepViolations(sweepProbe($page)['read'], 'tournaments.scores submission roundtrip', $violations);

        expect(ScoreRun::query()->where('user_id', $admin->id)->value('value'))->toBe(58_765);
    }

    expect($violations)->toBe([]);
})->with([
    'guest' => [false],
    'admin who plays' => [true],
]);

/** The boxes of the score pages' main parts (P4 measurement): width, height and right edge in CSS px. */
const SCORE_BOXES_SCRIPT = <<<'JS'
    () => Object.fromEntries(["score-leaderboard", "tournament-leaderboard", "score-submit", "score-direct", "score-game", "admin-scores", "score-row", "tag-field-score-demo"]
        .map((key) => {
            const node = document.querySelector(`[data-test="${key}"]`);
            if (! node) return [key, null];
            const box = node.getBoundingClientRect();
            return [key, { w: Math.round(box.width), h: Math.round(box.height), right: Math.round(box.right) }];
        })
        .filter(([, box]) => box !== null))
    JS;

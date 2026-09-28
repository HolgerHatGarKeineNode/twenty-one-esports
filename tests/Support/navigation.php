<?php

use App\Enums\ChessEndReason;
use App\Enums\InviteStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

/*
| The navigation crawler of P16, shared by tests/Browser/NavigationCrawlTest.php
| (the walk per role) and tests/Browser/NavigationMenusTest.php (menus, context
| actions, positive controls). Two files, two shards: together they take longer
| than one shard should (scripts/test-browser.sh).
*/

const NAV_ROLES = ['guest', 'player', 'captain', 'organizer', 'admin'];

const NAV_LOGGED_IN = ['player', 'captain', 'organizer', 'admin'];

/** Width => height of each measured viewport. */
const NAV_VIEWS = [1440 => 900, 1024 => 768, 375 => 667];

/**
 * Who needs which page, and the most clicks it may take. `max` 1: in the
 * chrome; 2: one page below it; 3: below a detail page, 2 from the overview
 * that owns it.
 *
 * @var array<string, array{roles: list<string>, max: int}>
 */
const NAV_PAGES = [
    'home' => ['roles' => NAV_ROLES, 'max' => 1],
    'login' => ['roles' => ['guest'], 'max' => 1],
    'play' => ['roles' => NAV_ROLES, 'max' => 1],
    'chess.lobby' => ['roles' => NAV_ROLES, 'max' => 1],
    'chess.challenge' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'me.correspondence' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'dashboard' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'games.index' => ['roles' => NAV_ROLES, 'max' => 1],
    'live' => ['roles' => NAV_ROLES, 'max' => 1],
    'games.show' => ['roles' => NAV_ROLES, 'max' => 2],
    'games.rocket-league' => ['roles' => NAV_ROLES, 'max' => 1],
    'games.series' => ['roles' => NAV_ROLES, 'max' => 1],
    'ladder.show' => ['roles' => NAV_ROLES, 'max' => 1],
    // P40: beside each game's ladder in the context bar, under Everywhere on phones.
    'ladder.strongest' => ['roles' => NAV_ROLES, 'max' => 1],
    'players.show' => ['roles' => NAV_ROLES, 'max' => 2],
    'mining' => ['roles' => NAV_ROLES, 'max' => 1],
    'matches.index' => ['roles' => NAV_ROLES, 'max' => 1],
    'matches.show' => ['roles' => NAV_ROLES, 'max' => 2],
    'matches.room' => ['roles' => ['captain'], 'max' => 3],
    'challenges.create' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'clans.index' => ['roles' => NAV_ROLES, 'max' => 1],
    'clans.show' => ['roles' => NAV_ROLES, 'max' => 2],
    'clans.create' => ['roles' => ['player'], 'max' => 2],
    'clans.manage' => ['roles' => ['captain'], 'max' => 2],
    'invites.show' => ['roles' => ['player'], 'max' => 1],
    'tournaments.index' => ['roles' => NAV_ROLES, 'max' => 1],
    'tournaments.show' => ['roles' => NAV_ROLES, 'max' => 2],
    'tournaments.draw' => ['roles' => NAV_ROLES, 'max' => 3],
    'tournaments.tv' => ['roles' => NAV_ROLES, 'max' => 3],
    'tournaments.signup' => ['roles' => ['player', 'captain'], 'max' => 3],
    'tournaments.director' => ['roles' => ['organizer', 'admin'], 'max' => 3],
    'tournaments.pool' => ['roles' => ['organizer', 'admin'], 'max' => 3],
    'admin.tournaments' => ['roles' => ['organizer', 'admin'], 'max' => 1],
    'admin.tournaments.create' => ['roles' => ['organizer', 'admin'], 'max' => 2],
    'admin.tournaments.edit' => ['roles' => ['organizer', 'admin'], 'max' => 2],
    'admin.status' => ['roles' => ['admin'], 'max' => 2],
    'admin.disputes' => ['roles' => ['admin'], 'max' => 1],
    'admin.disputes.show' => ['roles' => ['admin'], 'max' => 2],
    'admin.payouts' => ['roles' => ['admin'], 'max' => 2],
    'admin.season' => ['roles' => ['admin'], 'max' => 2],
    'admin.events' => ['roles' => ['admin'], 'max' => 2],
    'admin.trust' => ['roles' => ['admin'], 'max' => 2],
    'admin.fair-play' => ['roles' => ['admin'], 'max' => 2],
    'admin.admins' => ['roles' => ['admin'], 'max' => 2],
    'admin.organizers' => ['roles' => ['admin'], 'max' => 2],
    'gaming.edit' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'settings.account' => ['roles' => NAV_LOGGED_IN, 'max' => 2],
    'settings.notifications' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'settings.chess' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'settings.opponents' => ['roles' => NAV_LOGGED_IN, 'max' => 2],
    'settings.badges' => ['roles' => NAV_LOGGED_IN, 'max' => 1],
    'rules' => ['roles' => NAV_ROLES, 'max' => 1],
    'search' => ['roles' => NAV_ROLES, 'max' => 1],
    'protocol' => ['roles' => NAV_ROLES, 'max' => 1],
];

/**
 * GET routes that are no page a person navigates to, each with its reason.
 * The crawler never opens them (locale.switch would change the language).
 *
 * @var array<string, string>
 */
const NAV_NOT_PAGES = [
    'invites.link' => 'entry point: the invite link a player shares outside the app',
    'challenges.casual' => 'entry point: "Schedule a 1v1" from the casual 1v1 module and a player page, always with ?to=',
    'notifications.dm-off' => 'entry point: the signed link at the end of every DM',
    'settings' => 'redirect to settings/gaming',
    'locale.switch' => 'action: switches the language (footer)',
    'styleguide' => 'local and testing only',
    'players.card' => 'fragment: the player card on hover',
    'players.search' => 'JSON: the player picker suggestions',
    'stream.status' => 'JSON: the live stream status the pages poll',
    'stream.cover' => 'image: the live stream cover the 30311 event points at',
    'admin.disputes.evidence' => 'file: a dispute screenshot, linked from the dispute page',
    'nostr.nip05' => 'JSON for Nostr clients',
    'lnurl.pay' => 'JSON for Lightning wallets: the pool address (LUD-06/16)',
    'lnurl.callback' => 'JSON for Lightning wallets: the invoice of a zap (LUD-06)',
    'robots' => 'file for crawlers',
    'tournaments.calendar' => 'file: the calendar download, linked from the tournament page',
    'sitemap' => 'file for crawlers',
    'sitemap.section' => 'file for crawlers',
    'avatars.generated' => 'image',
    'badges.rank' => 'image',
    'badges.rank.thumb' => 'image',
    'invites.card' => 'image',
    'cards.rank-up' => 'image',
    'cards.block' => 'image',
    'cards.tournament' => 'image',
    'cards.tournament-invite' => 'image',
    'cards.wrapped' => 'image',
];

/**
 * Routes the crawler opens once per URL instead of once per route: a
 * tournament page links other pages in each state (sign-up: "Sign up";
 * running: "The draw", "Director desk").
 *
 * @var list<string>
 */
const NAV_OPEN_EACH = ['tournaments.show'];

/** Vendor and infrastructure prefixes, as in RouteSweepTest. */
const NAV_VENDOR_PREFIXES = ['flux/', 'livewire-', 'storage/', 'broadcasting/', '__test/', 'horizon', 'up'];

/**
 * Every link a person can reach on the page: visible, or behind a visible
 * opener (a shell panel with `data-nav-panel` and the buttons that control
 * it, a Flux dropdown, the mobile menu or search, a <details>). A GET
 * form (the site search) counts as a link to its action, reachable when its
 * field is. `chrome` is true outside <main>.
 */
const NAV_LINKS_SCRIPT = <<<'JS'
    () => {
        const visible = (el) => !!el && el.checkVisibility({ checkVisibilityCSS: true });
        const opener = (a) => {
            // A shell panel (the game hub, the phone's More sheet) opens from any visible button that controls it.
            const panel = a.closest('[data-nav-panel][id]');
            if (panel) return [...document.querySelectorAll(`[aria-controls="${panel.id}"]`)].some(visible) ? panel.id : null;
            const drop = a.closest('ui-dropdown');
            if (drop) return visible(drop.querySelector('button')) ? 'menu' : null;
            if (a.closest('#mobile-nav')) return visible(document.querySelector('[aria-controls=mobile-nav]')) ? 'mobile-menu' : null;
            if (a.closest('#mobile-search')) return visible(document.querySelector('[aria-controls=mobile-search]')) ? 'mobile-search' : null;
            const details = a.closest('details');
            if (details) return visible(details.querySelector('summary')) ? 'details' : null;
            return null;
        };
        const links = [...document.querySelectorAll('a[href]')].map((a) => {
            const url = new URL(a.getAttribute('href'), location.href);
            return {
                url: url.origin === location.origin ? url.pathname + url.search : null,
                chrome: !a.closest('main'),
                how: visible(a) ? 'visible' : opener(a),
                text: (a.getAttribute('aria-label') || a.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
            };
        });
        const forms = [...document.querySelectorAll('form[action]')].filter((f) => (f.getAttribute('method') || 'get').toLowerCase() === 'get').map((f) => {
            const url = new URL(f.getAttribute('action'), location.href);
            const field = f.querySelector('input:not([type=hidden])');
            return {
                url: url.origin === location.origin ? url.pathname : null,
                chrome: !f.closest('main'),
                how: field && visible(field) ? 'form' : (field ? opener(field) : null),
                text: 'form: ' + (f.getAttribute('aria-label') || f.getAttribute('role') || ''),
            };
        });
        return [...links, ...forms].filter((link) => link.url !== null && link.how !== null);
    }
    JS;

/** Two frames after a resize, so layout and visibility have settled. */
const NAV_SETTLE = '() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))';

/**
 * One user per role, and the data every page needs to have something to
 * link: clans with ready lineups, a running series of the captain, a disputed
 * one, a published tournament and a running one the organizer directs, a live
 * and a daily game, a casual chess ladder with rows and a pending clan invite
 * for the player.
 *
 * @return array{users: array<string, User|null>, rival: Lineup}
 */
function navWorld(): array
{
    $player = User::factory()->member()->create(['name' => 'Pia Player']);
    $captainLineup = Lineup::factory()->mode('3v3')->ready()->create();
    $captain = $captainLineup->clan->owner;
    $captain->forceFill(['name' => 'Cato Captain'])->save();
    $rivalLineup = Lineup::factory()->mode('3v3')->ready()->create();
    $organizer = organizer();
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $captainLineup->id, 'challenged_lineup_id' => $rivalLineup->id]);
    SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Disputed]);

    config(['esports.league.nsec' => (new TestSigner)->secret]);
    openTournament(['created_by_id' => $organizer->id], rocketLeague: true);
    // Drawn from a block, as a real running tournament is: its page links the draw.
    runningChess(TournamentFormat::SingleElimination, 4)->forceFill(['created_by_id' => $organizer->id, 'draw_height' => 915000, 'draw_hash' => str_repeat('ab', 32)])->save();

    ChessGame::factory()->create(['white_id' => $player->id]);
    ChessGame::factory()->daily()->create(['black_id' => $player->id]);
    ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Timeout)->create();

    foreach ([$player, $captain, $organizer, $admin, ...User::factory()->count(2)->create()->all()] as $index => $user) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
            'user_id' => $user->id, 'rating' => 1000 + 20 * $index, 'results' => 3, 'wins' => 2, 'draws' => 0, 'losses' => 1]);
    }

    ClanInvite::query()->create([
        'clan_id' => $rivalLineup->clan_id,
        'inviter_id' => $rivalLineup->clan->owner_id,
        'invitee_id' => $player->id,
        'status' => InviteStatus::Pending,
    ]);

    return ['users' => ['guest' => null, 'player' => $player, 'captain' => $captain, 'organizer' => $organizer, 'admin' => $admin], 'rival' => $rivalLineup];
}

/** A page of this role, with the console collector from its first navigation on. */
function navPage(?User $user, int $width = 1440): Page
{
    $landing = '/robots.txt';
    $page = visit($user === null ? $landing : route('testing.login', ['user' => $user, 'to' => $landing]))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, NAV_VIEWS[$width] ?? 900);

    return $page;
}

/**
 * Open a URL and wait for the load. Collected problems of the page are added
 * to $problems.
 *
 * @param  list<string>  $problems
 */
function navOpen(Page $page, string $url, array &$problems): void
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);

    foreach ($page->evaluate('() => window.__errors') as $error) {
        $problems[] = "{$url}: {$error}";
    }

    foreach ($page->evaluate(BrowserConsole::BAD_RESPONSES) as $bad) {
        $problems[] = "{$url}: {$bad}";
    }
}

/**
 * The links of the open page at one width.
 *
 * @return list<array{url: string, chrome: bool, how: string, text: string}>
 */
function navLinksAt(Page $page, int $width): array
{
    $page->setViewportSize($width, NAV_VIEWS[$width]);
    $page->evaluate(NAV_SETTLE);

    return $page->evaluate(NAV_LINKS_SCRIPT);
}

/** The route name of a same-origin path, null for the fallback and unknown paths. */
function navRouteName(string $url): ?string
{
    try {
        $route = Route::getRoutes()->match(Request::create($url, 'GET'));
    } catch (Throwable) {
        return null;
    }

    return $route->isFallback ? null : $route->getName();
}

/**
 * Breadth first from the chrome of the home page, one walk per width. Each
 * URL is opened once and read at every width that reached it.
 *
 * @param  list<int>  $widths
 * @return array{routes: array<int, array<string, array{depth: int, url: string, from: list<string>}>>, chrome: array<int, list<string>>, problems: list<string>, pages: int}
 */
function navCrawl(Page $page, array $widths, int $maxDepth = 3): array
{
    $routes = array_fill_keys($widths, []);
    $chrome = array_fill_keys($widths, []);
    $problems = [];
    $next = [];
    $homeLinks = [];

    $queued = array_fill_keys($widths, []);

    $record = function (int $width, array $link, int $depth, string $from) use (&$routes, &$next, &$queued, $maxDepth): void {
        $name = navRouteName($link['url']);

        if ($name === null) {
            return;
        }

        $first = ! isset($routes[$width][$name]);
        $routes[$width][$name] ??= ['depth' => $depth, 'url' => $link['url'], 'from' => []];

        if (($first || in_array($name, NAV_OPEN_EACH, true)) && $depth < $maxDepth && ! isset(NAV_NOT_PAGES[$name]) && ! isset($queued[$width][$link['url']])) {
            $queued[$width][$link['url']] = true;
            $next[$link['url']]['name'] = $name;
            $next[$link['url']]['widths'][] = $width;
        }

        $entry = $from.'@'.$depth;
        if (! in_array($entry, $routes[$width][$name]['from'], true)) {
            $routes[$width][$name]['from'][] = $entry;
        }
    };

    navOpen($page, '/', $problems);
    $opened = 1;

    foreach ($widths as $width) {
        $homeLinks[$width] = navLinksAt($page, $width);

        foreach ($homeLinks[$width] as $link) {
            if ($link['chrome']) {
                $record($width, $link, 1, 'chrome');
                $chrome[$width][] = $link['url'];
            }
        }

        $chrome[$width] = array_values(array_unique($chrome[$width]));
        sort($chrome[$width]);
    }

    for ($depth = 1; $depth < $maxDepth; $depth++) {
        $level = $next;
        $next = [];

        foreach ($level as $url => ['name' => $name, 'widths' => $at]) {
            if ($name !== 'home') {
                navOpen($page, (string) $url, $problems);
                $opened++;
            }

            foreach ($at as $width) {
                foreach ($name === 'home' ? $homeLinks[$width] : navLinksAt($page, $width) as $link) {
                    if (! $link['chrome']) {
                        $record($width, $link, $depth + 1, $name);
                    }
                }
            }
        }
    }

    return ['routes' => $routes, 'chrome' => $chrome, 'problems' => $problems, 'pages' => $opened];
}

/**
 * The walk of tests/Browser/NavigationCrawlTest.php for some of the roles
 * (the roles are split over two files so each stays inside one shard's
 * minute). NAV_INVENTORY=<file> writes the inventory of these roles, with
 * the first role in the file name.
 *
 * @param  list<string>  $roles
 * @return list<string> failures
 */
function navCrawlRoles(array $roles): array
{
    $world = navWorld();
    $inventory = [];
    $failures = [];

    foreach ($roles as $role) {
        $started = microtime(true);
        $crawl = navCrawl(navPage($world['users'][$role]), array_keys(NAV_VIEWS));
        $inventory[$role] = $crawl['routes'];
        fwrite(STDERR, sprintf("\n[nav-crawl] %s: %d pages opened, routes per width %s, %.1f s", $role, $crawl['pages'],
            json_encode(array_map('count', $crawl['routes'])), microtime(true) - $started));

        foreach ($crawl['problems'] as $problem) {
            $failures[] = "{$role}: {$problem}";
        }

        foreach ($crawl['routes'] as $width => $routes) {
            foreach (NAV_PAGES as $name => $rule) {
                if (! in_array($role, $rule['roles'], true)) {
                    continue;
                }
                $depth = $routes[$name]['depth'] ?? null;
                if ($depth === null) {
                    $failures[] = "{$role} @{$width}px: {$name} is an orphan (not reached within 3 clicks)";
                } elseif ($depth > $rule['max']) {
                    $failures[] = "{$role} @{$width}px: {$name} takes {$depth} clicks, at most {$rule['max']} allowed";
                }
            }
        }
    }

    $file = getenv('NAV_INVENTORY');
    if (is_string($file) && $file !== '') {
        File::put(preg_replace('/(\.json)?$/', '-'.$roles[0].'$1', $file, 1), json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $failures;
}

function navShot(Page $page, string $name): void
{
    $dir = getenv('NAV_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

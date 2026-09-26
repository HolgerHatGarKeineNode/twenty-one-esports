<?php

use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Navigation menus and context actions (P16)
|--------------------------------------------------------------------------
|
| DoD 3: the desktop icon bar, the games menu, the account menu and the
| mobile menu reach the same pages for every role, at 1440, 1280, 1024 and
| 375 px, without horizontal overflow. DoD 4: the actions people look for on
| a page are there (new tournament, challenge a player or a clan, play on an
| empty ladder, a clan's own matches), at 375 and 1440 px, with a clean
| console and no response >= 400. The crawler and its fixtures are in
| tests/Support/navigation.php; the walk per role in NavigationCrawlTest.
|
| NAV_SHOTS=<dir> writes the English screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

test('desktop nav, games menu, account menu and mobile menu reach the same pages for every role', function () {
    $world = navWorld();
    $widths = [1440, 1280, 1024, 375];
    $differences = [];

    foreach ($world['users'] as $role => $user) {
        $page = navPage($user);
        $problems = [];
        navOpen($page, '/', $problems);
        $targets = [];

        foreach ($widths as $width) {
            $page->setViewportSize($width, 800);
            $page->evaluate(NAV_SETTLE);
            $urls = array_map(fn (array $link): string => $link['url'], array_filter($page->evaluate(NAV_LINKS_SCRIPT), fn (array $link): bool => $link['chrome']));
            $targets[$width] = array_values(array_unique($urls));
            sort($targets[$width]);
            // No horizontal overflow of the bar at any width (1024 overflowed before, P5f).
            [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
            if ($scroll > $client) {
                $differences[] = "{$role} @{$width}px: document {$scroll}px wide in a {$client}px viewport";
            }
        }

        fwrite(STDERR, "\n[nav-menus] {$role}: ".count($targets[1440]).' chrome targets: '.implode(' ', $targets[1440]));

        foreach ($widths as $width) {
            $missing = array_diff($targets[1440], $targets[$width]);
            $extra = array_diff($targets[$width], $targets[1440]);
            if ($missing !== [] || $extra !== []) {
                $differences[] = "{$role} @{$width}px: missing ".json_encode(array_values($missing)).', extra '.json_encode(array_values($extra));
            }
        }

        foreach ($problems as $problem) {
            $differences[] = "{$role}: {$problem}";
        }
    }

    expect($differences)->toBe([]);
});

test('context actions sit on the page where they are looked for, at 375 and 1440 px', function () {
    $world = navWorld();
    $users = $world['users'];
    $rival = $world['rival'];

    // Where, who, what: the new tournament on /tournaments, "Challenge" on a player and on a
    // rival clan, "Play" on an empty ladder, and the clan's own matches behind its "All matches".
    $cases = [
        ['tournaments-new', 'organizer', route('tournaments.index', absolute: false), 'index-new-tournament', route('admin.tournaments.create', absolute: false)],
        ['player-challenge', 'player', route('players.show', $users['captain']->npub, false), 'challenge', route('chess.challenge', ['to' => $users['captain']->npub], false)],
        ['clan-challenge', 'captain', route('clans.show', $rival->clan, false), 'challenge-clan', null],
        ['ladder-empty', 'player', route('ladder.show', ['rocket-league', '3v3'], false).'?pool=casual', 'ladder-empty-play', route('games.rocket-league', absolute: false)],
        ['clan-matches', 'guest', route('clans.show', $rival->clan, false), 'clan-matches', route('matches.index', ['clan' => $rival->clan->slug], false)],
    ];
    $problems = [];

    foreach ([375, 1440] as $width) {
        $pages = [];

        foreach ($cases as [$shot, $role, $url, $test, $href]) {
            $page = $pages[$role] ??= navPage($users[$role], $width);
            navOpen($page, $url, $problems);

            $action = $page->evaluate(sprintf('() => { const a = document.querySelector(%s); if (!a) return null; a.scrollIntoView({ block: "center" }); const r = a.getBoundingClientRect(); return { href: a.getAttribute("href"), visible: a.checkVisibility(), w: Math.round(r.width), h: Math.round(r.height), left: Math.round(r.left), right: Math.round(r.right), lang: document.documentElement.lang }; }', json_encode("[data-test=\"{$test}\"]")));
            fwrite(STDERR, "\n[nav-action] {$width}px {$shot}: ".json_encode($action));

            expect($action)->not->toBeNull("{$shot} at {$width}px: no [data-test={$test}]")
                ->and($action['visible'])->toBeTrue()
                ->and($action['h'])->toBeGreaterThanOrEqual(44)
                ->and($action['left'])->toBeGreaterThanOrEqual(0)
                ->and($action['right'])->toBeLessThanOrEqual($width)
                ->and($action['lang'])->toBe('en');

            if ($href !== null) {
                expect(parse_url(url($action['href']), PHP_URL_PATH).(($q = parse_url(url($action['href']), PHP_URL_QUERY)) ? '?'.$q : ''))->toBe($href);
            }

            $widths = $page->evaluate(BrowserConsole::WIDTHS);
            expect($widths[0])->toBeLessThanOrEqual($widths[1]);
            navShot($page, "p16-{$shot}-{$width}");
        }

        // The clan challenge opens the challenge form with the captain's lineup and the rival picked.
        $page = $pages['captain'];
        navOpen($page, route('clans.show', $rival->clan, false), $problems);
        $page->locator('[data-test=challenge-clan]')->click();
        BrowserWait::until($page, '() => location.pathname === "/challenges/create" && document.readyState === "complete"', 10_000);
        expect($page->evaluate('() => new URLSearchParams(location.search).get("to")'))->toBe((string) $rival->id);
        navShot($page, "p16-clan-challenge-form-{$width}");

        // The whole menu of an admin, and the menu bar at this width.
        $admin = navPage($users['admin'], $width);
        navOpen($admin, '/clans', $problems);
        if ($width === 375) {
            $admin->locator('[aria-controls=mobile-nav]')->click();
            BrowserWait::until($admin, '() => document.getElementById("mobile-nav").checkVisibility()', 5_000);
            $panel = $admin->evaluate('() => { const r = document.getElementById("mobile-nav").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), document.getElementById("mobile-nav").scrollHeight]; }');
            fwrite(STDERR, "\n[nav-menu] 375px admin menu top/bottom/scrollHeight ".json_encode($panel));
            expect($panel[1])->toBeLessThanOrEqual(667);
            navShot($admin, 'p16-mobile-menu-admin-375');
        } else {
            $admin->locator('[data-test=games-menu]')->click();
            BrowserWait::until($admin, '() => document.querySelector("[data-test=games-menu-rocket-league]").checkVisibility()', 5_000);
            navShot($admin, 'p16-games-menu-admin-1440');
        }
    }

    expect($problems)->toBe([]);
});

test('positive controls: the crawler misses a link nobody can see, and the collector catches a thrown error, a 404 fetch and a 500 page', function () {
    $player = User::factory()->create();
    $page = navPage($player);
    $problems = [];
    navOpen($page, '/rules', $problems);

    // Visibility filter: a hidden link with no opener is no way to a page, a visible one is.
    $page->evaluate('() => { const main = document.querySelector("main"); main.insertAdjacentHTML("beforeend", \'<a href="/__probe/hidden" style="display:none">hidden</a><a href="/__probe/shown">shown</a>\'); }');
    $urls = array_column($page->evaluate(NAV_LINKS_SCRIPT), 'url');
    expect($urls)->toContain('/__probe/shown')->not->toContain('/__probe/hidden');

    // Depth: with one click allowed, a clan page (two clicks) is not reached.
    $shallow = navCrawl($page, [1440], 1);
    expect($shallow['routes'][1440])->toHaveKey('clans.index')->not->toHaveKey('clans.show');

    // Collector: a thrown error and a 404 fetch land in window.__errors, a 500 page in the responses.
    navOpen($page, '/rules', $problems);
    $page->evaluate('() => { setTimeout(() => { throw new Error("nav positive control"); }); fetch("/__probe/missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("nav positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);

    $caught = [];
    navOpen($page, route('testing.server-error', absolute: false), $caught);
    expect($problems)->toBe([])
        ->and(implode("\n", $caught))->toContain('500 ');
});

test('the header search: type and press Enter at 375 and 1440 px, results for a name, a jump for a match number', function () {
    User::factory()->create(['name' => 'Mempool Max']);
    Clan::factory()->create(['name' => 'Mempool Miners', 'clantag' => 'MEM1']);
    $series = SeriesMatch::factory()->create();
    $problems = [];

    foreach ([375 => '#site-search-mobile', 1440 => '#site-search'] as $width => $field) {
        $page = navPage(null, $width);
        navOpen($page, '/rules', $problems);

        if ($width === 375) {
            $page->locator('[aria-controls=mobile-search]')->click();
            BrowserWait::until($page, '() => document.getElementById("site-search-mobile").checkVisibility()', 5_000);
        }

        $page->locator($field)->fill('mempool');
        $page->locator($field)->press('Enter');
        BrowserWait::until($page, '() => location.pathname === "/search" && document.readyState === "complete"', 10_000);
        $state = $page->evaluate('() => ({ players: document.querySelectorAll("[data-test=search-player]").length, clans: document.querySelectorAll("[data-test=search-clan]").length, q: document.getElementById("search-page-q").value, lang: document.documentElement.lang })');
        fwrite(STDERR, "\n[nav-search] {$width}px results ".json_encode($state).' widths '.json_encode($page->evaluate(BrowserConsole::WIDTHS)));
        expect($state)->toBe(['players' => 1, 'clans' => 1, 'q' => 'mempool', 'lang' => 'en']);
        [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
        expect($scroll)->toBeLessThanOrEqual($client);
        foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
            $problems[] = "{$width}px results: {$problem}";
        }
        navShot($page, "p16-search-results-{$width}");

        // Nothing found: the empty state, searched from the field on the results page.
        $page->locator('#search-page-q')->fill('zzzz');
        $page->locator('#search-page-q')->press('Enter');
        BrowserWait::until($page, '() => document.querySelector("[data-test=search-empty]") !== null', 10_000);
        expect($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($width);
        navShot($page, "p16-search-empty-{$width}");

        // A match number jumps straight to the match.
        $page->locator('#search-page-q')->fill('#'.$series->number);
        $page->locator('#search-page-q')->press('Enter');
        BrowserWait::until($page, '() => location.pathname === "/matches/'.$series->number.'" && document.readyState === "complete"', 10_000);
        foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
            $problems[] = "{$width}px match: {$problem}";
        }
    }

    expect($problems)->toBe([]);
});

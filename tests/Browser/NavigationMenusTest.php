<?php

use App\Enums\SeriesStatus;
use App\Games\Blockfill;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use App\Support\Navigation\AdminNavigation;
use App\Support\Stacker\BlockfillWeeks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
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
            // The More sheet of the tab bar (header concept B): it ends on the tab bar and scrolls inside if it must.
            $admin->locator('[data-test=tab-more]')->click();
            BrowserWait::until($admin, '() => document.getElementById("more-sheet").checkVisibility()', 5_000);
            // Measured after its 220 ms rise, not in the middle of it.
            $admin->evaluate('() => Promise.all(document.getElementById("more-sheet").getAnimations().map((a) => a.finished)).then(() => true)');
            $panel = $admin->evaluate('() => { const r = document.getElementById("more-sheet").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), document.getElementById("more-sheet").scrollHeight, Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top)]; }');
            fwrite(STDERR, "\n[nav-menu] 375px admin More sheet top/bottom/scrollHeight/tab bar top ".json_encode($panel));
            expect($panel[0])->toBeGreaterThanOrEqual(0)
                ->and($panel[1])->toBe($panel[3]);
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

    // One field at every width, in the search row under the header (plan "Mempool-Streifen", P4): the button opens it on a phone, "/" on desktop.
    foreach ([375 => '#site-search', 1440 => '#site-search'] as $width => $field) {
        $page = navPage(null, $width);
        navOpen($page, '/rules', $problems);

        if ($width === 375) {
            $page->locator('[aria-controls=mobile-search]')->click();
        } else {
            $page->evaluate('() => document.activeElement.blur()');
            $page->locator('body')->press('/');
            BrowserWait::until($page, '() => document.activeElement?.id === "site-search"', 5_000);
            // The row opens under the header, its field at the end of row 1 (same 32 px inset), below the button.
            $row = $page->evaluate('() => { const f = document.getElementById("site-search").getBoundingClientRect(), b = document.querySelector("[data-test=mobile-search-toggle]").getBoundingClientRect(), r = document.querySelector("body > header > div").getBoundingClientRect(); return [Math.round(f.width), Math.round(r.right - f.right), f.top >= b.bottom]; }');
            fwrite(STDERR, "\n[nav-search] 1440px field width, inset from the right, below the button: ".json_encode($row));
            expect($row)->toBe([384, 32, true]);
        }
        BrowserWait::until($page, '() => document.getElementById("site-search").checkVisibility()', 5_000);

        // Esc closes the row and gives the focus back to the search button, on a phone and on desktop, as the hub and the More sheet do.
        $page->locator('#site-search')->press('Escape');
        BrowserWait::until($page, '() => !document.getElementById("site-search").checkVisibility() && document.activeElement?.dataset.test === "mobile-search-toggle"', 5_000);
        $page->locator('[data-test=mobile-search-toggle]')->click();
        BrowserWait::until($page, '() => document.getElementById("site-search").checkVisibility() && document.activeElement?.id === "site-search"', 5_000);

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

test('an open challenge shows in the opponent list and replaces the send button, at 375 and 1440 px', function () {
    $me = User::factory()->create(['locale' => 'en']);
    $opponent = User::factory()->create(['name' => 'open-rival']);
    User::factory()->count(10)->create();
    app(DailyChallenges::class)->challenge($me, $opponent);
    $problems = [];

    foreach ([375, 1440] as $width) {
        $page = navPage($me, $width);
        navOpen($page, route('chess.challenge', absolute: false), $problems);

        $badge = $page->evaluate('() => { const b = document.querySelector(\'[data-test="open-challenge-badge"]\'); if (!b) return null; const r = b.getBoundingClientRect(); return {w: r.width, h: r.height, right: r.right, vw: innerWidth, text: b.textContent.trim()}; }');
        expect($badge)->not->toBeNull()
            ->and($badge['text'])->toBe('challenge sent')
            ->and($badge['h'])->toBeGreaterThan(0)
            ->and($badge['right'])->toBeLessThanOrEqual($badge['vw']);

        $page->locator('[data-test="open-challenge-badge"]')->click();
        BrowserWait::until($page, '() => !!document.querySelector(\'[data-test="open-challenge-notice"]\')', 5_000);
        expect($page->evaluate('() => !!document.querySelector(\'[data-test="send-challenge"]\')'))->toBeFalse()
            ->and($page->evaluate('() => document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();

        foreach ($page->evaluate('() => window.__errors') as $error) {
            $problems[] = "after pick: {$error}";
        }

        $page->screenshot(true, "open-challenge-{$width}");
    }

    expect($problems)->toBe([]);
});

test('the admin nav: groups on top, only the active group\'s pages below, the whole map behind one disclosure, at 375 and 1440 px in English and German', function () {
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Disputed]);
    $page = navPage($admin);
    $problems = [];
    $failures = [];

    // Per viewport: sideways scroll, the nav, the rows, and the visible nav links as [text, height, right edge].
    $measure = <<<'JS'
        () => {
            const nav = document.querySelector('[data-test=admin-nav]');
            const vis = (el) => !!el && el.checkVisibility({ checkVisibilityCSS: true });
            const links = [...nav.querySelectorAll('a')].filter(vis).map((a) => { const r = a.getBoundingClientRect(); return [a.innerText.trim().replace(/\s+/g, ' '), Math.round(r.height), Math.round(r.right)]; });
            // The phone row: its height and the outer edges of what it paints (text and chevron), not of its box.
            const summaryBox = nav.querySelector('summary').getBoundingClientRect();
            const painted = [...nav.querySelectorAll('summary > *')].filter(vis).map((el) => el.getBoundingClientRect()).filter((r) => r.width > 0);
            return {
                lang: document.documentElement.lang,
                scroll: document.documentElement.scrollWidth,
                client: document.documentElement.clientWidth,
                navHeight: Math.round(nav.getBoundingClientRect().height),
                groups: vis(nav.querySelector('[data-test=admin-nav-groups]')),
                activeGroup: nav.querySelector('[data-test=admin-nav-groups] [aria-current=true]')?.innerText.trim() ?? null,
                pages: [...nav.querySelectorAll('[data-test=admin-nav-pages] a')].filter(vis).map((a) => a.dataset.test),
                current: [...nav.querySelectorAll('[aria-current=page]')].filter(vis).map((a) => a.dataset.test),
                summary: [Math.round(summaryBox.height), Math.min(...painted.map((r) => r.left)), Math.max(...painted.map((r) => r.right))],
                links,
            };
        }
        JS;

    foreach (['en', 'de'] as $locale) {
        if ($locale === 'de') {
            $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
        }

        // Tournaments: the longest group and page pair in English ("Tournaments / All tournaments").
        foreach (['disputes' => 'league', 'organizers' => 'people', 'tournaments' => 'tournaments'] as $key => $group) {
            navOpen($page, route('admin.'.$key, absolute: false), $problems);

            foreach ([1440, 375, 320] as $width) {
                $page->setViewportSize($width, 900);
                $page->evaluate(NAV_SETTLE);
                // The chevron turns back for 150 ms after the map closed; a turning box is wider than the chevron.
                $page->evaluate('() => Promise.all(document.querySelector("[data-test=admin-nav]").getAnimations({ subtree: true }).map((a) => a.finished)).then(() => true)');
                $m = $page->evaluate($measure);
                $widest = max(array_column($m['links'], 2) ?: [0]);
                fwrite(STDERR, "\n[admin-nav] {$locale} {$key} @{$width} ".json_encode(array_diff_key($m, ['links' => true]))." widest link right edge {$widest}");
                $short = array_filter($m['links'], fn (array $link): bool => $link[1] < 44);
                $ok = $m['lang'] === $locale && $m['scroll'] <= $m['client'] && $short === [] && $m['summary'][0] >= 44
                    && $m['summary'][1] >= 16 && $m['summary'][2] <= $m['client'] - 16;

                if ($width === 1440) {
                    $expected = array_map(fn (string $page): string => 'admin-nav-'.$page, array_keys(array_filter(AdminNavigation::PAGES, fn (string $g, string $p): bool => $g === $group && ($p !== 'scores' || Route::has('admin.scores')) && ($p !== 'blockfill' || Route::has('admin.blockfill')), ARRAY_FILTER_USE_BOTH)));
                    // Two rows of 44 px and the hairline; as wide as the widest group, not as all eleven pages with their labels.
                    $ok = $ok && $m['groups'] && $m['navHeight'] <= 89 && $m['pages'] === $expected && $m['current'] === ['admin-nav-'.$key]
                        && $m['activeGroup'] === AdminNavigation::groupLabel($group) && $widest <= 640;
                    // An open case waits in League: seen from another group, the League tab carries the count.
                    if ($group !== 'league') {
                        $ok = $ok && in_array(AdminNavigation::groupLabel('league').' 1 '.__('waiting'), array_column($m['links'], 0), true);
                    }
                } else {
                    $ok = $ok && ! $m['groups'] && $m['links'] === [];
                }

                if (! $ok) {
                    $failures[] = "{$locale} {$key} @{$width}: ".json_encode($m);
                }

                if ($key === 'disputes' && $width !== 320) {
                    navShot($page, "p17-admin-nav-{$locale}-{$width}");
                    // The whole map opens from the one disclosure, every page a row of 44 px, and Escape closes it.
                    $page->locator('[data-test=admin-nav-menu] summary')->click();
                    $page->evaluate(NAV_SETTLE);
                    $map = $page->evaluate('() => [...document.querySelectorAll("[data-test=admin-nav-menu] a")].filter((a) => a.checkVisibility()).map((a) => Math.round(a.getBoundingClientRect().height))');
                    $fits = $page->evaluate(BrowserConsole::WIDTHS);
                    navShot($page, "p17-admin-nav-{$locale}-{$width}-map");
                    $page->locator('[data-test=admin-nav-menu] summary')->press('Escape');
                    $closed = $page->evaluate('() => !document.querySelector("[data-test=admin-nav-menu]").open && document.activeElement.tagName === "SUMMARY"');
                    if (count($map) !== count(array_filter(array_keys(AdminNavigation::PAGES), fn (string $p): bool => ($p !== 'scores' || Route::has('admin.scores')) && ($p !== 'blockfill' || Route::has('admin.blockfill')))) || min($map ?: [0]) < 44 || $fits[0] > $fits[1] || ! $closed) {
                        $failures[] = "{$locale} map @{$width}: ".json_encode([$map, $fits, $closed]);
                    }
                }
            }

            foreach ($page->evaluate('() => window.__errors') as $error) {
                $problems[] = "{$key}: {$error}";
            }
        }
    }

    // Positive control, same page and collector: a thrown error is caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("admin nav control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("admin nav control"))', 5_000);

    expect($failures)->toBe([])->and($problems)->toBe([]);
});

test('Blockfill\'s replays: a tab of its context bar and its tab bar, marked on the replays page, on a replay and on a shared moment, at 375 and 1440 px', function () {
    BlockfillOn::play();
    leagueWeeksApproved(Blockfill::SLUG);
    $player = User::factory()->create(['name' => 'Replay Walker']);
    $forty = BlockfillOn::fixture('forty-lines');
    $run = StackerRun::factory()->for($player)->verified(958)->create(['replay' => $forty['replay'], 'state_hash' => '6102773e', 'seed' => $forty['seed']]);
    app(BlockfillWeeks::class)->record($run, now());
    $problems = [];

    foreach ([1440, 375] as $width) {
        $page = navPage($player, $width);

        foreach (['replays' => route('stacker.replays', absolute: false), 'replay' => route('stacker.replay', $run, false), 'moment' => route('stacker.moment', $run->id, false)] as $key => $url) {
            navOpen($page, $url, $problems);
            // [the bar shown, its Replays link: href, aria-current, height, left, right; how many links of the bar are current]
            $bar = $page->evaluate(<<<'JS'
                () => {
                    const shown = [...document.querySelectorAll('[data-test=context-bar], [data-test=tab-bar]')].find((bar) => bar.checkVisibility());
                    const link = shown?.querySelector('[data-test=ctx-replays], [data-test=tab-replays]');
                    const r = link?.getBoundingClientRect();
                    return link ? [shown.dataset.test, link.getAttribute('href'), link.getAttribute('aria-current'), Math.round(r.height), Math.round(r.left), Math.round(r.right),
                        shown.querySelectorAll('a[aria-current=page]').length, document.documentElement.scrollWidth <= document.documentElement.clientWidth] : null;
                }
                JS);
            fwrite(STDERR, "\n[nav-replays] {$width}px {$key}: ".json_encode($bar));

            expect($bar)->not->toBeNull("{$key} at {$width}px: no Replays in the shown bar")
                ->and($bar[0])->toBe($width >= 1024 ? 'context-bar' : 'tab-bar')
                ->and($bar[1])->toBe(route('stacker.replays'))
                ->and($bar[2])->toBe('page')
                ->and($bar[3])->toBeGreaterThanOrEqual(44)
                ->and($bar[4])->toBeGreaterThanOrEqual(0)
                ->and($bar[5])->toBeLessThanOrEqual($width)
                ->and($bar[6])->toBe(1)
                ->and($bar[7])->toBeTrue();
            navShot($page, "replays-nav-{$key}-{$width}");
        }

        // From the game page the Replays tab leads to the replays page.
        navOpen($page, route('stacker.play', absolute: false), $problems);
        $page->locator($width >= 1024 ? '[data-test=ctx-replays]' : '[data-test=tab-replays]')->click();
        BrowserWait::until($page, '() => location.pathname === "/blockfill/replays" && document.readyState === "complete"', 10_000);
        expect($page->evaluate('() => !! document.querySelector("[data-test=replays-page]")'))->toBeTrue();
    }

    // Positive control on the same page and collector: a throw is caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("replays nav control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("replays nav control"))', 5_000);

    expect($problems)->toBe([]);
});

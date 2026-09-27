<?php

use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\FakeGame;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Shell navigation, header concept B "game tabs"
|--------------------------------------------------------------------------
|
| Row 1 (game tabs, the hub, Clans, Season, search, account, Admin), row 2
| (the context bar of the active game), the game hub, and below lg the game
| chips, the tab bar and the More sheet (components/shell/). Measured with
| real rects (every width and role: ShellNavigationWidthsTest): the
| chrome height, tap targets, the hub with 4 games and with 12 (FakeGame, a
| test-only registry), the match dock above the tab bar. Every visited page
| keeps a clean console and no response >= 400 (BrowserConsole), with its
| positive control in the last test.
|
| SHELL_SHOTS=<dir> writes the English screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

test('the game hub opens with a click, filters and toggles, pins your games in order and closes with Esc, focus back on its button', function () {
    $player = shellPlayer();
    $problems = [];
    $page = shellPage($player, 1440);
    shellOpen($page, '/clans', $problems);

    // Your games first, by the last match: Rocket League, then chess, then the rest in registry order.
    expect($page->evaluate('() => [...document.querySelectorAll(".gtab:not(.gtab-hub)")].map((el) => el.dataset.test)'))
        ->toBe(['game-tab-rocket-league', 'game-tab-chess', 'game-tab-ea-sports-fc-27']);

    $page->locator('[data-test=games-menu]')->click();
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility() && document.activeElement?.id === "hub-filter"', 5_000);
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test^=hub-game-]:has([data-test=hub-yours])")].map((el) => el.dataset.test)'))
        ->toBe(['hub-game-rocket-league', 'hub-game-chess']);
    $hub = $page->evaluate('() => { const r = document.getElementById("game-hub").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height), Math.round(r.left), Math.round(r.right)]; }');
    fwrite(STDERR, "\n[shell-hub] 4 games @1440 top/height/left/right ".json_encode($hub));
    shellShot($page, 'shell-player-1440-hub');

    // The filter: "rock" leaves Rocket League; the toggle "1v1" hides no real game (every one has a 1v1 ladder).
    $page->locator('#hub-filter')->fill('rock');
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test^=hub-game-]")].filter((el) => el.checkVisibility()).length === 1', 5_000);
    $page->locator('#hub-filter')->fill('zzz');
    BrowserWait::until($page, '() => document.querySelector("[data-test=hub-empty]").checkVisibility()', 5_000);
    $page->locator('#hub-filter')->fill('');
    $page->locator('[data-test=hub-kind-solo]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=hub-kind-solo]").getAttribute("aria-pressed") === "true"', 5_000);
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test^=hub-game-]")].filter((el) => el.checkVisibility()).length'))->toBe(4);

    // Tab stays inside, Esc closes and gives the focus back to "All 4 games".
    foreach (range(1, 40) as $step) {
        $page->locator(':focus')->press('Tab');
    }
    expect($page->evaluate('() => document.getElementById("game-hub").contains(document.activeElement)'))->toBeTrue();
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => !document.getElementById("game-hub").checkVisibility() && document.activeElement?.dataset.test === "games-menu"', 5_000);

    // The keyboard opens it too.
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
    $page->locator(':focus')->press('Escape');

    // "/" jumps to the search field from anywhere but a field.
    $page->evaluate('() => document.activeElement.blur()');
    $page->locator('body')->press('/');
    expect($page->evaluate('() => document.activeElement?.id'))->toBe('site-search');

    expect($problems)->toBe([]);
});

/**
 * The open hub: its rect, whether the tile list scrolls, how much of the
 * panel's inner width the first row of cards spans, how many cards that row
 * holds and how tall they are, and where the filter row sits.
 */
const HUB_MEASURE = <<<'JS'
    () => {
        const hub = document.getElementById('game-hub');
        const r = hub.getBoundingClientRect();
        const list = hub.querySelector('[x-ref=hubTiles]');
        const cards = [...hub.querySelectorAll('[data-test^=hub-game-]')].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect());
        const top = Math.min(...cards.map((c) => c.top));
        const row = cards.filter((c) => Math.abs(c.top - top) < 2);
        const span = Math.max(...row.map((c) => c.right)) - Math.min(...row.map((c) => c.left));
        return {
            top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height), width: Math.round(r.width),
            scrollHeight: list.scrollHeight, clientHeight: list.clientHeight,
            fill: Math.round((span / hub.clientWidth) * 1000) / 1000,
            columns: row.length,
            heights: [...new Set(row.map((c) => Math.round(c.height)))],
            filterTop: Math.round(hub.querySelector('#hub-filter').getBoundingClientRect().top),
        };
    }
    JS;

test('the hub spends its width on one card grid and does not scroll with 4 games at 1280, 1440 and 1920 px', function () {
    $player = shellPlayer();
    $problems = [];
    $sizes = [];

    foreach ([1280 => 800, 1440 => 900, 1920 => 1080] as $width => $height) {
        $page = shellPage($player, $width, $height);
        shellOpen($page, '/clans', $problems);
        $page->locator('[data-test=games-menu]')->click();
        BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
        $page->evaluate(SHELL_SETTLE);
        $m = $sizes[$width] = $page->evaluate(HUB_MEASURE);
        shellShot($page, "shell-player-{$width}-hub");

        expect($m['scrollHeight'])->toBeLessThanOrEqual($m['clientHeight'], "inner scroll @{$width}: ".json_encode($m))
            ->and($m['bottom'])->toBeLessThanOrEqual($height)
            ->and($m['fill'])->toBeGreaterThanOrEqual(0.9, "first row fill @{$width}: ".json_encode($m))
            ->and($m['columns'])->toBe(4)
            ->and($m['heights'])->toHaveCount(1);
    }

    // Your games first, marked; each card leads with its primary action.
    expect($page->evaluate('() => [...document.querySelectorAll("#game-hub [data-test^=hub-game-]")].map((el) => el.dataset.test.replace("hub-game-", "") + (el.querySelector("[data-test=hub-yours]") ? "*" : ""))'))
        ->toBe(['rocket-league*', 'chess*', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        ->and($page->evaluate('() => ["chess", "rocket-league"].map((slug) => document.querySelector(`[data-test=hub-game-${slug}] .hub-action`).innerText.trim())'))
        ->toBe(['Play blitz', 'Challenge a clan']);

    fwrite(STDERR, "\n[shell-hub-grid] ".json_encode($sizes));
    expect($problems)->toBe([]);
});

test('row 2 follows the game of the page, and a page of every game keeps the game opened last', function () {
    $player = User::factory()->create();
    $problems = [];
    $page = shellPage($player, 1280);
    $state = '() => ({ game: document.querySelector("[data-test=context-bar]").dataset.game, current: document.querySelector(".gtab[aria-current]")?.dataset.test ?? null, how: document.querySelector(".gtab[aria-current]")?.getAttribute("aria-current") ?? null, links: [...document.querySelectorAll("[data-test=context-bar] a")].map((a) => a.dataset.test) })';

    // No history: the first registered game.
    shellOpen($page, '/clans', $problems);
    expect($page->evaluate($state))->toMatchArray(['game' => 'chess', 'current' => 'game-tab-chess', 'how' => 'true']);

    shellOpen($page, route('games.rocket-league', absolute: false), $problems);
    $rl = $page->evaluate($state);
    expect($rl)->toMatchArray(['game' => 'rocket-league', 'current' => 'game-tab-rocket-league', 'how' => 'page'])
        ->and($rl['links'])->toBe(['ctx-play', 'ctx-matches', 'ctx-challenge', 'ctx-ladder']);
    shellShot($page, 'shell-player-1280-rocket-league');

    shellOpen($page, '/clans', $problems);
    expect($page->evaluate($state))->toMatchArray(['game' => 'rocket-league', 'how' => 'true']);

    shellOpen($page, route('ladder.show', ['chess', 'blitz'], false), $problems);
    $chess = $page->evaluate($state);
    expect($chess)->toMatchArray(['game' => 'chess', 'how' => 'page'])
        ->and($chess['links'])->toBe(['ctx-play', 'ctx-daily', 'ctx-challenge', 'ctx-watch', 'ctx-matches', 'ctx-ladder', 'ctx-settings']);

    expect($problems)->toBe([]);
});

test('the hub with 8 and 12 games (a test-only registry): 8 fit without scrolling, 12 scroll under a filter row that stays, at 1440 and 375 px', function () {
    $admin = shellAdmin();
    $problems = [];
    $sizes = [];

    foreach ([4, 8, 12] as $count) {
        // FakeGame::registry() builds on the bound registry: the real one each time, not the last fake.
        app()->forgetInstance(GameRegistry::class);
        app()->instance(GameRegistry::class, FakeGame::registry($count));

        foreach ([1440 => 900, 375 => 667] as $width => $height) {
            $page = shellPage($admin, $width, $height);
            shellOpen($page, '/rules', $problems);
            $page->locator($width === 1440 ? '[data-test=games-menu]' : '[data-test=mobile-games-menu]')->click();
            BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
            $page->evaluate(SHELL_SETTLE);
            $m = $page->evaluate(HUB_MEASURE);
            $m['tiles'] = $page->evaluate('() => document.querySelectorAll("#game-hub [data-test^=hub-game-]").length');
            $m['tabbarTop'] = $page->evaluate('() => Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top)');
            expect($m['tiles'])->toBe($count)
                ->and($m['bottom'])->toBeLessThanOrEqual($width === 375 ? $m['tabbarTop'] : $height);
            shellShot($page, "shell-admin-{$width}-hub-{$count}games");

            // Scrolled to its end, the list leaves the filter row where it was.
            if ($m['scrollHeight'] > $m['clientHeight']) {
                $page->evaluate('() => { const list = document.querySelector("#game-hub [x-ref=hubTiles]"); list.scrollTop = list.scrollHeight; }');
                $m['filterTopScrolled'] = $page->evaluate('() => Math.round(document.getElementById("hub-filter").getBoundingClientRect().top)');
                expect($m['filterTopScrolled'])->toBe($m['filterTop']);
            }
            $sizes["{$count}@{$width}"] = $m;
        }
    }

    app()->forgetInstance(GameRegistry::class);
    fwrite(STDERR, "\n[shell-hub] ".json_encode($sizes));

    expect($sizes['8@1440']['scrollHeight'])->toBeLessThanOrEqual($sizes['8@1440']['clientHeight'])
        ->and($sizes['12@1440']['scrollHeight'])->toBeGreaterThan($sizes['12@1440']['clientHeight'])
        ->and($sizes['12@375']['scrollHeight'])->toBeGreaterThan($sizes['12@375']['clientHeight'])
        ->and($problems)->toBe([]);
});

test('on a phone: game chips, the tab bar, the More sheet and the hub sheet; the match dock sits above the tab bar; a guest sees "Log in" whole', function () {
    $player = shellPlayer();
    $problems = [];

    // The guest first: the pages of one test share their cookies, so a guest after the login would not be one.
    $guest = shellPage(null, 375, 667);
    shellOpen($guest, '/', $problems);
    $login = $guest->evaluate('() => { const r = document.querySelector("[data-test=mobile-login]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.top), Math.round(r.bottom), Math.round(r.height)]; }');
    fwrite(STDERR, "\n[shell-mobile] guest Log in rect ".json_encode($login));
    expect($login[0])->toBeGreaterThanOrEqual(0)
        ->and($login[1])->toBeLessThanOrEqual(375)
        ->and($login[2])->toBeGreaterThanOrEqual(0)
        ->and($login[4])->toBeGreaterThanOrEqual(44);
    shellShot($guest, 'shell-guest-375-home');

    $guest->locator('[data-test=tab-more]')->click();
    BrowserWait::until($guest, '() => document.getElementById("more-sheet").checkVisibility()', 5_000);
    shellShot($guest, 'shell-guest-375-more');

    // "New here?": dismissed, it stays away on the next page.
    $guest->locator(':focus')->press('Escape');
    BrowserWait::until($guest, '() => !document.getElementById("more-sheet").checkVisibility() && document.activeElement?.dataset.test === "tab-more"', 5_000);
    $guest->locator('[data-test=first-steps-dismiss]')->click();
    shellOpen($guest, '/rules', $problems);
    expect($guest->evaluate('() => document.querySelector("[data-test=first-steps]").checkVisibility()'))->toBeFalse();

    $page = shellPage($player, 375, 667);
    shellOpen($page, route('games.rocket-league', absolute: false), $problems);
    $rects = '() => { const r = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return null; const b = el.getBoundingClientRect(); return [Math.round(b.top), Math.round(b.bottom), Math.round(b.left), Math.round(b.right)]; }; return { dock: r("[data-test=dock-mobile-bar]"), tabbar: r("[data-test=tab-bar]"), chip: r("#game-chips [aria-current]"), chips: r("#game-chips"), tabs: [...document.querySelectorAll("[data-test=tab-bar] .tab")].map((t) => t.textContent.trim() + " " + Math.round(t.getBoundingClientRect().height)) }; }';
    $m = $page->evaluate($rects);
    fwrite(STDERR, "\n[shell-mobile] player 375x667 ".json_encode($m));

    expect($m['tabs'])->toBe(['Play 63', 'Matches 63', 'Ladder 63', 'Tournaments 63', 'More 63'])
        ->and($m['dock'])->not->toBeNull()
        ->and($m['dock'][1])->toBeLessThanOrEqual($m['tabbar'][0] - 8)
        // The active game's chip is inside the row's visible part.
        ->and($m['chip'][2])->toBeGreaterThanOrEqual(0)
        ->and($m['chip'][3])->toBeLessThanOrEqual(375);
    shellShot($page, 'shell-player-375-rocket-league');

    $page->locator('[data-test=tab-more]')->click();
    BrowserWait::until($page, '() => document.getElementById("more-sheet").checkVisibility()', 5_000);
    $page->evaluate(SHELL_SETTLE);
    $sheet = $page->evaluate('() => { const r = document.getElementById("more-sheet").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), document.getElementById("more-sheet").scrollHeight]; }');
    fwrite(STDERR, "\n[shell-mobile] More sheet top/bottom/scrollHeight ".json_encode($sheet));
    expect($sheet[1])->toBe($m['tabbar'][0])
        // No label cut short, and "Season" on one line (it broke into "Sea son" next to its tag).
        ->and($page->evaluate('() => [...document.querySelectorAll("#more-sheet a span")].filter((el) => el.scrollWidth > el.clientWidth + 1).length'))->toBe(0)
        ->and($page->evaluate('() => { const r = document.createRange(); r.selectNodeContents(document.querySelector("[data-test=mobile-season] span").firstChild); return r.getClientRects().length; }'))->toBe(1);
    shellShot($page, 'shell-player-375-more');
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => !document.getElementById("more-sheet").checkVisibility()', 5_000);

    $page->locator('[data-test=mobile-games-menu]')->click();
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
    shellShot($page, 'shell-player-375-hub');

    expect($problems)->toBe([]);
});

test('/play lists every game for a guest and a player, your games first', function () {
    $player = shellPlayer();
    $problems = [];

    foreach (['guest' => null, 'player' => $player] as $role => $user) {
        foreach ([1440 => 900, 375 => 667] as $width => $height) {
            $page = shellPage($user, $width, $height);
            shellOpen($page, route('play', absolute: false), $problems);
            $m = $page->evaluate('() => ({ games: [...document.querySelectorAll("[data-test^=play-game-]")].map((el) => el.dataset.test.replace("play-game-", "")), login: !!document.querySelector("[data-test=play-login]"), widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth] })');
            fwrite(STDERR, "\n[shell-play] {$role} @{$width} ".json_encode($m));
            expect($m['games'])->toHaveCount(count(app(GameRegistry::class)->all()))
                ->and($m['login'])->toBe($role === 'guest')
                ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1]);
            if ($role === 'player') {
                expect(array_slice($m['games'], 0, 2))->toBe(['rocket-league', 'chess']);
            }
            shellShot($page, "shell-{$role}-{$width}-play");
        }
    }

    expect($problems)->toBe([]);
});

test('positive control: the collector of this file sees a thrown error and a 404 fetch', function () {
    $problems = [];
    $page = shellPage(null, 1440);
    shellOpen($page, '/rules', $problems);
    $page->evaluate('() => { setTimeout(() => { throw new Error("shell positive control"); }); fetch("/__probe/missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("shell positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);

    expect($problems)->toBe([]);
});

<?php

use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
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
| real rects: no sideways scroll at 375, 768, 1024, 1280 and 1440 px, the
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

/** Rects of the chrome: header rows, tab bar, sideways scroll and every control under 44 px (below lg) or 24 px (from lg). */
const SHELL_MEASURE = <<<'JS'
    () => {
        const rect = (el) => { if (!el || !el.checkVisibility({ checkVisibilityCSS: true })) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), width: Math.round(r.width) }; };
        const header = document.querySelector('body > header');
        const rows = [...header.children].filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && getComputedStyle(el).position === 'static');
        const desktop = window.innerWidth >= 1024;
        const min = desktop ? 24 : 44;
        const small = [];
        for (const root of [header, document.getElementById('mobile-nav')]) {
            for (const el of root.querySelectorAll('a[href], button, input:not([type=hidden])')) {
                if (!el.checkVisibility({ checkVisibilityCSS: true }) || el.closest('#bell-panel')) continue;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) continue;
                if (Math.round(r.height) < min || Math.round(r.width) < min) small.push(`${(el.dataset.test || el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30) || el.tagName)} ${Math.round(r.width)}x${Math.round(r.height)}`);
            }
        }
        const tabs = [...document.querySelectorAll('.gtab:not(.gtab-hub)')].filter((el) => el.checkVisibility({ checkVisibilityCSS: true })).map((el) => el.dataset.test.replace('game-tab-', '') + ':' + el.querySelector(getComputedStyle(el.querySelector('.gtab-full')).display === 'none' ? '.gtab-short' : '.gtab-full').textContent.trim());
        // Squeezed, not scrolled: a row whose content is wider than its box, a label cut short.
        const squeezed = [...document.querySelectorAll('[data-test=game-tabs], [data-test=context-bar], [data-test=tab-bar] ul, [data-test=tab-bar] .tab span, .nav-link, .ctx-link')]
            .filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && el.scrollWidth > el.clientWidth + 1)
            .map((el) => `${el.dataset.test || el.className.split(' ')[0] || el.tagName} ${el.scrollWidth}>${el.clientWidth}`);
        const nav = document.querySelector('[data-test=game-tabs]');
        const search = document.querySelector('[data-test=site-search-form]');
        if (nav && search && nav.checkVisibility() && search.checkVisibility()) {
            const last = [...nav.children].filter((el) => el.checkVisibility()).pop();
            if (last && last.getBoundingClientRect().right > search.getBoundingClientRect().left) squeezed.push(`row 1 runs under the search: ${Math.round(last.getBoundingClientRect().right)} > ${Math.round(search.getBoundingClientRect().left)}`);
        }
        return {
            squeezed,
            width: window.innerWidth,
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            header: rect(header),
            rows: rows.map((el) => (el.dataset.test || el.tagName.toLowerCase()) + ' ' + Math.round(el.getBoundingClientRect().height)),
            tabbar: rect(document.querySelector('[data-test=tab-bar]')),
            tabs,
            small,
            lang: document.documentElement.lang,
        };
    }
    JS;

/** Every finite animation finished (a sheet measured mid-rise is 24 px off), then two frames. */
const SHELL_SETTLE = '() => Promise.all(document.getAnimations().filter((a) => a.effect?.getComputedTiming().iterations !== Infinity).map((a) => a.finished.catch(() => null))).then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)))))';

function shellPage(?User $user, int $width, int $height = 900): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);

    return $page;
}

/**
 * @param  list<string>  $problems
 */
function shellOpen(Page $page, string $url, array &$problems): void
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Alpine', 10_000);
    $page->evaluate(SHELL_SETTLE);

    foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
        $problems[] = "{$url}: {$problem}";
    }
}

function shellShot(Page $page, string $name): void
{
    $dir = getenv('SHELL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->evaluate(SHELL_SETTLE);
    // The viewport, not the full page: a full-page shot paints the fixed tab bar in the middle of a long page.
    $page->screenshot(false, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * A player whose last match was Rocket League, the one before chess, and an
 * open daily game (so the match dock shows).
 */
function shellPlayer(): User
{
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    $player->forceFill(['name' => 'Pia Player'])->save();
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'created_at' => now()->subDays(2)]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => Lineup::factory()->mode('3v3')->ready()->create()->id, 'created_at' => now()->subDay()]);

    return $player;
}

function shellAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

test('row 1 and row 2 fit 1024, 1280 and 1440 px and the phone bars fit 375 and 768 px, for guest, player and admin', function () {
    $users = ['guest' => null, 'player' => shellPlayer(), 'admin' => shellAdmin()];
    $problems = [];
    $failures = [];

    foreach ($users as $role => $user) {
        foreach ([1440 => 900, 1280 => 800, 1024 => 768, 768 => 1024, 375 => 667, 320 => 568] as $width => $height) {
            $page = shellPage($user, $width, $height);
            shellOpen($page, '/rules', $problems);
            $m = $page->evaluate(SHELL_MEASURE);
            fwrite(STDERR, "\n[shell] {$role} @{$width}: ".json_encode($m));

            if ($m['scroll'] > $m['client']) {
                $failures[] = "{$role} @{$width}: document {$m['scroll']} px wide in {$m['client']} px";
            }
            if ($m['squeezed'] !== []) {
                $failures[] = "{$role} @{$width}: squeezed ".json_encode($m['squeezed']);
            }
            if ($m['small'] !== []) {
                $failures[] = "{$role} @{$width}: small targets ".json_encode($m['small']);
            }
            if ($width >= 1024) {
                // Row 1 (64) and row 2 (48); a guest's "New here?" strip comes on top until dismissed.
                $rows = array_sum(array_map(fn (string $row): int => (int) substr(strrchr($row, ' '), 1), array_filter($m['rows'], fn (string $row) => ! str_starts_with($row, 'first-steps'))));
                expect($rows)->toBeLessThanOrEqual(112, "{$role} @{$width}: chrome rows ".json_encode($m['rows']));
                expect($m['tabbar'])->toBeNull();
            } else {
                expect($m['tabbar'])->not->toBeNull()
                    ->and($m['tabbar']['bottom'])->toBe($height)
                    ->and($m['tabbar']['height'])->toBe(64);
            }
            if (in_array($width, [375, 1440], true) || ($width === 1024 && $role === 'admin')) {
                shellShot($page, "shell-{$role}-{$width}-closed");
            }
        }
    }

    // German labels run longer ("Einstellungen", "Herausfordern"): the tightest desktop widths once more, as the admin.
    foreach ([1024 => 768, 1280 => 800] as $width => $height) {
        $page = shellPage($users['admin'], $width, $height);
        $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
        shellOpen($page, '/rules', $problems);
        $m = $page->evaluate(SHELL_MEASURE);
        fwrite(STDERR, "\n[shell] admin de @{$width}: ".json_encode($m));
        expect($m['lang'])->toBe('de')
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'])
            ->and($m['squeezed'])->toBe([]);
    }

    expect($failures)->toBe([])->and($problems)->toBe([]);
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
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=hub-section-yours] [data-test^=hub-game-]")].map((el) => el.dataset.test)'))
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
        ->and($rl['links'])->toBe(['ctx-play', 'ctx-matches', 'ctx-challenge', 'ctx-ladder', 'ctx-tournaments']);
    shellShot($page, 'shell-player-1280-rocket-league');

    shellOpen($page, '/clans', $problems);
    expect($page->evaluate($state))->toMatchArray(['game' => 'rocket-league', 'how' => 'true']);

    shellOpen($page, route('ladder.show', ['chess', 'blitz'], false), $problems);
    $chess = $page->evaluate($state);
    expect($chess)->toMatchArray(['game' => 'chess', 'how' => 'page'])
        ->and($chess['links'])->toBe(['ctx-play', 'ctx-daily', 'ctx-challenge', 'ctx-watch', 'ctx-matches', 'ctx-ladder', 'ctx-settings', 'ctx-tournaments']);

    expect($problems)->toBe([]);
});

test('the hub with 12 games (a test-only registry): a fixed height that scrolls, at 1440 and 375 px', function () {
    $admin = shellAdmin();
    $problems = [];
    $sizes = [];

    foreach ([4, 12] as $count) {
        app()->instance(GameRegistry::class, FakeGame::registry($count));

        foreach ([1440 => 900, 375 => 667] as $width => $height) {
            $page = shellPage($admin, $width, $height);
            shellOpen($page, '/rules', $problems);
            $page->locator($width === 1440 ? '[data-test=games-menu]' : '[data-test=mobile-games-menu]')->click();
            BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
            $page->evaluate(SHELL_SETTLE);
            $sizes["{$count}@{$width}"] = $page->evaluate('() => { const hub = document.getElementById("game-hub"); const r = hub.getBoundingClientRect(); const list = hub.querySelector("[x-ref=hubTiles]"); return { top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height), scrolls: list.scrollHeight > list.clientHeight, tiles: hub.querySelectorAll("[data-test^=hub-game-]").length, tabbarTop: Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top) }; }');
            expect($sizes["{$count}@{$width}"]['tiles'])->toBe($count)
                ->and($sizes["{$count}@{$width}"]['bottom'])->toBeLessThanOrEqual($width === 375 ? $sizes["{$count}@{$width}"]['tabbarTop'] : $height);
            shellShot($page, "shell-admin-{$width}-hub-{$count}games");
        }
    }

    app()->forgetInstance(GameRegistry::class);
    fwrite(STDERR, "\n[shell-hub] ".json_encode($sizes));

    expect($sizes['12@1440']['scrolls'])->toBeTrue()
        ->and($sizes['12@375']['scrolls'])->toBeTrue()
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

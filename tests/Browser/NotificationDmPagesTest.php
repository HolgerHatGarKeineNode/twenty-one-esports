<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Notifications\OnSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\RunnerConsole;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| DM opt-out page and the notification settings, measured
|--------------------------------------------------------------------------
|
| A guest opens the signed "Turn off these DMs" link, turns every DM off and
| lands on the confirmation; a logged-in player opens the notification settings
| (P51: its own tab). Both at 375 and 1440 px: no horizontal overflow, the card
| inside the viewport, and a clean page.
|
| Collected on every page (the collector of ClanEditTest): console.error,
| uncaught errors, rejected promises, and every response >= 400 (fetch, XHR
| and the resource timing entries of the document, images and scripts).
|
| DM_PAGES_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const DM_PAGES_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const DM_PAGES_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const DM_PAGES_LAYOUT = <<<'JS'
    (selector) => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            left: Math.round(r.left),
            right: Math.round(r.right),
            width: Math.round(r.width),
            height: Math.round(r.height),
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function dmPagesOpen(string $to, int $width, ?User $user = null): Page
{
    $page = visit($user === null ? '/' : route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(DM_PAGES_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function dmPagesShot(Page $page, string $name): void
{
    $dir = getenv('DM_PAGES_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array{scrollWidth: int, clientWidth: int, left: int, right: int, width: int, height: int}
 */
function dmPagesMeasure(Page $page, string $selector, string $label, int $width): array
{
    $layout = $page->evaluate(DM_PAGES_LAYOUT, $selector);
    fwrite(STDERR, "\n[dm-pages] {$label} {$width}px: ".json_encode($layout)."\n");

    if ($layout['scrollWidth'] > $layout['clientWidth']) {
        // Name what sticks out, so a failure says where to look.
        fwrite(STDERR, '[dm-pages] wider than the viewport: '.json_encode($page->evaluate('() => [...document.querySelectorAll("body *")].filter((el) => el.getBoundingClientRect().right > innerWidth + 0.5 && el.getClientRects().length > 0).slice(0, 8).map((el) => el.tagName + "." + [...el.classList].slice(0, 4).join(".") + " " + Math.round(el.getBoundingClientRect().right) + " " + (el.textContent || "").trim().slice(0, 40))'))."\n");
    }

    expect($layout['scrollWidth'])->toBeLessThanOrEqual($layout['clientWidth'])
        ->and($layout['left'])->toBeGreaterThanOrEqual(0)
        ->and($layout['right'])->toBeLessThanOrEqual($width);

    return $layout;
}

function dmPagesClean(Page $page): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(DM_PAGES_BAD_RESPONSES))->toBe([]);
}

test('a guest turns every DM off from the signed link, and the page fits 375 and 1440 px', function () {
    foreach ([375, 1440] as $width) {
        $bert = User::factory()->create(['name' => 'Bert Satoshi-Nakamoto the Third', 'locale' => 'en']);
        $url = URL::signedRoute('notifications.dm-off', ['user' => $bert->id], absolute: false);

        $page = dmPagesOpen($url, $width);
        BrowserWait::until($page, '() => document.querySelector("[data-test=dm-off-all]") !== null', 10_000);
        dmPagesMeasure($page, '[data-test=dm-off] section', 'opt-out', $width);
        dmPagesShot($page, "dm-off-{$width}");
        dmPagesClean($page);

        $page->locator('[data-test=dm-off-all]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=dm-off-done]") !== null', 10_000);

        expect($bert->refresh()->chessSettings()->dm)->toBeFalse()
            ->and($page->evaluate('() => document.querySelector("[data-test=dm-off-state]").textContent.trim()'))->toBe('Nostr DMs are off for you.');

        dmPagesMeasure($page, '[data-test=dm-off] section', 'opt-out done', $width);
        dmPagesShot($page, "dm-off-done-{$width}");
        dmPagesClean($page);
    }
});

test('the notification settings fit 375 and 1440 px and explain the DM channel', function () {
    foreach ([375, 1440] as $width) {
        $anna = User::factory()->create(['locale' => 'en']);

        $page = dmPagesOpen(route('settings.notifications', absolute: false), $width, $anna);
        BrowserWait::until($page, '() => document.querySelector("[data-test=dm-explained]") !== null && document.readyState === "complete"', 10_000);

        dmPagesMeasure($page, '#notifications', 'settings #notifications', $width);
        dmPagesMeasure($page, '[data-test=notify-about]', 'settings notify-about', $width);

        expect($page->evaluate('() => document.querySelector("[data-test=switch-dm]").getAttribute("aria-checked")'))->toBe('true');
        dmPagesShot($page, "settings-notifications-default-{$width}");

        // A Livewire roundtrip: the switch turns off and the page stays clean.
        $page->locator('[data-test=switch-dm]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=switch-dm]").getAttribute("aria-checked") === "false"', 10_000);
        expect($anna->refresh()->chessSettings()->dm)->toBeFalse();

        $page->evaluate('() => document.getElementById("notifications").scrollIntoView()');
        dmPagesShot($page, "settings-notifications-{$width}");
        dmPagesClean($page);
    }
});

test('the collector sees a thrown error and a failed request (positive control)', function () {
    $bert = User::factory()->create(['locale' => 'en']);
    $page = dmPagesOpen(URL::signedRoute('notifications.dm-off', ['user' => $bert->id], absolute: false), 1440);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=dm-off-all]") !== null', 10_000);

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/notifications/dm/'.$bert->id.'")');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("403 "))', 5_000);

    expect($page->evaluate('() => window.__errors.length'))->toBe(2);
});

/*
 * Audit 2026-09-30: the switches in four groups, each row saying how far it
 * reaches, no switch for a live game's own calls; and the page, like every
 * logged-in page, telling the server that the player is here (OnSite).
 * Measured at 320, 375 and 1280 px in English and German: nothing wider than
 * the viewport, nothing of the card sticking out, no text cut off.
 */
const DM_PAGES_CLIPPED = <<<'JS'
    (selector) => {
        const card = document.querySelector(selector).getBoundingClientRect();
        return [...document.querySelectorAll(selector + ' *')]
            .filter((el) => el.getClientRects().length > 0)
            .filter((el) => { const r = el.getBoundingClientRect(); return r.left < card.left - 0.5 || r.right > card.right + 0.5 || (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible'); })
            .map((el) => (el.dataset.test || el.tagName) + ': ' + el.textContent.trim().slice(0, 40));
    }
    JS;

/**
 * Waits for the page's first on-site ping (onSite.js, 2 s after load), so it
 * is not in flight while the test clicks.
 */
function dmPagesPinged(Page $page): void
{
    BrowserWait::until($page, '() => performance.getEntriesByType("resource").some((e) => e.name.endsWith("/presence/ping"))', 10_000);
}

test('the notification settings group the switches, show how far each reaches and fit 320, 375 and 1280 px in English and German', function () {
    foreach (['en', 'de'] as $locale) {
        foreach ([320, 375, 1280] as $width) {
            $anna = User::factory()->create(['locale' => $locale, 'chess_settings' => ['triggers' => ['match_found' => false]]]);

            $page = dmPagesOpen(route('settings.notifications', absolute: false), $width, $anna);
            BrowserWait::until($page, '() => document.querySelector("[data-test=page-only-kinds]") !== null && document.readyState === "complete"', 10_000);
            dmPagesPinged($page);

            dmPagesMeasure($page, '[data-test=notify-about]', "notify-about {$locale}", $width);
            dmPagesMeasure($page, '#notifications', "channels {$locale}", $width);

            $shape = $page->evaluate('() => ({
                groups: [...document.querySelectorAll("[data-test^=notify-group-]")].map((h) => h.dataset.test),
                switches: document.querySelectorAll("[data-test=notify-about] [data-test^=switch-trigger-]").length,
                pageOnly: ["match_found", "invite", "casual_match_found", "casual_invite", "casual_opponent_joined"].filter((k) => document.querySelector("[data-test=switch-trigger-" + k + "]")),
                reachDm: [...document.querySelectorAll("[data-test=notify-about] [data-test=reach]")].filter((r) => r.textContent.includes("DM")).length,
            })');

            expect($shape)->toBe([
                'groups' => ['notify-group-correspondence', 'notify-group-play', 'notify-group-community', 'notify-group-league'],
                'switches' => 26, // team_match (plan Schach Rapid/Clan, P6) added one
                'pageOnly' => [],
                'reachDm' => 8,
            ])
                ->and($page->evaluate(DM_PAGES_CLIPPED, '[data-test=notify-about]'))->toBe([])
                ->and($page->evaluate(DM_PAGES_CLIPPED, '#notifications'))->toBe([]);

            // A Livewire roundtrip on a grouped switch.
            $page->locator('[data-test=switch-trigger-game_over]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=switch-trigger-game_over]").getAttribute("aria-checked") === "false"', 10_000);

            expect($anna->refresh()->chessSettings()->wants('game_over'))->toBeFalse()
                // The stored "off" of a page-only kind from before no longer counts.
                ->and($anna->chessSettings()->wants('match_found'))->toBeTrue();

            dmPagesShot($page, "settings-notify-about-{$locale}-{$width}");
            dmPagesClean($page);
        }
    }
});

test('a visible logged-in page tells the server the player is on the site', function () {
    $anna = User::factory()->create(['locale' => 'en']);

    expect(app(OnSite::class)->isOnSite($anna))->toBeFalse();

    $page = dmPagesOpen(route('settings.notifications', absolute: false), 1280, $anna);
    dmPagesPinged($page);

    // The ping's answer is in: the server marked her (it runs in this process, so no polling here).
    expect(app(OnSite::class)->isOnSite($anna))->toBeTrue();

    dmPagesClean($page);
});

test('a daily game offers push or only here for the opponent\'s move, never a DM, and the row fits in English and German', function () {
    foreach (['en', 'de'] as $locale) {
        $anna = User::factory()->create(['locale' => $locale, 'name' => 'Anna Satoshi-Nakamoto the Third']);
        $bert = User::factory()->create(['locale' => $locale, 'name' => 'Bert Hal-Finney-Szabo the Second']);
        $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);
        // A per-game DM stored before the DM was dropped shows as "only here".
        $game->forceFill(['white_notify' => 'dm'])->save();

        foreach ([320, 375, 1024, 1280] as $width) {
            $page = dmPagesOpen(route('games.show', $game, absolute: false), $width, $anna);
            BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=daily-bottom-bar]") !== null', 10_000);
            dmPagesPinged($page);

            if ($width < 1024) {
                dmPagesMeasure($page, '[data-test=daily-bottom-bar]', "daily bar {$locale}", $width);
                expect($page->evaluate(DM_PAGES_CLIPPED, '[data-test=daily-bottom-bar]'))->toBe([]);
                dmPagesShot($page, "daily-bar-{$locale}-{$width}");
                dmPagesClean($page);

                continue;
            }

            dmPagesMeasure($page, 'section[aria-labelledby=nt-h]', "notify row {$locale}", $width);
            $row = $page->evaluate('() => ({
                buttons: [...document.querySelectorAll("[role=radiogroup][aria-labelledby=nt-h] [data-test^=notify-]")].map((b) => b.dataset.test),
                checked: document.querySelector("[role=radiogroup][aria-labelledby=nt-h] [aria-checked=true]")?.dataset.test ?? null,
                awayLine: document.querySelector("[data-test=notify-away-only]").offsetParent !== null,
            })');

            expect($row)->toBe(['buttons' => ['notify-push', 'notify-here'], 'checked' => 'notify-here', 'awayLine' => false])
                ->and($page->evaluate(DM_PAGES_CLIPPED, 'section[aria-labelledby=nt-h]'))->toBe([]);

            // Push chosen: stored, and the line on how often shows.
            $page->locator('[data-test=notify-push]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=notify-away-only]").offsetParent !== null', 5_000);
            BrowserWait::until($page, '() => performance.getEntriesByType("resource").some((e) => e.name.includes("/livewire") && e.name.endsWith("/update") && e.responseEnd > 0)', 10_000);
            expect($game->refresh()->white_notify)->toBe('push');
            expect($page->evaluate(DM_PAGES_CLIPPED, 'section[aria-labelledby=nt-h]'))->toBe([]);
            dmPagesMeasure($page, 'section[aria-labelledby=nt-h]', "notify row push {$locale}", $width);
            dmPagesShot($page, "daily-notify-{$locale}-{$width}");
            dmPagesClean($page);

            $game->forceFill(['white_notify' => 'dm'])->save();
        }
    }
});

/*
 * A tab left open after the player logged out (here: the session ended on
 * the server, as a logout in another tab does). Measured on the runner's
 * own console channel (RunnerConsole), which sees Chrome's network lines.
 */
const DM_PAGES_FLIP_VISIBILITY = <<<'JS'
    () => {
        const flip = (state) => {
            Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
            document.dispatchEvent(new Event('visibilitychange'));
        };
        flip('hidden');
        flip('visible');
        return true;
    }
    JS;

test('a tab left open after logout stops pinging and writes nothing to the console', function () {
    $anna = User::factory()->create(['locale' => 'en']);
    $page = dmPagesOpen(route('settings.notifications', absolute: false), 1280, $anna);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=notify-about]") !== null', 10_000);
    dmPagesPinged($page);
    expect(app(OnSite::class)->isOnSite($anna))->toBeTrue();

    $console = RunnerConsole::watch($page);

    // Logged out elsewhere: the session is gone. Back to visible, the page pings, is told to stop, and stops.
    DB::table('sessions')->where('user_id', $anna->id)->delete();
    $console->run(DM_PAGES_FLIP_VISIBILITY);

    expect($console->until('() => window.esportsOnSite.stopped()'))->toBeTrue()
        ->and(array_values(array_filter($console->messages(), fn (array $m): bool => in_array($m['type'], ['error', 'warning'], true))))->toBe([]);

    // Stopped for good: another turn to visible sends nothing.
    $pings = $console->run('() => performance.getEntriesByType("resource").filter((e) => e.name.endsWith("/presence/ping")).length');
    $console->run(DM_PAGES_FLIP_VISIBILITY);
    $console->run('() => new Promise((resolve) => setTimeout(resolve, 500))');
    expect($console->run('() => performance.getEntriesByType("resource").filter((e) => e.name.endsWith("/presence/ping")).length'))->toBe($pings);

    // Positive control: the same channel sees a failed request and a console error.
    $console->run('() => fetch("/presence/ping-nowhere").then(() => true)');
    $console->run('() => { console.error("positive control"); return true; }');
    $console->run('() => new Promise((resolve) => setTimeout(resolve, 300))');
    $seen = array_map(fn (array $m): string => $m['text'], $console->messages());

    expect(collect($seen)->contains(fn (string $text): bool => str_contains($text, 'status of 404')))->toBeTrue()
        ->and($seen)->toContain('positive control');
});

test('the heading of the notification settings stays one word on one line at 320, 375 and 1280 px in English and German', function () {
    foreach (['en', 'de'] as $locale) {
        foreach ([320, 375, 1280] as $width) {
            $anna = User::factory()->create(['locale' => $locale]);
            $page = dmPagesOpen(route('settings.notifications', absolute: false), $width, $anna);
            BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=settings-heading]") !== null', 10_000);

            $heading = $page->evaluate('() => {
                const h1 = document.querySelector("[data-test=settings-heading]");
                const range = document.createRange();
                range.selectNodeContents(h1);
                const lines = new Set([...range.getClientRects()].map((r) => Math.round(r.top))).size;
                const box = h1.getBoundingClientRect();
                const parent = h1.parentElement.getBoundingClientRect();
                return { text: h1.textContent.trim(), lines, inside: box.right <= parent.right + 0.5 && Math.max(...[...range.getClientRects()].map((r) => r.right)) <= parent.right + 0.5 };
            }');
            fwrite(STDERR, "\n[dm-pages] heading {$locale} {$width}px: ".json_encode($heading)."\n");

            expect($heading)->toBe(['text' => $locale === 'de' ? 'Benachrichtigungen' : 'Notifications', 'lines' => 1, 'inside' => true]);
            dmPagesMeasure($page, '[data-test=settings-header]', "header {$locale}", $width);
            dmPagesClean($page);
        }
    }
});

test('the game row shows the away line only when a push can go out', function () {
    $anna = User::factory()->create(['locale' => 'en', 'chess_settings' => ['push' => false]]);
    $game = app(ChessGameService::class)->start($anna, User::factory()->create(), ChessGame::CORRESPONDENCE);

    $page = dmPagesOpen(route('games.show', $game, absolute: false), 1280, $anna);
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=notify-away-only]") !== null', 10_000);
    dmPagesPinged($page);
    $state = fn () => $page->evaluate('() => ({ away: document.querySelector("[data-test=notify-away-only]").offsetParent !== null, summary: [...document.querySelectorAll("section[aria-labelledby=nt-h] span")].map((s) => s.offsetParent !== null ? s.textContent.trim() : "").filter((t) => t.startsWith("Now:")).join("") })');

    // Push off and no choice for this game: nothing goes out, and the row says only that.
    expect($state())->toBe(['away' => false, 'summary' => 'Now: your settings (Notifications are off)']);

    $page->locator('[data-test=notify-push]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=notify-away-only]").offsetParent !== null', 5_000);
    expect($state()['away'])->toBeTrue();
    dmPagesClean($page);
});

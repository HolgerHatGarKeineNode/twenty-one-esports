<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| DM opt-out page and the notification settings, measured
|--------------------------------------------------------------------------
|
| A guest opens the signed "Turn off these DMs" link, turns every DM off and
| lands on the confirmation; a logged-in player opens the chess settings at
| #notifications. Both at 375 and 1440 px: no horizontal overflow, the card
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

        $page = dmPagesOpen(route('settings.chess', absolute: false).'#notifications', $width, $anna);
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

<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The settings tabs and the gamer tag page, measured (P51)
|--------------------------------------------------------------------------
|
| Every settings page at 375 and 1440 px: the one tab strip is there, its
| active tab names the heading and sits inside the strip's visible part,
| and neither the strip nor the page overflows. The gamer tag page saves and
| removes a tag in the browser, the tabs switch with wire:navigate (P6b) and
| the notifications page's push toggle starts after it, and the German page
| fits a phone.
|
| Collected on every page (the collector of NotificationDmPagesTest):
| console.error, uncaught errors, rejected promises, and every response
| >= 400 (fetch, XHR and the resource timing entries). The last test is the
| positive control of that collector on a settings page.
|
| SETTINGS_SHOTS=<dir> additionally writes the screenshots there.
|
*/

const SETTINGS_COLLECTOR = <<<'JS'
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

const SETTINGS_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** The strip, its active tab and the heading, as the screen shows them. */
const SETTINGS_LAYOUT = <<<'JS'
    () => {
        const nav = document.querySelector('[data-test=settings-tabs]');
        const active = nav.querySelector('[aria-current=page]');
        const n = nav.getBoundingClientRect();
        const a = active.getBoundingClientRect();
        return {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            tabs: nav.querySelectorAll('a').length,
            activeCount: nav.querySelectorAll('[aria-current=page]').length,
            active: active.innerText.trim(),
            heading: document.querySelector('[data-test=settings-heading]').innerText.trim(),
            navLeft: Math.round(n.left),
            navRight: Math.round(n.right),
            navScrolls: nav.scrollWidth > nav.clientWidth,
            activeLeft: Math.round(a.left),
            activeRight: Math.round(a.right),
            activeHeight: Math.round(a.height),
        };
    }
    JS;

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

function settingsOpen(string $to, int $width, User $user): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(SETTINGS_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("[data-test=settings-heading]") !== null', 10_000);

    return $page;
}

function settingsShot(Page $page, string $name): void
{
    $dir = getenv('SETTINGS_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function settingsMeasure(Page $page, string $label, int $width, string $heading): array
{
    $layout = $page->evaluate(SETTINGS_LAYOUT);
    fwrite(STDERR, "\n[settings] {$label} {$width}px: ".json_encode($layout)."\n");

    expect($layout['scrollWidth'])->toBeLessThanOrEqual($layout['clientWidth'])
        ->and($layout['tabs'])->toBe(7)
        ->and($layout['activeCount'])->toBe(1)
        ->and($layout['active'])->toBe($heading)
        ->and($layout['heading'])->toBe($heading)
        ->and($layout['navRight'])->toBeLessThanOrEqual($width)
        ->and($layout['activeLeft'])->toBeGreaterThanOrEqual($layout['navLeft'])
        ->and($layout['activeRight'])->toBeLessThanOrEqual($layout['navRight'])
        ->and($layout['activeHeight'])->toBeGreaterThanOrEqual(44);

    return $layout;
}

function settingsClean(Page $page): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(SETTINGS_BAD_RESPONSES))->toBe([]);
}

test('every settings page has the same tabs, the active one names the heading and is in view, at 375 and 1440 px', function () {
    $user = User::factory()->create(['locale' => 'en', 'gamer_tags' => ['ea' => 'Striker_21']]);
    $scrolled = [];

    foreach ([375, 1440] as $width) {
        foreach ([
            'gaming.edit' => 'Gamer tags',
            'settings.account' => 'Account',
            'settings.notifications' => 'Notifications',
            'settings.chess' => 'Chess',
            'settings.opponents' => 'Opponents',
            'settings.badges' => 'Badges and sharing',
            'settings.nip05' => 'Nostr address',
        ] as $route => $heading) {
            $page = settingsOpen(route($route, absolute: false), $width, $user);
            $layout = settingsMeasure($page, $route, $width, $heading);
            $scrolled[$width][] = $layout['navScrolls'];
            settingsShot($page, 'settings-'.str_replace('.', '-', $route)."-{$width}");
            settingsClean($page);
        }
    }

    // The phone strip is narrower than its tabs (so the in-view check above bit); the desktop one is not.
    expect(in_array(true, $scrolled[375], true))->toBeTrue()
        ->and($scrolled[1440])->each->toBeFalse();
});

test('a gamer tag is saved and removed in the browser, and the tabs switch with wire:navigate and start each page\'s own scripts', function () {
    $user = User::factory()->create(['locale' => 'en']);

    foreach ([375, 1440] as $width) {
        $user->forceFill(['gamer_tags' => null])->save();
        $page = settingsOpen(route('gaming.edit', absolute: false), $width, $user);

        expect($page->evaluate('() => document.querySelector("[data-test=all-private]") !== null'))->toBeTrue();
        settingsShot($page, "gamer-tags-empty-{$width}");

        $page->locator('[data-test=tag-input-ea]')->fill('Striker_21');
        $page->locator('[data-test=gamer-tags-save]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=tag-clear-ea]") !== null', 10_000);
        expect($user->refresh()->gamer_tags)->toBe(['ea' => 'Striker_21']);

        $card = $page->evaluate('() => { const r = document.querySelector("[data-test=tag-card-ea-sports-fc-27]").getBoundingClientRect(); const b = document.querySelector("[data-test=tag-clear-ea]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), buttonRight: Math.round(b.right), buttonHeight: Math.round(b.height), scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }; }');
        fwrite(STDERR, "\n[settings] saved tag card {$width}px: ".json_encode($card)."\n");
        expect($card['scrollWidth'])->toBeLessThanOrEqual($card['clientWidth'])
            ->and($card['buttonRight'])->toBeLessThanOrEqual($card['right'])
            ->and($card['buttonHeight'])->toBeGreaterThanOrEqual(44);
        $page->evaluate('() => document.querySelector("[data-test=tag-card-ea-sports-fc-27]").scrollIntoView({ block: "center" })');
        settingsShot($page, "gamer-tags-saved-{$width}");

        $page->locator('[data-test=tag-clear-ea]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=tag-clear-ea]") === null && document.querySelector("[data-test=all-private]") !== null', 10_000);
        expect($user->refresh()->gamer_tags)->toBeNull()
            ->and($page->evaluate('() => document.querySelector("[data-test=tag-input-ea]").value'))->toBe('');
        settingsClean($page);

        // wire:navigate (P6b): the document stays (the marker survives), the heading and the active tab change.
        $page->evaluate('() => { window.__sameDocument = true; }');
        $page->locator('[data-test=settings-account-tab]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=settings-heading]")?.innerText.trim() === "Account"', 10_000);
        expect($page->evaluate('() => window.__sameDocument === true'))->toBeTrue();
        settingsMeasure($page, 'account after wire:navigate', $width, 'Account');

        // The notifications page's push.js registers its toggle at once in a running Alpine (resources/js/registerAlpine.js);
        // on 2026-10-05 it waited for alpine:init, which a swap never fires: the toggle was dead, with 7 console errors.
        $page->locator('[data-test=settings-notifications-tab]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=settings-heading]")?.innerText.trim() === "Notifications"', 10_000);
        BrowserWait::until($page, '() => typeof Alpine.$data(document.querySelector("[x-data^=pushToggle]"))?.toggle === "function"', 5_000);
        expect($page->evaluate('() => window.__sameDocument === true'))->toBeTrue();
        settingsClean($page);

        $page->locator('[data-test=settings-badges-tab]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=settings-heading]")?.innerText.trim() === "Badges and sharing"', 10_000);
        settingsMeasure($page, 'badges after wire:navigate', $width, 'Badges and sharing');
        settingsClean($page);
    }
});

test('an old link to the chess settings at #notifications lands on the notifications tab', function () {
    $user = User::factory()->create(['locale' => 'en']);
    // settingsOpen() lands on the page without the fragment (ComputeUrl drops it), so go there again with it.
    $page = settingsOpen(route('settings.chess', absolute: false), 375, $user);
    $page->evaluate('() => { window.location.href = window.location.pathname + "#notifications"; window.location.reload(); }');
    BrowserWait::until($page, '() => location.pathname === "/settings/notifications" && document.querySelector("[data-test=settings-heading]")?.innerText.trim() === "Notifications" && document.readyState === "complete"', 10_000);

    settingsMeasure($page, 'notifications from the old anchor', 375, 'Notifications');
    expect($page->evaluate('() => document.getElementById("notifications") !== null && document.querySelector("[data-test=switch-dm]") !== null'))->toBeTrue();
    settingsClean($page);

    // Without the anchor the chess tab stays where it is.
    $chess = settingsOpen(route('settings.chess', absolute: false), 375, $user);
    expect($chess->evaluate('() => location.pathname'))->toBe('/settings/chess');
    settingsClean($chess);
});

test('the German gamer tag page fits a phone', function () {
    $user = User::factory()->create(['locale' => 'de', 'gamer_tags' => ['epic' => 'rocketeer']]);
    $page = settingsOpen(route('gaming.edit', absolute: false), 375, $user);

    $layout = settingsMeasure($page, 'gaming de', 375, 'Gamer-Tags');
    expect($page->evaluate('() => document.querySelector("[data-test=gamer-tag-privacy] h2").innerText.trim()'))->toBe('Jedes Feld ist freiwillig')
        ->and($page->evaluate('() => document.querySelector("[data-test=tag-clear-epic]").innerText.trim()'))->toBe('Entfernen')
        ->and($layout['navScrolls'])->toBeTrue();

    settingsShot($page, 'gamer-tags-de-375');
    settingsClean($page);
});

test('the collector sees a thrown error and a failed request on a settings page (positive control)', function () {
    $page = settingsOpen(route('gaming.edit', absolute: false), 1440, User::factory()->create(['locale' => 'en']));

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/settings/does-not-exist")');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);

    expect($page->evaluate('() => window.__errors.length'))->toBe(2);
});

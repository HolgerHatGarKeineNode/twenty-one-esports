<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The account menu in the header (performance plan P5, F1)
|--------------------------------------------------------------------------
|
| The chip's menu is an Alpine menu button (shellHeader in
| resources/js/shellNav.js), not Flux's: Flux Pro's script (83 KB) loaded on
| every page for this one dropdown. It follows the WAI-ARIA menu button
| pattern: Enter, Space or ArrowDown open it on the first item, ArrowUp on
| the last, the arrows, Home and End move, Escape closes it and gives the
| focus back to the chip, Tab and a click outside close it.
|
| ACCOUNT_MENU_REPORT=<file> writes the measured boxes (header, chip, menu,
| items) at 390 and 1440 px as JSON, for the before/after table of P5.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    // A login that holds across the browser's requests, as in NavigationMenusTest.
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** The page of a logged-in player at the given width, console collector installed. */
function accountMenuPage(User $user, int $width): mixed
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => '/robots.txt']))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from('/rules'));
    BrowserWait::until($page, '() => document.readyState === "complete" && !! window.Alpine', 10_000);

    return $page;
}

/** What has the focus: its data-test, else its text. */
const ACCOUNT_MENU_FOCUS = '() => { const el = document.activeElement; return el?.dataset?.test || (el?.textContent || "").trim().replace(/\s+/g, " ") || el?.tagName; }';

/** The menu element: the parent of the name row, in Flux's markup and in ours. */
const ACCOUNT_MENU_OPEN = '() => { const menu = document.querySelector("[data-test=account-menu-name]")?.parentElement; return !! menu && menu.checkVisibility({ checkVisibilityCSS: true }); }';

const ACCOUNT_MENU_BOXES = <<<'JS'
    () => {
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.x * 10) / 10, y: Math.round(r.y * 10) / 10, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10 }; };
        const menu = document.querySelector('[data-test=account-menu-name]')?.parentElement;
        const style = menu ? getComputedStyle(menu) : null;
        const items = menu ? [...menu.querySelectorAll('a, button')].map((el) => ({ text: el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30), ...box(el), fontSize: getComputedStyle(el).fontSize, color: getComputedStyle(el).color })) : [];
        return {
            header: box(document.querySelector('header.shell-header')),
            row1: box(document.querySelector('header.shell-header > div')),
            chip: box(document.querySelector('[data-test=account-chip]')),
            menu: box(menu),
            menuStyle: style ? { background: style.backgroundColor, border: style.borderTopColor + ' ' + style.borderTopWidth, radius: style.borderTopLeftRadius, padding: style.paddingTop, shadow: style.boxShadow !== 'none' } : null,
            items,
            overflow: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        };
    }
    JS;

test('the account menu is measured at 390 and 1440 px', function () {
    $user = User::factory()->create(['name' => 'Mempool Max']);
    $report = [];

    foreach ([390, 1440] as $width) {
        $page = accountMenuPage($user, $width);
        $closed = $page->evaluate(ACCOUNT_MENU_BOXES);
        if ($width >= 1024) {
            $page->locator('[data-test="account-chip"]')->click();
            BrowserWait::until($page, ACCOUNT_MENU_OPEN, 5_000);
            $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 400))');
        }
        $report[$width] = ['closed' => $closed, 'open' => $page->evaluate(ACCOUNT_MENU_BOXES)];
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    if ($file = env('ACCOUNT_MENU_REPORT')) {
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
    }

    // The header keeps its height: 56 px on a phone (row 1), 64 + 48 px from lg (row 1 and the context bar).
    expect($report[390]['closed']['row1']['h'])->toBe(56)
        ->and($report[1440]['closed']['row1']['h'])->toBe(64)
        ->and($report[1440]['open']['menu'])->not->toBeNull()
        ->and($report[1440]['open']['overflow'][0])->toBeLessThanOrEqual($report[1440]['open']['overflow'][1]);
});

test('the account menu opens with Enter, Space and the arrows, moves with the arrows, Home and End, and Escape, Tab or a click outside close it', function () {
    $user = User::factory()->create(['name' => 'Mempool Max']);
    $page = accountMenuPage($user, 1440);
    $chip = '[data-test="account-chip"]';
    $open = fn (): bool => $page->evaluate(ACCOUNT_MENU_OPEN);
    $focus = fn (): string => $page->evaluate(ACCOUNT_MENU_FOCUS);
    $settle = fn () => $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');

    expect($open())->toBeFalse()
        ->and($page->locator($chip)->getAttribute('aria-expanded'))->toBe('false')
        ->and($page->locator($chip)->getAttribute('aria-haspopup'))->toBe('menu');

    // Enter: open, the focus on the first item.
    $page->locator($chip)->press('Enter');
    BrowserWait::until($page, ACCOUNT_MENU_OPEN, 5_000);
    $settle();
    expect($page->locator($chip)->getAttribute('aria-expanded'))->toBe('true')
        ->and($focus())->toBe('account-page');

    // Down, Up twice (wraps to the last), Home, End.
    $page->locator(':focus')->press('ArrowDown');
    $second = $focus();
    $page->locator(':focus')->press('ArrowUp');
    $page->locator(':focus')->press('ArrowUp');
    $wrapped = $focus();
    $page->locator(':focus')->press('Home');
    $home = $focus();
    $page->locator(':focus')->press('End');
    expect($second)->toBe('account-invite')
        ->and($wrapped)->toBe('Log out')
        ->and($home)->toBe('account-page')
        ->and($focus())->toBe('Log out');

    // Escape: closed, the focus back on the chip.
    $page->locator(':focus')->press('Escape');
    $settle();
    expect($open())->toBeFalse()
        ->and($focus())->toBe('account-chip')
        ->and($page->locator($chip)->getAttribute('aria-expanded'))->toBe('false');

    // Space opens as well; Tab leaves the menu and closes it.
    $page->locator($chip)->press(' ');
    BrowserWait::until($page, ACCOUNT_MENU_OPEN, 5_000);
    $settle();
    expect($focus())->toBe('account-page');
    $page->locator(':focus')->press('Tab');
    $settle();
    expect($open())->toBeFalse();

    // ArrowUp on the chip opens on the last item; a click outside closes.
    $page->locator($chip)->press('ArrowUp');
    BrowserWait::until($page, ACCOUNT_MENU_OPEN, 5_000);
    $settle();
    expect($focus())->toBe('Log out');
    $page->locator('main')->click();
    $settle();
    expect($open())->toBeFalse();

    // A click on the chip and on an item follows the link.
    $page->locator($chip)->click();
    BrowserWait::until($page, ACCOUNT_MENU_OPEN, 5_000);
    $page->locator('[data-test="account-menu"] [data-test="account-badges"]')->click();
    BrowserWait::until($page, '() => location.pathname !== "/rules" && document.readyState === "complete"', 10_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // A Livewire roundtrip of every component on the page (dock, bell, cup badge): answered 2xx, console still empty.
    $refreshed = $page->evaluate('async () => { const all = window.Livewire.all(); await Promise.all(all.map((c) => c.$wire.$refresh())); return all.length; }');
    expect($refreshed)->toBeGreaterThan(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: a thrown error reaches the collector, so the empty list above is a measurement.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
});

test('a foreign avatar falls back to the Blockpile when the image proxy refuses it, and a page without the proxy has no proxy URL', function () {
    // A proxy base on this server that answers 404 for every picture, as group answers 403 or 502.
    config(['esports.image_proxy_url' => '/__no-image-proxy']);
    $user = User::factory()->create(['name' => 'Mempool Max', 'picture' => 'https://example.invalid/max.png']);
    $page = accountMenuPage($user, 1440);

    $img = 'document.querySelector("[data-test=account-chip] img[data-avatar]")';
    BrowserWait::until($page, "() => { const img = {$img}; return !! img && img.complete && img.naturalWidth > 0; }", 10_000);
    $avatar = $page->evaluate("() => { const img = {$img}; return { src: img.getAttribute('src'), alt: img.alt, width: img.naturalWidth }; }");

    expect($avatar['src'])->toContain('/avatars/'.$user->pubkey.'.svg')
        ->and($avatar['alt'])->toEndWith(', generated')
        ->and($avatar['width'])->toBeGreaterThan(0);
    // The refused pictures are the only failures on the page: the proxy URL with the picture's own URL, 96 px cut.
    $bad = $page->evaluate(BrowserConsole::BAD_RESPONSES);
    expect($bad)->not->toBe([]);
    foreach ($bad as $entry) {
        expect($entry)->toStartWith('404 ')->toContain('/__no-image-proxy/avatar?src='.rawurlencode('https://example.invalid/max.png'));
    }

    // Without the proxy the page carries no proxy URL and no proxy meta.
    config(['esports.image_proxy_url' => '']);
    $direct = accountMenuPage($user, 1440);
    expect($direct->evaluate('() => document.documentElement.outerHTML.includes("__no-image-proxy")'))->toBeFalse()
        ->and($direct->evaluate('() => !! document.querySelector("meta[name=image-proxy]")'))->toBeFalse()
        ->and($direct->evaluate("() => {$img}.getAttribute('data-fallback')"))->toContain('/avatars/');
});

<?php

use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Opponent found" on another page (P5c)
|--------------------------------------------------------------------------
|
| Anna joins the blitz queue and goes to /clans with the tab in the
| background; Bert joins. Anna's page must notice on its own: a toast with
| the countdown, the bell count, the match-found sound, the flashing title,
| and the jump into the game. Two contexts, one in-process app, as in
| BlitzGameTest (same session/guard reset, same Reverb from test-browser.sh).
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

/**
 * The error collector of BlitzGameTest, plus three spies: every sound asked
 * of the audio module (window.esportsSounds.play), every tab title, and a
 * switch that makes the page believe its tab is hidden.
 */
const NOTIFY_SPIES = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...args) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push(this.status + ' ' + this.responseURL); });
        return originalSend.apply(this, args);
    };

    window.__played = [];
    let sounds;
    Object.defineProperty(window, 'esportsSounds', {
        configurable: true,
        get: () => sounds,
        set(value) {
            const play = value.play.bind(value);
            value.play = (name) => { window.__played.push(name); return play(name); };
            sounds = value;
        },
    });

    window.__hidden = false;
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.__hidden });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (window.__hidden ? 'hidden' : 'visible') });

    window.__titles = [];
    document.addEventListener('DOMContentLoaded', () => {
        new MutationObserver(() => window.__titles.push(document.title)).observe(document.querySelector('title'), { childList: true, characterData: true, subtree: true });
    });
    JS;

function notifyPage(User $user, string $to): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(NOTIFY_SPIES);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

test('a player waiting on another page hears, sees and is taken to the game the queue found', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();

    // Anna joins the queue; the one-time question about desktop notifications comes with it.
    $pageA = notifyPage($anna, '/chess');
    $pageA->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=searching]") !== null', 10_000);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=notify-prompt]")?.offsetParent !== null', 5_000);
    $pageA->locator('[data-test=notify-prompt-no]')->click();
    expect($pageA->evaluate('() => localStorage.getItem("esports.notify-asked")'))->toBe('declined');

    // She moves on to the clans page and puts the tab in the background.
    $pageA->goto(ComputeUrl::from('/clans'));
    BrowserWait::until($pageA, '() => window.Echo?.connector?.pusher?.connection?.state === "connected" && window.esportsAlerts !== undefined', 10_000);
    $pageA->evaluate('() => { window.__hidden = true; }');
    $titleBefore = $pageA->evaluate('() => document.title');

    $pageB = notifyPage($bert, '/chess');
    $pageB->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($pageB, '() => location.pathname.startsWith("/games/")', 10_000);
    $game = ChessGame::query()->sole();

    // Anna's clans page: toast with the countdown, bell count, sound, title.
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=toast-countdown]") !== null', 5_000);
    $toast = $pageA->evaluate('() => document.querySelector("[x-data=toastStack] [role=status]").innerText');
    BrowserWait::until($pageA, '() => [...document.querySelectorAll("[data-test=bell-count]")].some((b) => b.innerText.trim() === "1")', 5_000);
    $played = $pageA->evaluate('() => window.__played');
    $titles = $pageA->evaluate('() => window.__titles');

    expect($toast)->toContain('Opponent found: '.$bert->displayName())
        ->toContain('Play now')
        ->toContain('Opening the game in')
        ->and($played)->toContain('matchFound')
        ->and($titles)->toContain('● Opponent found: '.$bert->displayName().' — TWENTY ONE')
        ->and($pageA->url())->toEndWith('/clans')
        ->and($pageA->evaluate('() => window.__errors'))->toBe([]);

    // The countdown opens the game on its own.
    BrowserWait::until($pageA, '() => location.pathname === "/games/'.$game->id.'"', 10_000);

    expect($anna->notifications()->sole()->data['kind'])->toBe('match_found')
        ->and($titleBefore)->not->toStartWith('●')
        ->and($pageA->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => window.__errors'))->toBe([]);
});

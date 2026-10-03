<?php

use App\Support\Chess\ChessInvites;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Finding your cup match, in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A participant whose cup round is open opens home: the header badge
| ("Your match"), the highlighted first entry of the dock ("Waiting for
| <opponent>") and the banner on top are there, inside the viewport, and
| the dock entry does not cover the dock's own bar. Then the opponent
| accepts the cup invite and the league starts the game: without a reload
| a toast says so, and the badge, the dock entry and the banner flip to
| "live" (the badge pulses). At en 375 and 1440 and de 375.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400, and horizontal overflow; a thrown error injected at the
| end proves the collector sees one. CUP_SHOTS=<dir> writes the screenshots there.
|
*/

const CUP_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const CUP_MEASURE = <<<'JS'
    () => {
        const box = (selector) => {
            const el = document.querySelector(selector);
            if (el === null || el.offsetParent === null && getComputedStyle(el).position !== 'fixed') return null;
            const r = el.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) return null;
            return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), state: el.dataset.state ?? null, text: el.innerText.replace(/\s+/g, ' ').trim() };
        };
        const onTop = (selector) => {
            const el = document.querySelector(selector);
            if (el === null) return false;
            const r = el.getBoundingClientRect();
            const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return hit !== null && (el === hit || el.contains(hit));
        };
        const bar = box('[data-test=dock-mobile-bar]') ?? box('[data-test=match-dock]');
        return {
            badge: box('[data-test=cup-badge]'),
            dock: box('[data-test=dock-cup]'),
            banner: box('[data-test=cup-banner]'),
            bar,
            onTop: ['[data-test=cup-badge]', '[data-test=dock-cup]', '[data-test=cup-banner-open]'].map(onTop),
            pulse: document.querySelector('[data-test=cup-badge] .animate-live') !== null,
            toasts: [...document.querySelectorAll('[role=status] > div')].map((el) => el.innerText.replace(/\s+/g, ' ').trim()),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function cupShot(Page $page, string $name): void
{
    $dir = getenv('CUP_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('a cup participant finds the match on home and sees the toast and the flip to live when the game starts, at en 375 and 1440 and de 375', function () {
    $measured = [];
    $page = null;
    // One cup (a series has one open cup at a time), one first-round match per viewport.
    $cup = runningCup(8);
    cupTick();
    $matches = openCupMatches($cup)->values();

    foreach ([[375, 812, 'en'], [1440, 900, 'en'], [375, 812, 'de']] as $run => [$width, $height, $locale]) {
        $match = $matches[$run];
        [$white, $black] = matchPlayers($match);
        $black->forceFill(['locale' => $locale])->save();
        TestSigner::forBrowser($black);

        $page = visit(BrowserLogin::url($black))->page();
        $page->context()->addInitScript(CUP_COLLECTOR);
        $page->context()->addInitScript(TestSigner::browserStub($black));
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('home')));
        // The league acts only once the player's private channel is subscribed: Reverb drops an event sent
        // before that. Waited for, not slept: a usleep() froze this in-process server, so the channel's
        // /broadcasting/auth stayed unanswered and the subscription landed a few ms before the accept, or after it.
        BrowserWait::until($page, '() => document.querySelector("[data-test=dock-cup]") !== null && document.readyState === "complete"'
            .' && window.Echo?.connector?.pusher?.connection?.state === "connected"'
            .' && window.Echo.connector.pusher.channel("private-App.Models.User.'.$black->id.'")?.subscribed === true', 8_000);
        $before = $page->evaluate(CUP_MEASURE);
        cupShot($page, "cup-waiting-{$locale}-{$width}");

        expect($before['badge']['state'])->toBe('waiting')
            ->and($before['dock']['state'])->toBe('waiting')
            ->and($before['banner']['state'])->toBe('waiting')
            ->and($before['badge']['top'])->toBeGreaterThanOrEqual(0)
            ->and($before['badge']['right'])->toBeLessThanOrEqual($width)
            ->and($before['badge']['height'])->toBeGreaterThanOrEqual(44)
            ->and($before['dock']['left'])->toBeGreaterThanOrEqual(0)
            ->and($before['dock']['right'])->toBeLessThanOrEqual($width)
            ->and($before['dock']['bottom'])->toBeLessThanOrEqual($height)
            ->and($before['banner']['right'])->toBeLessThanOrEqual($width)
            ->and($before['banner']['top'])->toBeLessThan($height)
            ->and($before['onTop'])->toBe([true, true, true])
            ->and($before['overflow'])->toBeLessThanOrEqual(0)
            ->and($before['errors'])->toBe([]);

        // The dock entry floats above the dock's own bar, never on it.
        if ($before['bar'] !== null) {
            expect($before['dock']['bottom'])->toBeLessThanOrEqual($before['bar']['top']);
        }

        expect($before['dock']['text'])->toContain($locale === 'en' ? 'Waiting for '.$white->displayName() : 'Warte auf '.$white->displayName())
            ->and($before['banner']['text'])->toContain($locale === 'en' ? 'Your cup match is next' : 'Dein Cup-Match steht an');

        // The opponent accepts Black's cup invite: the league starts the game, Black is told wherever they are.
        $invites = app(ChessInvites::class);
        $game = $invites->accept($invites->inviteToCupMatch($black, $match), $white);

        BrowserWait::until($page, '() => document.querySelector("[data-test=cup-badge]")?.dataset.state === "live" && document.querySelector("[data-test=dock-cup]")?.dataset.state === "live" && document.querySelector("[data-test=cup-banner]")?.dataset.state === "live"', 10_000);
        BrowserWait::until($page, '() => [...document.querySelectorAll("[role=status] > div")].some((el) => el.innerText.includes(":"))', 5_000);
        $after = $page->evaluate(CUP_MEASURE);
        cupShot($page, "cup-live-{$locale}-{$width}");

        expect($after['pulse'])->toBeTrue()
            ->and($after['onTop'][0])->toBeTrue()
            ->and($after['dock']['bottom'])->toBeLessThanOrEqual($height)
            ->and($after['overflow'])->toBeLessThanOrEqual(0)
            ->and($after['errors'])->toBe([])
            ->and(implode(' | ', $after['toasts']))->toContain($cup->name.($locale === 'en' ? ': your game is on' : ': deine Partie läuft'))
            ->and($after['dock']['text'])->toContain($locale === 'en' ? 'Your cup game is live — play now' : 'Deine Cup-Partie läuft — jetzt spielen')
            ->and($page->evaluate('() => document.querySelector("[data-test=cup-badge]").getAttribute("href")'))->toBe(route('games.show', $game));

        $measured["{$locale}-{$width}"] = ['before' => array_diff_key($before, ['errors' => 1]), 'after' => array_diff_key($after, ['errors' => 1])];
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);

    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[cup-findability] ".json_encode($measured, JSON_UNESCAPED_UNICODE)."\n");
});

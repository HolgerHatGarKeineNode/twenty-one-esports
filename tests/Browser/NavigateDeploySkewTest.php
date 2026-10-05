<?php

use App\Events\UserNotified;
use App\Models\User;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserAssets;
use Tests\Support\BrowserBodylessFraming;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

beforeEach(function () {
    Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled-for-browser-tests'));
    BrowserAssets::use();
    app(HttpKernel::class)->pushMiddleware(BrowserBodylessFraming::class);
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.navigate' => true]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

const SOCKETS = <<<'JS'
    (() => {
        window.__sockets = [];
        const Orig = window.WebSocket;
        window.WebSocket = function (...args) {
            const ws = new Orig(...args);
            window.__sockets.push(ws);
            return ws;
        };
        window.WebSocket.prototype = Orig.prototype;
        Object.assign(window.WebSocket, { CONNECTING: 0, OPEN: 1, CLOSING: 2, CLOSED: 3 });
    })();
    JS;

// Rewrites the entry file names in a wire:navigate response, as a deploy in between would.
const SKEW = <<<'JS'
    (() => {
        const orig = window.fetch.bind(window);
        window.fetch = async (...args) => {
            const headers = args[1]?.headers;
            const nav = headers && (headers['X-Livewire-Navigate'] !== undefined || (typeof headers.has === 'function' && headers.has('X-Livewire-Navigate')));
            const res = await orig(...args);
            if (!nav || !window.__skew) return res;
            const text = await res.text();
            const patched = text.replace(/\/(app|echo)-[A-Za-z0-9_-]+\.js/g, '/$1-SKEW1.js').replace(/navigate-build\.js\?v=[^"&]+/g, 'navigate-build.js?v=SKEW1');
            window.__skewed = patched !== text;
            const out = new Response(patched, { status: res.status, statusText: res.statusText, headers: res.headers });
            Object.defineProperty(out, 'url', { value: res.url });
            Object.defineProperty(out, 'redirected', { value: res.redirected });
            return out;
        };
    })();
    JS;

function gateOnline(Page $page): void
{
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected" && window.Echo.connector.pusher.channel("presence-online")?.subscribed === true', 15_000);
}

function gatePage(User $user, string $path, int $width = 1440): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(SOCKETS);
    $page->context()->addInitScript(SKEW);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Livewire && !!window.Alpine', 15_000);

    return $page;
}

function gateSkewFiles(): array
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $made = [];
    foreach (['resources/js/app.js', 'resources/js/echo.js'] as $entry) {
        $file = public_path('build/'.$manifest[$entry]['file']);
        $copy = preg_replace('/-[A-Za-z0-9_-]+\.js$/', '-SKEW1.js', $file);
        copy($file, $copy);
        $made[] = $copy;
    }

    return $made;
}

test('a tab opened before a deploy reloads at its next navigate instead of starting the new build next to the old one', function (bool $skew) {
    $player = User::factory()->member()->create(['name' => 'Walker']);
    $unused = false && visit('/');
    $made = gateSkewFiles();

    try {
        $result = Playwright::usingTimeout(60_000, function () use ($player, $skew): array {
            $page = gatePage($player, '/');
            gateOnline($page);
            $page->evaluate('() => { window.__skew = '.($skew ? 'true' : 'false').'; window.__kept = true; window.__echo0 = window.Echo; window.__presence0 = window.esportsPresence; window.__alerts0 = window.esportsAlerts; }');

            $page->locator('[data-test=nav-tournaments]')->first()->click();
            BrowserWait::until($page, '() => location.pathname === "/tournaments" && document.readyState === "complete" && !!window.Alpine', 15_000);
            Execution::instance()->wait(2.0);

            $out = $page->evaluate('() => ({
                skewed: window.__skewed === true,
                kept: window.__kept === true,
                echoReplaced: window.Echo !== window.__echo0,
                presenceReplaced: window.esportsPresence !== window.__presence0,
                alertsReplaced: window.esportsAlerts !== window.__alerts0,
                sockets: window.__sockets.length,
                openSockets: window.__sockets.filter((s) => s.readyState <= 1).length,
                entryScripts: [...document.querySelectorAll("head script[src]")].map((s) => s.getAttribute("src").split("/").pop()).filter((n) => /^(app|echo)-/.test(n)),
                errors: window.__errors,
            })');

            // One notification: how many toasts does the player see?
            event(new UserNotified($player->id, ['id' => 'gate-1', 'kind' => 'invite', 'title' => 'Gate', 'body' => 'Gate', 'url' => route('rules'), 'match' => null, 'action' => null, 'sound' => 'none', 'tone' => 'confirmed', 'redirect' => false]));
            Execution::instance()->wait(2.0);
            $out['toasts'] = $page->evaluate('() => Alpine.$data(document.querySelector("[x-data=toastStack]")).toasts.length');

            return $out;
        });
    } finally {
        foreach ($made as $copy) {
            @unlink($copy);
        }
    }

    fwrite(STDERR, "\n[skew=".json_encode($skew).'] '.json_encode($result)."\n");
    expect($result['kept'])->toBe(! $skew)->and($result['openSockets'])->toBeLessThanOrEqual(1)->and($result['toasts'])->toBe(1);
})->with([false, true]);

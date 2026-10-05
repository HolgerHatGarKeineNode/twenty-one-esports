<?php

use App\Events\ChessGameUpdated;
use App\Events\UserNotified;
use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| wire:navigate spike between home and /play (performance plan P6)
|--------------------------------------------------------------------------
|
| A measurement, not a guard: the same logged-in player walks home -> /play
| -> home five times, once with `esports.navigate` off (every click is a
| full page load, today's behaviour) and once with it on (the header logo
| and the hub's "All games and modes" carry `wire:navigate.hover`). Per pass
| it records:
|
| - click to paint: from the pointerdown on the link to the second animation
|   frame after the target page's marker is in the document (a full load:
|   the new document polls for the marker from its first script; a navigate:
|   the same poll in the kept window), real clock, epoch milliseconds;
| - presence: a second player on another page counts the protocol's
|   member_removed / member_added for the walking player (the raw events,
|   before echo.js's 5 s leave grace);
| - what stays alive in the window after each arrival: document and window
|   listeners (a wrapped addEventListener, deduplicated as the browser
|   does), live setInterval ids, pending setTimeouts of 1 s or more, Alpine
|   components started minus destroyed (a wrapped Alpine.data), Livewire
|   components and how many of them are detached, and per Echo channel the
|   Pusher callbacks per event, plus the connection's own callbacks;
| - console errors and every Livewire answer's status.
|
| A planted listener that adds one `p6-control` listener per navigation is
| the positive control for the growth count; a thrown error is the control
| for the console collector.
|
| NAVIGATE_SPIKE_REPORT=<file> writes the raw numbers as JSON there.
|
*/

const SPIKE_ROUNDS = 5;

const SPIKE_PROBE = <<<'JS'
    (() => {
        const origAdd = EventTarget.prototype.addEventListener;
        const origRemove = EventTarget.prototype.removeEventListener;
        const origSetInterval = window.setInterval.bind(window);
        const origClearInterval = window.clearInterval.bind(window);
        const origSetTimeout = window.setTimeout.bind(window);
        const origClearTimeout = window.clearTimeout.bind(window);
        const ids = new WeakMap();
        let nextId = 1;
        const fid = (fn) => {
            if (!fn || (typeof fn !== 'function' && typeof fn !== 'object')) return 0;
            if (!ids.has(fn)) ids.set(fn, nextId++);
            return ids.get(fn);
        };
        const snip = (fn) => {
            try { return String(typeof fn === 'function' ? fn : fn?.handleEvent ?? fn).replace(/\s+/g, ' ').slice(0, 140); } catch { return '?'; }
        };
        const P = (window.__p6 = { listeners: { document: new Map(), window: new Map() }, intervals: new Map(), timeouts: new Map(), alpine: {}, statuses: [], requests: [] });
        const which = (target) => (target === document ? 'document' : target === window ? 'window' : null);
        const captureOf = (options) => (typeof options === 'boolean' ? options : !!(options && options.capture));

        EventTarget.prototype.addEventListener = function (type, fn, options) {
            const where = which(this);
            if (where && fn) {
                const key = type + '|' + fid(fn) + '|' + captureOf(options);
                const map = P.listeners[where];
                map.set(key, { type, snip: snip(fn) });
                if (options && typeof options === 'object' && options.once) {
                    origAdd.call(this, type, () => map.delete(key), { once: true, capture: captureOf(options) });
                }
                if (options && typeof options === 'object' && options.signal) {
                    options.signal.addEventListener('abort', () => map.delete(key));
                }
            }
            return origAdd.call(this, type, fn, options);
        };
        EventTarget.prototype.removeEventListener = function (type, fn, options) {
            const where = which(this);
            if (where && fn) P.listeners[where].delete(type + '|' + fid(fn) + '|' + captureOf(options));
            return origRemove.call(this, type, fn, options);
        };
        window.setInterval = (fn, delay, ...args) => {
            const id = origSetInterval(fn, delay, ...args);
            P.intervals.set(id, snip(fn) + ' @' + delay);
            return id;
        };
        window.clearInterval = (id) => { P.intervals.delete(id); return origClearInterval(id); };
        window.setTimeout = (fn, delay = 0, ...args) => {
            if (Number(delay) < 1000) return origSetTimeout(fn, delay, ...args);
            const id = origSetTimeout((...a) => { P.timeouts.delete(id); return typeof fn === 'function' ? fn(...a) : undefined; }, delay, ...args);
            P.timeouts.set(id, snip(fn) + ' @' + delay);
            return id;
        };
        window.clearTimeout = (id) => { P.timeouts.delete(id); return origClearTimeout(id); };
        const origFetch = window.fetch;
        window.fetch = (...args) => {
            if (/\/players\/[^/]+\/card/.test(String(args[0]?.url ?? args[0]))) P.cardFetches = (P.cardFetches ?? 0) + 1;
            // The page fetches of wire:navigate (hover prefetch or press): when each started and was answered.
            const headers = args[1]?.headers;
            const navigateFetch = headers && (headers['X-Livewire-Navigate'] !== undefined || (typeof headers.has === 'function' && headers.has('X-Livewire-Navigate')));
            if (navigateFetch) {
                const record = { url: new URL(String(args[0]?.url ?? args[0]), location.href).pathname, start: Math.round(performance.timeOrigin + performance.now()), end: null };
                P.pageFetches = [...(P.pageFetches ?? []), record];
                return origFetch(...args).then((response) => { record.end = Math.round(performance.timeOrigin + performance.now()); return response; });
            }
            return origFetch(...args);
        };

        // Alpine components: started minus destroyed, per name (registered before the app's own alpine:init listeners).
        origAdd.call(document, 'alpine:init', () => {
            const register = window.Alpine.data.bind(window.Alpine);
            window.Alpine.data = (name, factory) => register(name, function (...args) {
                const component = factory.apply(this, args);
                const init = component.init;
                const destroy = component.destroy;
                const count = (P.alpine[name] ??= { init: 0, destroy: 0 });
                component.init = function (...a) { count.init++; return init?.apply(this, a); };
                component.destroy = function (...a) { count.destroy++; return destroy?.apply(this, a); };
                return component;
            });
        });
        origAdd.call(document, 'livewire:init', () => {
            window.Livewire.interceptRequest(({ request, onSend, onResponse, onFailure }) => {
                onSend(() => P.requests.push([...request.messages].map((message) => message.component.name + ':' + ([...message.actions].map((action) => action.name).join('+') || 'render') + (message.component.el?.isConnected ? '' : ' (detached)'))));
                onResponse(({ response }) => P.statuses.push(response.status));
                onFailure(() => P.statuses.push(0));
            });
        });

        // Click to paint. A pointerdown on one of the two links starts it; the page that has the target marker ends it.
        const targets = { '/': '[data-test=home-stage]', '/play': '[data-test=play-page]' };
        const now = () => performance.timeOrigin + performance.now();
        const watch = () => {
            const pending = JSON.parse(sessionStorage.getItem('__p6Pending') || 'null');
            if (!pending) return;
            const step = () => {
                const still = JSON.parse(sessionStorage.getItem('__p6Pending') || 'null');
                if (!still || still.t0 !== pending.t0) return;
                if (location.pathname === pending.path && document.querySelector(targets[pending.path])) {
                    const found = now();
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        const results = JSON.parse(sessionStorage.getItem('__p6Results') || '[]');
                        results.push({ path: pending.path, t0: Math.round(pending.t0), found: Math.round(found - pending.t0), paint: Math.round(now() - pending.t0), fullLoad: performance.timeOrigin > pending.t0 });
                        sessionStorage.setItem('__p6Results', JSON.stringify(results));
                        sessionStorage.removeItem('__p6Pending');
                    }));
                    return;
                }
                requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        origAdd.call(document, 'pointerdown', (event) => {
            const link = event.target.closest?.('[data-test=shell-home],[data-test=hub-all-games]');
            if (!link) return;
            const path = new URL(link.href).pathname;
            sessionStorage.setItem('__p6Pending', JSON.stringify({ t0: now(), path }));
            watch();
        }, true);
        watch();

        const callbacks = (registry) => Object.fromEntries(Object.entries(registry?._callbacks ?? {}).map(([name, list]) => [name.replace(/^_/, ''), list.length]));
        P.snapshot = () => {
            const pusher = window.Echo?.connector?.pusher;
            const byType = (map) => [...map.values()].reduce((all, { type }) => ({ ...all, [type]: (all[type] ?? 0) + 1 }), {});
            const components = window.Livewire?.all?.() ?? [];
            return {
                path: location.pathname,
                listeners: { document: P.listeners.document.size, window: P.listeners.window.size, documentByType: byType(P.listeners.document), windowByType: byType(P.listeners.window) },
                listenerKeys: [...P.listeners.document.entries(), ...P.listeners.window.entries()].map(([key, v]) => key + '|' + v.snip),
                intervals: P.intervals.size,
                intervalList: [...P.intervals.values()],
                timeouts: P.timeouts.size,
                timeoutList: [...P.timeouts.values()],
                alpineLive: Object.fromEntries(Object.entries(P.alpine).map(([name, c]) => [name, c.init - c.destroy]).filter(([, n]) => n !== 0)),
                alpineStarted: Object.values(P.alpine).reduce((sum, c) => sum + c.init, 0),
                xData: document.querySelectorAll('[x-data]').length,
                livewire: components.length,
                livewireDetached: components.filter((c) => !c.el?.isConnected).length,
                livewireNames: components.map((c) => c.name).sort(),
                echoChannels: Object.keys(window.Echo?.connector?.channels ?? {}).sort(),
                pusherChannels: pusher ? Object.fromEntries(Object.values(pusher.channels.channels).map((c) => [c.name, { subscribed: c.subscribed, callbacks: callbacks(c.callbacks) }])) : null,
                connectionCallbacks: pusher ? callbacks(pusher.connection.callbacks) : null,
                socketId: pusher?.connection?.socket_id ?? null,
                presenceListeners: window.esportsPresence?.listeners?.size ?? null,
                headScripts: document.head.querySelectorAll('script').length,
                jsonLd: document.querySelectorAll('script[type="application/ld+json"]').length,
                domNodes: document.getElementsByTagName('*').length,
            };
        };
    })();
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

/** Waits until the page's socket is connected and on the `online` presence channel. */
function spikeOnline(Page $page): void
{
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected" && window.Echo.connector.pusher.channel("presence-online")?.subscribed === true', 15_000);
}

/** Clicks one of the two links like a person: hover, a moment, click; then waits for the target page to have painted. */
function spikeClick(Page $page, string $link, string $path): void
{
    if ($link === 'hub-all-games') {
        $page->locator('[data-test=games-menu]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=hub-all-games]")?.checkVisibility() === true', 5_000);
    }
    $count = $page->evaluate('() => JSON.parse(sessionStorage.getItem("__p6Results") || "[]").length');
    $page->locator('[data-test='.$link.']')->hover();
    Execution::instance()->wait(0.15);
    $page->locator('[data-test='.$link.']')->click();
    BrowserWait::until($page, '() => location.pathname === '.json_encode($path).' && JSON.parse(sessionStorage.getItem("__p6Results") || "[]").length > '.$count, 15_000);
    BrowserWait::until($page, '() => !!window.__p6 && !!window.Livewire && !!window.Alpine', 10_000);
}

/**
 * One pass: the player loads home, then walks to /play and back SPIKE_ROUNDS times.
 *
 * @return array<string, mixed>
 */
function spikePass(User $player, User $watcher, ChessGame $game, Page $observer, bool $navigate): array
{
    config(['esports.navigate' => $navigate]);
    $observer->evaluate('() => { window.__members = { added: 0, removed: 0 }; }');

    $webpage = visit(BrowserLogin::url($player));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(SPIKE_PROBE);
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from('/'));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.__p6 && !!window.Livewire && !!window.Alpine', 15_000);
    spikeOnline($page);

    $attribute = $page->evaluate('() => document.querySelector("[data-test=shell-home]").hasAttribute("wire:navigate.hover")');
    expect($attribute)->toBe($navigate);

    // Positive control for the growth count: one more `p6-control` listener per navigation (navigate only; a full load starts fresh).
    $page->evaluate('() => document.addEventListener("livewire:navigated", () => document.addEventListener("p6-control", () => {}))');

    $snapshots = ['home 0' => $page->evaluate('() => window.__p6.snapshot()')];
    $errors = [];
    $statuses = [];
    $collect = function () use ($page, &$errors, &$statuses): void {
        $errors = [...$errors, ...$page->evaluate('() => window.__errors')];
        $statuses = [...$statuses, ...$page->evaluate('() => window.__p6.statuses')];
        $page->evaluate('() => { window.__errors = []; window.__p6.statuses = []; }');
    };

    for ($round = 1; $round <= SPIKE_ROUNDS; $round++) {
        if (! $navigate) {
            $collect();
        }
        spikeClick($page, 'hub-all-games', '/play');
        spikeOnline($page);
        Execution::instance()->wait(0.5);
        $snapshots["play {$round}"] = $page->evaluate('() => window.__p6.snapshot()');

        if (! $navigate) {
            $collect();
        }
        spikeClick($page, 'shell-home', '/');
        spikeOnline($page);
        Execution::instance()->wait(0.5);
        $snapshots["home {$round}"] = $page->evaluate('() => window.__p6.snapshot()');
    }

    // A Livewire roundtrip on the last page: the dock refreshes and answers.
    $page->evaluate('() => Livewire.getByName("match-dock")[0]?.$refresh()');
    Execution::instance()->wait(1.0);
    $collect();

    // Two pushes after the walk: a move in the dock's game (its watch channel) and a notification (the player's
    // channel, playerEvents.js). What each one costs in Livewire requests, and from which components.
    $page->evaluate('() => { window.__pushes = 0; window.Echo.connector.pusher.connection.bind("message", (m) => { if (m.event && ! m.event.startsWith("pusher")) window.__pushes++; }); }');
    $pushes = [];
    foreach (['game move' => new ChessGameUpdated($game->id, ['ply' => 1]), 'notification' => new UserNotified($player->id, ['id' => 'p6-push', 'kind' => 'invite', 'title' => 'P6', 'body' => 'P6', 'url' => route('home'), 'match' => null, 'action' => null, 'sound' => 'none', 'tone' => 'confirmed', 'redirect' => false])] as $name => $event) {
        $page->evaluate('() => { window.__p6.requests = []; window.__pushes = 0; }');
        event($event);
        BrowserWait::until($page, '() => window.__pushes >= 1', 10_000);
        Execution::instance()->wait(1.5);
        $pushes[$name] = $page->evaluate('() => window.__p6.requests');
    }
    $collect();

    // Two shell behaviours that hang on window/document listeners: the "/" shortcut opens the search, and hovering a
    // player name fetches the player card once.
    $page->locator('body')->press('/');
    Execution::instance()->wait(0.3);
    $shortcut = $page->evaluate('() => ({ focused: document.activeElement?.id ?? null, connected: document.activeElement?.isConnected ?? null })');
    $page->locator('body')->press('Escape');
    // Home lists no player name in this world: one is put at the top of the page, as <x-player-link> renders it.
    $link = sprintf('<a href="%s" data-player-card="%s" data-pubkey="%s" data-player-name="Watcher" data-test="p6-card">Watcher</a>', route('players.show', $watcher->npub, absolute: false), $watcher->npub, $watcher->pubkey);
    $page->evaluate('() => { window.__p6.cardFetches = 0; document.querySelector("main").insertAdjacentHTML("afterbegin", '.json_encode($link).'); }');
    $page->evaluate('() => document.querySelector("[data-test=p6-card]").dispatchEvent(new MouseEvent("mouseover", { bubbles: true }))');
    Execution::instance()->wait(1.5);
    $cards = $page->evaluate('() => window.__p6.cardFetches');
    $timings = $page->evaluate('() => JSON.parse(sessionStorage.getItem("__p6Results") || "[]")');
    $pageFetches = $page->evaluate('() => window.__p6.pageFetches ?? []');

    // Positive control for the console collector.
    $page->evaluate('() => setTimeout(() => { throw new Error("p6 positive control"); }, 0)');
    Execution::instance()->wait(0.2);
    $control = $page->evaluate('() => window.__errors');

    // The protocol's leave arrives when the old socket closes; give the last one time to land.
    Execution::instance()->wait(2.0);
    $members = $observer->evaluate('() => window.__members');

    $badResponses = $page->evaluate(BrowserConsole::BAD_RESPONSES);
    $webpage->page()->close();

    return compact('timings', 'snapshots', 'errors', 'statuses', 'control', 'members', 'badResponses', 'pushes', 'shortcut', 'cards', 'pageFetches');
}

test('wire:navigate between home and /play: click to paint, presence, what stays alive, console', function () {
    $player = User::factory()->member()->create(['name' => 'Walker']);
    $watcher = User::factory()->member()->create(['name' => 'Watcher']);
    $game = ChessGame::factory()->daily()->create(['white_id' => $player->id]);

    $observerPage = visit(BrowserLogin::url($watcher));
    $observer = $observerPage->page();
    $observer->setViewportSize(1280, 800);
    $observer->goto(ComputeUrl::from('/rules'));
    spikeOnline($observer);
    $observer->evaluate('() => { window.__members = { added: 0, removed: 0 }; const channel = window.Echo.connector.pusher.channel("presence-online"); channel.bind("pusher:member_added", (m) => { if (String(m.id) === '.json_encode((string) $player->id).') window.__members.added++; }); channel.bind("pusher:member_removed", (m) => { if (String(m.id) === '.json_encode((string) $player->id).') window.__members.removed++; }); }');

    $results = Playwright::usingTimeout(60_000, fn (): array => [
        'full load' => spikePass($player, $watcher, $game, $observer, false),
        'navigate' => spikePass($player, $watcher, $game, $observer, true),
    ]);

    if (is_string($file = getenv('NAVIGATE_SPIKE_REPORT')) && $file !== '') {
        file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    foreach ($results as $mode => $result) {
        expect($result['timings'])->toHaveCount(2 * SPIKE_ROUNDS, $mode.': one timing per click')
            ->and($result['control'])->toContain('error: Uncaught Error: p6 positive control');
    }
    // The growth count sees the planted listener: one per navigation.
    expect($results['navigate']['snapshots']['home 5']['listeners']['documentByType']['p6-control'] ?? 0)->toBe(2 * SPIKE_ROUNDS);
});

/*
| A page entry (`scripts` of the layout: push.js, chess.js, matchRoom.js, ...) registers its Alpine components in
| `alpine:init`. Reached by wire:navigate, the entry is injected after Alpine started, so that listener never runs.
| The settings tabs already navigate with wire:navigate: account -> notifications (push.js) -> chess (chess.js).
*/
test('a page entry reached by wire:navigate: the settings tabs into notifications and chess', function () {
    $player = User::factory()->member()->create(['name' => 'Tabber']);

    $webpage = visit(BrowserLogin::url($player));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('settings.account', absolute: false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Livewire && !!window.Alpine', 15_000);
    $page->evaluate('() => { window.__kept = true; }');

    $seen = [];
    foreach (['settings-notifications-tab' => '/settings/notifications', 'settings-chess-tab' => '/settings/chess'] as $tab => $path) {
        $page->locator('[data-test='.$tab.']')->click();
        BrowserWait::until($page, '() => location.pathname === '.json_encode($path).' && window.__kept === true', 10_000);
        Execution::instance()->wait(1.0);
        $seen[$path] = [
            'errors' => $page->evaluate('() => window.__errors'),
            'pushToggleStarted' => $page->evaluate('() => { const el = document.querySelector("[x-data^=pushToggle]"); return el ? !!el._x_dataStack : null; }'),
        ];
        $page->evaluate('() => { window.__errors = []; }');
    }

    if (is_string($file = getenv('NAVIGATE_SPIKE_ENTRY_REPORT')) && $file !== '') {
        file_put_contents($file, json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    expect($seen)->toHaveKeys(['/settings/notifications', '/settings/chess']);
});

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
| wire:navigate on the shell (performance plan P6b; spike P6)
|--------------------------------------------------------------------------
|
| A wire:navigate swap keeps the window, so whatever a page leaves behind on
| window, document, the websocket or a timer doubles with every click. The
| spike counted +10 listeners and one `game.updated` callback per click on
| home <-> /play (docs/plans/…-performance/p6-spike.md). These tests walk the
| navigable pages (App\Support\Navigation\Navigate::PAGES) and hold:
|
| - after 10 navigations across shell pages the document and window
|   listeners, the Echo callbacks (the dock's `game.updated` included), the
|   live intervals, the Alpine components started minus destroyed and the
|   Livewire components are the same as after the first round, and a hover
|   over a player name fetches the card once;
| - presence: a second player sees no leave and no join per click;
| - the console stays empty (a thrown error is the positive control) and
|   every Livewire answer is 2xx;
| - the settings tabs into Notifications start the push toggle (the defect of
|   2026-10-05), and the tabs still navigate after a Livewire roundtrip;
| - into and out of a chess game the page loads in full, also when something
|   asks for a navigate anyway (resources/js/navigateGuard.js), and the
|   game's presence channel sees one join and one leave, not a swap's;
| - the same at 390 px over the tab bar, and for a guest.
|
| The probe wraps addEventListener/removeEventListener (deduplicated as the
| browser does), setInterval, setTimeout >= 1 s and Alpine.data (from the
| moment Livewire sets window.Alpine, so page entries that register at once
| are counted too). A planted listener that adds one `p6-control` listener per
| navigation is the positive control for the growth count.
|
| The last test is the measurement for the plan (full load vs. navigate on
| the same walk, click to paint); it runs only with NAVIGATE_SPIKE_REPORT=<file>.
|
*/

const NAVIGATE_PROBE = <<<'JS'
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
        const P = (window.__p6 = { listeners: { document: new Map(), window: new Map() }, intervals: new Map(), timeouts: new Map(), alpine: {}, statuses: [], cardFetches: 0, pageFetches: [] });
        const which = (target) => (target === document ? 'document' : target === window ? 'window' : null);
        const captureOf = (options) => (typeof options === 'boolean' ? options : !!(options && options.capture));

        EventTarget.prototype.addEventListener = function (type, fn, options) {
            const where = which(this);
            if (where && fn && !(options && typeof options === 'object' && options.signal?.aborted)) {
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
            const url = String(args[0]?.url ?? args[0]);
            if (/\/players\/[^/]+\/card/.test(url)) P.cardFetches++;
            const headers = args[1]?.headers;
            if (headers && (headers['X-Livewire-Navigate'] !== undefined || (typeof headers.has === 'function' && headers.has('X-Livewire-Navigate')))) {
                P.pageFetches.push(new URL(url, location.href).pathname);
            }
            return origFetch(...args);
        };

        // Alpine components, started minus destroyed per name: wrapped when Livewire sets window.Alpine, before any
        // script registers a component (page entries register at once when Alpine is there, resources/js/registerAlpine.js).
        const wrap = (alpine) => {
            const register = alpine.data.bind(alpine);
            alpine.data = (name, factory) => register(name, function (...args) {
                const component = factory.apply(this, args);
                const init = component.init;
                const destroy = component.destroy;
                const count = (P.alpine[name] ??= { init: 0, destroy: 0 });
                component.init = function (...a) {
                    count.init++;
                    // Across documents too: a page that starts and is then reloaded still counts.
                    const inits = JSON.parse(sessionStorage.getItem('__p6Inits') || '{}');
                    inits[name] = (inits[name] ?? 0) + 1;
                    sessionStorage.setItem('__p6Inits', JSON.stringify(inits));
                    return init?.apply(this, a);
                };
                component.destroy = function (...a) { count.destroy++; return destroy?.apply(this, a); };
                return component;
            });
        };
        let alpine;
        Object.defineProperty(window, 'Alpine', { configurable: true, get: () => alpine, set: (value) => { alpine = value; if (value?.data) wrap(value); } });
        origAdd.call(document, 'livewire:init', () => {
            window.Livewire.interceptRequest(({ onResponse, onFailure }) => {
                onResponse(({ response }) => P.statuses.push(response.status));
                onFailure(() => P.statuses.push(0));
            });
        });

        // Click to paint: a pointerdown on a link starts it; the target path with a new body (navigate) or a new
        // document (full load) and #content in it ends it, two animation frames later.
        const now = () => performance.timeOrigin + performance.now();
        const watch = () => {
            const pending = JSON.parse(sessionStorage.getItem('__p6Pending') || 'null');
            if (!pending) return;
            const step = () => {
                const still = JSON.parse(sessionStorage.getItem('__p6Pending') || 'null');
                if (!still || still.t0 !== pending.t0) return;
                if (location.pathname === pending.path && document.body && !document.body.hasAttribute('data-p6-left') && document.getElementById('content')) {
                    const found = now();
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        const results = JSON.parse(sessionStorage.getItem('__p6Results') || '[]');
                        results.push({ path: pending.path, found: Math.round(found - pending.t0), paint: Math.round(now() - pending.t0), fullLoad: performance.timeOrigin > pending.t0 });
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
            const link = event.target.closest?.('a[href]');
            if (!link || new URL(link.href).origin !== location.origin) return;
            document.body.setAttribute('data-p6-left', '');
            sessionStorage.setItem('__p6Pending', JSON.stringify({ t0: now(), path: new URL(link.href).pathname }));
            watch();
        }, true);
        origAdd.call(document, 'DOMContentLoaded', watch);

        const callbacks = (registry) => Object.fromEntries(Object.entries(registry?._callbacks ?? {}).map(([name, list]) => [name.replace(/^_/, ''), list.length]));
        P.snapshot = () => {
            const pusher = window.Echo?.connector?.pusher;
            const components = window.Livewire?.all?.() ?? [];
            const byType = (map) => [...map.values()].reduce((all, { type }) => ({ ...all, [type]: (all[type] ?? 0) + 1 }), {});
            return {
                path: location.pathname,
                listeners: { document: P.listeners.document.size, window: P.listeners.window.size, documentByType: byType(P.listeners.document), windowByType: byType(P.listeners.window) },
                listenerKeys: [...P.listeners.document.entries(), ...P.listeners.window.entries()].map(([key, v]) => key + '|' + v.snip),
                intervals: P.intervals.size,
                intervalList: [...P.intervals.values()],
                timeouts: P.timeouts.size,
                alpineLive: Object.fromEntries(Object.entries(P.alpine).map(([name, c]) => [name, c.init - c.destroy]).filter(([, n]) => n !== 0)),
                livewire: components.length,
                livewireDetached: components.filter((c) => !c.el?.isConnected).length,
                pusherChannels: pusher ? Object.fromEntries(Object.values(pusher.channels.channels).map((c) => [c.name, callbacks(c.callbacks)])) : null,
                connectionCallbacks: pusher ? callbacks(pusher.connection.callbacks) : null,
                socketId: pusher?.connection?.socket_id ?? null,
                presenceListeners: window.esportsPresence?.listeners?.size ?? null,
                domNodes: document.getElementsByTagName('*').length,
            };
        };
    })();
    JS;

beforeEach(function () {
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

/** A page with the probe and the console collector, logged in as $user (or a guest), on $path. */
function navigatePage(?User $user, string $path, int $width = 1440, int $height = 900): Page
{
    $page = ($user === null ? visit(BrowserLogin::LANDING) : visit(BrowserLogin::url($user)))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(NAVIGATE_PROBE);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    navigateReady($page);

    return $page;
}

function navigateReady(Page $page): void
{
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.__p6 && !!window.Livewire && !!window.Alpine', 15_000);
}

/** Waits until the page's socket is connected and on the `online` presence channel. */
function navigateOnline(Page $page): void
{
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected" && window.Echo.connector.pusher.channel("presence-online")?.subscribed === true', 15_000);
}

/** Clicks a link like a person (hover, a moment, click) and waits until the target page has painted. */
function navigateClick(Page $page, string $selector, string $path, int $pressMs = 0): void
{
    if (str_contains($selector, 'hub-all-games')) {
        $page->locator('[data-test=games-menu]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=hub-all-games]")?.checkVisibility() === true', 5_000);
    }
    $count = $page->evaluate('() => JSON.parse(sessionStorage.getItem("__p6Results") || "[]").length');
    $page->locator($selector)->first()->hover();
    Execution::instance()->wait(0.15);
    $page->locator($selector)->first()->click($pressMs > 0 ? ['delay' => $pressMs] : null);
    BrowserWait::until($page, '() => location.pathname === '.json_encode($path).' && JSON.parse(sessionStorage.getItem("__p6Results") || "[]").length > '.$count, 15_000);
    navigateReady($page);
}

/** The listener, callback, interval, Alpine and Livewire counts that must not grow, from a snapshot. */
function navigateCounts(array $snapshot): array
{
    return [
        'document' => $snapshot['listeners']['document'],
        'window' => $snapshot['listeners']['window'],
        'intervals' => $snapshot['intervals'],
        'alpineLive' => $snapshot['alpineLive'],
        'livewire' => $snapshot['livewire'],
        'livewireDetached' => $snapshot['livewireDetached'],
        'pusherChannels' => $snapshot['pusherChannels'],
        'connectionCallbacks' => $snapshot['connectionCallbacks'],
        'presenceListeners' => $snapshot['presenceListeners'],
    ];
}

/**
 * Walks $round (selector => path, ending where it started) $rounds times and snapshots the start page after
 * each round. Errors and Livewire statuses are collected along the way.
 *
 * @param  array<int, array{0: string, 1: string}>  $round
 * @return array{snapshots: list<array<string, mixed>>, timings: list<array<string, mixed>>, errors: list<string>, statuses: list<int>}
 */
function navigateWalk(Page $page, array $round, int $rounds, bool $online, int $pressMs = 0): array
{
    $snapshots = [];
    for ($i = 1; $i <= $rounds; $i++) {
        foreach ($round as [$selector, $path]) {
            navigateClick($page, $selector, $path, $pressMs);
            if ($online) {
                navigateOnline($page);
            }
        }
        Execution::instance()->wait(0.5);
        $snapshots[] = $page->evaluate('() => window.__p6.snapshot()');
    }

    return [
        'snapshots' => $snapshots,
        'timings' => $page->evaluate('() => JSON.parse(sessionStorage.getItem("__p6Results") || "[]")'),
        'errors' => $page->evaluate('() => window.__errors'),
        'statuses' => $page->evaluate('() => window.__p6.statuses'),
    ];
}

/** Expects the console collector to see a thrown error (the positive control) and nothing else before it. */
function navigateConsoleClean(Page $page, array $errorsBefore): void
{
    expect($errorsBefore)->toBe([]);
    $page->evaluate('() => setTimeout(() => { throw new Error("p6 positive control"); }, 0)');
    Execution::instance()->wait(0.2);
    expect($page->evaluate('() => window.__errors'))->toContain('error: Uncaught Error: p6 positive control')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
}

/** Round of the desktop walk: five shell pages by header links, back home. */
const NAVIGATE_DESKTOP_ROUND = [
    ['[data-test=hub-all-games]', '/play'],
    ['[data-test=nav-tournaments]', '/tournaments'],
    ['[data-test=nav-clans]', '/clans'],
    ['[data-test=nav-mempool]', '/matches'],
    ['[data-test=shell-home]', '/'],
];

test('ten navigations across the shell leave nothing behind: listeners, callbacks, intervals, components, presence, console', function () {
    $player = User::factory()->member()->create(['name' => 'Walker']);
    $watcher = User::factory()->member()->create(['name' => 'Watcher']);
    $game = ChessGame::factory()->daily()->create(['white_id' => $player->id]);

    $observer = navigatePage($watcher, '/rules', 1280, 800);
    navigateOnline($observer);
    $observer->evaluate('() => { window.__members = { added: 0, removed: 0 }; const channel = window.Echo.connector.pusher.channel("presence-online"); channel.bind("pusher:member_added", (m) => { if (String(m.id) === '.json_encode((string) $player->id).') window.__members.added++; }); channel.bind("pusher:member_removed", (m) => { if (String(m.id) === '.json_encode((string) $player->id).') window.__members.removed++; }); }');

    $result = Playwright::usingTimeout(60_000, function () use ($player, $watcher, $game, $observer): array {
        $page = navigatePage($player, '/');
        navigateOnline($page);
        expect($page->evaluate('() => document.documentElement.hasAttribute("data-navigate-page") && document.querySelector("[data-test=shell-home]").hasAttribute("wire:navigate")'))->toBeTrue();
        $page->evaluate('() => { window.__kept = true; document.addEventListener("livewire:navigated", () => document.addEventListener("p6-control", () => {})); }');
        $socket = $page->evaluate('() => window.Echo.connector.pusher.connection.socket_id');

        $walk = navigateWalk($page, NAVIGATE_DESKTOP_ROUND, 2, true);
        $walk['kept'] = $page->evaluate('() => window.__kept === true');
        $walk['sameSocket'] = $page->evaluate('() => window.Echo.connector.pusher.connection.socket_id') === $socket;

        // A Livewire roundtrip on the last page, then a move in the dock's game: one refresh, not one per click.
        $page->evaluate('() => Livewire.getByName("match-dock")[0]?.$refresh()');
        Execution::instance()->wait(1.0);
        $page->evaluate('() => { window.__pushes = 0; window.__dockRequests = 0; window.Echo.connector.pusher.connection.bind("message", (m) => { if (m.event === "game.updated" || m.event === ".game.updated" || String(m.event).endsWith("game.updated")) window.__pushes++; }); Livewire.interceptRequest(({ request, onSend }) => onSend(() => { window.__dockRequests += [...request.messages].filter((m) => m.component.name === "match-dock").length; })); }');
        event(new ChessGameUpdated($game->id, ['ply' => 1]));
        BrowserWait::until($page, '() => window.__pushes >= 1', 10_000);
        Execution::instance()->wait(1.5);
        $walk['dockRequests'] = $page->evaluate('() => window.__dockRequests');
        $walk['gameUpdatedCallbacks'] = $page->evaluate('() => (window.Echo.connector.pusher.channel("game.'.$game->id.'.watch")?.callbacks?._callbacks?.["_game.updated"] ?? window.Echo.connector.pusher.channel("game.'.$game->id.'.watch")?.callbacks?._callbacks?.["_.game.updated"] ?? []).length');

        // A notification on the player's channel still reaches the shell once (playerEvents.js).
        $page->evaluate('() => { window.__p6.statuses = []; }');
        event(new UserNotified($player->id, ['id' => 'p6b-push', 'kind' => 'invite', 'title' => 'P6b', 'body' => 'P6b', 'url' => route('home'), 'match' => null, 'action' => null, 'sound' => 'none', 'tone' => 'confirmed', 'redirect' => false]));
        Execution::instance()->wait(1.5);
        $walk['statuses'] = [...$walk['statuses'], ...$page->evaluate('() => window.__p6.statuses')];

        // Hovering a player name fetches the card once, not once per page passed.
        $link = sprintf('<a href="%s" data-player-card="%s" data-pubkey="%s" data-test="p6-card">Watcher</a>', route('players.show', $watcher->npub, absolute: false), $watcher->npub, $watcher->pubkey);
        $page->evaluate('() => { window.__p6.cardFetches = 0; document.querySelector("main").insertAdjacentHTML("afterbegin", '.json_encode($link).'); }');
        $page->evaluate('() => document.querySelector("[data-test=p6-card]").dispatchEvent(new MouseEvent("mouseover", { bubbles: true }))');
        Execution::instance()->wait(1.5);
        $walk['cards'] = $page->evaluate('() => window.__p6.cardFetches');

        // "/" still opens the search.
        $page->locator('body')->press('/');
        Execution::instance()->wait(0.3);
        $walk['search'] = $page->evaluate('() => document.activeElement?.closest("#mobile-search") !== null || document.activeElement?.type === "search"');
        $page->locator('body')->press('Escape');

        navigateConsoleClean($page, $walk['errors']);
        $walk['control'] = $page->evaluate('() => window.__p6.snapshot().listeners.documentByType["p6-control"] ?? 0');
        Execution::instance()->wait(2.0);
        $walk['members'] = $observer->evaluate('() => window.__members');
        $page->close();

        return $walk;
    });

    if (is_string($file = getenv('NAVIGATE_WALK_REPORT')) && $file !== '') {
        file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    [$afterFive, $afterTen] = $result['snapshots'];
    expect($result['timings'])->toHaveCount(10)
        ->and(array_filter($result['timings'], fn (array $t): bool => $t['fullLoad']))->toBe([])
        ->and($result['kept'])->toBeTrue()
        ->and($result['sameSocket'])->toBeTrue()
        // The positive control grows by one per navigation, so the count can see growth.
        ->and($result['control'])->toBe(10)
        // Flat: after ten navigations the same as after five, the planted control aside.
        ->and(navigateCounts($afterTen))->toEqual(array_merge(navigateCounts($afterFive), [
            'document' => $afterFive['listeners']['document'] + 5,
        ]))
        ->and($afterTen['livewireDetached'])->toBe(0)
        ->and($result['gameUpdatedCallbacks'])->toBe(1)
        ->and($result['dockRequests'])->toBe(1)
        ->and($result['cards'])->toBe(1)
        ->and($result['search'])->toBeTrue()
        ->and(array_filter($result['statuses'], fn (int $status): bool => $status < 200 || $status >= 300))->toBe([])
        // The first load's join only, no leave/join per click.
        ->and($result['members'])->toBe(['added' => 1, 'removed' => 0]);
});

test('the settings tabs start the push toggle after a navigate, and still navigate after a Livewire roundtrip', function () {
    $player = User::factory()->member()->create(['name' => 'Tabber']);

    Playwright::usingTimeout(30_000, function () use ($player): void {
        $page = navigatePage($player, route('settings.account', absolute: false));
        $page->evaluate('() => { window.__kept = true; }');

        navigateClick($page, '[data-test=settings-notifications-tab]', '/settings/notifications');
        BrowserWait::until($page, '() => !!document.querySelector("[x-data^=pushToggle]")?._x_dataStack', 5_000);
        expect($page->evaluate('() => window.__kept === true'))->toBeTrue()
            ->and($page->evaluate('() => window.__p6.snapshot().alpineLive.pushToggle ?? 0'))->toBe(1);

        // A roundtrip of the page component: the tabs keep wire:navigate (the server decides it from the page, not the update).
        $page->evaluate('() => window.__p6.statuses = []');
        $page->evaluate('() => Livewire.all().find((c) => c.el.contains(document.querySelector("[data-test=settings-chess-tab]")))?.$wire.$refresh()');
        BrowserWait::until($page, '() => window.__p6.statuses.length > 0', 5_000);
        expect($page->evaluate('() => document.querySelector("[data-test=settings-chess-tab]").hasAttribute("wire:navigate")'))->toBeTrue();

        navigateClick($page, '[data-test=settings-chess-tab]', '/settings/chess');
        navigateClick($page, '[data-test=settings-notifications-tab]', '/settings/notifications');
        BrowserWait::until($page, '() => !!document.querySelector("[x-data^=pushToggle]")?._x_dataStack', 5_000);
        expect($page->evaluate('() => window.__kept === true'))->toBeTrue()
            ->and($page->evaluate('() => window.__p6.snapshot().alpineLive.pushToggle ?? 0'))->toBe(1)
            ->and($page->evaluate('() => window.__p6.statuses.every((s) => s >= 200 && s < 300)'))->toBeTrue();
        navigateConsoleClean($page, $page->evaluate('() => window.__errors'));
        $page->close();
    });
});

test('into and out of a chess game the page loads in full, and its presence sees one join and one leave', function () {
    $player = User::factory()->member()->create(['name' => 'White']);
    $opponent = User::factory()->member()->create(['name' => 'Black']);
    $game = ChessGame::factory()->create(['white_id' => $player->id, 'black_id' => $opponent->id]);
    $gamePath = route('games.show', $game, absolute: false);

    Playwright::usingTimeout(60_000, function () use ($player, $opponent, $game, $gamePath): void {
        $observer = navigatePage($opponent, $gamePath, 1280, 800);
        BrowserWait::until($observer, '() => window.Echo?.connector?.pusher?.channel("presence-game.'.$game->id.'.players")?.subscribed === true', 15_000);
        $observer->evaluate('() => { window.__players = { added: 0, removed: 0 }; const channel = window.Echo.connector.pusher.channel("presence-game.'.$game->id.'.players"); channel.bind("pusher:member_added", () => window.__players.added++); channel.bind("pusher:member_removed", () => window.__players.removed++); }');

        $page = navigatePage($player, '/');
        navigateOnline($page);
        $page->evaluate('() => { window.__kept = true; }');

        // Into the game by a link: the link has no wire:navigate, the page loads in full.
        $page->evaluate('() => document.querySelector("main").insertAdjacentHTML("afterbegin", '.json_encode('<a href="'.$gamePath.'" data-test="p6-game">game</a>').')');
        expect($page->evaluate('() => document.querySelector("[data-test=p6-game]").hasAttribute("wire:navigate")'))->toBeFalse();
        navigateClick($page, '[data-test=p6-game]', $gamePath);
        BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.channel("presence-game.'.$game->id.'.players")?.subscribed === true', 15_000);
        expect($page->evaluate('() => window.__kept === true'))->toBeFalse()
            ->and($page->evaluate('() => document.documentElement.hasAttribute("data-navigate-page")'))->toBeFalse()
            // A full-load page links in full: no header link navigates.
            ->and($page->evaluate('() => document.querySelectorAll("[wire\\\\:navigate]").length'))->toBe(0);
        BrowserWait::until($observer, '() => window.__players.added === 1', 10_000);

        // Out by the logo: a full load, the game's presence is left once.
        $page->evaluate('() => { window.__kept = true; }');
        navigateClick($page, '[data-test=shell-home]', '/');
        expect($page->evaluate('() => window.__kept === true'))->toBeFalse();
        BrowserWait::until($observer, '() => window.__players.removed === 1', 15_000);

        // Something asks for a navigate into the game anyway (a stale link, a redirect): the guard loads it in full
        // before its components start, so the opponent sees one join, not a swap's join that is then left.
        $page->evaluate('() => { window.__kept = true; sessionStorage.removeItem("__p6Inits"); }');
        $page->evaluate('() => Livewire.navigate('.json_encode($gamePath).')');
        BrowserWait::until($page, '() => location.pathname === '.json_encode($gamePath).' && window.__kept !== true && document.readyState === "complete"', 15_000);
        navigateReady($page);
        BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.channel("presence-game.'.$game->id.'.players")?.subscribed === true', 15_000);
        Execution::instance()->wait(1.0);
        expect($observer->evaluate('() => window.__players'))->toBe(['added' => 2, 'removed' => 1])
            // The swapped-in board never started; only the reloaded one did.
            ->and($page->evaluate('() => JSON.parse(sessionStorage.getItem("__p6Inits") || "{}").chessGame ?? 0'))->toBe(1);

        // And out of it by a navigate: the guard turns it into a full load.
        $page->evaluate('() => { window.__kept = true; }');
        $page->evaluate('() => Livewire.navigate("/")');
        BrowserWait::until($page, '() => location.pathname === "/" && window.__kept !== true && document.readyState === "complete"', 15_000);
        navigateReady($page);
        BrowserWait::until($observer, '() => window.__players.removed === 2', 15_000);
        expect($observer->evaluate('() => window.__players'))->toBe(['added' => 2, 'removed' => 2]);

        navigateConsoleClean($page, $page->evaluate('() => window.__errors'));
        expect($observer->evaluate('() => window.__errors'))->toBe([]);
        $page->close();
        $observer->close();
    });
});

test('at 390 px over the tab bar, and for a guest, the walk leaves nothing behind either', function () {
    $player = User::factory()->member()->create(['name' => 'Phone']);

    $results = Playwright::usingTimeout(60_000, function () use ($player): array {
        $results = [];

        // The chess tab bar: Matches and Ladder navigate, Tournaments too; the logo goes home.
        $page = navigatePage($player, '/', 390, 844);
        navigateOnline($page);
        $page->evaluate('() => { window.__kept = true; }');
        $results['phone'] = navigateWalk($page, [
            ['[data-test=tab-matches]', '/matches'],
            ['[data-test=tab-ladder]', '/ladder/chess/blitz'],
            ['[data-test=tab-tournaments]', '/tournaments'],
            ['[data-test=shell-home]', '/'],
        ], 3, true);
        $results['phone']['kept'] = $page->evaluate('() => window.__kept === true');
        $results['phone']['widths'] = $page->evaluate(BrowserConsole::WIDTHS);
        navigateConsoleClean($page, $results['phone']['errors']);
        $page->close();

        // A guest: no Echo on these pages, the same walk over the header at 1440 px.
        auth()->logout();
        $guest = navigatePage(null, '/');
        $guest->evaluate('() => { window.__kept = true; }');
        $results['guest'] = navigateWalk($guest, [
            ['[data-test=hub-all-games]', '/play'],
            ['[data-test=nav-tournaments]', '/tournaments'],
            ['[data-test=shell-home]', '/'],
        ], 3, false);
        $results['guest']['kept'] = $guest->evaluate('() => window.__kept === true');
        navigateConsoleClean($guest, $results['guest']['errors']);
        $guest->close();

        return $results;
    });

    if (is_string($file = getenv('NAVIGATE_PHONE_REPORT')) && $file !== '') {
        file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    foreach ($results as $who => $result) {
        [, $second, $third] = $result['snapshots'];
        expect($result['kept'])->toBeTrue($who.': one window for the whole walk')
            ->and(array_filter($result['timings'], fn (array $t): bool => $t['fullLoad']))->toBe([], $who.': no full load')
            ->and(navigateCounts($third))->toEqual(navigateCounts($second), $who.': flat from round two to three')
            ->and(array_filter($result['statuses'], fn (int $status): bool => $status < 200 || $status >= 300))->toBe([]);
    }
    expect($results['phone']['widths'][0])->toBeLessThanOrEqual($results['phone']['widths'][1]);
});

/*
| The measurement for the plan: the desktop walk once with the switch off (every click a full load) and once on,
| click to paint per click, with an instant click and with the button held 90 ms (wire:navigate starts fetching on
| the press, a plain link on the release; 90 ms is an assumed press, not a measured one). Not a guard; runs only
| with NAVIGATE_SPIKE_REPORT=<file>.
*/
test('measurement: click to paint on the shell walk, full load against navigate', function () {
    $player = User::factory()->member()->create(['name' => 'Walker']);
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);

    $results = Playwright::usingTimeout(60_000, function () use ($player): array {
        $results = [];
        foreach ([0, 90] as $pressMs) {
            foreach (['full load' => false, 'navigate' => true] as $mode => $navigate) {
                config(['esports.navigate' => $navigate]);
                $page = navigatePage($player, '/');
                navigateOnline($page);
                $page->evaluate('() => sessionStorage.removeItem("__p6Results")');
                $walk = navigateWalk($page, NAVIGATE_DESKTOP_ROUND, 3, true, $pressMs);
                $results[$mode.' / press '.$pressMs.' ms'] = ['timings' => $walk['timings'], 'last' => end($walk['snapshots'])];
                $page->close();
            }
        }

        return $results;
    });

    file_put_contents((string) getenv('NAVIGATE_SPIKE_REPORT'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    expect($results['navigate / press 0 ms']['timings'])->toHaveCount(15);
})->skip(! is_string(getenv('NAVIGATE_SPIKE_REPORT')) || getenv('NAVIGATE_SPIKE_REPORT') === '', 'a measurement: set NAVIGATE_SPIKE_REPORT=<file>');

<?php

use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
use App\Events\SeriesMatchChanged;
use App\Events\TournamentChanged;
use App\Events\UserNotified;
use App\Models\ChessGame;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Livewire traffic of the hot pages over one simulated minute (performance plan P1c)
|--------------------------------------------------------------------------
|
| Counts the Livewire roundtrips and their bytes that an open page causes by
| itself: the tournament page (running), the match room, home and /live. The
| number is the baseline P3 (fewer roundtrips) has to lower, and the upper
| bound each page may not pass again.
|
| METHOD. Pest's browser has no network listener, so the page counts for
| itself: an init script registers `Livewire.interceptRequest` (on
| `livewire:init`) and records, per roundtrip, the components and actions it
| carries, the request body, the response body in bytes (uncompressed: nginx
| gzips them in production) and the status. Waiting 60 s per page for real
| would cost four minutes, so the same init script also replaces the page's
| clock: setTimeout/setInterval with a delay of 100 ms or more, Date and
| performance.now run on a VIRTUAL clock that only moves when the test calls
| `__traffic.advance(ms)`. That fires the due timers in order, and after each
| instant waits until the Livewire requests it started are answered, so a
| poll, a debounce and the room's 8-second sync happen exactly as often as
| in a real minute and the run takes seconds. Shorter timers (animation
| frames, 0 ms yields) stay real. The page loads, 3 s of virtual time let
| its start-up settle (counted as "load"), then 60 s are measured ("window").
|
| What it does not see: server pushes (no event is broadcast here, so Echo is
| connected and silent: the idle cost), time-dependent server state (the
| server's clock is frozen at the start and the page's calendar begins on that
| instant, so the countdowns the dock waits for end at the same virtual second
| on every run), and requests that are not Livewire's: those are counted by
| path next to it ("other"), without bytes.
|
| Every page: the console stays empty (collector and the plugin's list), every
| answer is 2xx, and a thrown error, a 404 fetch and a failing Livewire call
| are the positive controls that prove the collectors listen.
|
| LIVEWIRE_TRAFFIC_REPORT=<file> writes the table there (markdown). The numbers
| of 2026-10-04 are in docs/plans/2026-10-04T1715-performance/p1-ist.md.
|
*/

/** Window of the measurement and the settle time after load, in virtual milliseconds. */
const TRAFFIC_WINDOW_MS = 60_000;
const TRAFFIC_LOAD_MS = 3_000;

const TRAFFIC_PROBE = <<<'JS'
    (() => {
        const MIN = 100;
        const realSetTimeout = window.setTimeout.bind(window);
        const realClearTimeout = window.clearTimeout.bind(window);
        const realSetInterval = window.setInterval.bind(window);
        const realClearInterval = window.clearInterval.bind(window);
        const RealDate = Date;
        // The page's calendar starts at the instant the server's clock is frozen on (the test sets window.__trafficBase): the countdowns
        // the dock counts down to (a check-in, a series start) then end after the same virtual seconds in every run, whatever the real time
        // between the fixture and the page's first timer was.
        const base = typeof window.__trafficBase === 'number' ? window.__trafficBase : null;
        const realPerformanceNow = performance.now.bind(performance);
        const queue = new Map();
        let virtualNow = 0;
        let nextId = 1_000_000_000;
        let sequence = 0;

        // The virtual clock: timers of MIN ms or more wait for advance().
        const schedule = (fn, delay, args, every) => {
            const id = nextId++;
            queue.set(id, { id, fn, args, at: virtualNow + delay, every, order: sequence++ });
            return id;
        };
        window.setTimeout = (fn, delay = 0, ...args) => (typeof fn === 'function' && Number(delay) >= MIN ? schedule(fn, Number(delay), args, 0) : realSetTimeout(fn, delay, ...args));
        window.setInterval = (fn, delay = 0, ...args) => (typeof fn === 'function' && Number(delay) >= MIN ? schedule(fn, Number(delay), args, Number(delay)) : realSetInterval(fn, delay, ...args));
        window.clearTimeout = (id) => (queue.has(id) ? queue.delete(id) : realClearTimeout(id));
        window.clearInterval = (id) => (queue.has(id) ? queue.delete(id) : realClearInterval(id));
        window.Date = class extends RealDate {
            constructor(...args) { args.length ? super(...args) : super((base ?? RealDate.now()) + virtualNow); }
            static now() { return (base ?? RealDate.now()) + virtualNow; }
        };
        performance.now = () => realPerformanceNow() + virtualNow;

        const sleep = (ms) => new Promise((resolve) => realSetTimeout(resolve, ms));
        const roundtrips = [];
        const open = new Set();
        const other = {};
        const bytes = (text) => new TextEncoder().encode(typeof text === 'string' ? text : '').length;

        const originalFetch = window.fetch;
        window.fetch = (...args) => {
            const url = new URL(String(args[0]?.url ?? args[0]), location.href);
            if (! url.pathname.includes('livewire')) other[url.pathname] = (other[url.pathname] ?? 0) + 1;
            return originalFetch(...args);
        };

        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ request, onSend, onResponse, onSuccess, onError, onFailure, onFinish }) => {
                const record = { at: virtualNow, what: '', sent: 0, received: 0, status: null };
                open.add(record);
                onSend(() => {
                    record.sent = bytes(request.options?.body);
                    record.what = [...request.messages].map((message) => message.component.name + ':' + ([...message.actions].map((action) => action.name).join('+') || 'render')).join(',');
                });
                onResponse(({ response }) => { record.status = response.status; });
                onSuccess(({ body }) => { record.received = bytes(body); });
                onError(({ body }) => { record.received = bytes(body); });
                onFailure(() => { record.status = record.status ?? 0; open.delete(record); });
                onFinish(() => open.delete(record));
                roundtrips.push(record);
            });
        });

        // The requests this instant started are answered before the next timer fires.
        const settle = async () => {
            await sleep(12);
            for (let waited = 0; open.size > 0 && waited < 5000; waited += 10) await sleep(10);
            await sleep(4);
        };

        window.__traffic = {
            async advance(ms) {
                const target = virtualNow + ms;
                for (;;) {
                    let next = null;
                    for (const timer of queue.values()) {
                        if (timer.at <= target && (next === null || timer.at < next.at || (timer.at === next.at && timer.order < next.order))) next = timer;
                    }
                    if (next === null) break;
                    virtualNow = Math.max(virtualNow, next.at);
                    if (next.every > 0) { next.at += next.every; next.order = sequence++; } else queue.delete(next.id);
                    try { next.fn(...next.args); } catch (error) { realSetTimeout(() => { throw error; }, 0); }
                    // Timers due at the same instant fire before the settle.
                    if (! [...queue.values()].some((timer) => timer.at === virtualNow)) await settle();
                }
                virtualNow = target;
                await settle();
            },
            mark() { return roundtrips.length; },
            since(mark) {
                const seen = roundtrips.slice(mark);
                const labels = {};
                seen.forEach((r) => { labels[r.what] = (labels[r.what] ?? 0) + 1; });
                return {
                    requests: seen.length,
                    sent: seen.reduce((sum, r) => sum + r.sent, 0),
                    received: seen.reduce((sum, r) => sum + r.received, 0),
                    statuses: seen.map((r) => r.status),
                    // When each one started, in virtual seconds since the page's clock began: what moved it shows in the spacing.
                    at: seen.map((r) => Math.round(r.at / 100) / 10),
                    labels,
                    // When each roundtrip left, in virtual ms since load: tells a poll from a one-off.
                    at: seen.map((r) => r.at),
                    other: { ...other },
                };
            },
            resetOther() { Object.keys(other).forEach((key) => delete other[key]); },
            pending: () => open.size,
            hasLivewire: () => typeof window.Livewire !== 'undefined' && typeof window.Alpine !== 'undefined',
        };
    })();
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();

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
 * The fixed "active player" of the query budget (tests/Feature/PageQueryBudgetTest.php: budgetWorld) in the form
 * these pages need: a seat of a lineup with a running series, a chess game, a signup and a clan invite, and a
 * running tournament.
 *
 * @return array{player: User, series: SeriesMatch, tournament: Tournament}
 */
function trafficWorld(): array
{
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    openSeason();

    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $rival = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->seats()->where('user_id', '!=', $lineup->clan->owner_id)->firstOrFail()->user;

    ChessGame::factory()->create(['white_id' => $player->id]);
    $series = SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => $rival->id]);
    TournamentSignup::query()->create([
        'tournament_id' => openTournament(['name' => 'Halving Cup', 'starts_at' => now()->addDays(3)], rocketLeague: true)->id,
        'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id],
    ]);
    ClanInvite::query()->create(['clan_id' => $rival->clan_id, 'inviter_id' => $rival->clan->owner_id, 'invitee_id' => $player->id, 'status' => InviteStatus::Pending]);

    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['draw_height' => 915000, 'draw_hash' => str_repeat('ab', 32), 'published_at' => now()->subDay()])->save();

    return ['player' => $player, 'series' => $series, 'tournament' => $tournament];
}

/**
 * Opens the page, lets it start up on the virtual clock, then measures one window.
 *
 * @return array{echo: bool, load: array<string, mixed>, window: array<string, mixed>, errors: list<string>}
 */
function trafficMeasure(Page $page, string $url): array
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.__traffic?.hasLivewire() === true', 15_000);

    // Echo is its own script and connects over a real websocket, outside the virtual clock. The window starts with it
    // connected: the idle state this test says it measures (no event is broadcast, the socket is up and silent).
    $echo = $page->evaluate('() => document.querySelector("meta[name=reverb]") !== null && document.querySelector("script[src*=echo-]") !== null');

    if ($echo) {
        BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"', 15_000);
    }

    return Playwright::usingTimeout(120_000, function () use ($page, $echo): array {
        $page->evaluate('() => window.__traffic.resetOther()');
        $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', TRAFFIC_LOAD_MS);
        $load = $page->evaluate('() => window.__traffic.since(0)');
        $mark = $page->evaluate('() => window.__traffic.mark()');
        $page->evaluate('() => window.__traffic.resetOther()');
        $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', TRAFFIC_WINDOW_MS);

        return [
            'echo' => $echo,
            'load' => $load,
            'window' => $page->evaluate('(mark) => window.__traffic.since(mark)', $mark),
            'errors' => [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)],
        ];
    });
}

/*
| The numbers after P3 (2026-10-04) as the upper bound per page: [requests in the window, request + response bytes].
| P1 measured 4 / 6 / 9 / 2 / 2 / 5 requests (tournament guest and player, room, home, live guest and player);
| P3 took out every poll that ran next to a connected socket. What is left are the dock's renders when a number it
| counts down (data-tick) reaches zero, 0.25 s after it (matchDock.js requestRefresh), not a poll; their number was
| 2 or 3 between runs on home (and 3 or 4 on /live before P3); the budget takes the larger one.
| The bytes carry 10 % on top of the largest measured size, rounded up to 1 000, because tokens and timestamps in
| the snapshots change by a few dozen bytes per run.
*/
const TRAFFIC_BUDGETS = [
    'tournament (guest)' => [0, 0],
    'tournament (player)' => [2, 57_000],
    'room (player)' => [2, 48_000],
    'home (player)' => [3, 84_000],
    'live (guest)' => [0, 0],
    'live (player)' => [3, 85_000],
];

test('the hot pages cost this many Livewire roundtrips and bytes in a minute, with a clean console and 2xx answers', function () {
    // The server's clock stands still from here on, the page's calendar starts on the same instant (TRAFFIC_PROBE): what the dock counts down
    // to is then the same set of moments on every run. With the real clock the count of its refreshes moved by one with the time the
    // fixture and each page's start-up took (2026-10-04: 2 and 3 for the same code).
    $this->freezeSecond();
    $frozenAt = now()->getTimestampMs();
    $world = trafficWorld();
    $cases = [
        'tournament (guest)' => [null, route('tournaments.show', $world['tournament'])],
        'tournament (player)' => [$world['player'], route('tournaments.show', $world['tournament'])],
        'room (player)' => [$world['player'], route('matches.room', $world['series'])],
        'home (player)' => [$world['player'], route('home')],
        'live (guest)' => [null, route('live')],
        'live (player)' => [$world['player'], route('live')],
    ];

    expect(array_keys($cases))->toBe(array_keys(TRAFFIC_BUDGETS));

    $measured = [];
    $problems = [];
    $over = [];
    $last = null;

    foreach ($cases as $name => [$viewer, $url]) {
        $webpage = visit($viewer === null ? BrowserLogin::LANDING : BrowserLogin::url($viewer));
        $page = $webpage->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->context()->addInitScript('window.__trafficBase = '.$frozenAt.';');
        $page->context()->addInitScript(TRAFFIC_PROBE);
        $page->setViewportSize(1440, 900);

        $run = trafficMeasure($page, $url);
        $bytes = $run['window']['sent'] + $run['window']['received'];
        $measured[$name] = [
            'window' => $run['window']['requests'], 'sent' => $run['window']['sent'], 'received' => $run['window']['received'],
            'load' => $run['load']['requests'], 'labels' => $run['window']['labels'], 'at' => $run['window']['at'], 'other' => $run['window']['other'], 'echo' => $run['echo'],
        ];
        $last = [$webpage, $page];

        foreach ([...$run['load']['statuses'], ...$run['window']['statuses']] as $status) {
            if (! is_int($status) || $status < 200 || $status > 299) {
                $problems[] = "{$name}: a Livewire answer with status ".json_encode($status);
            }
        }

        foreach ($run['errors'] as $error) {
            $problems[] = "{$name}: {$error}";
        }

        $webpage->assertNoJavaScriptErrors();

        [$maxRequests, $maxBytes] = TRAFFIC_BUDGETS[$name];

        if ($run['window']['requests'] > $maxRequests || $bytes > $maxBytes) {
            $over[] = "{$name}: {$run['window']['requests']} requests / {$bytes} bytes, budget {$maxRequests} / {$maxBytes}";
        }
    }

    fwrite(STDERR, "\n[livewire-traffic] ".json_encode($measured)."\n");

    if (is_string($report = getenv('LIVEWIRE_TRAFFIC_REPORT')) && $report !== '') {
        $rows = ['| Page | Requests in 60 s | Sent B | Received B | Requests at load (3 s) | What (60 s) | Other fetches (60 s) |', '|---|---:|---:|---:|---:|---|---|'];

        foreach ($measured as $name => $m) {
            $what = implode(', ', array_map(fn (string $label, int $n): string => "{$n}x {$label}", array_keys($m['labels']), $m['labels']));
            $other = implode(', ', array_map(fn (string $path, int $n): string => "{$n}x {$path}", array_keys($m['other']), $m['other']));
            $rows[] = "| {$name} | {$m['window']} | {$m['sent']} | {$m['received']} | {$m['load']} | {$what} | {$other} |";
        }

        file_put_contents($report, implode("\n", $rows)."\n");
    }

    // Since P3 a page with a live socket and nothing pushed is quiet: the traffic that remains is the dock's renders
    // at a deadline it counts down to (data-tick), not a poll. The player pages ran with a connected socket (the wait
    // above), so their quiet is the socket's doing; that the room and the tournament page still ask when they have to
    // (a push, no socket) is the next test's job.
    expect($measured['room (player)']['echo'])->toBeTrue()
        ->and($measured['home (player)']['echo'])->toBeTrue()
        ->and($measured['live (player)']['echo'])->toBeTrue()
        ->and($problems)->toBe([])
        ->and($over)->toBe([]);

    // Positive controls on the last page. The count follows a timer the test adds: a 10-second poll of its own
    // brings at least two more roundtrips into the next 30 virtual seconds than the page made in the 30 before
    // (three in all, one less for the edge of the window).
    [, $page] = $last;
    $advance = fn (int $ms) => Playwright::usingTimeout(60_000, fn () => $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', $ms));
    $mark = $page->evaluate('() => window.__traffic.mark()');
    $advance(30_000);
    $quiet = $page->evaluate('(mark) => window.__traffic.since(mark).requests', $mark);
    $page->evaluate('() => { setInterval(() => Livewire.first().$refresh(), 10_000); }');
    $mark = $page->evaluate('() => window.__traffic.mark()');
    $advance(30_000);
    $busy = $page->evaluate('(mark) => window.__traffic.since(mark).requests', $mark);

    expect($busy - $quiet)->toBeGreaterThanOrEqual(2);

    // A thrown error, a 404 fetch and a failing Livewire call (not 2xx) all reach their collectors.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); fetch("/__traffic-missing").catch(() => null); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.includes("404"))', 10_000);
    $mark = $page->evaluate('() => window.__traffic.mark()');
    $page->evaluate('() => { Livewire.first().trafficMissingMethod().catch(() => null); }');
    BrowserWait::until($page, "() => window.__traffic.since({$mark}).requests >= 1 && window.__traffic.pending() === 0", 10_000);
    $statuses = $page->evaluate('(mark) => window.__traffic.since(mark).statuses', $mark);

    expect(implode("\n", array_column($page->javaScriptErrors(), 'message')))->toContain('positive control')
        ->and($statuses)->not->toBe([])
        ->and(array_filter($statuses, fn ($status): bool => $status >= 200 && $status <= 299))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The other side of P3: the pages still ask when they have to
|--------------------------------------------------------------------------
|
| A quiet minute (above) proves nothing on its own: a page that never asks
| would pass it too. So, on the same virtual clock: a push makes exactly one
| render (the shell's three components in ONE request), the socket going
| away brings the fallback poll back (room every 30 s, tournament page every
| 15 s), a hidden tab asks nothing and once when it comes back, and /live's
| poll carries only its programme island once that is on screen.
*/

/** The Livewire requests of a page on the real clock, by the components and actions each carries. */
const REQUEST_COUNTER = <<<'JS'
    (() => {
        window.__requests = [];
        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ request, onSend }) => {
                onSend(() => window.__requests.push([...request.messages].map((m) => m.component.name + ':' + ([...m.actions].map((a) => a.name).join('+') || 'render')).join(',')));
            });
        });
    })();
    JS;

/** Waits until the page's socket has subscribed `$channel`, then counts the pushes it hears from there on. */
function trafficSubscribed(Page $page, string $channel): void
{
    BrowserWait::until($page, '() => window.Echo?.connector.pusher.connection.state === "connected" && window.Echo.connector.pusher.channel('.json_encode($channel).')?.subscribed === true', 10_000);
    $page->evaluate('() => { window.__pushes = 0; if (! window.__pushCounter) { window.__pushCounter = true; window.Echo.connector.pusher.connection.bind("message", (m) => { if (m.event && ! m.event.startsWith("pusher")) window.__pushes++; }); } }');
}

/** Lets `$ms` virtual milliseconds pass and returns what Livewire sent meanwhile. */
function trafficWindow(Page $page, int $ms): array
{
    $mark = $page->evaluate('() => window.__traffic.mark()');
    Playwright::usingTimeout(120_000, fn () => $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', $ms));

    return $page->evaluate('(mark) => window.__traffic.since(mark)', $mark);
}

/** Roundtrips whose label names `$needle` (a component:action). */
function trafficCount(array $window, string $needle): int
{
    return array_sum(array_filter($window['labels'], fn (int $n, string $label): bool => str_contains($label, $needle), ARRAY_FILTER_USE_BOTH));
}

test('a push renders once, the fallback poll comes back without a socket, a hidden tab asks nothing, and /live polls only its island', function () {
    $world = trafficWorld();
    $player = $world['player'];
    $series = $world['series'];
    $tournament = $world['tournament'];
    $seen = [];

    $open = function (?User $viewer, string $url): Page {
        $webpage = visit($viewer === null ? BrowserLogin::LANDING : BrowserLogin::url($viewer));
        $page = $webpage->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->context()->addInitScript(TRAFFIC_PROBE);
        $page->setViewportSize(1440, 900);
        trafficMeasure($page, $url);

        return $page;
    };

    // The room: a series push makes one request that carries the room's sync (and the dock's and badge's renders).
    $room = $open($player, route('matches.room', $series));
    trafficSubscribed($room, 'private-App.Models.User.'.$player->id);
    event(new SeriesMatchChanged([$player->id], $series->number, $series->status->value));
    BrowserWait::until($room, '() => window.__pushes >= 1', 10_000);
    $seen['room push'] = trafficWindow($room, 2_000);

    // No socket: the room asks every 30 s; a hidden tab asks nothing, and once when it comes back.
    $room->evaluate('() => window.Echo.connector.pusher.disconnect()');
    $seen['room no socket'] = trafficWindow($room, 60_000);
    $room->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); }');
    // A batch the shell raised just before the tab hid still leaves (playerEvents.js, 250 ms); then the quiet.
    trafficWindow($room, 1_000);
    $seen['room hidden'] = trafficWindow($room, 120_000);
    $room->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => false }); document.dispatchEvent(new Event("visibilitychange")); }');
    $seen['room back'] = trafficWindow($room, 1_000);
    $roomErrors = [...$room->evaluate('() => window.__errors'), ...$room->evaluate(BrowserConsole::BAD_RESPONSES)];

    // The tournament page: a push renders it once, after its random wait; without a socket it polls every 15 s.
    $show = $open(null, route('tournaments.show', $tournament));
    trafficSubscribed($show, 'tournament.'.$tournament->id);
    event(new TournamentChanged($tournament->id, 'result'));
    BrowserWait::until($show, '() => window.__pushes >= 1', 10_000);
    $seen['tournament push'] = trafficWindow($show, 2_000);
    $show->evaluate('() => window.Echo.connector.pusher.disconnect()');
    $seen['tournament no socket'] = trafficWindow($show, 60_000);
    $showErrors = [...$show->evaluate('() => window.__errors'), ...$show->evaluate(BrowserConsole::BAD_RESPONSES)];

    // Home: one notification renders the dock, the cup badge and banner, and the bell in ONE request. On the REAL
    // clock: the virtual one fires the 250 ms waits before Livewire's own 5 ms bundling buffer runs out, and so
    // bundles requests that a browser sends apart (the bell used to ask at once, the others 250 ms later).
    $webpage = visit(BrowserLogin::url($player));
    $home = $webpage->page();
    $home->context()->addInitScript(BrowserConsole::COLLECTOR);
    $home->context()->addInitScript(REQUEST_COUNTER);
    $home->setViewportSize(1440, 900);
    $home->goto(ComputeUrl::from(route('home')));
    BrowserWait::until($home, '() => document.readyState === "complete" && window.__requests !== undefined && typeof window.Livewire !== "undefined"', 15_000);
    trafficSubscribed($home, 'private-App.Models.User.'.$player->id);
    $before = $home->evaluate('() => window.__requests.length');
    event(new UserNotified($player->id, ['id' => 'p3-push', 'kind' => 'invite', 'title' => 'P3', 'body' => 'P3', 'url' => route('home'),
        'match' => null, 'action' => null, 'sound' => 'none', 'tone' => 'confirmed', 'redirect' => false]));
    BrowserWait::until($home, '() => window.__pushes >= 1', 10_000);
    usleep(1_500_000);
    $requests = array_slice($home->evaluate('() => window.__requests'), $before);
    $seen['home notification'] = ['requests' => count($requests), 'received' => 0, 'labels' => array_count_values($requests), 'at' => []];
    $homeErrors = [...$home->evaluate('() => window.__errors'), ...$home->evaluate(BrowserConsole::BAD_RESPONSES)];

    // /live: the programme scrolled into view, its 30 s poll renders the island only.
    $live = $open(null, route('live'));
    $live->evaluate('() => document.querySelector("[data-test=live-programme]")?.scrollIntoView()');
    BrowserWait::until($live, '() => { const r = document.querySelector("[data-test=live-programme]")?.getBoundingClientRect(); return r && r.top < innerHeight && r.bottom > 0; }', 5_000);
    $seen['live island'] = trafficWindow($live, 60_000);
    $liveErrors = [...$live->evaluate('() => window.__errors'), ...$live->evaluate(BrowserConsole::BAD_RESPONSES)];

    fwrite(STDERR, "\n[livewire-pushes] ".json_encode(array_map(fn (array $w): array => ['requests' => $w['requests'], 'received' => $w['received'], 'labels' => $w['labels'], 'at' => $w['at']], $seen))."\n");

    expect($roomErrors)->toBe([])->and($showErrors)->toBe([])->and($homeErrors)->toBe([])->and($liveErrors)->toBe([])
        // A push: one request, which carries the room's sync.
        ->and($seen['room push']['requests'])->toBe(1)
        ->and(trafficCount($seen['room push'], 'pages::matches.room:sync'))->toBe(1)
        // No socket: every 30 s; hidden: nothing from the room or anything else; back: one catch-up.
        ->and(trafficCount($seen['room no socket'], 'pages::matches.room:sync'))->toBe(2)
        ->and($seen['room hidden']['requests'])->toBe(0)
        ->and(trafficCount($seen['room back'], 'pages::matches.room:sync'))->toBe(1)
        // The tournament page: one render per push; every 15 s without the socket. While it runs, a push renders
        // only its "now" and board islands (refreshLive, P4): ~29 KB instead of the ~63 KB page.
        ->and(trafficCount($seen['tournament push'], 'pages::tournaments.show:refreshLive'))->toBe(1)
        ->and($seen['tournament push']['received'])->toBeLessThan(40_000)
        ->and(trafficCount($seen['tournament no socket'], 'pages::tournaments.show:refreshLive'))->toBe(4)
        // One notification, one request, with the dock, the cup badge and the bell in it.
        ->and($seen['home notification']['requests'])->toBe(1)
        ->and(array_key_first($seen['home notification']['labels']))->toContain('match-dock:$refresh')->toContain('notification-bell:$refresh')->toContain('cup-match:$refresh')
        // /live: two island polls in a minute, each a fraction of the ~56 KB page.
        ->and(trafficCount($seen['live island'], 'pages::live:$refresh'))->toBe(2)
        ->and($seen['live island']['received'])->toBeLessThan(2 * 20_000);
});

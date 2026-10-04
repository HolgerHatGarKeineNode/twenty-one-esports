<?php

use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
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
| server's clock is real), and requests that are not Livewire's: those are
| counted by path next to it ("other"), without bytes.
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
            constructor(...args) { args.length ? super(...args) : super(RealDate.now() + virtualNow); }
            static now() { return RealDate.now() + virtualNow; }
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
                    labels,
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
 * @return array{load: array<string, mixed>, window: array<string, mixed>, errors: list<string>}
 */
function trafficMeasure(Page $page, string $url): array
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.__traffic?.hasLivewire() === true', 15_000);

    return Playwright::usingTimeout(120_000, function () use ($page): array {
        $page->evaluate('() => window.__traffic.resetOther()');
        $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', TRAFFIC_LOAD_MS);
        $load = $page->evaluate('() => window.__traffic.since(0)');
        $mark = $page->evaluate('() => window.__traffic.mark()');
        $page->evaluate('() => window.__traffic.resetOther()');
        $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', TRAFFIC_WINDOW_MS);

        return [
            'load' => $load,
            'window' => $page->evaluate('(mark) => window.__traffic.since(mark)', $mark),
            'errors' => [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)],
        ];
    });
}

/*
| Today's numbers (2026-10-04) as the upper bound per page: [requests in the window, request + response bytes].
| The request count is exact (it repeated in three runs); the bytes carry 10 % on top of the measured size, rounded
| up to 1 000, because tokens and timestamps in the snapshots change by a few dozen bytes per run. P3 lowers them
| with the saving named.
*/
const TRAFFIC_BUDGETS = [
    'tournament (guest)' => [4, 280_000],
    'tournament (player)' => [6, 380_000],
    'room (player)' => [9, 65_000],
    'home (player)' => [2, 56_000],
    'live (guest)' => [2, 126_000],
    'live (player)' => [5, 241_000],
];

test('the hot pages cost this many Livewire roundtrips and bytes in a minute, with a clean console and 2xx answers', function () {
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
        $page->context()->addInitScript(TRAFFIC_PROBE);
        $page->setViewportSize(1440, 900);

        $run = trafficMeasure($page, $url);
        $bytes = $run['window']['sent'] + $run['window']['received'];
        $measured[$name] = [
            'window' => $run['window']['requests'], 'sent' => $run['window']['sent'], 'received' => $run['window']['received'],
            'load' => $run['load']['requests'], 'labels' => $run['window']['labels'], 'other' => $run['window']['other'],
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

    // The collector sees traffic where traffic is known to be: the room syncs every 8 s, the tournament page polls every 15 s.
    expect($measured['room (player)']['window'])->toBeGreaterThanOrEqual(6)
        ->and($measured['tournament (guest)']['window'])->toBeGreaterThanOrEqual(3)
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

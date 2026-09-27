<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\GameRecords;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignerMessages;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserThrottle;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A NIP-46 bunker login keeps its session (hotfix, 2026-09-27)
|--------------------------------------------------------------------------
|
| Reported: a bunker created in Amber signs the login, and then every daily
| move asks for a bunker again. Here the real login button, the real
| nostr-mill dialog and the real NIP-46 client talk to an emulated Amber
| bunker (tests/Support/amber-bunker.mjs: one-time secret, a spent secret is
| refused, the authorised client key is served) over a local `nak serve`
| relay. Never a public relay: every websocket the page opens is recorded
| and must be local.
|
| After the one login pairing, four daily moves are signed: straight after
| login, after a full reload, after two Livewire navigations, and after the
| relay went away and came back under the open page. No second dialog, one
| `connect` in the bunker's log, every move signed by the player's key.
| Logout removes the stored session: a move afterwards asks again and the
| bunker hears nothing.
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

    $this->relayPort = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relayUrl = 'ws://127.0.0.1:'.$this->relayPort;
    $this->relay = bunkerRelayStart($this->relayPort);
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl]]);

    // The player's key lives in the emulated bunker only; the test keeps it to read the pubkey.
    $this->player = new TestSigner;
    $this->connectSecret = bin2hex(random_bytes(16));
    $this->bunkerLog = tempnam(sys_get_temp_dir(), 'bunker-log-');
    $this->bunker = Process::path(base_path())->env([
        'BUNKER_KEY' => $this->player->secret,
        'BUNKER_SECRET' => $this->connectSecret,
        'BUNKER_RELAY' => $this->relayUrl,
        'BUNKER_LOG' => $this->bunkerLog,
    ])->start(['node', 'tests/Support/amber-bunker.mjs']);

    for ($i = 0; $i < 100 && ! str_contains($this->bunker->output(), 'ready'); $i++) {
        usleep(50_000);
    }
    expect($this->bunker->output())->toContain('ready');
});

afterEach(function () {
    $this->bunker->stop(1);
    $this->relay->stop(1);
    @unlink($this->bunkerLog);
});

function bunkerRelayStart(int $port): InvokedProcess
{
    $relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $port]);

    WaitForPort::open('127.0.0.1', $port);

    return $relay;
}

/**
 * The console/network collector, plus: every websocket URL the page opens
 * (kept in sessionStorage across reloads), and every time nostr-mill's
 * dialog is opened (MILL.open wrapped as mill's UMD bundle assigns
 * window.MILL). Neither changes what the page does.
 */
const BUNKER_PROBES = <<<'JS'
    (() => {
        const NativeSocket = window.WebSocket;
        window.WebSocket = function (url, protocols) {
            const seen = JSON.parse(sessionStorage.getItem('__sockets') ?? '[]');
            seen.push(String(url));
            sessionStorage.setItem('__sockets', JSON.stringify(seen));
            return protocols === undefined ? new NativeSocket(url) : new NativeSocket(url, protocols);
        };
        window.WebSocket.prototype = NativeSocket.prototype;
        Object.assign(window.WebSocket, { CONNECTING: 0, OPEN: 1, CLOSING: 2, CLOSED: 3 });

        let mill;
        Object.defineProperty(window, 'MILL', {
            configurable: true,
            get: () => mill,
            set: (value) => {
                const open = value.open.bind(value);
                value.open = (options) => {
                    sessionStorage.setItem('__millOpens', String(Number(sessionStorage.getItem('__millOpens') ?? '0') + 1));
                    return open(options);
                };
                mill = value;
            },
        });
    })();
    JS;

/**
 * Stacks for every uncaught error, and every Alpine expression warning with
 * the element it came from (connected or not, its scope depth): what found
 * the late-morph race of resources/js/livewireDetached.js. Printed only when
 * a page is not clean.
 */
const BUNKER_TRACE = <<<'JS'
    (() => {
        const push = (entry) => (window.__stacks ??= []).push(entry);
        window.addEventListener('error', (e) => push(String(e.error?.stack ?? e.message)), true);
        window.addEventListener('unhandledrejection', (e) => push('rejection ' + String(e.reason?.stack ?? e.reason)));
        const depth = (el) => String((el._x_dataStack ?? []).length) + '/' + String((el.closest('[x-data]')?._x_dataStack ?? []).length);
        const warn = console.warn;
        console.warn = function (...args) {
            push('warn ' + args.map((x) => x instanceof Element
                ? 'connected=' + x.isConnected + ' path=' + location.pathname + ' scope=' + depth(x) + ' ' + x.outerHTML.slice(0, 120)
                : String(x)).join(' | '));
            return warn.apply(console, args);
        };
    })();
    JS;

/** @return list<array{method: string, client: ?string, ok: bool, error: ?string}> */
function bunkerRequests(string $log): array
{
    return array_values(array_map(
        fn (string $line): array => json_decode($line, true),
        array_filter(explode("\n", (string) file_get_contents($log))),
    ));
}

function bunkerDaily(): string
{
    return 'Alpine.$data(document.querySelector("[data-test=daily-game]"))';
}

function bunkerWaitForTurn(Page $page, int $ply): void
{
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=daily-game]") && '.bunkerDaily().'.myTurn === true && '.bunkerDaily().'.state.ply === '.$ply, 15_000);
}

/** Play a daily move on the board (both widths), confirm it, and wait for the outcome. */
function bunkerMove(Page $page, string $from, string $to, int $plyAfter): void
{
    $page->locator('[data-square="'.$from.'"]')->click();
    $page->locator('[data-square="'.$to.'"]')->click();
    BrowserWait::until($page, '() => '.bunkerDaily().'.pending !== null', 5_000);
    $page->evaluate('() => '.bunkerDaily().'.makeMove()');
    $page->locator('[data-test=confirm-daily-move]')->click();
    BrowserWait::until($page, '() => '.bunkerDaily().'.state.ply === '.$plyAfter.' || '.bunkerDaily().'.error !== "" || Number(sessionStorage.getItem("__millOpens") ?? "0") > 1', 45_000);

    expect($page->evaluate('() => Number(sessionStorage.getItem("__millOpens") ?? "0")'))->toBe(1, 'the signer dialog opened again')
        ->and($page->evaluate('() => '.bunkerDaily().'.error'))->toBe('')
        ->and($page->evaluate('() => '.bunkerDaily().'.state.ply'))->toBe($plyAfter);
}

/** Bert answers on the server, signed with his own key, the way his browser would. */
function bunkerReply(ChessGame $game, User $bert, TestSigner $bertKey, string $uci, int $ply): void
{
    $records = app(GameRecords::class);
    $prepared = $records->prepareMove($game->refresh(), $bert, $uci, $ply);
    $records->playSigned($game->refresh(), $bert, $uci, $ply, $bertKey->signTemplates([$prepared['template']])[0]);
}

/** Log in through the real button and mill's remote-signer card, with the bunker:// URI Amber would show. */
function bunkerLogin(int $width, int $height, string $pubkey, string $relayUrl, string $secret): Page
{
    $page = visit('/login')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(BUNKER_PROBES);
    $page->context()->addInitScript(BUNKER_TRACE);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from('/login'));

    // No extension, so the Nostr button opens mill's remote-signer card.
    $page->locator('[data-test="login-nostr"]')->click();
    $page->locator('nostr-signer textarea')->fill('bunker://'.$pubkey.'?relay='.rawurlencode($relayUrl).'&secret='.$secret);
    $page->getByRole('button', ['name' => 'Connect to Bunker'])->click();
    $page->getByRole('button', ['name' => 'Confirm Connection'])->click();
    BrowserWait::until($page, '() => location.pathname === "/" && document.querySelector("[data-test=account-chip]") !== null', 30_000);

    return $page;
}

function bunkerPageClean(Page $page): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], json_encode($page->evaluate('() => window.__stacks ?? []')))
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
}

test('a bunker login signs every later daily move without a second pairing, across reload, navigation and a relay drop, and logout forgets it', function (int $width, int $height) {
    $anna = User::factory()->create(['name' => 'anna-bunker', 'pubkey' => $this->player->pubkey, 'npub' => NostrKeys::hexToNpub($this->player->pubkey), 'locale' => 'en']);
    $bert = User::factory()->create(['name' => 'bert-bunker', 'locale' => 'en']);
    $bertKey = TestSigner::forBrowser($bert);
    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $gamePath = route('games.show', $game, false);

    $page = bunkerLogin($width, $height, $this->player->pubkey, $this->relayUrl, $this->connectSecret);
    bunkerPageClean($page);

    // 1: right after the login's full page load.
    $page->goto(ComputeUrl::from($gamePath));
    bunkerWaitForTurn($page, 0);
    bunkerMove($page, 'e2', 'e4', 1);
    bunkerPageClean($page);

    // 2: after a full reload.
    bunkerReply($game, $bert, $bertKey, 'e7e5', 2);
    $page->reload();
    bunkerWaitForTurn($page, 2);
    bunkerMove($page, 'g1', 'f3', 3);
    bunkerPageClean($page);

    // 3: after two Livewire navigations (away and back, no page load), on a
    // CPU slowed down 6x: a late dock or bell refresh then lands after the
    // navigation, the race resources/js/livewireDetached.js closes (at load
    // ~20 it failed one full run in two, throttled every time without the fix).
    bunkerReply($game, $bert, $bertKey, 'b8c6', 4);
    BrowserThrottle::cpu($page, 6);
    $page->evaluate('() => { window.__sameDocument = true; Livewire.navigate("/"); }');
    BrowserWait::until($page, '() => location.pathname === "/" && window.__sameDocument === true', 15_000);
    $page->evaluate('() => Livewire.navigate('.json_encode($gamePath).')');
    BrowserWait::until($page, '() => location.pathname === '.json_encode($gamePath).' && window.__sameDocument === true', 15_000);
    bunkerWaitForTurn($page, 4);
    bunkerMove($page, 'f1', 'c4', 5);
    bunkerPageClean($page);
    BrowserThrottle::cpu($page, 1);

    // 4: the relay goes away and comes back under the open page, whose remote
    // signer is live since move 3 (Bert's answer arrives over the websocket,
    // no reload: a reload would start a fresh client after the drop).
    bunkerReply($game, $bert, $bertKey, 'g8f6', 6);
    bunkerWaitForTurn($page, 6);
    expect($page->evaluate('() => window.__sameDocument'))->toBeTrue();
    $this->relay->stop(1);
    $this->relay = bunkerRelayStart($this->relayPort);
    Execution::instance()->wait(1.5);
    bunkerMove($page, 'd2', 'd3', 7);

    $requests = bunkerRequests($this->bunkerLog);
    $connects = array_values(array_filter($requests, fn (array $r): bool => $r['method'] === 'connect'));
    $signs = array_values(array_filter($requests, fn (array $r): bool => $r['method'] === 'sign_event'));
    $annaMoves = $game->refresh()->moves()->with('nostrEvent')->get()->filter(fn ($move): bool => $move->ply % 2 === 1);

    expect($connects)->toHaveCount(1)
        ->and($connects[0]['ok'])->toBeTrue()
        // The login and the four moves; every request from the one paired client key, every one served.
        ->and(count($signs))->toBeGreaterThanOrEqual(5)
        ->and(array_unique(array_column($requests, 'client')))->toBe([$connects[0]['client']])
        ->and(array_filter($requests, fn (array $r): bool => ! $r['ok']))->toBe([])
        ->and($annaMoves)->toHaveCount(4)
        ->and($annaMoves->map(fn ($move) => $move->nostrEvent?->pubkey)->unique()->values()->all())->toBe([$anna->pubkey])
        ->and($annaMoves->map(fn ($move) => $move->nostrEvent?->kind)->unique()->values()->all())->toBe([64]);

    // The session is stored for Anna, and its client key stays in the browser: not in
    // the markup, not in a server session payload, not in the app log. Only booleans
    // are compared, so a failure never prints the key.
    $session = $page->evaluate('() => JSON.parse(localStorage.getItem("esports:nip46:session") ?? "null")');
    $clientKey = (string) ($session['clientSecretKey'] ?? '');
    $payloads = DB::table('sessions')->pluck('payload')->map(fn (string $payload): string => (string) base64_decode($payload))->implode("\n");
    $appLog = (string) @file_get_contents(storage_path('logs/laravel.log'));

    expect($session['userPubkey'] ?? null)->toBe($anna->pubkey)
        ->and(strlen($clientKey) === 64)->toBeTrue()
        ->and(str_contains($page->evaluate('() => document.documentElement.outerHTML'), $clientKey))->toBeFalse()
        ->and(str_contains($payloads, $clientKey))->toBeFalse()
        ->and(str_contains($appLog, $clientKey))->toBeFalse();

    bunkerPageClean($page);

    // Logout forgets the session (the header's and the mobile nav's form share the handler).
    $page->evaluate('() => document.querySelector("form[action$=\"/logout\"]").requestSubmit()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=account-chip]") === null', 15_000);
    // Compared as booleans: a failure message must not print the stored client key.
    expect($page->evaluate('() => [localStorage.getItem("esports:nip46:session") === null, sessionStorage.getItem("mill:nip46:state") === null]'))->toBe([true, true]);

    // Negative: logged in again without a signer, a move asks to connect, and the bunker hears nothing.
    $heard = count(bunkerRequests($this->bunkerLog));
    bunkerReply($game, $bert, $bertKey, 'd7d6', 8);
    $page->goto(ComputeUrl::from(route('testing.login', ['user' => $anna, 'to' => $gamePath], false)));
    bunkerWaitForTurn($page, 8);
    $page->locator('[data-square="c2"]')->click();
    $page->locator('[data-square="c3"]')->click();
    BrowserWait::until($page, '() => '.bunkerDaily().'.pending !== null', 5_000);
    $page->evaluate('() => '.bunkerDaily().'.makeMove()');
    $page->locator('[data-test=confirm-daily-move]')->click();
    BrowserWait::until($page, '() => Number(sessionStorage.getItem("__millOpens") ?? "0") === 2', 10_000);
    Execution::instance()->wait(1.0);

    expect(bunkerRequests($this->bunkerLog))->toHaveCount($heard)
        ->and($game->refresh()->ply)->toBe(8);

    // Another player logs in on this browser: a session stored for Anna is gone on his first page.
    $page->evaluate('() => localStorage.setItem("esports:nip46:session", JSON.stringify({ clientSecretKey: "1".repeat(64), remotePubkey: '.json_encode($anna->pubkey).', relays: ['.json_encode($this->relayUrl).'], userPubkey: '.json_encode($anna->pubkey).' }))');
    $page->goto(ComputeUrl::from(route('testing.login', ['user' => $bert, 'to' => '/'], false)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=account-chip]") !== null', 15_000);
    expect($page->evaluate('() => localStorage.getItem("esports:nip46:session") === null'))->toBeTrue();

    // Only the local relay and the local Reverb were ever dialled.
    $hosts = array_unique(array_map(fn (string $url): string => (string) parse_url($url, PHP_URL_HOST), $page->evaluate('() => JSON.parse(sessionStorage.getItem("__sockets") ?? "[]")')));
    expect(array_diff($hosts, ['127.0.0.1', 'localhost']))->toBe([]);

    // Positive control: the collector does see a thrown error on this page.
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
})->with([
    'phone 375x812' => [375, 812],
    'desktop 1440x900' => [1440, 900],
]);

test('a bunker that revoked this browser gets a clear message, the stored session goes, and only the next try opens the connect dialog', function () {
    $anna = User::factory()->create(['name' => 'anna-bunker', 'pubkey' => $this->player->pubkey, 'npub' => NostrKeys::hexToNpub($this->player->pubkey), 'locale' => 'en']);
    $bert = User::factory()->create(['name' => 'bert-bunker', 'locale' => 'en']);
    $bertKey = TestSigner::forBrowser($bert);
    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);

    $page = bunkerLogin(1440, 900, $this->player->pubkey, $this->relayUrl, $this->connectSecret);
    $page->goto(ComputeUrl::from(route('games.show', $game, false)));
    bunkerWaitForTurn($page, 0);
    bunkerMove($page, 'e2', 'e4', 1);
    bunkerReply($game, $bert, $bertKey, 'e7e5', 2);
    $page->reload();
    bunkerWaitForTurn($page, 2);

    // The player removes this app in Amber (SIGUSR2 to the emulated bunker).
    $this->bunker->signal(12);
    Execution::instance()->wait(0.5);

    $page->locator('[data-square="g1"]')->click();
    $page->locator('[data-square="f3"]')->click();
    BrowserWait::until($page, '() => '.bunkerDaily().'.pending !== null', 5_000);
    $page->evaluate('() => '.bunkerDaily().'.makeMove()');
    $page->locator('[data-test=confirm-daily-move]')->click();
    BrowserWait::until($page, '() => '.bunkerDaily().'.error !== ""', 15_000);

    expect($page->evaluate('() => '.bunkerDaily().'.error'))->toBe(SignerMessages::labels()['revoked'])
        ->and($page->evaluate('() => [localStorage.getItem("esports:nip46:session") === null, typeof window.nostr]'))->toBe([true, 'undefined'])
        ->and($page->evaluate('() => Number(sessionStorage.getItem("__millOpens") ?? "0")'))->toBe(1)
        ->and($game->refresh()->ply)->toBe(2);

    // The next try is the reconnect: the dialog opens, and nothing reached the bunker in between.
    $heard = count(bunkerRequests($this->bunkerLog));
    $page->evaluate('() => '.bunkerDaily().'.makeMove()');
    $page->locator('[data-test=confirm-daily-move]')->click();
    BrowserWait::until($page, '() => Number(sessionStorage.getItem("__millOpens") ?? "0") === 2', 10_000);

    $requests = bunkerRequests($this->bunkerLog);
    expect($requests)->toHaveCount($heard)
        ->and(array_values(array_column(array_filter($requests, fn (array $r): bool => ! $r['ok']), 'error')))->toBe(['unauthorized']);
});

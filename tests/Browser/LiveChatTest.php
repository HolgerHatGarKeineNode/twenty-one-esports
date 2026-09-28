<?php

use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The stream chat on /live (P24)
|--------------------------------------------------------------------------
|
| NIP-53 kind 1311 under the stream's 30311, over a real websocket to the
| in-memory relay (tests/Support/MiniRelay.php), never a real relay. Custom
| emoji are https images, served by tests/Support/emoji-image-server.php
| (self-signed; the test contexts are opened with ignoreHTTPSErrors). Each player's
| context signs with its own key through TestSigner::browserStub.
|
| Every page carries BrowserConsole's collector (console.error, uncaught
| errors, rejections, fetch/XHR >= 400, failed images) plus the resource
| entries >= 400; one test proves the collector catches what it should.
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

    // Off air: the chat does not depend on the picture, and no HLS request runs beside it.
    $this->hls = sys_get_temp_dir().'/esports-p24-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->hls);
    $this->streamKey = new TestSigner;
    config([
        'twentyone.stream.hls_dir' => $this->hls,
        'twentyone.stream.public_url' => '/__test/live/stream.m3u8',
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.stream.event.d' => 'twentyone-247',
    ]);
    $this->address = '30311:'.$this->streamKey->pubkey.':twentyone-247';
});

afterEach(function () {
    File::deleteDirectory($this->hls);
});

function p24FreePort(): int
{
    return (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
}

/**
 * The relay with the given events, and the chat and profile relays pointed at it.
 *
 * @param  list<array<string, mixed>>  $events
 * @return array{0: InvokedProcess, 1: string, 2: string}
 */
function p24Relay(array $events): array
{
    [$relay, $url, $seed] = p24StartRelay($events);
    config(['esports.stream_chat.relays' => [$url], 'esports.profile_relays' => [$url]]);

    return [$relay, $url, $seed];
}

/**
 * One relay with the given events and MiniRelay limits (e.g. `shuffle`, `eose_delay_ms`), not yet configured.
 *
 * @param  list<array<string, mixed>>  $events
 * @param  array<string, mixed>  $limits
 * @return array{0: InvokedProcess, 1: string, 2: string}
 */
function p24StartRelay(array $events, array $limits = []): array
{
    $seed = (string) tempnam(sys_get_temp_dir(), 'p24-seed');
    file_put_contents($seed, json_encode($events));
    $port = p24FreePort();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed, (string) json_encode((object) $limits)]);
    WaitForPort::open('127.0.0.1', $port);

    return [$relay, 'ws://127.0.0.1:'.$port, $seed];
}

/** The https emoji host; returns the process and the image base URL. */
function p24Images(): array
{
    $dir = sys_get_temp_dir().'/p24-img-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($dir);
    $server = Process::path(base_path())->start(['php', 'tests/Support/emoji-image-server.php', $dir]);
    $deadline = microtime(true) + 10;
    while (trim($server->latestOutput()) === '' && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $port = (int) trim($server->output());
    WaitForPort::open('127.0.0.1', $port);

    return [$server, 'https://127.0.0.1:'.$port];
}

function p24Page(?User $user, int $width, int $height, bool $touch = false, ?string $initScript = null): Page
{
    $start = $user ? BrowserLogin::url($user) : BrowserLogin::LANDING;
    // The emoji host's certificate is self-signed; the plugin's launch option does not reach the context, this does.
    $options = ['ignoreHTTPSErrors' => true];
    $page = $touch ? visit($start, $options)->on()->mobile()->page() : visit($start, $options)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($user) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
    }

    if ($initScript !== null) {
        $page->context()->addInitScript($initScript);
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from('/live'));
    BrowserWait::until($page, '() => window.Alpine !== undefined && document.fonts.status === "loaded" && document.querySelector("[data-test=live-chat]") !== null', 10_000);

    return $page;
}

function p24Live(Page $page): void
{
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=live-chat]")).status === "live"', 10_000);
}

function p24Errors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/** Texts of the visible messages, in list order. */
const P24_TEXTS = '() => [...document.querySelectorAll("[data-test=live-chat-message]")].filter((el) => el.checkVisibility()).map((el) => el.querySelector("[data-test=live-chat-text]").innerText.trim())';

function p24Shot(Page $page, string $name): void
{
    $dir = getenv('P24_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);

    foreach (Client::instance()->execute($guid, 'screenshot', ['type' => 'png', 'fullPage' => false, 'caret' => 'hide', 'animations' => 'allow', 'scale' => 'css']) as $message) {
        if (isset($message['result']['binary'])) {
            File::ensureDirectoryExists($dir);
            File::put($dir.'/'.$name.'.png', base64_decode((string) $message['result']['binary']));
        }
    }
}

test('a player posts a picked custom emoji through her signer, another player and a guest receive it, bot and zap are marked, a mute folds a spammer away', function () {
    [$images, $imageBase] = p24Images();
    [$anna, $bert] = User::factory()->count(2)->create();
    $annaKey = TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    $setOwner = new TestSigner;
    $bot = new TestSigner;
    $spammer = new TestSigner;
    $zapper = new TestSigner;
    $lnurl = new TestSigner;
    config(['esports.stream_bot.nsec' => $bot->secret, 'esports.stream_chat.zap_signers' => [$lnurl->pubkey]]);

    $satoshi = $imageBase.'/satoshi.png';
    $now = now()->getTimestamp();
    $root = fn (): array => ['a', $this->address, 'ws://127.0.0.1', 'root'];
    $zapRequest = $zapper->sign(9734, [['a', $this->address], ['p', $this->streamKey->pubkey], ['amount', '21000']], 'love the music', $now - 50);

    // Older talk, so the list scrolls and "N new" has something to count against.
    $regular = new TestSigner;
    $older = array_map(fn (int $i): array => $regular->sign(1311, [$root()], 'earlier message '.$i, $now - 3000 + $i * 60), range(1, 16));

    [$relay, $url, $seed] = p24Relay([
        ...$older,
        // Anna's emoji: her list points to a set of someone else's.
        $annaKey->sign(10030, [['a', '30030:'.$setOwner->pubkey.':party']], '', $now - 3600),
        $setOwner->sign(30030, [['d', 'party'], ['emoji', 'satoshi', $satoshi]], '', $now - 3600),
        $annaKey->sign(0, [], (string) json_encode(['display_name' => 'Anna Relay']), $now - 3600),
        $bot->sign(1311, [$root()], 'New here? The ladder is open: https://esports.example/ladder', $now - 120),
        $spammer->sign(1311, [$root()], 'buy my coin', $now - 100),
        $spammer->sign(1311, [$root()], 'buy it now', $now - 95),
        $lnurl->sign(9735, [['p', $this->streamKey->pubkey], ['a', $this->address], ['bolt11', 'lnbc210n1pjtest'], ['description', (string) json_encode($zapRequest)]], '', $now - 40),
        // Another stream's chat and a forged zap never show.
        $spammer->sign(1311, [['a', '30311:'.$this->streamKey->pubkey.':other', '', 'root']], 'wrong stream', $now - 30),
        $spammer->sign(9735, [['a', $this->address], ['bolt11', 'lnbc10m1pjtest'], ['description', (string) json_encode($zapRequest)]], '', $now - 20),
        // The real LNURL server's receipt for a zap to someone else, with the stream's a tag copied on: not a zap of the stream.
        $lnurl->sign(9735, [['p', $spammer->pubkey], ['a', $this->address], ['bolt11', 'lnbc10m1pjtest'], ['description', (string) json_encode($spammer->sign(9734, [['a', $this->address], ['p', $spammer->pubkey], ['amount', '1000000000']], 'fake big zap', $now - 16))]], '', $now - 15),
        // The spammer's profile claims to be the league's bot.
        $spammer->sign(0, [], (string) json_encode(['display_name' => 'TWENTY ONE Bot', 'bot' => true]), $now - 3600),
    ]);

    try {
        $pageA = p24Page($anna, 1440, 900);
        $pageB = p24Page($bert, 1440, 900);
        $guest = p24Page(null, 1440, 900);
        foreach ([$pageA, $pageB, $guest] as $page) {
            p24Live($page);
        }

        // What everyone reads: the bot marked, the zap once with its amount, nothing of the other stream.
        BrowserWait::until($guest, '() => document.querySelectorAll("[data-test=live-chat-zap]").length === 1', 5_000);
        expect(array_slice($guest->evaluate(P24_TEXTS), -3))->toBe(['New here? The ladder is open: esports.example/ladder', 'buy my coin', 'buy it now'])
            ->and(count($guest->evaluate(P24_TEXTS)))->toBe(19)
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-zap]").innerText.replace(/\s+/g, " ").trim()'))->toContain('21 sats')->toContain('love the music')
            ->and($guest->evaluate('() => [...document.querySelectorAll("[data-test=live-chat-message]")].slice(-3).map((el) => !!el.querySelector("[data-test=live-chat-bot]")?.checkVisibility())'))->toBe([true, false, false])
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-zap]").innerText'))->not->toContain('fake big zap')
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-text] a[href]")?.getAttribute("href")'))->toBe('https://esports.example/ladder')
            // A guest reads and gets a way in, no form, no emoji button.
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-guest] a")?.getAttribute("href")'))->toEndWith('/login?then=live')
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-form]")'))->toBeNull()
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-emoji]")'))->toBeNull();

        // The look-alike: its own name, no badge, its npub beside the name.
        $lookAlike = '[data-test=live-chat-message][data-pubkey="'.$spammer->pubkey.'"]';
        BrowserWait::until($guest, '() => document.querySelector('.json_encode($lookAlike).')?.innerText.includes("TWENTY ONE Bot")', 5_000);
        expect($guest->evaluate('() => document.querySelector('.json_encode($lookAlike.' [data-test=live-chat-bot]').').checkVisibility()'))->toBeFalse()
            ->and($guest->evaluate('() => document.querySelector('.json_encode($lookAlike.' [data-test=live-chat-npub]').').innerText'))->toStartWith('npub1')->toContain('…');

        // Anna picks her custom emoji from the picker (a pointer device): her 10030 -> the 30030 set.
        // The Unicode set is not loaded with the page, only when the picker opens (its own JSON asset).
        $emojibase = '() => performance.getEntriesByType("resource").filter((e) => /\\/build\\/assets\\/(compact|messages)-/.test(e.name)).map((e) => new URL(e.name).pathname.replace(/-[\\w-]+\\./, "."))';
        expect($pageA->evaluate('() => matchMedia("(hover: hover) and (pointer: fine)").matches'))->toBeTrue()
            ->and($pageA->evaluate($emojibase))->toBe([]);
        $pageA->locator('[data-test=live-chat-emoji]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("[data-emoji-panel] [data-test=emoji-tab-custom]") !== null', 10_000);
        expect($pageA->evaluate($emojibase))->toContain('/build/assets/compact.json')->toContain('/build/assets/messages.json');
        $pageA->locator('[data-emoji-panel] [data-test=emoji-tab-custom]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("[data-emoji-panel] [data-test=emoji-grid] button[data-custom] img")?.naturalWidth === 16', 10_000);
        p24Shot($pageA, 'p24-picker-custom-1440');
        $pageA->locator('[data-emoji-panel] [data-test=emoji-grid] button[data-custom]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("[data-emoji-panel]") === null && document.querySelector("#live-chat-input").value === ":satoshi:"', 3_000);
        p24Shot($pageA, 'p24-chat-before-send-1440');

        $pageA->locator('#live-chat-input')->type(' gm stream');
        $pageA->locator('[data-test=live-chat-send]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("#live-chat-input").value === ""', 5_000);

        // Bert and the guest receive it over the relay, the emoji as its image, Anna's name from her kind 0.
        foreach ([$pageB, $guest] as $page) {
            BrowserWait::until($page, '() => { const img = [...document.querySelectorAll("[data-test=live-chat-emoji-img]")].find((i) => i.checkVisibility()); return !!img && img.complete && img.naturalWidth === 16; }', 10_000);
            expect($page->evaluate(P24_TEXTS))->toContain('gm stream')
                ->and($page->evaluate('() => document.querySelector("[data-test=live-chat-emoji-img]:not([style*=none])")?.getAttribute("src")'))->toBe($satoshi);
        }
        BrowserWait::until($pageB, '() => [...document.querySelectorAll("[data-test=live-chat-message]")].some((el) => el.innerText.includes("Anna Relay") && el.innerText.includes("gm stream"))', 5_000);

        // What went to the relay: the stream address as root, one emoji tag, no t tag, signed with Anna's key.
        $sent = $guest->evaluate('async (url) => await new Promise((resolve) => { const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["REQ", "p24", { kinds: [1311], authors: ['.json_encode($anna->pubkey).'] }])); ws.onmessage = (m) => { const f = JSON.parse(m.data); if (f[0] === "EVENT") { ws.close(); resolve(f[2]); } }; })', $url);
        expect($sent['content'])->toBe(':satoshi: gm stream')
            ->and($sent['pubkey'])->toBe($anna->pubkey)
            ->and($sent['tags'])->toBe([['a', $this->address, $url, 'root'], ['emoji', 'satoshi', $satoshi]]);

        // One message every 2 s: a second one right away is held back and says so.
        $pageA->locator('#live-chat-input')->fill('again');
        $pageA->locator('[data-test=live-chat-send]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("[data-test=live-chat-error]").innerText.includes("2 seconds")', 2_000);
        expect($pageA->evaluate('() => document.querySelector("#live-chat-input").value'))->toBe('again');

        // Bert reads back up. Anna's next message (after the pause) is counted, not scrolled to.
        $pageB->evaluate('() => { const list = document.querySelector("[data-test=live-chat-list]"); list.scrollTop = 0; list.dispatchEvent(new Event("scroll")); }');
        BrowserWait::until($pageB, '() => Alpine.$data(document.querySelector("[data-test=live-chat]")).atBottom === false', 2_000);
        // At the top the older page is asked for: there is none, so the chat's start shows above the first message.
        BrowserWait::until($pageB, '() => document.querySelector("[data-test=live-chat-start]")?.checkVisibility()', 5_000);
        $readingAt = $pageB->evaluate('() => document.querySelector("[data-test=live-chat-list]").scrollTop');
        Execution::instance()->wait(2.1);
        $pageA->locator('[data-test=live-chat-send]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("#live-chat-input").value === ""', 5_000);
        BrowserWait::until($pageB, '() => document.querySelector("[data-test=live-chat-new]")?.checkVisibility() && document.querySelector("[data-test=live-chat-new]").innerText.trim() === "1 new"', 5_000);
        expect($pageB->evaluate('() => document.querySelector("[data-test=live-chat-list]").scrollTop'))->toBe($readingAt);
        Execution::instance()->wait(0.3);
        p24Shot($pageB, 'p24-chat-new-pill-1440');
        $pageB->locator('[data-test=live-chat-new]')->click();
        BrowserWait::until($pageB, '() => { const list = document.querySelector("[data-test=live-chat-list]"); return list.scrollHeight - list.scrollTop - list.clientHeight < 2 && ! document.querySelector("[data-test=live-chat-new]").checkVisibility(); }', 3_000);
        expect(array_slice($pageB->evaluate(P24_TEXTS), -1))->toBe(['again']);

        // Bert mutes the spammer: both messages fold into one line, kept on his account, open on request.
        $pageB->locator('[data-test=live-chat-message][data-pubkey="'.$spammer->pubkey.'"] button')->first()->click();
        $pageB->locator('[data-test=live-chat-mute]:visible')->click();
        BrowserWait::until($pageB, '() => document.querySelectorAll("[data-test=live-chat-muted]").length === 1', 3_000);
        expect($pageB->evaluate(P24_TEXTS))->not->toContain('buy my coin')
            ->and($pageB->evaluate('() => document.querySelector("[data-test=live-chat-muted]").innerText'))->toContain('2 messages from muted accounts');
        // setMuted is a Livewire roundtrip after the local change: wait for its answer to land.
        $deadline = microtime(true) + 3;
        while (ChatMute::query()->where('user_id', $bert->id)->doesntExist() && microtime(true) < $deadline) {
            Execution::instance()->wait(0.05);
        }
        expect(ChatMute::query()->where('user_id', $bert->id)->pluck('muted_pubkey')->all())->toBe([$spammer->pubkey]);
        $pageB->locator('[data-test=live-chat-muted] button')->click();
        BrowserWait::until($pageB, '() => '.substr(P24_TEXTS, 6).'.includes("buy my coin")', 2_000);
        p24Shot($pageB, 'p24-chat-muted-1440');
        p24Shot($guest, 'p24-chat-guest-1440');

        foreach ([$pageA, $pageB, $guest] as $page) {
            expect(p24Errors($page))->toBe([]);
        }
    } finally {
        $relay->stop(1);
        $images->stop(1);
        @unlink($seed);
    }
});

test('the auditor\'s 63 KB message renders bounded and keeps the main thread under 200 ms', function () {
    [$images, $imageBase] = p24Images();
    $attacker = new TestSigner;
    $payload = $attacker->sign(1311, [['a', $this->address, '', 'root'], ['emoji', 'a', $imageBase.'/a.png']], str_repeat(':a:', 21_000));
    [$relay, $url, $seed] = p24Relay([]);

    try {
        $page = p24Page(null, 1440, 900);
        p24Live($page);
        // Long animation frames name the scripts that ran in them: the chat's are the receive path (the relay
        // socket in pool-*, liveChat-*, and Alpine rendering inside that same task). Frames of anything else on
        // the page are listed but not judged: after twenty contexts at host load 44 they reached 272 ms, while
        // this test alone measured 54-56 ms three times in a row.
        $page->evaluate('() => { window.__long = []; window.__frames = []; new PerformanceObserver((list) => list.getEntries().forEach((e) => window.__long.push(Math.round(e.duration)))).observe({ type: "longtask" }); new PerformanceObserver((list) => list.getEntries().forEach((e) => window.__frames.push({ duration: Math.round(e.duration), chat: (e.scripts ?? []).some((s) => /\\/build\\/assets\\/(pool|liveChat)-/.test(s.sourceURL ?? "")) }))).observe({ type: "long-animation-frame" }); }');

        // Published while the page listens: the receive path, not the first load, takes it.
        $page->evaluate('async ([url, event]) => await new Promise((resolve) => { const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["EVENT", event])); ws.onmessage = () => { ws.close(); resolve(); }; })', [$url, $payload]);
        BrowserWait::until($page, '() => { const imgs = [...document.querySelectorAll("[data-test=live-chat-emoji-img]")].filter((i) => i.checkVisibility()); return imgs.length > 0 && imgs.every((i) => i.complete); }', 10_000);
        Execution::instance()->wait(0.5);

        $measured = $page->evaluate('() => { const li = document.querySelector("[data-test=live-chat-message]").closest("li"); return { longTasks: window.__long, frames: window.__frames, nodes: li.querySelectorAll("*").length, images: [...li.querySelectorAll("[data-test=live-chat-emoji-img]")].filter((i) => i.checkVisibility()).length, chars: [...li.querySelector("[data-test=live-chat-text]").innerText].length, allNodes: document.querySelectorAll("*").length }; }');
        fwrite(STDERR, "\n[p24 payload] ".json_encode($measured)."\n");

        $chatFrames = array_values(array_filter($measured['frames'], fn (array $frame): bool => $frame['chat']));
        expect($chatFrames)->not->toBeEmpty()
            ->and(max(array_column($chatFrames, 'duration')))->toBeLessThan(200)
            ->and($measured['images'])->toBe(20)
            ->and($measured['nodes'])->toBeLessThan(400)
            ->and($measured['chars'])->toBeLessThanOrEqual(4 * 280 + 1)
            ->and(p24Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        $images->stop(1);
        @unlink($seed);
    }
});

test('on a touch screen there is no emoji button at all, and the phone switch shows chat or programme', function () {
    $player = User::factory()->create();
    TestSigner::forBrowser($player);
    [$relay, , $seed] = p24Relay([]);

    try {
        $phone = p24Page($player, 375, 667, touch: true);
        p24Live($phone);

        expect($phone->evaluate('() => matchMedia("(pointer: fine)").matches'))->toBeFalse()
            ->and($phone->evaluate('() => document.querySelector("[data-test=live-chat-form]")?.checkVisibility()'))->toBeTrue()
            // Not hidden: not built (x-if), so no emojibase load and no grid on a phone.
            ->and($phone->evaluate('() => document.querySelector("[data-test=live-chat-emoji]")'))->toBeNull()
            ->and($phone->evaluate('() => performance.getEntriesByType("resource").some((e) => /compact|messages/.test(e.name))'))->toBeFalse()
            ->and($phone->evaluate('() => document.querySelector("[data-test=live-programme]").checkVisibility()'))->toBeFalse();

        $phone->locator('[data-test=live-switch-programme]')->tap();
        BrowserWait::until($phone, '() => document.querySelector("[data-test=live-programme]").checkVisibility() && ! document.querySelector("[data-test=live-chat]").checkVisibility()', 2_000);

        // The page's poll morphs the component: the switch keeps its state.
        $phone->evaluate('() => Livewire.first().$refresh()');
        Execution::instance()->wait(1.0);
        expect($phone->evaluate('() => document.querySelector("[data-test=live-programme]").checkVisibility() && ! document.querySelector("[data-test=live-chat]").checkVisibility()'))->toBeTrue();

        $phone->locator('[data-test=live-switch-chat]')->tap();
        BrowserWait::until($phone, '() => document.querySelector("[data-test=live-chat]").checkVisibility()', 2_000);
        expect(p24Errors($phone))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

/**
 * Rects (left, top, right, bottom) of the stage, the chat and its parts, the
 * programme, the dock and the header, plus sideways overflow and whether
 * the composer and the dock meet.
 */
const P24_LAYOUT = <<<'JS'
    () => {
        const r = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const b = el.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top), Math.round(b.right), Math.round(b.bottom)]; };
        const meets = (a, b) => !!a && !!b && a[0] < b[2] && a[2] > b[0] && a[1] < b[3] && a[3] > b[1];
        const composer = r('[data-test=live-chat-form]') ?? r('[data-test=live-chat-guest]');
        const dock = r('[data-test=match-dock]') ?? r('[data-test=dock-mobile-bar]');
        const list = document.querySelector('[data-test=live-chat-list]');
        return {
            viewport: [innerWidth, innerHeight],
            header: r('body > header'),
            stage: r('[data-live-stage]'),
            chat: r('[data-test=live-chat]'),
            composer,
            programme: r('[data-test=live-programme]'),
            share: r('[data-test=live-share]'),
            switcher: r('[data-test=live-switch]'),
            dock,
            composerMeetsDock: meets(composer, dock),
            chatMeetsStage: meets(r('[data-test=live-chat]'), r('[data-live-stage]')),
            listScrolls: list ? list.scrollHeight > list.clientHeight : null,
            atBottom: list ? Math.round(list.scrollHeight - list.scrollTop - list.clientHeight) : null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

/**
 * A realistic chat: 30 messages of five people over the last hour, a bot
 * post, a zap and a custom emoji, with names from their kind 0.
 *
 * @return list<array<string, mixed>>
 */
function p24Conversation(string $address, string $imageBase, TestSigner $bot, TestSigner $lnurl): array
{
    $people = collect(['Satsflow', 'Knightrider', 'Lena Blocks', 'hodl_harry', 'Mira'])->map(fn (string $name): array => [new TestSigner, $name]);
    $now = now()->getTimestamp();
    $root = ['a', $address, 'ws://127.0.0.1', 'root'];
    $lines = [
        'gm stream', 'who is playing on the left board?', 'that knight sacrifice was wild', 'is the RL cup tonight?',
        'yes, 20:00 CET I think', 'the music today :fire:', 'first time here, how do I join the ladder?',
        'log in with Nostr and pick a game', 'e4 e5, classic', 'Bf7+ incoming', 'no way he saw that',
        'stacking sats and watching blitz, perfect evening', 'LET HIM COOK', 'can we get a rematch?', 'gg',
        'the bracket is on the TV view', 'https://esports.einundzwanzig.space/tournaments', 'thanks!', 'that was a draw by repetition, right?',
        'yes, threefold', 'next game starts soon', 'anyone from Berlin?', 'Leipzig here', 'Vienna', 'zapped the stream, keep it going',
        'the scene with the clock is great', 'what engine does the eval bar use?', 'no eval bar, pure vibes', 'haha', 'see you at the final :fire:',
    ];
    $events = [];

    foreach ($people as [$key, $name]) {
        $events[] = $key->sign(0, [], (string) json_encode(['display_name' => $name]), $now - 7200);
    }

    foreach ($lines as $i => $line) {
        [$key] = $people[$i % 5];
        $tags = str_contains($line, ':fire:') ? [$root, ['emoji', 'fire', $imageBase.'/fire.png']] : [$root];
        $events[] = $key->sign(1311, $tags, $line, $now - 3600 + $i * 110);
    }

    $events[] = $bot->sign(1311, [$root], 'The RL cup starts at 20:00. Sign up: https://esports.einundzwanzig.space/tournaments', $now - 1900);
    $recipient = explode(':', $address)[1];
    $request = $people[2][0]->sign(9734, [['a', $address], ['p', $recipient], ['amount', '2100000']], 'for the music', $now - 900);
    $events[] = $lnurl->sign(9735, [['p', $recipient], ['a', $address], ['bolt11', 'lnbc21u1pjtest'], ['description', (string) json_encode($request)]], '', $now - 890);

    return $events;
}

test('the stage stays whole, the chat fills the column and ends above the dock, nothing overflows', function (int $width, int $height, bool $player) {
    [$images, $imageBase] = p24Images();
    $bot = new TestSigner;
    $lnurl = new TestSigner;
    config(['esports.stream_bot.nsec' => $bot->secret, 'esports.stream_chat.zap_signers' => [$lnurl->pubkey]]);
    [$relay, , $seed] = p24Relay(p24Conversation($this->address, $imageBase, $bot, $lnurl));

    $user = null;
    if ($player) {
        $user = User::factory()->member()->create(['name' => 'Pia Player']);
        TestSigner::forBrowser($user);
        // A daily game on her move: the match dock floats at the bottom.
        ChessGame::factory()->daily()->create(['white_id' => $user->id]);
    }

    try {
        $page = p24Page($user, $width, $height, touch: $width < 1024);
        p24Live($page);
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=live-chat-message]").length >= 30', 10_000);
        if ($player) {
            BrowserWait::until($page, '() => [...document.querySelectorAll("[data-live-floor]")].some((el) => el.checkVisibility())', 10_000);
        }
        Execution::instance()->wait(0.6);
        $layout = $page->evaluate(P24_LAYOUT);
        fwrite(STDERR, "\n[p24 layout {$width}x{$height}".($player ? ' player' : ' guest').'] '.json_encode($layout)."\n");
        p24Shot($page, 'p24-live-'.$width.'x'.$height.($player ? '-player' : '-guest'));
        if ($width < 1024) {
            // Scrolled to, the chat and its composer sit above the floating tab bar and dock bar.
            $page->evaluate('() => document.querySelector("[data-test=live-chat]").scrollIntoView({ block: "end" })');
            Execution::instance()->wait(0.3);
            $scrolled = $page->evaluate('() => ({ composer: Math.round(document.querySelector("[data-test=live-chat-form], [data-test=live-chat-guest]").getBoundingClientRect().bottom), chatTop: Math.round(document.querySelector("[data-test=live-chat]").getBoundingClientRect().top), floor: Math.round(Math.min(innerHeight, ...[...document.querySelectorAll("[data-live-floor]")].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect().top))) })');
            fwrite(STDERR, "[p24 layout {$width}x{$height} scrolled] ".json_encode($scrolled)."\n");
            p24Shot($page, 'p24-live-'.$width.'x'.$height.($player ? '-player' : '-guest').'-chat');
            expect($scrolled['composer'])->toBeLessThanOrEqual($scrolled['floor'])
                ->and($scrolled['chatTop'])->toBeGreaterThanOrEqual(0);
        }

        [, $vh] = $layout['viewport'];
        expect($layout['overflow'])->toBeLessThanOrEqual(0)
            // The whole stage below the header, without scrolling.
            ->and($layout['stage'][1])->toBeGreaterThanOrEqual($layout['header'][3])
            ->and($layout['stage'][3])->toBeLessThanOrEqual($vh)
            // The chat opens at its newest message and scrolls inside itself.
            ->and($layout['listScrolls'])->toBeTrue()
            ->and($layout['atBottom'])->toBeLessThanOrEqual(1)
            ->and($layout['composerMeetsDock'])->toBeFalse();

        if ($width >= 1024) {
            // A column beside the stage, top-aligned with it, wholly on screen with its composer.
            expect($layout['chatMeetsStage'])->toBeFalse()
                ->and($layout['chat'][1])->toBe($layout['stage'][1])
                ->and($layout['chat'][3])->toBeLessThanOrEqual($vh)
                ->and($layout['chat'][2])->toBeLessThanOrEqual($width)
                ->and($layout['programme'])->not->toBeNull();
        } else {
            // Under the stage, behind the switch; the programme one tap away.
            expect($layout['switcher'][1])->toBeGreaterThanOrEqual($layout['stage'][3])
                ->and($layout['chat'][1])->toBeGreaterThan($layout['switcher'][3])
                ->and($layout['programme'])->toBeNull();
        }

        expect(p24Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        $images->stop(1);
        @unlink($seed);
    }
})->with([
    'phone guest' => [375, 667, false],
    'phone player' => [375, 667, true],
    '1024 guest' => [1024, 768, false],
    '1280x720 player' => [1280, 720, true],
    '1440 guest' => [1440, 900, false],
    '1440 player' => [1440, 900, true],
    '1920x1080 player' => [1920, 1080, true],
]);

/**
 * Records the chat list in every animation frame, before the browser paints
 * it: from the first frame with a message, the time (ms since navigation),
 * the row count, scrollTop and the distance from the bottom; the first such
 * frame also keeps the texts it shows. A frame that showed the list away from
 * its bottom is a frame the reader saw jump.
 */
const P24_FRAMES = <<<'JS'
    (() => {
        window.__frames = [];
        const texts = (list) => [...list.querySelectorAll('[data-test=live-chat-message]')].map((el) => el.querySelector('[data-test=live-chat-text]').innerText.trim());
        const tick = () => {
            const list = document.querySelector('[data-test=live-chat-list]');
            const rows = list ? list.querySelectorAll('[data-test=live-chat-message]').length : 0;
            if (rows > 0) {
                window.__firstRender ??= { t: Math.round(performance.now()), texts: texts(list) };
                window.__frames.push({ t: Math.round(performance.now()), rows, top: Math.round(list.scrollTop), gap: Math.round(list.scrollHeight - list.scrollTop - list.clientHeight) });
            }
            if (performance.now() < 20000) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    })();
    JS;

test('the newest page of three shuffled relays is drawn once at the bottom, older pages come above without moving the view, late and new messages find their place', function (int $width, int $height) {
    $people = [new TestSigner, new TestSigner, new TestSigner, new TestSigner];
    $now = now()->getTimestamp();
    $root = ['a', $this->address, 'ws://127.0.0.1', 'root'];
    $at = fn (int $i): int => $now - 7200 + $i * 50;
    $message = fn (int $i, string $text, int $createdAt): array => $people[$i % 4]->sign(1311, [$root], $text, $createdAt);

    // 120 messages; m101 shares its second with m100, so the id decides between them.
    $all = [];
    foreach (range(0, 119) as $i) {
        $all[$i] = $message($i, sprintf('m%03d', $i), $i === 101 ? $at(100) : $at($i));
    }
    // Only on the slow relay: one inside the first page, two newer than everything.
    $slowOnly = [$message(1, 'm105b', $at(105) + 25), $message(2, 'm120', $at(120)), $message(3, 'm121', $at(121))];

    $fast = array_values(array_filter($all, fn (array $e, int $i): bool => $i % 2 === 0 || $i >= 110, ARRAY_FILTER_USE_BOTH));
    $second = array_values(array_filter($all, fn (array $e, int $i): bool => $i % 2 === 1 || ($i >= 60 && $i <= 70), ARRAY_FILTER_USE_BOTH));
    $slow = [...array_values(array_filter($all, fn (array $e, int $i): bool => $i % 3 === 0, ARRAY_FILTER_USE_BOTH)), ...$slowOnly];

    // What the chat must show: the relays' `limit` 50 each (newest first), the union's newest 50, in time order (then id).
    $order = fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']];
    $newest = function (array $events, int $max) use ($order): array {
        $byId = [];
        foreach ($events as $event) {
            $byId[$event['id']] ??= $event;
        }
        $sorted = array_values($byId);
        usort($sorted, $order);

        return array_slice($sorted, -$max);
    };
    $answer = function (array $events): array {
        usort($events, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return array_slice($events, 0, 50);
    };
    $firstPage = $newest([...$answer($fast), ...$answer($second)], 50);
    $oldestShown = $firstPage[0];
    // The slow relay arrives after the first draw: what is newer than the oldest shown goes in, the rest waits for its page.
    $afterSlow = $newest([...$firstPage, ...array_filter($answer($slow), fn (array $e): bool => $order($e, $oldestShown) > 0)], PHP_INT_MAX);
    $everything = $newest([...$all, ...$slowOnly], PHP_INT_MAX);
    $texts = fn (array $events): array => array_column($events, 'content');

    [$relayA, $urlA, $seedA] = p24StartRelay($fast, ['shuffle' => true]);
    [$relayB, $urlB, $seedB] = p24StartRelay($second, ['shuffle' => true, 'eose_delay_ms' => 150]);
    [$relayC, $urlC, $seedC] = p24StartRelay($slow, ['shuffle' => true, 'eose_delay_ms' => 1500]);
    config(['esports.stream_chat.relays' => [$urlA, $urlB, $urlC], 'esports.profile_relays' => [$urlA]]);
    $publish = fn (Page $page, string $url, array $event) => $page->evaluate('async ([url, event]) => await new Promise((resolve) => { const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["EVENT", event])); ws.onmessage = () => { ws.close(); resolve(); }; })', [$url, $event]);
    $state = '() => Alpine.$data(document.querySelector("[data-test=live-chat]"))';
    $label = "{$width}x{$height}";

    try {
        $page = p24Page(null, $width, $height, touch: $width < 1024, initScript: P24_FRAMES);
        p24Live($page);
        // On a phone the chat sits below the stage: in view for the screenshots (its list scrolls on its own).
        $page->evaluate('() => document.querySelector("[data-test=live-chat]").scrollIntoView({ block: "end" })');
        BrowserWait::until($page, '() => '.substr(P24_TEXTS, 6).'.includes("m121")', 10_000);
        BrowserWait::until($page, '() => window.__frames.length > 0 && performance.now() - window.__firstRender.t > 2000', 10_000);

        // (1) One draw: the first frame with messages already holds the whole newest page, in order, at the bottom.
        $first = $page->evaluate('() => window.__firstRender');
        $frames = $page->evaluate('() => window.__frames.filter((f) => f.t <= window.__firstRender.t + 2000)');
        $moved = array_values(array_filter($frames, fn (array $f): bool => $f['gap'] > 1));
        fwrite(STDERR, "\n[p24 first page {$label}] ".json_encode([
            'firstRenderMs' => $first['t'],
            'framesIn2s' => count($frames),
            'framesAwayFromBottom' => count($moved),
            'rowCounts' => array_values(array_unique(array_column($frames, 'rows'))),
            'scrollTops' => array_values(array_unique(array_column($frames, 'top'))),
            'maxGap' => max(array_column($frames, 'gap')),
        ])."\n");
        expect($first['texts'])->toBe($texts($firstPage))
            ->and($first['t'])->toBeLessThan(3000)
            ->and(count($frames))->toBeGreaterThan(20)
            ->and($moved)->toBe([])
            // The slow relay's messages came inside the measured two seconds: the list grew and stayed at its bottom.
            ->and(count(array_unique(array_column($frames, 'rows'))))->toBeGreaterThan(1)
            ->and($page->evaluate(P24_TEXTS))->toBe($texts($afterSlow));

        // (4) A relay delivers an old message late: it goes to its place, not to the end; the reader stays at the bottom.
        $late = $message(0, 'late100', $at(100) + 10);
        $publish($page, $urlA, $late);
        BrowserWait::until($page, '() => '.substr(P24_TEXTS, 6).'.includes("late100")', 5_000);
        $afterLate = $newest([...$afterSlow, $late], PHP_INT_MAX);
        expect($page->evaluate(P24_TEXTS))->toBe($texts($afterLate))
            ->and($page->evaluate('() => { const l = document.querySelector("[data-test=live-chat-list]"); return Math.round(l.scrollHeight - l.scrollTop - l.clientHeight); }'))->toBeLessThanOrEqual(1)
            ->and($page->evaluate('() => document.querySelector("[data-test=live-chat-new]").checkVisibility()'))->toBeFalse();

        // (2) Older pages: scrolled to the top, the next page comes above; the row seen first stays where it was.
        $shifts = [];
        $sawLoading = false;
        for ($pageNo = 0; $pageNo < 6 && $page->evaluate($state.'.older') !== 'end'; $pageNo++) {
            $before = $page->evaluate('() => { const list = document.querySelector("[data-test=live-chat-list]"); list.scrollTop = 0; const edge = list.getBoundingClientRect().top; const row = [...list.querySelectorAll(":scope > li[data-key]")].find((li) => li.getBoundingClientRect().bottom > edge + 1); return { key: row.dataset.key, y: row.getBoundingClientRect().top, count: Alpine.$data(document.querySelector("[data-test=live-chat]")).items.length }; }');
            // The slow relay holds every page back 1.5 s: the indicator shows meanwhile.
            BrowserWait::until($page, $state.'.older === "loading"', 3_000);
            if (! $sawLoading && $page->evaluate('() => document.querySelector("[data-test=live-chat-loading-older]").checkVisibility()')) {
                $sawLoading = true;
                p24Shot($page, 'p50-chat-loading-older-'.$label);
            }
            BrowserWait::until($page, $state.'.older !== "loading"', 8_000);
            Execution::instance()->wait(0.1);
            $after = $page->evaluate('(key) => ({ y: document.querySelector(`[data-test=live-chat-list] > li[data-key="${key}"]`).getBoundingClientRect().top, count: Alpine.$data(document.querySelector("[data-test=live-chat]")).items.length, older: Alpine.$data(document.querySelector("[data-test=live-chat]")).older })', $before['key']);
            $shifts[] = ['added' => $after['count'] - $before['count'], 'dy' => round($after['y'] - $before['y'], 2), 'state' => $after['older']];
        }
        fwrite(STDERR, "[p24 older pages {$label}] ".json_encode($shifts)."\n");
        expect($sawLoading)->toBeTrue()
            ->and($page->evaluate($state.'.older'))->toBe('end')
            ->and(array_sum(array_column($shifts, 'added')))->toBeGreaterThan(0)
            ->and(max(array_map(fn (array $s): float => abs($s['dy']), $shifts)))->toBeLessThanOrEqual(2)
            ->and($page->evaluate('() => document.querySelector("[data-test=live-chat-start]").checkVisibility()'))->toBeTrue()
            ->and($page->evaluate(P24_TEXTS))->toBe($texts($newest([...$everything, $late], PHP_INT_MAX)));

        $page->evaluate('() => { document.querySelector("[data-test=live-chat-list]").scrollTop = 0; }');
        Execution::instance()->wait(0.2);
        p24Shot($page, 'p50-chat-start-'.$label);

        // (3) Scrolled up, a new message is counted in the pill and does not move the view; the pill goes to the bottom.
        $readingAt = $page->evaluate('() => document.querySelector("[data-test=live-chat-list]").scrollTop');
        $publish($page, $urlB, $message(1, 'fresh1', $now + 5));
        BrowserWait::until($page, '() => document.querySelector("[data-test=live-chat-new]")?.checkVisibility() && document.querySelector("[data-test=live-chat-new]").innerText.trim() === "1 new"', 5_000);
        expect($page->evaluate('() => document.querySelector("[data-test=live-chat-list]").scrollTop'))->toBe($readingAt);
        Execution::instance()->wait(0.3);
        p24Shot($page, 'p50-chat-pill-'.$label);
        $page->locator('[data-test=live-chat-new]')->click();
        BrowserWait::until($page, '() => { const l = document.querySelector("[data-test=live-chat-list]"); return l.scrollHeight - l.scrollTop - l.clientHeight < 2 && ! document.querySelector("[data-test=live-chat-new]").checkVisibility(); }', 3_000);

        // At the bottom, the next one is followed: no pill, still at the bottom, and it is last.
        $publish($page, $urlC, $message(2, 'fresh2', $now + 6));
        BrowserWait::until($page, '() => '.substr(P24_TEXTS, 6).'.at(-1) === "fresh2"', 5_000);
        Execution::instance()->wait(0.2);
        expect($page->evaluate('() => { const l = document.querySelector("[data-test=live-chat-list]"); return Math.round(l.scrollHeight - l.scrollTop - l.clientHeight); }'))->toBeLessThanOrEqual(1)
            ->and($page->evaluate('() => document.querySelector("[data-test=live-chat-new]").checkVisibility()'))->toBeFalse()
            ->and(p24Errors($page))->toBe([]);
    } finally {
        foreach ([[$relayA, $seedA], [$relayB, $seedB], [$relayC, $seedC]] as [$relay, $seed]) {
            $relay->stop(1);
            @unlink($seed);
        }
    }
})->with([
    'phone' => [375, 667],
    'desktop' => [1440, 900],
]);

test('the collector catches a thrown error and a broken image (positive control)', function () {
    [$relay, , $seed] = p24Relay([]);

    try {
        $page = p24Page(null, 1440, 900);
        $page->evaluate('() => { setTimeout(() => { throw new Error("p24 positive control"); }); const img = new Image(); img.src = "/__p24/missing.png"; document.body.append(img); }');
        BrowserWait::until($page, '() => window.__errors.length >= 2', 3_000);

        $errors = p24Errors($page);
        expect(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'p24 positive control')))->toBeTrue()
            ->and(collect($errors)->contains(fn (string $e): bool => str_contains($e, '/__p24/missing.png')))->toBeTrue();
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

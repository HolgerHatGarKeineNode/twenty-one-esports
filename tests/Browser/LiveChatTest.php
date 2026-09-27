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
    $seed = (string) tempnam(sys_get_temp_dir(), 'p24-seed');
    file_put_contents($seed, json_encode($events));
    $port = p24FreePort();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);
    WaitForPort::open('127.0.0.1', $port);
    $url = 'ws://127.0.0.1:'.$port;
    config(['esports.stream_chat.relays' => [$url], 'esports.profile_relays' => [$url]]);

    return [$relay, $url, $seed];
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

function p24Page(?User $user, int $width, int $height, bool $touch = false): Page
{
    $start = $user ? BrowserLogin::url($user) : BrowserLogin::LANDING;
    // The emoji host's certificate is self-signed; the plugin's launch option does not reach the context, this does.
    $options = ['ignoreHTTPSErrors' => true];
    $page = $touch ? visit($start, $options)->on()->mobile()->page() : visit($start, $options)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($user) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
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
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-text] a[href]")?.getAttribute("href")'))->toBe('https://esports.example/ladder')
            // A guest reads and gets a way in, no form, no emoji button.
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-guest] a")?.getAttribute("href")'))->toEndWith('/login?then=live')
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-form]")'))->toBeNull()
            ->and($guest->evaluate('() => document.querySelector("[data-test=live-chat-emoji]")'))->toBeNull();

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
        Execution::instance()->wait(2.1);
        $pageA->locator('[data-test=live-chat-send]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("#live-chat-input").value === ""', 5_000);
        BrowserWait::until($pageB, '() => document.querySelector("[data-test=live-chat-new]")?.checkVisibility() && document.querySelector("[data-test=live-chat-new]").innerText.trim() === "1 new"', 5_000);
        expect($pageB->evaluate('() => document.querySelector("[data-test=live-chat-list]").scrollTop'))->toBe(0);
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
    $request = $people[2][0]->sign(9734, [['a', $address], ['amount', '2100000']], 'for the music', $now - 900);
    $events[] = $lnurl->sign(9735, [['a', $address], ['bolt11', 'lnbc21u1pjtest'], ['description', (string) json_encode($request)]], '', $now - 890);

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

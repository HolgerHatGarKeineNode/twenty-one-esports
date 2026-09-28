<?php

use App\Models\Rating;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Execution;
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
| The global chat of a game (P21, NIP "Game channels")
|--------------------------------------------------------------------------
|
| A NIP-28 channel with NIP-88 polls on the Rocket League page, over a real
| websocket to the in-memory relay (tests/Support/MiniRelay.php), never a
| real relay. Two players in two contexts sign with their own keys through
| TestSigner::browserStub; a guest reads along. Every page carries
| BrowserConsole's collector, proved by a positive control.
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

    $this->creator = new TestSigner;
    config(['esports.game_chat.creator' => $this->creator->pubkey]);
    $this->channel = (string) GameChannels::channelId('rocket-league');
});

/**
 * @param  list<array<string, mixed>>  $events
 * @return array{0: InvokedProcess, 1: string, 2: string}
 */
function p21Relay(array $events): array
{
    $seed = (string) tempnam(sys_get_temp_dir(), 'p21-seed');
    file_put_contents($seed, json_encode($events));
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);
    WaitForPort::open('127.0.0.1', $port);
    $url = 'ws://127.0.0.1:'.$port;
    config(['esports.chat.relays' => [$url], 'esports.profile_relays' => []]);

    return [$relay, $url, $seed];
}

function p21Page(?User $user, string $path, int $width = 1440, int $height = 900): Page
{
    $page = visit($user ? BrowserLogin::url($user) : BrowserLogin::LANDING, ['ignoreHTTPSErrors' => true])->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($user) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine !== undefined && Alpine.$data(document.querySelector("[data-test=game-chat]"))?.status === "live"', 10_000);

    return $page;
}

function p21Errors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

function p21Shot(Page $page, string $name, ?string $element = null): void
{
    $dir = getenv('P21_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot(false, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Texts of the visible chat messages, in list order. */
const P21_TEXTS = '() => [...document.querySelectorAll("[data-test=game-chat-message]")].filter((el) => el.checkVisibility()).map((el) => el.querySelector("[data-test=game-chat-text]").innerText.trim())';

/** The first poll card in the list: its question, shares and total line. */
const P21_POLL = '() => { const card = document.querySelector("[data-test=game-chat-list] [data-test=game-chat-poll]"); if (!card) return null; return { question: card.querySelector("[data-test=game-chat-poll-question-text]").innerText, shares: [...card.querySelectorAll("[data-test=game-chat-poll-share]")].map((s) => s.innerText), mine: [...card.querySelectorAll("[data-test=game-chat-poll-option]")].map((b) => b.getAttribute("aria-pressed")), total: card.querySelector("[data-test=game-chat-poll-total]").innerText, uncounted: card.querySelector("[data-test=game-chat-poll-uncounted]")?.checkVisibility() ? card.querySelector("[data-test=game-chat-poll-uncounted]").innerText : null }; }';

test('two players talk in the Rocket League channel, one starts a poll, both vote, the latest vote counts and outsiders do not', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    $carlKey = TestSigner::forBrowser($carl);
    // Who counts (NIP "Game channels"): Anna and Bert have a result in the league; Carl only has an account.
    // (Not is_member: the login refreshes membership from the association's API, faked empty here.)
    foreach ([$anna, $bert] as $player) {
        Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
    }
    $outsider = new TestSigner;
    $spammer = new TestSigner;
    $now = now()->getTimestamp();
    $root = ['e', $this->channel, '', 'root'];

    [$relay, $url, $seed] = p21Relay([
        $outsider->sign(42, [$root], 'hi from another client', $now - 300),
        // Another game's channel never shows here.
        $outsider->sign(42, [['e', (string) GameChannels::channelId('chess'), '', 'root']], 'wrong channel', $now - 290),
        // An outsider's poll is not shown, and neither is the poll of an account without a result.
        $outsider->sign(1068, [$root, ['option', 'a', 'Yes'], ['option', 'b', 'No'], ['polltype', 'singlechoice'], ['endsAt', (string) ($now + 3600)]], 'Free sats?', $now - 280),
        $carlKey->sign(1068, [$root, ['option', 'a', 'Yes'], ['option', 'b', 'No'], ['polltype', 'singlechoice'], ['endsAt', (string) ($now + 3600)]], 'Fresh account poll?', $now - 275),
        // The league muted this pubkey (kind 44 by the channel's creator): gone for everyone.
        $spammer->sign(42, [$root], 'buy my coin', $now - 270),
        $this->creator->sign(44, [['p', $spammer->pubkey]], '', $now - 260),
        // A kind 44 of anybody else changes nothing.
        $spammer->sign(44, [['p', $outsider->pubkey]], '', $now - 250),
    ]);

    try {
        $pageA = p21Page($anna, '/games/rocket-league');
        $pageB = p21Page($bert, '/games/rocket-league');
        $guest = p21Page(null, '/games/rocket-league');

        // Positive control: the collector catches a thrown error and a 500; then it starts empty.
        $pageA->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
        BrowserWait::until($pageA, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
        $pageA->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

        BrowserWait::until($guest, '() => document.querySelector("[data-test=game-chat-outside]")?.checkVisibility()', 5_000);
        expect($guest->evaluate(P21_TEXTS))->toBe(['hi from another client'])
            ->and($guest->evaluate('() => document.querySelectorAll("[data-test=game-chat-poll]").length'))->toBe(0)
            ->and($guest->evaluate('() => document.querySelector("[data-test=game-chat-form]")'))->toBeNull()
            ->and($guest->evaluate('() => document.querySelector("[data-test=game-chat-guest]").innerText'))->toContain('Log in to chat');

        // Anna writes; Bert reads it under her league name.
        $pageA->locator('#game-chat-input')->fill('gg everyone, rematch at 21:00?');
        $pageA->locator('[data-test=game-chat-send]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("#game-chat-input").value === ""', 5_000);
        BrowserWait::until($pageB, '() => [...document.querySelectorAll("[data-test=game-chat-message]")].some((el) => el.innerText.includes('.json_encode($anna->displayName()).') && el.innerText.includes("rematch at 21:00"))', 10_000);

        // Anna starts a poll with three answers, a moment later (the list sorts by the second, then by id, as NIP-01 does).
        Execution::instance()->wait(1.1);
        $pageA->locator('[data-test=game-chat-poll-open]')->click();
        $pageA->locator('[data-test=game-chat-poll-question]')->fill('Which mode for Friday?');
        $pageA->locator('[data-test=game-chat-poll-add]')->click();
        $answers = $pageA->locator('[data-test=game-chat-poll-answer]');
        $answers->nth(0)->fill('1v1');
        $answers->nth(1)->fill('2v2');
        $answers->nth(2)->fill('3v3');
        p21Shot($pageA, 'p21-poll-form-1440', '[data-test=game-chat]');
        $pageA->locator('[data-test=game-chat-poll-send]')->click();

        foreach ([$pageA, $pageB, $guest] as $page) {
            BrowserWait::until($page, '() => document.querySelector("[data-test=game-chat-list] [data-test=game-chat-poll-question-text]")?.innerText === "Which mode for Friday?"', 10_000);
        }
        // From lg it is pinned beside the conversation too.
        expect($pageB->evaluate('() => document.querySelectorAll("[data-test=game-chat-polls] [data-test=game-chat-poll]").length'))->toBe(1);

        // Bert votes 2v2, Anna 1v1; then Bert changes to 1v1: his latest vote counts, once.
        $vote = fn (Page $page, int $option) => $page->locator('[data-test=game-chat-list] [data-test=game-chat-poll-option]')->nth($option)->click();
        $vote($pageB, 1);
        BrowserWait::until($pageA, '() => ('.P21_POLL.')()?.total === "1 vote"', 10_000);
        $vote($pageA, 0);
        BrowserWait::until($pageB, '() => ('.P21_POLL.')()?.shares.join() === "50%,50%,0%"', 10_000);
        Execution::instance()->wait(1.1);
        $vote($pageB, 0);
        BrowserWait::until($pageA, '() => ('.P21_POLL.')()?.shares.join() === "100%,0%,0%"', 10_000);

        // An outsider votes too: said, not counted.
        $pollId = $pageA->evaluate('() => document.querySelector("[data-test=game-chat-list] [data-test=game-chat-poll]").dataset.poll');
        $outsiderVote = $outsider->sign(1018, [['e', $pollId], ['response', $pageA->evaluate('() => document.querySelector("[data-test=game-chat-list] [data-test=game-chat-poll-option]").dataset.option')]], '', now()->getTimestamp());
        $pageA->evaluate('async ([url, event]) => await new Promise((resolve) => { const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["EVENT", event])); ws.onmessage = () => { ws.close(); resolve(); }; })', [$url, $outsiderVote]);
        BrowserWait::until($guest, '() => ('.P21_POLL.')()?.uncounted !== null', 10_000);

        expect($guest->evaluate(P21_POLL))->toBe(['question' => 'Which mode for Friday?', 'shares' => ['100%', '0%', '0%'], 'mine' => ['false', 'false', 'false'], 'total' => '2 votes', 'uncounted' => '1 vote not counted: no result in the league'])
            ->and($pageB->evaluate(P21_POLL)['mine'])->toBe(['true', 'false', 'false'])
            ->and($guest->evaluate(P21_TEXTS))->toBe(['hi from another client', 'gg everyone, rematch at 21:00?']);
        p21Shot($pageB, 'p21-channel-1440', '[data-test=game-chat]');

        // On the relay: the poll scoped to the channel, single choice, with an end; Bert's two votes, the newest for 1v1.
        $req = 'async ([url, filter]) => await new Promise((resolve) => { const out = []; const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["REQ", "p21", filter])); ws.onmessage = (m) => { const f = JSON.parse(m.data); if (f[0] === "EVENT") out.push(f[2]); if (f[0] === "EOSE") { ws.close(); resolve(out); } }; })';
        $polls = $guest->evaluate($req, [$url, ['kinds' => [1068], 'authors' => [$anna->pubkey]]]);
        $votes = $guest->evaluate($req, [$url, ['kinds' => [1018], 'authors' => [$bert->pubkey]]]);
        $messages = $guest->evaluate($req, [$url, ['kinds' => [42], 'authors' => [$anna->pubkey]]]);
        $tags = $polls[0]['tags'];
        usort($votes, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        expect($tags[0])->toBe(['e', $this->channel, $url, 'root'])
            ->and(array_values(array_filter($tags, fn (array $tag): bool => $tag[0] === 'option')))->toHaveCount(3)
            ->and(collect($tags)->firstWhere(0, 'polltype'))->toBe(['polltype', 'singlechoice'])
            ->and((int) collect($tags)->firstWhere(0, 'endsAt')[1] - $polls[0]['created_at'])->toBe(86400)
            ->and(collect($tags)->firstWhere(0, 'relay'))->toBe(['relay', $url])
            ->and(collect($tags)->where(0, 't')->all())->toBe([])
            ->and($votes)->toHaveCount(2)
            ->and($votes[0]['tags'][1])->toBe(['response', collect($tags)->where(0, 'option')->values()[0][1]])
            ->and($messages[0]['tags'])->toBe([['e', $this->channel, $url, 'root']])
            // Anna wrote before she asked: the list keeps that order, for everyone.
            ->and($messages[0]['created_at'])->toBeLessThan($polls[0]['created_at'])
            ->and($guest->evaluate('() => [...document.querySelectorAll("[data-test=game-chat-list] > li[data-type]")].map((li) => li.dataset.type)'))->toBe(['message', 'message', 'poll']);

        // Phone: the chat and its composer inside the screen, nothing sideways.
        $pageB->setViewportSize(375, 812);
        $pageB->evaluate('() => document.querySelector("[data-test=game-chat]").scrollIntoView()');
        Execution::instance()->wait(0.3);
        $narrow = $pageB->evaluate('() => { const box = (s) => document.querySelector(s).getBoundingClientRect(); const chat = box("[data-test=game-chat]"); const form = box("[data-test=game-chat-form]"); return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, chatInside: chat.left >= 0 && chat.right <= innerWidth, formInside: form.left >= chat.left && form.right <= chat.right, side: document.querySelector("[data-test=game-chat-polls]").checkVisibility(), poll: document.querySelector("[data-test=game-chat-list] [data-test=game-chat-poll]").getBoundingClientRect().right <= chat.right }; }');
        p21Shot($pageB, 'p21-channel-375', '[data-test=game-chat]');

        expect($narrow)->toBe(['overflow' => 0, 'chatInside' => true, 'formInside' => true, 'side' => false, 'poll' => true]);

        foreach ([$pageA, $pageB, $guest] as $page) {
            expect(p21Errors($page))->toBe([]);
        }
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

test('the chess channel sits under the lobby, and the invite keeps its place in the first screen', function () {
    $player = User::factory()->create();
    TestSigner::forBrowser($player);
    [$relay, , $seed] = p21Relay([]);

    try {
        $page = p21Page($player, '/chess', 375, 812);
        $layout = $page->evaluate('() => { const box = (s) => document.querySelector(s)?.getBoundingClientRect(); return { chatBelowPlay: box("[data-test=game-chat]").top > box("[data-test=lobby-daily]").top, weeklyBelowChat: box("[data-test=game-chat]").bottom <= (document.querySelector("[aria-labelledby=lobby-weekly-h]")?.getBoundingClientRect().top ?? Infinity), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');
        $page->setViewportSize(1440, 900);
        Execution::instance()->wait(0.3);
        $page->evaluate('() => document.querySelector("[data-test=game-chat]").scrollIntoView({ block: "center" })');
        Execution::instance()->wait(0.3);
        p21Shot($page, 'p21-chess-1440', '[data-test=game-chat]');

        expect($layout)->toBe(['chatBelowPlay' => true, 'weeklyBelowChat' => true, 'overflow' => 0])
            ->and($page->evaluate('() => document.querySelector("[data-test=game-chat-polls-empty]").checkVisibility()'))->toBeTrue()
            // A player without a result yet: told why, and no poll form offered.
            ->and($page->evaluate('() => document.querySelector("[data-test=game-chat-polls-locked]").checkVisibility()'))->toBeTrue()
            ->and($page->evaluate('() => document.querySelector("[data-test=game-chat-poll-open]").checkVisibility()'))->toBeFalse()
            ->and(p21Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

test('the auditor\'s 63 KB message renders bounded here too, and keeps the main thread under 200 ms', function () {
    $dir = sys_get_temp_dir().'/p21-img-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($dir);
    $images = Process::path(base_path())->start(['php', 'tests/Support/emoji-image-server.php', $dir]);
    $deadline = microtime(true) + 10;
    while (trim($images->latestOutput()) === '' && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $imagePort = (int) trim($images->output());
    WaitForPort::open('127.0.0.1', $imagePort);
    $attacker = new TestSigner;
    $payload = $attacker->sign(42, [['e', $this->channel, '', 'root'], ['emoji', 'a', 'https://127.0.0.1:'.$imagePort.'/a.png']], str_repeat(':a:', 21_000));
    [$relay, $url, $seed] = p21Relay([]);

    try {
        $page = p21Page(null, '/games/rocket-league');
        $page->evaluate('() => { window.__frames = []; new PerformanceObserver((list) => list.getEntries().forEach((e) => window.__frames.push({ duration: Math.round(e.duration), chat: (e.scripts ?? []).some((s) => /\\/build\\/assets\\/(pool|gameChannel)-/.test(s.sourceURL ?? "")) }))).observe({ type: "long-animation-frame" }); }');

        $page->evaluate('async ([url, event]) => await new Promise((resolve) => { const ws = new WebSocket(url); ws.onopen = () => ws.send(JSON.stringify(["EVENT", event])); ws.onmessage = () => { ws.close(); resolve(); }; })', [$url, $payload]);
        BrowserWait::until($page, '() => { const imgs = [...document.querySelectorAll("[data-test=game-chat] [data-test=live-chat-emoji-img]")].filter((i) => i.checkVisibility()); return imgs.length > 0 && imgs.every((i) => i.complete); }', 10_000);
        Execution::instance()->wait(0.5);

        $measured = $page->evaluate('() => { const li = document.querySelector("[data-test=game-chat-message]").closest("li"); return { frames: window.__frames, nodes: li.querySelectorAll("*").length, images: [...li.querySelectorAll("[data-test=live-chat-emoji-img]")].filter((i) => i.checkVisibility()).length, chars: [...li.querySelector("[data-test=game-chat-text]").innerText].length }; }');
        fwrite(STDERR, "\n[p21 payload] ".json_encode($measured)."\n");

        $chatFrames = array_values(array_filter($measured['frames'], fn (array $frame): bool => $frame['chat']));
        expect(max([0, ...array_column($chatFrames, 'duration')]))->toBeLessThan(200)
            ->and($measured['images'])->toBe(20)
            ->and($measured['nodes'])->toBeLessThan(400)
            ->and($measured['chars'])->toBeLessThanOrEqual(4 * 280 + 1)
            ->and(p21Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        $images->stop(1);
        @unlink($seed);
        File::deleteDirectory($dir);
    }
});

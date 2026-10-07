<?php

use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\PubkeyModeration;
use App\Models\Rating;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Nostr\NostrKeys;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
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
        // In the side column (from xl) it is pinned above the conversation too.
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

        // Phone: the chat a bar under the head (2026-10-03), opened; then it and its composer inside the screen, nothing sideways.
        $pageB->setViewportSize(375, 812);
        Execution::instance()->wait(0.3);
        $pageB->locator('[data-test=game-chat-toggle]')->click();
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

/*
| Placement (2026-10-03, user: "Kann der Chat bitte weiter oben hin? Ganz da
| unten geht er verloren. Er kann auch gerne auf größerem Desktop an der Seite
| sein."). At 375 the chat is one bar right under the head, after the primary
| action (which stays above the tab bar), with the newest message and the
| unread count; a tap opens it in place. From 1280 it is a side column right
| of the content: sticky under the header while the page scrolls, ending
| above the dock (a daily game puts the dock on screen), open, nothing
| sideways. Measured on /chess and a series page at 375, 1440 and 1920.
*/

/** The bar and its parts below xl, the primary action, what sits between them, and the tab bar's top. */
const P21_PHONE = <<<'JS'
    (primary) => {
        // `y`: the top on the page, whatever a click scrolled.
        const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), y: Math.round(r.top + scrollY), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) }; };
        const chat = document.querySelector('[data-test=game-chat]');
        const action = document.querySelector(primary);
        const bar = document.querySelector('[data-test=tab-bar]');
        const toggle = chat.querySelector('[data-test=game-chat-toggle]');
        // Everything visible that starts between the action's bottom and the chat's top, by its data-test.
        const between = [...document.querySelectorAll('#content [data-test]')].filter((el) => el.checkVisibility() && ! el.contains(chat) && ! chat.contains(el) && ! el.contains(action) && ! action.contains(el))
            .filter((el) => { const r = el.getBoundingClientRect(); return r.height > 0 && r.top >= action.getBoundingClientRect().bottom - 0.5 && r.bottom <= chat.getBoundingClientRect().top + 0.5; })
            .filter((el, _, all) => ! all.some((other) => other !== el && other.contains(el))).map((el) => el.dataset.test);
        return {
            chat: box(chat), action: box(action), floor: bar && bar.checkVisibility() ? Math.round(bar.getBoundingClientRect().top) : innerHeight,
            toggle: box(toggle), expanded: toggle.getAttribute('aria-expanded'), toggleName: toggle.getAttribute('aria-label'),
            body: chat.querySelector('[data-test=game-chat-body]').checkVisibility(),
            preview: chat.querySelector('[data-test=game-chat-preview]').checkVisibility() ? chat.querySelector('[data-test=game-chat-preview]').innerText.trim() : null,
            unread: chat.querySelector('[data-test=game-chat-unread]').checkVisibility() ? chat.querySelector('[data-test=game-chat-unread]').innerText.trim() : null,
            messages: [...chat.querySelectorAll('[data-test=game-chat-message]')].filter((el) => el.checkVisibility()).length,
            form: chat.querySelector('[data-test=game-chat-form]')?.checkVisibility() ? box(chat.querySelector('[data-test=game-chat-form]')) : null,
            between, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, vw: innerWidth,
        };
    }
    JS;

/** The side column from xl: its box, where the content ends, the header's bottom, the dock's top, and how it scrolls. */
const P21_RAIL = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const chat = document.querySelector('[data-test=game-chat]');
        const host = chat.closest('.chat-rail-host');
        const rail = chat.closest('.chat-rail');
        // The content: every visible leaf of the host's subtree outside the column (a wrapper's box includes its padding).
        const content = [...host.querySelectorAll('*')].filter((el) => ! rail.contains(el) && el.children.length === 0 && el.checkVisibility() && el.getBoundingClientRect().width > 0);
        const dock = [document.querySelector('[data-test=match-dock]'), document.querySelector('[data-test=dock-cup]')].filter((el) => el && el.checkVisibility());
        return {
            chat: box(chat), contentRight: Math.round(Math.max(...content.map((el) => el.getBoundingClientRect().right))),
            header: Math.round(document.querySelector('header.shell-header, [data-test=shell-header]')?.getBoundingClientRect().bottom ?? 0),
            dockTop: dock.length ? Math.round(Math.min(...dock.map((el) => el.getBoundingClientRect().top))) : null,
            dockLeft: dock.length ? Math.round(Math.min(...dock.map((el) => el.getBoundingClientRect().left))) : null,
            rail: box(rail),
            // Every ancestor that clips or scrolls: one between the column and the page makes sticky a no-op.
            scrollers: (() => { const out = []; for (let el = chat.parentElement; el; el = el.parentElement) { const s = getComputedStyle(el); if (s.overflowX !== 'visible' || s.overflowY !== 'visible') out.push(el.tagName + '.' + String(el.className).slice(0, 40) + ' ' + s.overflowX + '/' + s.overflowY); } return out; })(),
            position: getComputedStyle(chat).position, toggle: chat.querySelector('[data-test=game-chat-toggle]').checkVisibility(),
            list: box(chat.querySelector('[data-test=game-chat-list]')), messages: [...chat.querySelectorAll('[data-test=game-chat-message]')].filter((el) => el.checkVisibility()).length,
            composer: chat.querySelector('[data-test=game-chat-form]')?.checkVisibility() ? box(chat.querySelector('[data-test=game-chat-form]')) : null,
            polls: chat.querySelector('[data-test=game-chat-polls]').checkVisibility(),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, vw: innerWidth, vh: innerHeight, scrollY: Math.round(scrollY),
        };
    }
    JS;

test('the chat sits right under the head at 375, a bar opened in place, and in a sticky side column above the dock from 1280', function (string $path, string $game, string $primary, array $between) {
    $player = User::factory()->create();
    TestSigner::forBrowser($player);
    // An open daily game: the dock is on screen, on the right at the bottom where the column ends.
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);
    $other = new TestSigner;
    $root = ['e', (string) GameChannels::channelId($game), '', 'root'];
    $now = now()->getTimestamp();
    [$relay, , $seed] = p21Relay([
        $other->sign(42, [$root], 'yesterday morning', $now - 2 * 86400),
        $other->sign(42, [$root], 'who is up for a game?', $now - 300),
        $other->sign(42, [$root], 'gg, rematch at 21:00?', $now - 120),
        $other->sign(42, [$root], 'see you there', $now - 60),
    ]);

    try {
        $page = p21Page($player, $path, 375, 667);
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=game-chat]")).items.length === 4 && document.fonts.status === "loaded"', 10_000);
        Execution::instance()->wait(0.2);
        $closed = $page->evaluate(P21_PHONE, $primary);
        p21Shot($page, "p21-place-{$game}-375-closed");
        $page->locator('[data-test=game-chat-toggle]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=game-chat-body]").checkVisibility()', 5_000);
        Execution::instance()->wait(0.3);
        $open = $page->evaluate(P21_PHONE, $primary);
        $page->evaluate('() => document.querySelector("[data-test=game-chat]").scrollIntoView({ block: "start" })');
        p21Shot($page, "p21-place-{$game}-375-open");

        $rails = [];
        foreach ([[1440, 900], [1920, 1080]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->evaluate('() => scrollTo(0, 0)');
            Execution::instance()->wait(0.4);
            $top = $page->evaluate(P21_RAIL);
            p21Shot($page, "p21-place-{$game}-{$width}");
            // Down the page, halfway along the column's travel (its box minus the chat): the chat holds its place under the header.
            $page->evaluate('() => { const chat = document.querySelector("[data-test=game-chat]"); const travel = chat.closest(".chat-rail").getBoundingClientRect().height - chat.getBoundingClientRect().height; scrollTo(0, Math.max(0, Math.round(travel / 2)) + 8); }');
            Execution::instance()->wait(0.4);
            $rails[$width] = ['top' => $top, 'scrolled' => $page->evaluate(P21_RAIL)];
            p21Shot($page, "p21-place-{$game}-{$width}-scrolled");
        }

        fwrite(STDERR, "\n[p21 place] {$game}: ".json_encode(compact('closed', 'open', 'rails'))."\n");

        // 375, closed: the primary action above the tab bar, the bar right after it, small, with the newest message and the count.
        expect($closed['action']['bottom'])->toBeLessThanOrEqual($closed['floor'])
            ->and($closed['chat']['top'])->toBeGreaterThanOrEqual($closed['action']['bottom'])
            ->and($closed['between'])->toBe($between)
            ->and($closed['chat']['height'])->toBeLessThanOrEqual(96)
            ->and($closed['toggle']['height'])->toBeGreaterThanOrEqual(44)
            ->and($closed['expanded'])->toBe('false')
            ->and($closed['body'])->toBeFalse()
            ->and($closed['preview'])->toContain('see you there')
            // The last 24 hours of a first visit: three, not the message of two days ago.
            ->and($closed['unread'])->toBe('3')
            ->and($closed['toggleName'])->toBe('Open the chat, 3 new')
            ->and($closed['overflow'])->toBe(0)
            // Opened in place: the same top, the messages, the composer inside the screen, the count gone.
            ->and($open['chat']['y'])->toBe($closed['chat']['y'])
            ->and($open['expanded'])->toBe('true')
            ->and($open['body'])->toBeTrue()
            ->and($open['messages'])->toBe(4)
            ->and($open['unread'])->toBeNull()
            ->and($open['form']['left'] ?? -1)->toBeGreaterThanOrEqual($open['chat']['left'])
            ->and($open['form']['right'] ?? PHP_INT_MAX)->toBeLessThanOrEqual($open['chat']['right'])
            ->and($open['chat']['right'])->toBeLessThanOrEqual($open['vw'])
            ->and($open['overflow'])->toBe(0);

        foreach ($rails as $width => ['top' => $top, 'scrolled' => $scrolled]) {
            foreach (['top' => $top, 'scrolled' => $scrolled] as $where => $m) {
                $label = "{$game} {$width} {$where}";
                expect($m['position'])->toBe('sticky', $label)
                    ->and($m['toggle'])->toBeFalse($label)
                    // Beside the content, never over it, inside the window.
                    ->and($m['chat']['left'])->toBeGreaterThanOrEqual($m['contentRight'] + 24, $label)
                    ->and($m['chat']['right'])->toBeLessThanOrEqual($m['vw'] - 24, $label)
                    ->and($m['chat']['width'])->toBe($width >= 1536 ? 400 : 360, $label)
                    // Under the header; scrolled on a page no longer than the chat it goes along with the content.
                    ->and($where === 'top' || $top['rail']['height'] > $top['chat']['height'] + 16 ? $m['chat']['top'] >= $m['header'] : true)->toBeTrue($label)
                    // Ends above the dock where the dock reaches into the column, and above the window's edge.
                    ->and($m['dockTop'])->not->toBeNull($label)
                    ->and($m['dockLeft'] < $m['chat']['right'] ? $m['chat']['bottom'] <= $m['dockTop'] : true)->toBeTrue($label.': '.json_encode($m))
                    ->and($m['chat']['bottom'])->toBeLessThanOrEqual($m['vh'], $label)
                    ->and($m['messages'])->toBe(4, $label)
                    ->and($m['composer'])->not->toBeNull($label)
                    ->and($m['composer']['bottom'] ?? PHP_INT_MAX)->toBeLessThanOrEqual($m['chat']['bottom'], $label)
                    ->and($m['list']['height'])->toBeGreaterThanOrEqual(300, $label)
                    // No open poll: no empty poll block on top of the column.
                    ->and($m['polls'])->toBeFalse($label)
                    ->and($m['overflow'])->toBe(0, $label);
            }

            // Sticky: where the page is longer than the chat, scrolled down it stands exactly where it started, under the
            // header; where it is not (chess at 1440 x 900 scrolls 34 px), the chat is exactly as tall as the content beside it.
            $travels = $top['rail']['height'] > $top['chat']['height'] + 16;
            expect($travels ? $scrolled['scrollY'] > 0 && abs($scrolled['chat']['top'] - $top['chat']['top']) <= 1 : $top['chat']['height'] === $top['rail']['height'])
                ->toBeTrue("{$game} {$width}: ".json_encode(['top' => $top, 'scrolled' => $scrolled]));
        }

        expect(p21Errors($page))->toBe([]);
        // Positive control: the collector on this page catches a thrown error.
        $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw"))', 5_000);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
})->with([
    'chess' => ['/chess', 'chess', '[data-test=play]', []],
    // A series page: the invite keeps its first-screen place between the head (with "Play now") and the chat.
    'rocket league' => ['/games/rocket-league', 'rocket-league', '[data-test=game-cta]', ['game-page-challenge', 'game-pulse', 'game-start', 'invite-module']],
]);

test('TMNF and Blockfill have their chat too: a bar after the hero or the game at 375, the side column at 1440', function (string $game, string $path, string $after) {
    $game === 'tmnf' ? tmnfOn() : BlockfillOn::play();
    $player = User::factory()->create();
    TestSigner::forBrowser($player);
    $other = new TestSigner;
    [$relay, , $seed] = p21Relay([$other->sign(42, [['e', (string) GameChannels::channelId($game), '', 'root']], 'anyone on the server tonight?', now()->getTimestamp() - 60)]);

    try {
        $page = p21Page($player, $path, 375, 812);
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=game-chat]")).items.length === 1 && document.fonts.status === "loaded"', 10_000);
        $phone = $page->evaluate('(after) => { const chat = document.querySelector("[data-test=game-chat]"); const rail = chat.closest(".chat-rail"); return { after: rail.previousElementSibling?.matches(after) || rail.previousElementSibling?.querySelector(after) !== null, height: Math.round(chat.getBoundingClientRect().height), preview: chat.querySelector("[data-test=game-chat-preview]").innerText, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }', $after);
        $page->evaluate('() => document.querySelector("[data-test=game-chat]").scrollIntoView({ block: "center" })');
        p21Shot($page, "p21-place-{$game}-375");

        $page->setViewportSize(1440, 900);
        $page->evaluate('() => scrollTo(0, 0)');
        Execution::instance()->wait(0.4);
        $desk = $page->evaluate(P21_RAIL);
        p21Shot($page, "p21-place-{$game}-1440");
        fwrite(STDERR, "\n[p21 place] {$game}: ".json_encode(compact('phone', 'desk'))."\n");

        expect($phone)->toBe(['after' => true, 'height' => $phone['height'], 'preview' => $phone['preview'], 'overflow' => 0])
            ->and($phone['height'])->toBeLessThanOrEqual(96)
            ->and($phone['preview'])->toContain('anyone on the server tonight?')
            ->and($desk['position'])->toBe('sticky')
            ->and($desk['chat']['left'])->toBeGreaterThanOrEqual($desk['contentRight'] + 24)
            ->and($desk['chat']['width'])->toBe(360)
            ->and($desk['chat']['top'])->toBeGreaterThanOrEqual($desk['header'])
            ->and($desk['chat']['bottom'])->toBeLessThanOrEqual($desk['vh'])
            ->and($desk['messages'])->toBe(1)
            ->and($desk['overflow'])->toBe(0)
            ->and(p21Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
})->with([
    'tmnf' => ['tmnf', '/scores/tmnf', '[data-test=tmnf-hero]'],
    'blockfill' => ['blockfill', '/blockfill', '[data-test=stacker]'],
]);

/*
| Site-wide mute (user, 2026-10-05: "globales Muten für alle im Chat"): an
| admin opens the menu on a spammer's message, picks "Mute for everyone",
| gives a reason and confirms; the message leaves the admin's list at once
| and a second logged-in viewer's list by push, without a reload. The
| personal "Mute … for me" stays beside it, worded apart. The menu with its
| reason form fits at 390 and 1440.
*/
test('an admin mutes a message author for everyone: the message leaves a second viewer\'s chat without a reload', function () {
    [$admin, $viewer] = User::factory()->count(2)->create();
    TestSigner::forBrowser($admin);
    TestSigner::forBrowser($viewer);
    // After forBrowser(): it gives the account its real key.
    Admin::query()->create(['pubkey' => $admin->refresh()->pubkey]);
    $spammer = new TestSigner;
    $friend = new TestSigner;
    $root = ['e', $this->channel, '', 'root'];
    $now = now()->getTimestamp();
    [$relay, , $seed] = p21Relay([
        $friend->sign(42, [$root], 'good game yesterday', $now - 300),
        $spammer->sign(42, [$root], 'obscene spam here', $now - 200),
    ]);
    $menu = '(s) => { const message = document.querySelector(s); const form = message.querySelector("[data-test=chat-mod-form]"); const chat = document.querySelector("[data-test=game-chat]").getBoundingClientRect(); const box = form.getBoundingClientRect(); return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, inside: box.left >= chat.left && box.right <= chat.right, visible: form.checkVisibility(), personal: message.querySelector("[data-test=game-chat-mute]").innerText.trim() }; }';

    try {
        $adminPage = p21Page($admin, '/games/rocket-league');
        $viewerPage = p21Page($viewer, '/games/rocket-league');

        // Positive control on the viewer's page: the collector catches a thrown error and a 500; then it starts empty.
        $viewerPage->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
        BrowserWait::until($viewerPage, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
        $viewerPage->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

        foreach ([$adminPage, $viewerPage] as $page) {
            BrowserWait::until($page, '() => ('.P21_TEXTS.')().includes("obscene spam here")', 10_000);
        }
        // The viewer is no admin: their menu has only their own mute.
        expect($viewerPage->evaluate('() => document.querySelectorAll("[data-test=chat-moderation]").length'))->toBe(0)
            // The viewer's page listens on the moderation channel (logged in: Echo is there).
            ->and($viewerPage->evaluate('() => window.esportsSiteHidden?.listening === true'))->toBeTrue();

        $message = '[data-test=game-chat-message][data-pubkey="'.$spammer->pubkey.'"]';
        $adminPage->locator($message.' button[aria-expanded]')->first()->click();
        p21Shot($adminPage, 'moderation-menu-items-1440', '[data-test=game-chat]');
        $adminPage->locator($message.' [data-test=chat-mod-mute]')->click();
        $adminPage->locator($message.' [data-test=chat-mod-reason]')->fill('Obscene spam');
        $wide = $adminPage->evaluate($menu, $message);
        p21Shot($adminPage, 'moderation-menu-1440', '[data-test=game-chat]');

        $adminPage->locator($message.' [data-test=chat-mod-confirm]')->click();
        BrowserWait::until($adminPage, '() => ! ('.P21_TEXTS.')().includes("obscene spam here")', 5_000);
        BrowserWait::until($viewerPage, '() => ! ('.P21_TEXTS.')().includes("obscene spam here")', 10_000);

        expect($viewerPage->evaluate(P21_TEXTS))->toBe(['good game yesterday'])
            ->and($adminPage->evaluate(P21_TEXTS))->toBe(['good game yesterday'])
            ->and(PubkeyModeration::query()->where('pubkey', $spammer->pubkey)->where('actor_pubkey', $admin->pubkey)->value('reason'))->toBe('Obscene spam')
            ->and(['overflow' => $wide['overflow'], 'inside' => $wide['inside'], 'visible' => $wide['visible']])->toBe(['overflow' => 0, 'inside' => true, 'visible' => true])
            // The personal mute says it is the viewer's own, apart from the admin's "Mute for everyone".
            ->and($wide['personal'])->toStartWith('Mute ')->toEndWith(' for me');

        // Phone: the same menu and reason form inside the chat, nothing sideways (the other author's message, not muted).
        $friendMessage = '[data-test=game-chat-message][data-pubkey="'.$friend->pubkey.'"]';
        $adminPage->setViewportSize(390, 844);
        Execution::instance()->wait(0.3);
        $adminPage->locator('[data-test=game-chat-toggle]')->click();
        $adminPage->locator($friendMessage.' button[aria-expanded]')->first()->click();
        $adminPage->locator($friendMessage.' [data-test=chat-mod-ban]')->click();
        $adminPage->evaluate('(s) => document.querySelector(s).scrollIntoView({ block: "center" })', $friendMessage);
        Execution::instance()->wait(0.3);
        $narrow = $adminPage->evaluate($menu, $friendMessage);
        p21Shot($adminPage, 'moderation-menu-390', '[data-test=game-chat]');

        expect(['overflow' => $narrow['overflow'], 'inside' => $narrow['inside'], 'visible' => $narrow['visible']])->toBe(['overflow' => 0, 'inside' => true, 'visible' => true])
            ->and(p21Errors($viewerPage))->toBe([])
            ->and(p21Errors($adminPage))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

test('a message sent in the chess chat is still there after a reload, next to the history the relay already had', function () {
    $player = User::factory()->create(['locale' => 'de']);
    TestSigner::forBrowser($player);
    $other = new TestSigner;
    $chess = (string) GameChannels::channelId('chess');
    $now = now()->getTimestamp();
    [$relay, , $seed] = p21Relay([
        $other->sign(42, [['e', $chess, '', 'root']], 'anyone up for a blitz?', $now - 300),
        // Another channel's message: never shown here, so the reload proves the channel's own REQ.
        $other->sign(42, [['e', $this->channel, '', 'root']], 'rocket league only', $now - 290),
    ]);

    try {
        $page = p21Page($player, '/chess', 375, 812);
        $page->locator('[data-test=game-chat-toggle]')->click();
        BrowserWait::until($page, '() => ('.P21_TEXTS.')().length > 0', 5_000);
        $history = $page->evaluate(P21_TEXTS);

        $page->locator('#game-chat-input')->fill('Ich hoffe ihr habt Spaß');
        $page->locator('[data-test=game-chat-send]')->click();
        BrowserWait::until($page, '() => document.querySelector("#game-chat-input").value === "" && ('.P21_TEXTS.')().includes("Ich hoffe ihr habt Spaß")', 10_000);

        $page->reload();
        BrowserWait::until($page, '() => window.Alpine !== undefined && Alpine.$data(document.querySelector("[data-test=game-chat]"))?.status === "live"', 10_000);
        // Read before the reload: the bar has nothing new, and says what was written last.
        BrowserWait::until($page, '() => document.querySelector("[data-test=game-chat-preview]").innerText.includes("Ich hoffe ihr habt Spaß")', 5_000);
        $bar = $page->evaluate('() => ({ unread: document.querySelector("[data-test=game-chat-unread]").checkVisibility(), preview: document.querySelector("[data-test=game-chat-preview]").innerText.trim() })');
        $page->locator('[data-test=game-chat-toggle]')->click();
        BrowserWait::until($page, '() => ('.P21_TEXTS.')().length >= 2', 5_000);

        expect($history)->toBe(['anyone up for a blitz?'])
            ->and($bar['unread'])->toBeFalse()
            ->and($bar['preview'])->toContain('Ich hoffe ihr habt Spaß')
            ->and($page->evaluate(P21_TEXTS))->toBe(['anyone up for a blitz?', 'Ich hoffe ihr habt Spaß'])
            ->and($page->evaluate('() => document.querySelector("[data-test=game-chat-empty]")?.checkVisibility() ?? false'))->toBeFalse()
            ->and(p21Errors($page))->toBe([]);

        // Positive control: the collector on the reloaded page catches a thrown error.
        $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw"))', 5_000);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

test('a mention of a league player opens the player page under the league name, anybody else\'s njump.me, and the bar says @name', function (int $width, int $height) {
    $player = User::factory()->create(['name' => 'Pia Player']);
    $outsider = new TestSigner;
    $writer = new TestSigner;
    $root = ['e', $this->channel, '', 'root'];
    $outsiderNpub = NostrKeys::hexToNpub($outsider->pubkey);
    [$relay, , $seed] = p21Relay([
        $writer->sign(42, [$root, ['p', $player->pubkey]], 'gg nostr:'.$player->npub.' and nostr:'.$outsiderNpub, now()->getTimestamp() - 60),
    ]);

    try {
        $page = p21Page(null, '/games/rocket-league', $width, $height);
        $visible = '[...document.querySelectorAll("[data-test=game-chat-text] [data-test=chat-mention]")].filter((el) => el.checkVisibility())';
        if ($width < 1280) {
            // Below xl the chat is a bar first: its line names the mentioned player, not the code.
            BrowserWait::until($page, '() => document.querySelector("[data-test=game-chat-preview]").innerText.includes("@Pia Player")', 5_000);
            expect($page->evaluate('() => document.querySelector("[data-test=game-chat-preview]").innerText'))->not->toContain('nostr:');
            $page->locator('[data-test=game-chat-toggle]')->click();
        }
        BrowserWait::until($page, '() => '.$visible.'.length === 2 && '.$visible.'[0].getAttribute("href").startsWith("/players/")', 5_000);
        $page->evaluate('() => document.querySelector("[data-test=game-chat-text]").scrollIntoView({ block: "center" })');
        Execution::instance()->wait(0.3);
        $chips = $page->evaluate('() => { const list = document.querySelector("[data-test=game-chat-text]").closest("ol, ul").getBoundingClientRect(); return '.$visible.'.map((el) => { const box = el.getBoundingClientRect(); return { text: el.innerText.trim(), href: el.getAttribute("href"), target: el.getAttribute("target"), inside: box.left >= list.left && box.right <= list.right, height: Math.round(box.height) }; }); }');
        fwrite(STDERR, "\n[p21 mentions {$width}x{$height}] ".json_encode($chips)."\n");
        p21Shot($page, 'p21-mentions-'.$width);
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($chips[0])->toMatchArray(['text' => '@Pia Player', 'href' => '/players/'.$player->npub, 'target' => null, 'inside' => true])
            ->and($chips[1])->toMatchArray(['href' => 'https://njump.me/'.$outsiderNpub, 'target' => '_blank', 'inside' => true])
            ->and($chips[1]['text'])->toStartWith('@npub1')
            ->and($chips[0]['height'])->toBeLessThanOrEqual(24)
            ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
            ->and(p21Errors($page))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
})->with([
    'phone' => [390, 844],
    'desktop' => [1440, 900],
]);

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

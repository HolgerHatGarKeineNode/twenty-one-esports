<?php

use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Game chat (NIP-17) and a daily move, two players in two contexts (P5b)
|--------------------------------------------------------------------------
|
| Each context gets a stubbed window.nostr (TestSigner::browserStub) that
| signs and encrypts with that player's own key on the test server. The chat
| runs over a real websocket to an in-memory relay (tests/Support/MiniRelay.php,
| started here), never through the league server.
|
| Same session/auth reset as tests/Browser/BlitzGameTest.php, for two users.
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
});

const P5B_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    JS;

function playerPage(User $user, string $to): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(P5B_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->goto(ComputeUrl::from($to));
    $page->setViewportSize(1440, 900);

    return $page;
}

function chatSees(Page $page, string $from, string $text): string
{
    return '() => [...document.querySelectorAll("section[aria-labelledby=chat-h] [data-test=chat-messages] li[data-from='.$from.']")].some((li) => li.innerText.includes('.json_encode($text).'))';
}

function sendChat(Page $page, string $text): void
{
    $page->locator('#chatin')->fill($text);
    $page->locator('section[aria-labelledby=chat-h] [data-test=chat-send]')->click();
}

test('two players chat over NIP-17 through a relay, and a muted sender disappears for the one who muted', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);

        // Both first moves made, so no first-move timer ends the game mid-test.
        $game = ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
        app(ChessGameService::class)->move($game, $anna, 'e2e4');
        app(ChessGameService::class)->move($game->refresh(), $bert, 'e7e5');

        $pageA = playerPage($anna, route('games.show', $game, false));
        $pageB = playerPage($bert, route('games.show', $game, false));

        foreach ([$pageA, $pageB] as $page) {
            BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chat]")).status === "live"', 10_000);
        }

        sendChat($pageA, 'gl hf');
        BrowserWait::until($pageB, chatSees($pageB, 'them', 'gl hf'), 10_000);
        BrowserWait::until($pageA, chatSees($pageA, 'me', 'gl hf'), 5_000);

        sendChat($pageB, 'have fun');
        BrowserWait::until($pageA, chatSees($pageA, 'them', 'have fun'), 10_000);

        // Bert mutes Anna: kept on his account, and her next message never shows for him.
        $pageB->locator('section[aria-labelledby=chat-h] [data-test=mute]')->click();
        BrowserWait::until($pageB, '() => Alpine.$data(document.querySelector("[data-test=chat]")).opponentMuted === true', 5_000);
        sendChat($pageA, 'knight on h7, bold');
        BrowserWait::until($pageA, chatSees($pageA, 'me', 'knight on h7'), 5_000);
        usleep(1_500_000);

        expect($pageB->evaluate(chatSees($pageB, 'them', 'knight on h7')))->toBeFalse()
            ->and($pageB->evaluate(chatSees($pageB, 'them', 'gl hf')))->toBeFalse()
            ->and(ChatMute::query()->where('user_id', $bert->id)->pluck('muted_pubkey')->all())->toBe([$anna->pubkey])
            // The league server stores nothing about the chat.
            ->and(NostrEvent::query()->count())->toBe(0)
            ->and($pageA->evaluate('() => window.__errors'))->toBe([])
            ->and($pageB->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

test('a daily move signed by one player is there for the other after a reload', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);

    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);

    $pageB = playerPage($bert, route('games.show', $game, false));
    $pageA = playerPage($anna, route('games.show', $game, false));
    BrowserWait::until($pageA, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=daily-game]"))?.myTurn === true', 10_000);

    // F flips the board, as the Keyboard card on /settings/chess promises.
    $pageA->evaluate('() => window.dispatchEvent(new KeyboardEvent("keydown", { key: "f" }))');
    BrowserWait::until($pageA, '() => Alpine.$data(document.querySelector("[data-test=daily-game]")).flipped === true', 2_000);

    $pageA->locator('[data-test=daily-san-input]')->fill('e4');
    $pageA->locator('[data-test=daily-san-input]')->press('Enter');
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=pending-move]")?.innerText === "1. e4"', 5_000);
    $pageA->locator('[data-test=make-move]')->click();
    // Double-check is on by default (ChessSettings), so the move needs the second confirmation.
    $pageA->locator('[data-test=confirm-daily-move]')->click();
    BrowserWait::until($pageA, '() => Alpine.$data(document.querySelector("[data-test=daily-game]")).state.ply === 1', 10_000);

    $pageB->reload();
    BrowserWait::until($pageB, '() => window.Alpine && document.querySelector("[data-test=daily-moves]")?.innerText.includes("e4") && Alpine.$data(document.querySelector("[data-test=daily-game]")).myTurn === true', 10_000);

    $move = $game->refresh()->moves()->sole();
    $note = $move->nostrEvent;

    expect($game->ply)->toBe(1)
        ->and($note?->kind)->toBe(64)
        ->and($note?->pubkey)->toBe($anna->pubkey)
        ->and($note?->payload()['content'])->toContain('1. e4 *')
        ->and($pageA->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => window.__errors'))->toBe([]);
});

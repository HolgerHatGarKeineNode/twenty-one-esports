<?php

use App\Models\ChatMute;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The players chat on a Blockli game's page (plan "Blockli-Optimierung", P1)
|--------------------------------------------------------------------------
|
| Two players write to each other over NIP-17 through the in-memory relay (tests/Support/mini-relay.php), as on a
| chess game's page: the desktop panel at 1440, the bottom sheet on a phone. Every message carries the tag
| `board:<id>`, and muting is kept on the account. Console, uncaught errors and answers >= 400 stay empty.
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

    BlockliOn::play();
});

function boardChatPage(User $user, string $to): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function boardChatSees(string $from, string $text): string
{
    return '() => [...document.querySelectorAll("section[aria-labelledby=chat-h] [data-test=chat-messages] li[data-from='.$from.']")].some((li) => li.innerText.includes('.json_encode($text).'))';
}

function boardChatSend(Page $page, string $text): void
{
    $page->locator('#chatin')->fill($text);
    $page->locator('section[aria-labelledby=chat-h] [data-test=chat-send]')->click();
    BrowserWait::until($page, '() => document.querySelector("#chatin").value === ""', 1_500);
}

test('two Blockli players chat through a relay, tagged with the board game, and on a phone in the bottom sheet', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);
        $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
        $path = route('board.show', $game, false);
        $pageA = boardChatPage($anna, $path);
        $pageB = boardChatPage($bert, $path);

        foreach ([$pageA, $pageB] as $page) {
            BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chat]"))?.status === "live"', 10_000);
        }

        boardChatSend($pageA, 'gl hf');
        BrowserWait::until($pageB, boardChatSees('them', 'gl hf'), 10_000);
        boardChatSend($pageB, 'you too');
        BrowserWait::until($pageA, boardChatSees('them', 'you too'), 10_000);

        $tags = $pageB->evaluate('() => Alpine.$data(document.querySelector("[data-test=chat]")).rumors.map((r) => r.tags.find((t) => t[0] === "match")?.[1])');

        $pageB->locator('section[aria-labelledby=chat-h] [data-test=mute]')->click();
        BrowserWait::until($pageB, '() => Alpine.$data(document.querySelector("[data-test=chat]")).opponentMuted === true', 5_000);

        // Phone: the closed sheet sits on the tab bar; opened, it shows the conversation.
        $pageA->setViewportSize(390, 844);
        BrowserWait::until($pageA, '() => document.querySelector("[data-test=chat-sheet-toggle]")?.getBoundingClientRect().height > 0', 5_000);
        $pageA->locator('[data-test=chat-sheet-toggle]')->click();
        BrowserWait::until($pageA, '() => [...document.querySelectorAll("#sheet-body [data-test=chat-messages] li[data-from=them]")].some((li) => li.offsetParent !== null && li.innerText.includes("you too"))', 5_000);

        expect(array_values(array_unique($tags)))->toBe(['board:'.$game->id])
            ->and(ChatMute::query()->where('user_id', $bert->id)->pluck('muted_pubkey')->all())->toBe([$anna->pubkey])
            ->and($pageA->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth'))->toBeLessThanOrEqual(0);

        foreach ([$pageA, $pageB] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});

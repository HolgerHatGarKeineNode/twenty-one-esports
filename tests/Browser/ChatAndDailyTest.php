<?php

use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\Notifications\NotificationDm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
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
    $page = visit(BrowserLogin::url($user))->page();
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
    // The field empties at once, not after every relay has answered.
    BrowserWait::until($page, '() => document.querySelector("#chatin").value === ""', 1_500);
}

test('two players chat over NIP-17 through a relay, and a muted sender disappears for the one who muted', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        // The second relay never answers (unroutable): sending must not wait for it.
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port, 'ws://10.255.255.1:7777']]);

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

test('two players chat in a daily game through a relay, and on a phone the chat sheet sits under the move bar', function () {
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
        $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);
        app(ChessGameService::class)->move($game, $anna, 'e2e4');

        $pageA = playerPage($anna, route('games.show', $game, false));
        $pageB = playerPage($bert, route('games.show', $game, false));

        foreach ([$pageA, $pageB] as $page) {
            BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chat]")).status === "live"', 10_000);
        }

        // Desktop (1440x900): the last move and the chat input are on screen without scrolling.
        BrowserWait::until($pageA, '() => document.querySelectorAll("[data-test=daily-moves] li").length > 0', 5_000);
        $desktop = $pageA->evaluate('() => {
            const inside = (el, box) => { const b = el.getBoundingClientRect(); return b.height > 0 && b.top >= box.top && b.bottom <= box.bottom; };
            const rows = document.querySelectorAll("[data-test=daily-moves] li");
            const last = rows[rows.length - 1];
            const screen = { top: 0, bottom: innerHeight };
            return {
                scrollY: scrollY,
                lastMove: inside(last, screen) && inside(last, last.closest("[tabindex]").getBoundingClientRect()),
                chatInput: inside(document.querySelector("#chatin"), screen),
            };
        }');

        sendChat($pageA, 'your move tomorrow?');
        BrowserWait::until($pageB, chatSees($pageB, 'them', 'your move tomorrow?'), 10_000);
        sendChat($pageB, 'after work');
        BrowserWait::until($pageA, chatSees($pageA, 'them', 'after work'), 10_000);

        // Mute is the same as in a live game: kept on the account.
        $pageB->locator('section[aria-labelledby=chat-h] [data-test=mute]')->click();
        BrowserWait::until($pageB, '() => Alpine.$data(document.querySelector("[data-test=chat]")).opponentMuted === true', 5_000);

        // Phone: the closed sheet (72px) at the bottom edge, the move bar right above it, no overlap.
        $pageA->setViewportSize(375, 812);
        BrowserWait::until($pageA, '() => document.querySelector("[data-test=chat-sheet-toggle]")?.getBoundingClientRect().height > 0', 5_000);
        $phone = $pageA->evaluate('() => {
            const sheet = document.querySelector("[data-test=chat-sheet-toggle]").closest("section").getBoundingClientRect();
            const bar = document.querySelector("[data-test=daily-bottom-bar]").getBoundingClientRect();
            return { sheetTop: sheet.top, sheetBottom: sheet.bottom, barBottom: bar.bottom, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
        }');
        $pageA->locator('[data-test=chat-sheet-toggle]')->click();
        BrowserWait::until($pageA, '() => [...document.querySelectorAll("#sheet-body [data-test=chat-messages] li[data-from=them]")].some((li) => li.offsetParent !== null && li.innerText.includes("after work"))', 5_000);

        expect($desktop)->toBe(['scrollY' => 0, 'lastMove' => true, 'chatInput' => true])
            ->and($phone)->toBe(['sheetTop' => 740, 'sheetBottom' => 812, 'barBottom' => 740, 'overflow' => 0])
            ->and(ChatMute::query()->where('user_id', $bert->id)->pluck('muted_pubkey')->all())->toBe([$anna->pubkey])
            ->and(NostrEvent::query()->count())->toBe(0)
            ->and($pageA->evaluate('() => window.__errors'))->toBe([])
            ->and($pageB->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

test('a series room chat reads back to the challenge, not just the last two days', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        $match = SeriesMatch::factory()->accepted()->create([
            'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
            'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
            'created_at' => now()->subDays(5),
        ]);
        $anna = $match->challengerLineup->clan->owner;
        $bert = $match->challengedLineup->clan->owner;
        $annaKey = TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);

        // Anna wrote four days ago (a wrap is backdated up to two more days, NIP-59):
        // outside a "last 49 hours" window, inside "since the challenge".
        $wrap = (new NotificationDm($annaKey->secret))->build($bert->pubkey, 'lobby name is on the sheet', $match->number, now()->subDays(4)->getTimestamp())['wrap'];
        $sent = app(RelayPublisher::class)->publish(NostrEvent::fromSigned(SignedEvent::fromInput($wrap)), ['ws://127.0.0.1:'.$port]);
        expect(array_column($sent, 'accepted'))->toBe([true]);

        $page = playerPage($bert, route('matches.room', $match, false));
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=room-chat]")).status === "live"', 10_000);
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=room-chat] [data-test=chat-messages] li[data-from=them]")].some((li) => li.innerText.includes("lobby name is on the sheet"))', 10_000);

        expect($page->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

/*
|--------------------------------------------------------------------------
| The match dock (P5f)
|--------------------------------------------------------------------------
|
| Anna waits for Bert's daily move on another page. Bert moves: the tab turns
| to "Your move" over the websocket (the game's watch channel), without a
| reload. Opening the tab loads its panel (the island), and "Play your move"
| opens the game, where the dock has no tab for it.
|
*/

test('the match dock turns a daily tab live when the opponent moves, and its panel opens the game', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);
    app(ChessGameService::class)->move($game, $anna, 'e2e4');

    $page = playerPage($anna, '/clans');
    $tab = '[data-test=dock-tab][data-dock-tab="game-'.$game->id.'"]';
    // Nothing on her yet: the dock starts folded to its grey count.
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected" && (document.querySelector("[data-test=dock-handle]")?.getClientRects().length ?? 0) > 0', 10_000);
    $before = $page->evaluate('() => [document.querySelector("[data-test=dock-handle]").innerText, document.querySelector('.json_encode($tab).').offsetParent === null, document.querySelector('.json_encode($tab).').textContent]');

    app(ChessGameService::class)->move($game->refresh(), $bert, 'e7e5');
    // It unfolds on its own once something is on her.
    BrowserWait::until($page, '() => document.querySelector('.json_encode($tab).')?.dataset.need === "1" && document.querySelector('.json_encode($tab).').offsetParent !== null', 10_000);
    $after = $page->evaluate('() => document.querySelector('.json_encode($tab).').innerText');

    // The panel is not there until the tab is opened.
    $panelsBefore = $page->evaluate('() => document.querySelectorAll("[data-panel]").length');
    $page->locator($tab)->click();
    $panel = '[data-panel="game-'.$game->id.'"]';
    // Bert's move also sends Anna a notification: a second refresh may redraw the open panel meanwhile.
    BrowserWait::until($page, '() => document.querySelector('.json_encode($panel.' [data-test=dock-panel-cta]').')?.innerText === "Play your move"', 5_000);

    $page->locator($panel.' [data-test=dock-panel-cta]')->click();
    BrowserWait::until($page, '() => location.pathname === "/games/'.$game->id.'" && window.Alpine && document.querySelector("[data-test=daily-game]") !== null', 10_000);

    expect($before[0])->toContain('1')->toContain('waiting')
        ->and($before[1])->toBeTrue()
        ->and($before[2])->toContain($bert->displayName())->toContain('Their move')
        ->and($after)->toContain('Your move')
        ->and($panelsBefore)->toBe(0)
        ->and($page->evaluate('() => document.querySelector("[data-dock-item=\"game-'.$game->id.'\"]")'))->toBeNull()
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
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
    // Wait for either outcome, so a refused signature fails with its message instead of a timeout.
    BrowserWait::until($pageA, '() => { const d = Alpine.$data(document.querySelector("[data-test=daily-game]")); return d.state.ply === 1 || d.error !== ""; }', 10_000);
    expect($pageA->evaluate('() => Alpine.$data(document.querySelector("[data-test=daily-game]")).error'))->toBe('');

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

test('a daily game at 1440x900 keeps at least eight moves in view, on your move and on theirs', function () {
    [$anna, $bert] = User::factory()->member()->count(2)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    // A full Nostr profile, the tallest opponent strip.
    $bert->forceFill(['about' => 'Correspondence since 1998, blitz after work. Plays the Najdorf, never resigns early.', 'nip05' => 'bert@einundzwanzig.space', 'nip05_checked_at' => now(), 'nip05_verified_at' => now(), 'profile_event_at' => now()])->save();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    // 30 plies of a Closed Ruy Lopez: White (Anna) to move.
    foreach (explode(' ', 'e2e4 e7e5 g1f3 b8c6 f1b5 a7a6 b5a4 g8f6 e1g1 f8e7 f1e1 b7b5 a4b3 d7d6 c2c3 e8g8 h2h3 c6b8 d2d4 b8d7 b1d2 c8b7 b3c2 f8e8 d2f1 e7f8 f1g3 g7g6 a2a4 c7c5') as $i => $uci) {
        $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
    }

    $inView = '() => {
        const port = document.querySelector("[data-test=daily-moves]").closest("[tabindex]").getBoundingClientRect();
        return [...document.querySelectorAll("[data-test=daily-moves] li")].filter((li) => {
            const b = li.getBoundingClientRect();
            return b.height > 0 && b.top >= port.top && b.bottom <= port.bottom && b.bottom <= innerHeight;
        }).length;
    }';
    $counts = [];
    foreach (['yours' => $anna, 'theirs' => $bert] as $turn => $user) {
        $page = playerPage($user, route('games.show', $game, false));
        BrowserWait::until($page, '() => window.Alpine && document.querySelectorAll("[data-test=daily-moves] li").length === 15 && Alpine.$data(document.querySelector("[data-test=daily-game]")).myTurn === '.json_encode($turn === 'yours'), 10_000);
        $counts[$turn] = $page->evaluate($inView);
        expect($page->evaluate('() => window.__errors'))->toBe([]);
    }

    expect(min($counts))->toBeGreaterThanOrEqual(8, 'rows in view: '.json_encode($counts));
});

test('a daily move the signer refuses says why, logs the signer\'s error, and goes through once signed', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    $game = app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);

    $page = visit(BrowserLogin::url($anna))->page();
    $page->context()->addInitScript(P5B_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($anna));
    // The signer answers as window.__signMode says, with the errors real signers throw.
    $page->context()->addInitScript(<<<'JS'
        window.__warnings = [];
        const warn = console.warn;
        console.warn = (...args) => { window.__warnings.push(args.map((a) => (a instanceof Error ? a.message : String(a))).join(' ')); warn.apply(console, args); };
        const stub = window.nostr.signEvent;
        window.__signMode = 'ok';
        window.nostr.signEvent = async (draft) => {
            if (window.__signMode === 'deny') throw new Error('nos2x-fox: Insufficient permissions, required signEvent');
            if (window.__signMode === 'timeout') throw new Error('NIP-46 sign_event timed out');
            if (window.__signMode === 'broken') throw new Error('nos2x: no private key found');
            const signed = await stub(draft);

            return window.__signMode === 'otherKey' ? { ...signed, pubkey: 'f'.repeat(64) } : signed;
        };
        JS);
    $page->goto(ComputeUrl::from(route('games.show', $game, false)));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=daily-game]"))?.myTurn === true', 10_000);

    $page->locator('[data-test=daily-san-input]')->fill('e4');
    $page->locator('[data-test=daily-san-input]')->press('Enter');
    BrowserWait::until($page, '() => document.querySelector("[data-test=pending-move]")?.innerText === "1. e4"', 5_000);

    $daily = 'Alpine.$data(document.querySelector("[data-test=daily-game]"))';
    $attempt = function (string $mode) use ($page, $daily): string {
        $page->evaluate('() => { window.__signMode = '.json_encode($mode).'; '.$daily.'.error = ""; }');
        $page->locator('[data-test=make-move]')->click();
        $page->locator('[data-test=confirm-daily-move]')->click();
        BrowserWait::until($page, '() => '.$daily.'.error !== "" || '.$daily.'.state.ply === 1', 10_000);

        return $page->evaluate('() => '.$daily.'.error');
    };

    $labels = SignerMessages::labels();

    expect($attempt('deny'))->toBe($labels['rejected'])
        ->and($attempt('timeout'))->toBe($labels['unreachable'])
        ->and($attempt('otherKey'))->toBe($labels['wrongKey'])
        ->and($attempt('broken'))->toBe(str_replace(':reason', 'nos2x: no private key found', $labels['signerFailed']))
        ->and($game->refresh()->ply)->toBe(0)
        ->and($attempt('ok'))->toBe('');

    $warnings = $page->evaluate('() => window.__warnings');

    expect($game->refresh()->ply)->toBe(1)
        ->and($warnings)->toHaveCount(4)
        ->and($warnings[0])->toContain('(declined)')->toContain('Insufficient permissions')
        ->and($warnings[1])->toContain('(unreachable)')->toContain('NIP-46 sign_event timed out')
        ->and($warnings[2])->toContain('logged in as '.$anna->pubkey)
        ->and($warnings[3])->toContain('(failed)')->toContain('no private key found')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Nostr profiles of other players (P10a)
|--------------------------------------------------------------------------
|
| Bert's kind 0 lives only on the (in-memory) profile relay. Anna's browser
| reads it, hands the signed event to the league, and her board and the
| player card show it. Bert's picture points at a closed port: the avatar
| must fall back to his generated Blockpile.
|
*/

test('an opponent profile read from the relay shows on the board and in the player card', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    TestSigner::forBrowser($anna);
    $bertKey = TestSigner::forBrowser($bert);
    $bert->forceFill(['name' => null, 'picture' => null, 'profile_event_at' => null, 'profile_checked_at' => null])->save();

    $seed = tempnam(sys_get_temp_dir(), 'relay-seed');
    file_put_contents($seed, json_encode([$bertKey->sign(0, content: json_encode([
        'display_name' => 'Bert Blocks',
        'about' => 'Blitz after work, stack sats.',
        'picture' => 'https://127.0.0.1:9/bert.png',
        'lud16' => 'bert@getalby.com',
    ]), createdAt: now()->subDay()->getTimestamp())]));

    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        $game = ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
        app(ChessGameService::class)->move($game, $anna, 'e2e4');
        app(ChessGameService::class)->move($game->refresh(), $bert, 'e7e5');

        // The relay is switched on only now: the login redirect and the first load
        // must not fetch the profile before the error collector is in place.
        $page = playerPage($anna, route('games.show', $game, false));
        config(['esports.profile_relays' => ['ws://127.0.0.1:'.$port]]);
        $page->reload();
        $generated = route('avatars.generated', ['pubkey' => $bert->pubkey, 'v' => 1], false);

        // The profile went browser -> league, and the picture that cannot load gave way to the pile.
        BrowserWait::until($page, '() => window.Alpine && Alpine.store("profiles").statusOf('.json_encode($bert->pubkey).') === "done"', 10_000);
        expect($bert->refresh())->name->toBe('Bert Blocks')->about->toBe('Blitz after work, stack sats.')->lud16->toBe('bert@getalby.com');

        $card = '[data-test=player-top] [data-test=player-card-b]';
        BrowserWait::until($page, '() => { const img = document.querySelector('.json_encode($card.' img[data-avatar]').'); return img.complete && new URL(img.src).pathname + new URL(img.src).search === '.json_encode($generated).' && img.alt.endsWith(", generated"); }', 10_000);

        // Hover the name: the player card opens with the fresh profile.
        $page->locator($card.' [data-test=player-name]')->hover();
        BrowserWait::until($page, '() => { const pop = document.querySelector("[data-test=profile-popover]"); return pop.checkVisibility() && pop.innerText.includes("Blitz after work, stack sats.") && pop.innerText.includes("bert@getalby.com"); }', 5_000);
        expect($page->evaluate('() => document.querySelector("[data-test=profile-popover] [data-test=card-name]").innerText'))->toBe('Bert Blocks')
            ->and($page->evaluate('() => document.querySelector("[data-test=profile-popover]").getAttribute("aria-label")'))->toBe('Bert Blocks profile');

        // Esc closes it.
        $page->locator($card.' [data-test=player-name]')->press('Escape');
        BrowserWait::until($page, '() => ! document.querySelector("[data-test=profile-popover]").checkVisibility()', 2_000);

        // On a touch screen a tap opens the same card as a bottom sheet and does not leave the game.
        $phone = visit(BrowserLogin::url($anna))->on()->mobile()->page();
        $phone->context()->addInitScript(P5B_COLLECTOR);
        $phone->goto(ComputeUrl::from(route('games.show', $game, false)));
        BrowserWait::until($phone, '() => window.Alpine && document.querySelector("[data-test=profile-sheet]") !== null', 5_000);
        $phone->locator($card.' [data-test=player-name]')->tap();
        BrowserWait::until($phone, '() => document.querySelector("[data-test=profile-sheet] [role=dialog]")?.checkVisibility() && document.querySelector("[data-test=profile-sheet]").innerText.includes("Blitz after work, stack sats.")', 5_000);

        expect($phone->evaluate('() => location.pathname'))->toBe(route('games.show', $game, false))
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($phone->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
});

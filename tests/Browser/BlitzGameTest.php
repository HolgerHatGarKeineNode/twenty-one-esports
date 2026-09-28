<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\ChessLobby;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blitz game of two users over Reverb (plan, Test-Strategie flow 2)
|--------------------------------------------------------------------------
|
| Needs the Reverb server scripts/test-browser.sh starts; without it the
| pages have no websocket and this test says so instead of passing on
| polling.
|
| Two browser contexts, one in-process app: a real PHP process starts with a
| fresh session store and auth guard per request, this server keeps both
| between requests. Resetting them on every request (and keeping sessions in
| the database, which the array driver would lose) gives each context its
| own logged-in user.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);

    // Pairings and invites must arrive by push: the lobby's own checks (the
    // slow net and the widening search range) are pushed an hour away.
    config(['esports.chess.lobby_poll_seconds' => 3600, 'esports.chess.queue.range.every_seconds' => 3600]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        // The guard reads the `session.store` singleton, not the manager's driver.
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        // Livewire's "scripts already rendered" flag outlives a request here:
        // the page a Livewire redirect lands on came back without livewire.js.
        $app['livewire']->flushState();
    });
});

/**
 * Console errors, uncaught errors, rejected promises, failed websockets and
 * >= 400 answers to fetch/XHR, collected from the first script on. Kept in
 * sessionStorage, so a page the tab has left (the lobby a pairing moved
 * away from) still counts.
 */
const BLITZ_COLLECTOR = <<<'JS'
    window.__errors = JSON.parse(sessionStorage.getItem('__errors') ?? '[]');
    const push = (entry) => { window.__errors.push(entry); sessionStorage.setItem('__errors', JSON.stringify(window.__errors)); };
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...args) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push(this.status + ' ' + this.responseURL); });
        return originalSend.apply(this, args);
    };
    JS;

function blitzPage(User $user, string $to): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BLITZ_COLLECTOR);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * The live board's Alpine state, as plain data.
 */
function board(Page $page, string $expression): mixed
{
    return $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=chess-game]")); return '.$expression.'; }');
}

function playSan(Page $page, string $san): void
{
    $page->locator('[data-test=san-input]')->fill($san);
    $page->locator('[data-test=san-input]')->press('Enter');
}

test('two players find each other, play over Reverb, survive a reload and end by resignation', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();

    $pageA = blitzPage($anna, '/chess');
    $pageB = blitzPage($bert, '/chess');

    // Presence: each sees the other in "Online now".
    BrowserWait::until($pageA, '() => [...document.querySelectorAll("[data-test=online-player]")].some((li) => li.innerText.includes('.json_encode($bert->displayName()).'))', 10_000);
    BrowserWait::until($pageB, '() => [...document.querySelectorAll("[data-test=online-player]")].some((li) => li.innerText.includes('.json_encode($anna->displayName()).'))', 10_000);

    // Queue: Anna searches, Bert searches, both land on the same game.
    ChessLobby::openBlitz($pageA);
    $pageA->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=searching]") !== null', 10_000);
    expect($pageA->evaluate('() => [...document.querySelectorAll("*")].some((el) => [...el.attributes].some((a) => a.name.startsWith("wire:poll")))'))->toBeFalse()
        ->and($pageA->evaluate('() => window.__errors'))->toBe([]);
    ChessLobby::openBlitz($pageB);
    $pageB->locator('[data-test=find-opponent-button]')->click();

    // Anna waited: the pairing reaches her by push on her own channel. The
    // lobby's own checks are an hour away, and the match-found toast would
    // only open the game after its 5 s countdown.
    BrowserWait::until($pageB, '() => location.pathname.startsWith("/games/")', 10_000);
    BrowserWait::until($pageA, '() => location.pathname.startsWith("/games/")', 3_000);
    $game = ChessGame::query()->sole();
    expect($pageA->url())->toEndWith('/games/'.$game->id)->and($pageB->url())->toEndWith('/games/'.$game->id);

    [$white, $black] = $game->white_id === $anna->id ? [$pageA, $pageB] : [$pageB, $pageA];
    foreach ([$white, $black] as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).connection === "connected"', 10_000);
    }

    // Moves travel over Reverb: the other board follows while neither page polls.
    playSan($white, 'e4');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 1', 5_000);
    playSan($black, 'e5');
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 2', 5_000);
    playSan($white, 'Nf3');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 3', 5_000);

    expect(board($white, 'g.poller'))->toBeNull()
        ->and(board($black, 'g.poller'))->toBeNull()
        ->and(board($black, 'g.state.moves.map((m) => m.san)'))->toBe(['e4', 'e5', 'Nf3']);

    // Cursor: never the text I-beam over a glyph piece; a hand only where a
    // click does something (own piece on your turn, a legal target square).
    $cursor = fn ($page, string $square) => $page->evaluate('() => getComputedStyle(document.querySelector("[data-square='.$square.'] text")).cursor');
    expect($cursor($black, 'e5'))->toBe('pointer')
        ->and($cursor($black, 'e4'))->toBe('default')
        ->and($cursor($white, 'f3'))->toBe('default');

    // Copy the opponent's npub: a 44 px hit area inside the viewport, and a
    // click puts exactly that npub on the clipboard.
    $copy = $black->evaluate('async () => {
        const button = [...document.querySelectorAll("[data-test=copy-npub]")].find((b) => b.checkVisibility());
        const hit = getComputedStyle(button, "::after");
        const r = button.getBoundingClientRect();
        let copied = null;
        navigator.clipboard.writeText = async (text) => { copied = text; };
        button.click();
        await new Promise((resolve) => setTimeout(resolve, 50));
        return { npub: button.dataset.npub, copied, hit: r.width - 2 * parseFloat(hit.left), left: r.left, right: r.right, vw: document.documentElement.clientWidth };
    }');
    expect($copy['copied'])->toBe($copy['npub'])
        ->and($copy['npub'])->toBe($game->white->npub)
        ->and($copy['hit'])->toBeGreaterThanOrEqual(44.0)
        ->and($copy['left'])->toBeGreaterThanOrEqual(0.0)
        ->and($copy['right'])->toBeLessThanOrEqual((float) $copy['vw']);

    // Piece names on hover (a help for new players): pointing at e5 names it,
    // leaving the board clears it.
    $white->locator('[data-square=e5]')->hover();
    BrowserWait::until($white, '() => document.querySelector("[data-test=piece-tip]")?.innerText === "Black pawn"', 2_000);
    $white->locator('[data-test=clock-top]')->hover();
    BrowserWait::until($white, '() => ! document.querySelector("[data-test=piece-tip]")?.checkVisibility()', 2_000);

    // Captured pieces: the row is there on both strips, empty so far, and the
    // bundle computes captures (a won knight pair and pawn: +6).
    expect($white->evaluate('() => document.querySelector("[data-test=captured-top]")?.getAttribute("aria-label")'))->toBe('Nothing captured yet')
        ->and($white->evaluate('() => window.chessCaptured("r1bqkb1r/ppp1pppp/8/8/2PP4/8/PP3PPP/RNBQKBNR b KQkq - 0 5", "w").lead'))->toBe('+6');

    // Clocks: Black's runs on both boards, and both show the same time.
    $game->refresh();
    $clockWhite = board($white, '[g.state.clock.running, Math.round(g.remaining("b"))]');
    $clockBlack = board($black, '[g.state.clock.running, Math.round(g.remaining("b"))]');
    expect($clockWhite[0])->toBe('b')->and($clockBlack[0])->toBe('b')
        ->and(abs($clockWhite[1] - $clockBlack[1]))->toBeLessThan(1_500)
        ->and($clockBlack[1])->toBeLessThanOrEqual($game->black_ms)
        ->and(board($white, 'g.state.clock.w'))->toBe($game->white_ms);

    // Black reloads mid-game: position, moves and clocks come back from the server.
    $black->reload();
    BrowserWait::until($black, '() => window.Alpine && document.querySelector("[data-test=chess-game]") && Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 3', 10_000);
    expect(board($black, 'g.state.fen'))->toBe($game->refresh()->fen)
        ->and(board($black, 'g.state.moves.map((m) => m.san)'))->toBe(['e4', 'e5', 'Nf3'])
        ->and(board($black, 'g.state.clock.w'))->toBe($game->white_ms)
        ->and(board($black, 'g.state.clock.running'))->toBe('b');

    // The game goes on after the reload, then White resigns.
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).connection === "connected"', 10_000);
    playSan($black, 'Nc6');
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 4', 5_000);

    // A push that never arrives (server could not reach Reverb, socket dead
    // while still "connected"): Black stops hearing game events, White moves,
    // and Black's board still catches up from the server within seconds.
    $black->evaluate('() => window.Echo.private("game.'.$game->id.'").stopListening(".game.updated")');
    expect(board($black, 'g.connection'))->toBe('connected');
    playSan($white, 'Bb5');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 5', 7_000);
    // Heard again, so the end below reaches Black by push, not after another heartbeat.
    $black->evaluate('() => window.Echo.private("game.'.$game->id.'").listen(".game.updated", (update) => Alpine.$data(document.querySelector("[data-test=chess-game]")).receive(update))');

    $white->locator('[data-test=resign]')->click();
    $white->locator('[data-test=confirm-resign]')->click();

    BrowserWait::until($white, '() => document.querySelector("[data-test=outcome]")?.innerText === "Loss"', 5_000);
    BrowserWait::until($black, '() => document.querySelector("[data-test=outcome]")?.innerText === "Win"', 5_000);

    // Sounds (P5c): every move asked for its click, and each side its own end sound.
    BrowserWait::until($black, '() => window.esportsSounds.calls.includes("win")', 2_000);
    BrowserWait::until($white, '() => window.esportsSounds.calls.includes("loss")', 2_000);
    expect($white->evaluate('() => window.esportsSounds.calls.filter((s) => s === "move").length'))->toBe(5);

    $game->refresh();
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->end_reason)->toBe(ChessEndReason::Resignation)
        ->and($game->result)->toBe('0-1')
        ->and($white->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate('() => window.__errors'))->toBe([]);

    // Casual Elo (P7b): both players open the finished game and each page shows
    // the loser (White) at 980, −20 and the winner (Black) at 1020, +20.
    // Live first: the game-over card of each player shows their own new casual Elo.
    BrowserWait::until($white, '() => document.querySelector("[data-test=game-over-rating]")?.innerText === "Casual 980 −20"', 5_000);
    BrowserWait::until($black, '() => document.querySelector("[data-test=game-over-rating]")?.innerText === "Casual 1020 +20"', 5_000);

    $ratingText = '() => ["w", "b"].map((c) => document.querySelector(`[data-test=done-rating-${c}]`)?.innerText.replace(/\s+/g, " ").trim())';
    foreach ([$white, $black] as $page) {
        $page->reload();
        BrowserWait::until($page, '() => document.querySelector("[data-test=done-rating-b]") !== null', 10_000);

        expect($page->evaluate($ratingText))->toBe(['White · Casual 980 −20 · provisional', 'Black · Casual 1020 +20 · provisional'])
            ->and($page->evaluate('() => window.__errors'))->toBe([]);
    }
});

/**
 * Whether the page's "Online now" list shows this player.
 */
function listsPlayer(User $user): string
{
    return '() => [...document.querySelectorAll("[data-test=online-player]")].some((li) => li.textContent.includes('.json_encode($user->displayName()).'))';
}

/**
 * The online-list row of this player, as a CSS-free handle for evaluate().
 */
function rowOf(User $user): string
{
    return '[...document.querySelectorAll("[data-test=online-player]")].find((li) => li.textContent.includes('.json_encode($user->displayName()).'))';
}

test('a player who searches and is invited lands in the inviter\'s game at once', function () {
    // Only a player who is looking can be invited (ChessInvites::invite): Bert is.
    $anna = User::factory()->create();
    $bert = User::factory()->lookingToPlay()->create();

    $pageA = blitzPage($anna, '/chess');
    $pageB = blitzPage($bert, '/chess');
    BrowserWait::until($pageA, listsPlayer($bert), 10_000);

    ChessLobby::openBlitz($pageB);
    $pageB->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=searching]") !== null', 10_000);

    $pageA->evaluate('() => '.rowOf($bert).'.querySelector("[data-test=invite]").click()');

    BrowserWait::until($pageA, '() => location.pathname.startsWith("/games/")', 10_000);
    BrowserWait::until($pageB, '() => location.pathname.startsWith("/games/")', 10_000);
    $game = ChessGame::query()->sole();

    expect($pageA->url())->toEndWith('/games/'.$game->id)
        ->and($pageB->url())->toEndWith('/games/'.$game->id)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($pageA->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => window.__errors'))->toBe([]);
});

test('the lobby switch answers every click, the online list holds still, and an invite shows on its row', function () {
    // Bert is looking, so Anna's list offers "Invite" on his row (ChessInvites::invite).
    $anna = User::factory()->create();
    $bert = User::factory()->lookingToPlay()->create();

    $pageA = blitzPage($anna, '/chess');
    $pageB = blitzPage($bert, '/chess');
    BrowserWait::until($pageA, listsPlayer($bert), 10_000);
    BrowserWait::until($pageB, listsPlayer($anna), 10_000);

    // A's list records every moment Bert is missing from it, from now on.
    $watchBert = '() => { window.__drops = 0; let seen = true; const has = '.listsPlayer($bert).';
        new MutationObserver(() => { const now = has(); if (seen && !now) window.__drops++; seen = now; })
            .observe(document.querySelector("[data-test=online-now]"), { subtree: true, childList: true, characterData: true, attributes: true }); }';
    $pageA->evaluate($watchBert);

    // The switch flips on the click itself (Alpine's next tick), while the save is still under way.
    $switch = 'document.querySelector("[data-test=looking-toggle]")';
    expect($pageA->evaluate('async () => { '.$switch.'.click(); await Alpine.nextTick(); return ['.$switch.'.getAttribute("aria-checked"), document.querySelector("[data-test=looking-state]").textContent, Alpine.$data('.$switch.').savingLooking]; }'))->toBe(['true', 'On', true]);
    $settled = '() => { const d = Alpine.$data('.$switch.'); return !d.savingLooking && d.looking === d.savedLooking; }';
    BrowserWait::until($pageA, $settled, 5_000);
    expect($anna->refresh()->looking_to_play)->toBe('chess/blitz');

    // Four quick clicks (on -> off -> on -> off -> on) end on "on". Livewire merges
    // identical calls queued behind a running request, which lost clicks before P5e.
    $pageA->evaluate('() => new Promise((resolve) => { const b = '.$switch.'; [0, 60, 120, 180].forEach((ms) => setTimeout(() => b.click(), ms)); setTimeout(resolve, 200); })');
    BrowserWait::until($pageA, $settled, 5_000);
    expect($pageA->evaluate('() => '.$switch.'.getAttribute("aria-checked")'))->toBe('true')
        ->and($anna->refresh()->looking_to_play)->toBe('chess/blitz');
    BrowserWait::until($pageB, '() => '.rowOf($anna).'?.textContent.includes("looking: Blitz 5+3")', 5_000);

    // Bert moves around the site (full page loads): Anna's list never drops him.
    foreach (['/clans', '/chess', '/clans', '/chess'] as $to) {
        $pageB->goto(ComputeUrl::from($to));
    }
    BrowserWait::until($pageB, listsPlayer($anna), 10_000);
    $pageA->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1000))');
    expect($pageA->evaluate('() => window.__drops'))->toBe(0);

    // The switch survives a reload.
    $pageA->reload();
    BrowserWait::until($pageA, '() => window.Alpine && '.$switch.' !== null && Alpine.$data('.$switch.').looking === true', 10_000);
    expect($pageA->evaluate('() => '.$switch.'.getAttribute("aria-checked")'))->toBe('true');

    // A failed save puts the switch back and says so.
    $pageA->evaluate('() => { const original = window.fetch; window.fetch = (...args) => { window.fetch = original; return Promise.reject(new TypeError("offline")); }; '.$switch.'.click(); }');
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=looking-failed]").checkVisibility()', 5_000);
    expect($pageA->evaluate('() => ['.$switch.'.getAttribute("aria-checked"), document.querySelector("[data-test=looking-failed]").checkVisibility()]'))->toBe(['true', true])
        ->and($anna->refresh()->looking_to_play)->toBe('chess/blitz');

    // Invite: Anna's row for Bert turns into "Invited · Withdraw", on both ends live.
    BrowserWait::until($pageA, listsPlayer($bert), 10_000);
    $pageA->evaluate($watchBert);
    $pageA->evaluate('() => '.rowOf($bert).'.querySelector("[data-test=invite]").click()');
    BrowserWait::until($pageA, '() => '.rowOf($bert).'?.querySelector("[data-test=invited]") != null && '.rowOf($bert).'.querySelector("[data-test=invite]") == null', 5_000);
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=incoming-invite]") !== null', 5_000);

    // Bert declines: Anna's row is back to "Invite".
    $pageB->locator('[data-test=decline-invite]')->click();
    BrowserWait::until($pageA, '() => '.rowOf($bert).'?.querySelector("[data-test=invite]") != null', 5_000);

    // Invited again, then withdrawn from the row: Bert's invite card goes away.
    $pageA->evaluate('() => '.rowOf($bert).'.querySelector("[data-test=invite]").click()');
    BrowserWait::until($pageA, '() => '.rowOf($bert).'?.querySelector("[data-test=withdraw-invite]") != null', 5_000);
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=incoming-invite]") !== null', 5_000);
    $pageA->evaluate('() => '.rowOf($bert).'.querySelector("[data-test=withdraw-invite]").click()');
    BrowserWait::until($pageA, '() => '.rowOf($bert).'?.querySelector("[data-test=invite]") != null', 5_000);
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=incoming-invite]") === null', 5_000);

    expect($pageA->evaluate('() => window.__drops'))->toBe(0)
        ->and($pageA->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => window.__errors'))->toBe([]);

    // Control: a player who really leaves is dropped, after the grace period.
    $pageB->close();
    BrowserWait::until($pageA, '() => !('.listsPlayer($bert).')()', 10_000);
});

/**
 * 70 legal plies without a capture streak, a check or a repetition that ends
 * the game (seeded random walk, replayed through ChessRules): a blitz game
 * long enough to fill any move list.
 */
const BLITZ_LONG_GAME = 'b2b4 c7c6 g1h3 e7e5 d2d3 g7g5 d1d2 h7h6 c1a3 f7f5 g2g4 d7d6 b4b5 d6d5 h1g1 g8f6 a3d6 d8c7 e1d1 c7g7 d6b4 g7h7 b4c3 a7a5 d1c1 e5e4 a2a4 f8d6 c3d4 h7g8 d4e3 c8e6 e3d4 e8d7 d4b6 d6g3 h3g5 d7c8 b6e3 f5g4 d3d4 f6e8 g5h7 g8f8 e3g5 g3f4 h7f6 f8a3 c1d1 a3e7 f1g2 f4c7 b5c6 e7g7 f6g8 h6h5 c2c3 g7f7 g5e3 c8d8 d2c1 f7g6 g2f1 g6g5 d1c2 d8c8 f2f3 c7g3 c2b3 g5h4';

/**
 * The live room's layout while the moves pile up: board, the move list's box
 * (the card from lg, the one-line strip below), whether the newest move is in
 * view and the list sits at its end, and the document's height.
 */
const BLITZ_LAYOUT = <<<'JS'
    () => {
        const desktop = innerWidth >= 1024;
        const round = (n) => Math.round(n * 10) / 10;
        const board = document.querySelector('[data-test=live-board]').getBoundingClientRect();
        const box = document.querySelector(desktop ? '[data-test=moves-card]' : '[data-test=move-strip]');
        const scroller = desktop ? document.querySelector('[data-test=move-list]') : box;
        const port = scroller.getBoundingClientRect();
        const items = desktop ? scroller.querySelectorAll('li') : scroller.querySelectorAll(':scope > span');
        const last = items[items.length - 1]?.getBoundingClientRect() ?? null;
        return {
            board: round(board.height),
            box: round(box.getBoundingClientRect().height),
            rows: items.length,
            scrolls: desktop ? scroller.scrollHeight > scroller.clientHeight : scroller.scrollWidth > scroller.clientWidth,
            // Column-reverse list: scrollTop 0 is the newest end, older moves are at negative offsets.
            atEnd: desktop ? scroller.scrollTop >= -1 : scroller.scrollLeft >= scroller.scrollWidth - scroller.clientWidth - 1,
            lastInView: last !== null && (desktop ? last.top >= port.top - 1 && last.bottom <= port.bottom + 1 : last.left >= port.left - 1 && last.right <= port.right + 1),
            doc: document.documentElement.scrollHeight,
        };
    }
    JS;

test('a blitz room keeps its board and move list the same height through 70 moves, the newest move in view, at 1440 and 375 px', function () {
    $games = app(ChessGameService::class);
    $plies = explode(' ', BLITZ_LONG_GAME);
    $sizes = [];

    foreach ([1440 => 900, 375 => 667] as $width => $height) {
        [$anna, $bert] = User::factory()->count(2)->create();
        $game = $games->start($anna, $bert);
        // Two plies first: past the first-move notice and the abort button, both of which change the room's height on their own.
        foreach (array_slice($plies, 0, 2) as $i => $uci) {
            $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
        }

        $page = blitzPage($anna, route('games.show', $game, false));
        $page->setViewportSize($width, $height);
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chess-game]")).state.moves.length === 2', 10_000);
        $before = $page->evaluate(BLITZ_LAYOUT);
        // On a phone the move strip sits under the board: bring it into the shot (after measuring).
        $reveal = '() => innerWidth < 1024 && document.querySelector("[data-test=move-strip]").scrollIntoView({ block: "center" })';
        $page->evaluate($reveal);
        shellShot($page, "blitz-{$width}-2-plies");

        foreach (array_slice($plies, 2) as $i => $uci) {
            $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
        }
        // The page learns the moves the way it would after a missed push: its own state fetch.
        $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=chess-game]")).resync()');
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.moves.length === 70', 10_000);
        $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');
        $after = $page->evaluate(BLITZ_LAYOUT);
        $page->evaluate($reveal);
        shellShot($page, "blitz-{$width}-70-plies");
        $sizes[$width] = ['before' => $before, 'after' => $after];

        expect($after['rows'])->toBe(35)
            ->and($after['board'])->toBe($before['board'])
            ->and(abs($after['box'] - $before['box']))->toBeLessThanOrEqual(1.0, "move list box @{$width}: {$before['box']} -> {$after['box']}")
            ->and($after['doc'])->toBe($before['doc'], "document height @{$width}")
            ->and($after['scrolls'])->toBeTrue()
            ->and($after['atEnd'])->toBeTrue()
            ->and($after['lastInView'])->toBeTrue()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

        $games->resign($game->refresh(), $anna);
    }

    fwrite(STDERR, "\n[blitz-moves] ".json_encode($sizes));

    // Positive control: the same collectors do see a thrown error, a 404 fetch and a broken image.
    $page->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); document.body.append(Object.assign(new Image(), { src: "/control-missing.png" })); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
    BrowserWait::until($page, '() => ('.BrowserConsole::BAD_RESPONSES.')().some((e) => e.includes("control-missing.png"))', 5_000);
});

/*
|--------------------------------------------------------------------------
| Stepping back through the moves (P55)
|--------------------------------------------------------------------------
|
| Asked (2026-09-28): with many games at once nobody remembers every board,
| so every chess view lets the viewer step back through the moves, like
| Lichess. On an earlier position the board takes no move, the opponent's
| move does not pull the viewer back (the bar says "New move"), and one
| click or End returns to now. The clocks run on.
|
*/

test('players and a spectator step back through a live blitz game while it goes on: the board takes no move there, a new move does not pull them back, End returns (1440 and 375 px)', function () {
    $games = app(ChessGameService::class);
    $ucis = ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6'];
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = $games->start($anna, $bert);
    foreach (array_slice($ucis, 0, 4) as $i => $uci) {
        $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
    }
    $path = route('games.show', $game, false);

    // Anna (White) on a desktop, Bert (Black) on a phone, a guest watching on a desktop.
    $white = blitzPage($anna, $path);
    $white->setViewportSize(1440, 900);
    $black = blitzPage($bert, $path);
    $black->setViewportSize(375, 812);
    $guest = visit('/robots.txt')->page();
    $guest->context()->addInitScript(BLITZ_COLLECTOR);
    $guest->setViewportSize(1440, 900);
    $guest->goto(ComputeUrl::from($path));
    foreach ([$white, $black, $guest] as $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chess-game]"))?.connection === "connected"', 10_000);
    }
    expect(board($guest, 'g.color'))->toBeNull();
    $sizes = [];

    // Following the game: the bar names the newest move, the board is the game's.
    $liveWhite = historyOf($white, fenAt($game, 4));
    $liveBlack = historyOf($black, fenAt($game, 4));
    // From lg a player's bar holds the move field; the phone and the spectator name the newest move.
    expect($liveWhite)->toMatchArray(['ply' => 4, 'shown' => 4, 'browsing' => false, 'boardMatches' => true, 'past' => false, 'live' => false, 'back' => false])
        ->and($liveWhite['barText'])->toContain('Enter move')
        ->and($white->locator('[data-test=san-input]')->isVisible())->toBeTrue()
        ->and($liveBlack)->toMatchArray(['boardMatches' => true, 'live' => true])
        ->and($liveBlack['barText'])->toContain('2… Nc6')->toContain('Current position')
        ->and(historyOf($guest))->toMatchArray(['live' => true, 'browsing' => false]);
    shellShot($white, 'p55-1440-blitz-live');
    shellShot($black, 'p55-375-blitz-live');

    // Anna, on move, steps back twice with the arrow key: 1… e5 on a cold board, and the board takes nothing there.
    pressKey($white, 'ArrowLeft');
    pressKey($white, 'ArrowLeft');
    $past = historyOf($white, fenAt($game, 2));
    expect($past)->toMatchArray(['ply' => 4, 'shown' => 2, 'browsing' => true, 'boardMatches' => true, 'past' => true, 'live' => false, 'back' => true, 'fresh' => false])
        ->and($past['barText'])->toContain('Viewing 1… e5')->toContain('Back to the current position')
        ->and(board($white, 'g.canMove'))->toBeFalse();
    $white->locator('[data-square="f1"]')->click();
    expect(board($white, '[g.selected, g.dots.length]'))->toBe(['', 0]);
    // The move field gives way to the way back; a move sent anyway (a key held from before) is refused with a reason.
    expect($white->locator('[data-test=san-input]')->isVisible())->toBeFalse()
        ->and(board($white, '(g.sanInput = "Bb5", g.submitSan(), g.error)'))->toContain('earlier position')
        ->and($game->refresh()->ply)->toBe(4);

    // The clocks run on while she looks back.
    $clock = $white->evaluate('() => document.querySelector("[data-test=clock-bottom]").innerText');
    BrowserWait::until($white, '() => document.querySelector("[data-test=clock-bottom]").innerText !== '.json_encode($clock), 3_000);
    $sizes['1440 live'] = $liveWhite;
    $sizes['1440 past'] = $past;

    // End: back to now, and her move goes through.
    pressKey($white, 'End');
    expect(historyOf($white, fenAt($game, 4)))->toMatchArray(['browsing' => false, 'boardMatches' => true, 'past' => false]);
    // Arrows typed in a field (the move field here, the chat alike) stay in the field.
    $white->locator('[data-test=san-input]')->fill('');
    $white->locator('[data-test=san-input]')->press('ArrowLeft');
    $white->locator('[data-test=san-input]')->press('Home');
    expect(historyOf($white)['shown'])->toBe(4);
    playSan($white, 'Bb5');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 5', 5_000);

    // She goes to the start (Home) and Bert answers from his phone by tapping: she stays on the start position, the bar says so.
    pressKey($white, 'Home');
    pressKey($guest, 'ArrowLeft');
    $black->locator('[data-square="a7"]')->click();
    $black->locator('[data-square="a6"]')->click();
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 6', 5_000);
    BrowserWait::until($guest, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 6', 5_000);
    $fresh = historyOf($white, ChessGame::START_FEN);
    expect($fresh)->toMatchArray(['ply' => 6, 'shown' => 0, 'browsing' => true, 'newMoves' => 1, 'boardMatches' => true, 'back' => true, 'fresh' => true])
        ->and($fresh['barText'])->toContain('New move: 3… a6')->toContain('Back to the current position');
    // The spectator too: one step back from 3. Bb5, and the new move waits for him.
    $watching = historyOf($guest, fenAt($game, 4));
    expect($watching)->toMatchArray(['ply' => 6, 'shown' => 4, 'browsing' => true, 'newMoves' => 1, 'boardMatches' => true, 'fresh' => true]);
    shellShot($white, 'p55-1440-blitz-new-move');
    shellShot($guest, 'p55-1440-blitz-spectator');
    $sizes['1440 new move'] = $fresh;

    // One click on the bar and she is back on the game, on move, and plays.
    $white->locator('[data-test=history-back]')->click();
    expect(historyOf($white, fenAt($game, 6)))->toMatchArray(['shown' => 6, 'browsing' => false, 'boardMatches' => true])
        ->and(board($white, 'g.canMove'))->toBeTrue();
    playSan($white, 'Ba4');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 7', 5_000);
    // The spectator is still on his position until he presses End, then follows the game again.
    expect(historyOf($guest)['shown'])->toBe(4);
    pressKey($guest, 'End');
    expect(historyOf($guest, fenAt($game, 7))['browsing'])->toBeFalse();

    // Bert, on move on his phone, taps 1… e5 in the move strip, steps forward with the bar, and returns.
    $black->locator('[data-test=move-strip]')->getByText('e5', true)->first()->click();
    $phonePast = historyOf($black, fenAt($game, 2));
    expect($phonePast)->toMatchArray(['shown' => 2, 'browsing' => true, 'boardMatches' => true, 'past' => true, 'back' => true])
        ->and($phonePast['barText'])->toContain('Viewing 1… e5');
    $black->evaluate('() => document.querySelector("[data-test=history-bar]").scrollIntoView({ block: "center" })');
    shellShot($black, 'p55-375-blitz-past');
    $black->locator('[data-test=history-next]')->click();
    expect(historyOf($black, fenAt($game, 3))['shown'])->toBe(3);
    $black->locator('[data-test=history-last]')->click();
    expect(historyOf($black, fenAt($game, 7))['browsing'])->toBeFalse();
    $black->locator('[data-square="g8"]')->click();
    $black->locator('[data-square="f6"]')->click();
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 8', 5_000);
    $black->evaluate('() => window.scrollTo(0, 0)');
    $sizes['375 live'] = $liveBlack;
    $sizes['375 past'] = $phonePast;

    fwrite(STDERR, "\n[p55-blitz] ".json_encode($sizes));

    expect($game->refresh()->moves()->pluck('uci')->all())->toBe($ucis);

    // Nothing moves while browsing: board, bar and the player's own clock keep their place; 44 px steps; no cut-off text, no sideways scroll.
    foreach (['1440' => ['1440 live', '1440 past', '1440 new move'], '375' => ['375 live', '375 past']] as $width => $keys) {
        $first = $sizes[$keys[0]];
        foreach ($keys as $key) {
            expect($sizes[$key]['board'])->toBe($first['board'], "{$key} board")
                ->and($sizes[$key]['bar'])->toBe($first['bar'], "{$key} bar")
                ->and($sizes[$key]['clock'])->toBe($first['clock'], "{$key} clock")
                ->and($sizes[$key]['cut'])->toBe([], "{$key} text cut off")
                ->and($sizes[$key]['doc'][0])->toBeLessThanOrEqual($sizes[$key]['doc'][1], "{$key} sideways");
            foreach ($sizes[$key]['steps'] as [$w, $h]) {
                expect($w)->toBeGreaterThanOrEqual(44, "{$key} step width")->and($h)->toBeGreaterThanOrEqual(44, "{$key} step height");
            }
        }
    }

    foreach (['white' => $white, 'black' => $black, 'guest' => $guest] as $who => $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([], "console {$who}")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "responses {$who}");
    }

    // Positive control on the spectator's page.
    $guest->evaluate('() => { setTimeout(() => { throw new Error("control-throw"); }); fetch("/control-missing-page"); document.body.append(Object.assign(new Image(), { src: "/control-missing.png" })); }');
    BrowserWait::until($guest, '() => window.__errors.some((e) => e.includes("control-throw")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);
    BrowserWait::until($guest, '() => ('.BrowserConsole::BAD_RESPONSES.')().some((e) => e.includes("control-missing.png"))', 5_000);
});

test('the history bar fits a German phone: nothing cut off on an earlier position with a new move', function () {
    $games = app(ChessGameService::class);
    [$anna, $bert] = User::factory()->count(2)->create();
    $bert->forceFill(['locale' => 'de'])->save();
    $game = $games->start($anna, $bert);
    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6', 'e1g1', 'f8e7', 'f1e1', 'b7b5'] as $i => $uci) {
        $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
    }

    $page = blitzPage($bert, route('games.show', $game, false));
    $page->setViewportSize(375, 812);
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chess-game]"))?.state.ply === 12', 10_000);
    $live = historyOf($page);
    pressKey($page, 'Home');
    $start = historyOf($page, ChessGame::START_FEN);
    pressKey($page, 'ArrowRight');
    $games->move($game->refresh(), $anna, 'a4b3');
    $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=chess-game]")).resync()');
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).state.ply === 13', 10_000);
    $fresh = historyOf($page);

    $page->evaluate('() => document.querySelector("[data-test=history-bar]").scrollIntoView({ block: "center" })');
    shellShot($page, 'p55-375-blitz-de-new-move');
    fwrite(STDERR, "\n[p55-blitz-de] ".json_encode(compact('live', 'start', 'fresh')));

    expect($start['barText'])->toContain('Du siehst die Grundstellung')->toContain('Zurück zur aktuellen Stellung')
        ->and($fresh)->toMatchArray(['shown' => 1, 'newMoves' => 1, 'fresh' => true])
        ->and($fresh['barText'])->toContain('Neuer Zug: 7. Lb3');

    foreach (compact('live', 'start', 'fresh') as $key => $probe) {
        expect($probe['cut'])->toBe([], "{$key} text cut off")
            ->and($probe['bar'])->toBe($live['bar'], "{$key} bar")
            ->and($probe['doc'][0])->toBeLessThanOrEqual($probe['doc'][1], "{$key} sideways");
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

/*
 * The guest-only "New here?" strip puts the board of a watched game ~70 px lower. At 1440 x 900 the guest's
 * board gives that back (⚡show.blade.php, $boardWidth), so the history bar stays in the first viewport. At
 * 375 x 812 it cannot without shrinking the edge-to-edge board: the board alone ends at the tab bar (746 of
 * 748 px), the bar (96 px) sits under the bottom player as it does for players (measured 2026-09-28).
 */
test('a guest watching a live game has the history bar in the first viewport at 1440 under the "New here?" strip, and the players\' board does not move', function () {
    $games = app(ChessGameService::class);
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = $games->start($anna, $bert);
    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6'] as $i => $uci) {
        $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
    }
    $path = route('games.show', $game, false);
    $sizes = [];

    foreach ([1440 => 900, 375 => 812] as $width => $height) {
        $guest = visit('/robots.txt')->page();
        $guest->context()->addInitScript(BLITZ_COLLECTOR);
        $guest->setViewportSize($width, $height);
        $guest->goto(ComputeUrl::from($path));
        BrowserWait::until($guest, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chess-game]"))?.state.ply === 4 && document.fonts.status === "loaded"', 10_000);
        $guest->evaluate('() => window.scrollTo(0, 0)');
        $sizes["guest {$width}"] = historyOf($guest, fenAt($game, 4));
        shellShot($guest, "p55-{$width}-guest-fold");
        expect($guest->evaluate('() => window.__errors'))->toBe([], "console guest {$width}")
            ->and($guest->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "responses guest {$width}");

        $player = blitzPage($anna, $path);
        $player->setViewportSize($width, $height);
        BrowserWait::until($player, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chess-game]"))?.state.ply === 4 && document.fonts.status === "loaded"', 10_000);
        $player->evaluate('() => window.scrollTo(0, 0)');
        $sizes["player {$width}"] = historyOf($player, fenAt($game, 4));
    }

    fwrite(STDERR, "\n[p55-guest-fold] ".json_encode(array_map(fn (array $s) => array_intersect_key($s, array_flip(['bar', 'board', 'clock', 'floor', 'banner', 'doc'])), $sizes)));

    foreach ([1440, 375] as $width) {
        $guest = $sizes["guest {$width}"];
        expect($guest['banner'])->toBeTrue("guest {$width}: the strip is there")
            ->and($guest['boardMatches'])->toBeTrue()
            ->and($guest['doc'][0])->toBeLessThanOrEqual($guest['doc'][1], "guest {$width}: sideways");
    }
    [$top, $height] = $sizes['guest 1440']['bar'];
    expect($top + $height)->toBeLessThanOrEqual($sizes['guest 1440']['floor'], 'guest 1440: the history bar ends above the fold')
        ->and($sizes['guest 1440']['bar'][2])->toBe($sizes['guest 1440']['board'][2], 'guest 1440: the bar is as wide as the board')
        ->and($sizes['guest 375']['board'][1])->toBe(375, 'guest 375: the board stays edge to edge');

    // The players keep their board and bar where they were (P55: 1440 bar at 830, board 576 px; 375 board 375 px).
    expect($sizes['player 1440']['bar'][0])->toBe(830)
        ->and($sizes['player 1440']['board'][1])->toBe(576)
        ->and($sizes['player 375']['board'][1])->toBe(375);
});

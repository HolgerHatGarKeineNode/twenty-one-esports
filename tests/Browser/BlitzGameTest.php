<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

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
 * >= 400 answers to fetch/XHR, collected from the first script on.
 */
const BLITZ_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
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
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
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
    $pageA->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=searching]") !== null', 10_000);
    $pageB->locator('[data-test=find-opponent-button]')->click();

    $game = null;
    BrowserWait::until($pageB, '() => location.pathname.startsWith("/games/")', 10_000);
    BrowserWait::until($pageA, '() => location.pathname.startsWith("/games/")', 10_000);
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
    [$anna, $bert] = User::factory()->count(2)->create();

    $pageA = blitzPage($anna, '/chess');
    $pageB = blitzPage($bert, '/chess');
    BrowserWait::until($pageA, listsPlayer($bert), 10_000);

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
    [$anna, $bert] = User::factory()->count(2)->create();

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

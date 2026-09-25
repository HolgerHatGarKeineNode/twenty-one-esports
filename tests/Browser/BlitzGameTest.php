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

    $white->locator('[data-test=resign]')->click();
    $white->locator('[data-test=confirm-resign]')->click();

    BrowserWait::until($white, '() => document.querySelector("[data-test=outcome]")?.innerText === "Loss"', 5_000);
    BrowserWait::until($black, '() => document.querySelector("[data-test=outcome]")?.innerText === "Win"', 5_000);

    $game->refresh();
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->end_reason)->toBe(ChessEndReason::Resignation)
        ->and($game->result)->toBe('0-1')
        ->and($white->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate('() => window.__errors'))->toBe([]);
});

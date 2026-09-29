<?php

use App\Enums\BoardGameStatus;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FixtureBoardGame;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A board game next to chess, played to the end (plan "Mühle und Dame", P2)
|--------------------------------------------------------------------------
|
| Two players play the test-only three men's morris (FixtureBoardGame) by
| clicking points of the SVG board, over Reverb, while a guest watches: the
| board game core end to end, before nine men's morris and checkers exist.
| Measured at 390 and 1440 px; console, uncaught errors and every answer
| >= 400 are collected (BrowserConsole) and stay empty, with a positive
| control that shows the collector sees a throw and a failed answer.
|
| Two browser contexts, one in-process app (see BlitzGameTest): sessions in
| the database and fresh session/auth state per request give each context
| its own logged-in user.
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

    FixtureBoardGame::play();
});

function boardPage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function boardState(Page $page, string $expression): mixed
{
    return $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return '.$expression.'; }');
}

/**
 * Clicks the points of one move on the mover's board and waits until every
 * page shows the move (the others by push, not by polling).
 *
 * @param  list<Page>  $pages
 */
function clickBoardMove(Page $mover, array $pages, string $move, int $ply): void
{
    BrowserWait::until($mover, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);

    foreach (explode('-', $move) as $point) {
        $mover->locator('[data-point="'.$point.'"]')->click();
    }

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === '.$ply, 5_000);
    }
}

test('two players play the fixture board game to the end over Reverb while a guest watches, clean console, no overflow', function (int $width, int $height) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $anna, $bert);
    $path = route('board.show', $game, false);

    $white = boardPage($anna, $path, $width, $height);
    $black = boardPage($bert, $path, $width, $height);
    $guest = boardPage(null, $path, $width, $height);
    $pages = [$white, $black, $guest];

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"', 10_000);
    }

    expect(boardState($white, 'g.color'))->toBe('w')
        ->and(boardState($black, 'g.color'))->toBe('b')
        ->and(boardState($guest, 'g.color'))->toBeNull()
        // Nine points drawn from the rules' layout; White's placements all marked as targets, Black's none.
        ->and($white->evaluate('() => document.querySelectorAll("[data-test=board] [data-point]").length'))->toBe(9)
        ->and($white->evaluate('() => document.querySelectorAll("[data-test=board] [data-target=\"1\"]").length'))->toBe(9)
        ->and($black->evaluate('() => document.querySelectorAll("[data-test=board] [data-target=\"1\"]").length'))->toBe(0);

    // A click on the other side's turn does nothing: the board proposes nothing.
    $black->locator('[data-point="a1"]')->click();
    expect($game->refresh()->ply)->toBe(0);

    // Placing: White a1 c2 b3, Black b1 c1 a2; then White steps b3-c3, Black a2-a3, White c2-b2: a1 b2 c3.
    $moves = ['a1', 'b1', 'c2', 'c1', 'b3', 'a2', 'b3-c3', 'a2-a3'];
    foreach ($moves as $index => $move) {
        clickBoardMove($index % 2 === 0 ? $white : $black, $pages, $move, $index + 1);
    }

    // A step begun: the piece is marked and only its free neighbours are targets; the click is not sent yet.
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);
    $white->locator('[data-point="c2"]')->click();
    expect(boardState($white, 'g.clicks'))->toBe(['c2'])
        ->and($white->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-target=\"1\"]")].map((p) => p.dataset.point).sort()'))->toBe(['b2'])
        ->and($game->refresh()->ply)->toBe(8);
    shellShot($white, "board-{$width}-step-begun");
    $white->locator('[data-point="b2"]')->click();

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.status === "finished"', 5_000);
    }

    expect($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('three_in_a_row')
        ->and($game->moves()->pluck('move')->all())->toBe([...$moves, 'c2-b2']);

    $measured = [];

    foreach (['white' => $white, 'black' => $black, 'guest' => $guest] as $who => $page) {
        $result = $page->evaluate('() => document.querySelector("[data-test=result]")?.innerText');
        $measured[$who] = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height), top: Math.round(r.top) }; }');
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($result)->toContain($anna->displayName().' wins')->toContain('Three in a row')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=moves] li").length'))->toBe(9)
            // The board: square, inside the viewport, and the page does not scroll sideways.
            ->and($measured[$who]['left'])->toBeGreaterThanOrEqual(0)
            ->and($measured[$who]['right'])->toBeLessThanOrEqual($width)
            ->and($measured[$who]['width'])->toBe($measured[$who]['height'])
            ->and($measured[$who]['width'])->toBeGreaterThanOrEqual($width < 640 ? $width - 40 : 480)
            ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
            // No console error, no uncaught error, no failed request (Livewire roundtrips included).
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    shellShot($black, "board-{$width}-over-black");
    shellShot($guest, "board-{$width}-over-guest");
    fwrite(STDERR, "board {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $white->evaluate('() => { setTimeout(() => { throw new Error("board positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($white, '() => window.__errors.some((e) => e.includes("board positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390' => [390, 844],
    'desktop 1440' => [1440, 900],
]);

test('a player resigns from the page and the other board shows the result at once', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $anna, $bert);
    $path = route('board.show', $game, false);
    $white = boardPage($anna, $path, 1440, 900);
    $black = boardPage($bert, $path, 1440, 900);

    foreach ([$white, $black] as $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"', 10_000);
    }

    // Two clicks: the first asks, the second resigns.
    $black->locator('[data-test=resign]')->click();
    expect(BoardGame::query()->findOrFail($game->id)->status)->toBe(BoardGameStatus::Active);
    $black->locator('[data-test=resign]')->click();

    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.status === "finished"', 5_000);

    expect($white->evaluate('() => document.querySelector("[data-test=result]").innerText'))->toContain($anna->displayName().' wins')->toContain('Resignation')
        ->and($game->refresh()->result)->toBe('1-0')
        ->and($white->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate('() => window.__errors'))->toBe([]);
});

<?php

use App\Enums\BoardGameStatus;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Checkers played to a win on the live board (plan "Mühle und Dame", P4)
|--------------------------------------------------------------------------
|
| Two players play a short checkers ending by clicking squares of the SVG
| board, over Reverb: a capture chain clicked square by square, a man
| crowned on the far rank, and the new king taking the last piece from a
| distance. Measured at 390 and 1440 px; console, uncaught errors and every
| answer >= 400 are collected (BrowserConsole) and stay empty, with a
| positive control that shows the collector sees a throw and a failed
| answer. Sessions as in BoardGameTest.
|
*/

const CHECKERS_READY = '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"';

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

    CheckersGame::play();
});

function checkersPage(User $user, string $to, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/** The squares a click may go to next on this page, sorted. */
function checkersTargets(Page $page): array
{
    return $page->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-target=\"1\"]")].map((p) => p.dataset.point).sort()');
}

/**
 * Clicks the squares of one move on the mover's board and waits until both
 * pages show it.
 *
 * @param  list<Page>  $pages
 */
function clickCheckersMove(Page $mover, array $pages, string $move, int $ply): void
{
    BrowserWait::until($mover, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);

    foreach (preg_split('/[-x]/', $move) as $square) {
        $mover->locator('[data-point="'.$square.'"]')->click();
    }

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === '.$ply, 5_000);
    }
}

test('two players play checkers to a win: a capture chain square by square, a new king, a capture from a distance; clean console, no overflow', function (int $width, int $height) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    // White: men c1 and g7. Black: men d2, f4 and b6.
    $game = CheckersGame::setUp(app(BoardGameService::class)->start('checkers', $anna, $bert), ['c1' => 'w', 'g7' => 'w', 'd2' => 'b', 'f4' => 'b', 'b6' => 'b']);
    $path = route('board.show', $game, false);

    $white = checkersPage($anna, $path, $width, $height);
    $black = checkersPage($bert, $path, $width, $height);
    $pages = [$white, $black];

    foreach ($pages as $page) {
        BrowserWait::until($page, CHECKERS_READY, 10_000);
    }

    // Each player sees the board from its own side: a1 bottom left for White, top right for Black.
    $corner = '() => { const b = document.querySelector("[data-test=board]").getBoundingClientRect(); const a = document.querySelector("[data-test=board] [data-point=a1]").getBoundingClientRect(); return [a.left + a.width / 2 < b.left + b.width / 2 ? "left" : "right", a.top + a.height / 2 > b.top + b.height / 2 ? "bottom" : "top"]; }';
    expect($white->evaluate($corner))->toBe(['left', 'bottom'])
        ->and($black->evaluate($corner))->toBe(['right', 'top']);

    // The board: 32 dark squares drawn and clickable. Capturing is compulsory: only c1 may start a move.
    expect($white->evaluate('() => document.querySelectorAll("[data-test=board] rect").length'))->toBe(32)
        ->and($white->evaluate('() => document.querySelectorAll("[data-test=board] [data-point]").length'))->toBe(32)
        ->and(checkersTargets($white))->toBe(['c1'])
        ->and(checkersTargets($black))->toBe([]);

    // The chain c1 x d2 x f4: after each click the next landing square is the only target, and nothing is sent yet.
    $white->locator('[data-point="c1"]')->click();
    expect(checkersTargets($white))->toBe(['e3']);
    $white->locator('[data-point="e3"]')->click();
    expect(checkersTargets($white))->toBe(['g5'])
        ->and($game->refresh()->ply)->toBe(0);
    shellShot($white, "checkers-{$width}-chain-begun");
    $white->locator('[data-point="g5"]')->click();

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === 1', 5_000);
    }

    expect($black->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-piece^=\"b:\"]")].map((p) => p.dataset.point)'))->toBe(['b6']);

    // Black b6-c5, White crowns g7 on h8, Black c5-d4 onto the long diagonal, White's king takes it from h8.
    clickCheckersMove($black, $pages, 'b6-c5', 2);
    clickCheckersMove($white, $pages, 'g7-h8', 3);
    expect($black->evaluate('() => document.querySelector("[data-test=board] [data-point=h8]").dataset.piece'))->toBe('w:king');
    clickCheckersMove($black, $pages, 'c5-d4', 4);
    expect(checkersTargets($white))->toBe(['h8']);
    clickCheckersMove($white, $pages, 'h8xc3', 5);

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.status === "finished"', 5_000);
    }

    expect($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('no_pieces')
        ->and($game->moves()->pluck('notation')->all())->toBe(['c1xe3xg5', 'b6-c5', 'g7-h8', 'c5-d4', 'h8xc3']);

    $measured = [];

    foreach (['white' => $white, 'black' => $black] as $who => $page) {
        $result = $page->evaluate('() => document.querySelector("[data-test=result]")?.innerText');
        $measured[$who] = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); const p = document.querySelector("[data-test=board] [data-point=c3] circle[stroke=\\"#A1A1AA\\"]")?.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height), top: Math.round(r.top), piece: p ? Math.round(p.width) : null }; }');
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($result)->toContain($anna->displayName().' wins')->toContain('No pieces left')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=moves] li").length'))->toBe(5)
            // The board: square, inside the viewport, and the page does not scroll sideways.
            ->and($measured[$who]['left'])->toBeGreaterThanOrEqual(0)
            ->and($measured[$who]['right'])->toBeLessThanOrEqual($width)
            ->and($measured[$who]['width'])->toBe($measured[$who]['height'])
            ->and($measured[$who]['width'])->toBeGreaterThanOrEqual($width < 640 ? $width - 40 : 480)
            // The king on c3 fits inside its square (an eighth of the board).
            ->and($measured[$who]['piece'])->toBeGreaterThan(0)->toBeLessThanOrEqual((int) ceil($measured[$who]['width'] / 8))
            ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
            // No console error, no uncaught error, no failed request (Livewire roundtrips included).
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    shellShot($black, "checkers-{$width}-over-black");
    fwrite(STDERR, "checkers {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $white->evaluate('() => { setTimeout(() => { throw new Error("checkers positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($white, '() => window.__errors.some((e) => e.includes("checkers positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390' => [390, 844],
    'desktop 1440' => [1440, 900],
]);

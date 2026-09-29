<?php

use App\Enums\BoardGameStatus;
use App\Games\NineMensMorris;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Nine men's morris played to a win (plan "Mühle und Dame", P3)
|--------------------------------------------------------------------------
|
| Two players play a whole game of nine men's morris by clicking points of
| the SVG board, over Reverb, while a guest watches: eighteen placements,
| one of them closing a mill and taking a man (three clicks: the point, then
| the man removed), and a step that walls in every black man, so White wins
| with "No move left" (NineMensMorrisOn::BLOCKING_GAME). Measured at 390 and
| 1440 px; console, uncaught errors and every answer >= 400 are collected
| (BrowserConsole) and stay empty, with a positive control. Built as
| tests/Browser/BoardGameTest.php (the core with the fixture game).
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

    NineMensMorrisOn::play();
});

function morrisPage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * Clicks the points of one move on the mover's board and waits until every
 * page shows it.
 *
 * @param  list<Page>  $pages
 */
function morrisClickMove(Page $mover, array $pages, string $move, int $ply): void
{
    BrowserWait::until($mover, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);

    foreach (preg_split('/[-x]/', $move) as $point) {
        $mover->locator('[data-point="'.$point.'"]')->click();
    }

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === '.$ply, 5_000);
    }
}

test('two players play nine men\'s morris to a win over Reverb while a guest watches, clean console, no overflow', function (int $width, int $height) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start(NineMensMorris::SLUG, $anna, $bert);
    $path = route('board.show', $game, false);

    $white = morrisPage($anna, $path, $width, $height);
    $black = morrisPage($bert, $path, $width, $height);
    $guest = morrisPage(null, $path, $width, $height);
    $pages = [$white, $black, $guest];

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"', 10_000);
    }

    // The board: 24 points on 16 lines; every point a placement for White, none for Black.
    expect($white->evaluate('() => document.querySelectorAll("[data-test=board] [data-point]").length'))->toBe(24)
        ->and($white->evaluate('() => document.querySelectorAll("[data-test=board] line").length'))->toBe(16)
        ->and($white->evaluate('() => document.querySelectorAll("[data-test=board] [data-target=\"1\"]").length'))->toBe(24)
        ->and($black->evaluate('() => document.querySelectorAll("[data-test=board] [data-target=\"1\"]").length'))->toBe(0);

    $moves = NineMensMorrisOn::BLOCKING_GAME;

    foreach (array_slice($moves, 0, 16) as $index => $move) {
        morrisClickMove($index % 2 === 0 ? $white : $black, $pages, $move, $index + 1);
    }

    // f6 closes b6 d6 f6: the click is not sent yet, every black man is a target to take.
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);
    $white->locator('[data-point="f6"]')->click();
    expect(morrisState($white, 'g.clicks'))->toBe(['f6'])
        ->and($white->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-target=\"1\"]")].map((p) => p.dataset.point).sort()'))
        ->toBe(['d2', 'd3', 'd5', 'e3', 'e5', 'f2', 'f4', 'g1'])
        ->and($game->refresh()->ply)->toBe(16);
    shellShot($white, "morris-{$width}-take-a-man");
    $white->locator('[data-point="g1"]')->click();

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === 17', 5_000);
    }

    expect($guest->evaluate('() => document.querySelector("[data-point=g1]").dataset.piece'))->toBe('');

    morrisClickMove($black, $pages, $moves[17], 18);
    morrisClickMove($white, $pages, $moves[18], 19);

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.status === "finished"', 5_000);
    }

    expect($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('blocked')
        ->and($game->moves()->pluck('move')->all())->toBe($moves);

    $measured = [];

    foreach (['white' => $white, 'black' => $black, 'guest' => $guest] as $who => $page) {
        $result = $page->evaluate('() => document.querySelector("[data-test=result]")?.innerText');
        $measured[$who] = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height), top: Math.round(r.top) }; }');
        // The outermost points (a7 top left, g1 bottom right) inside the board's box.
        $corners = $page->evaluate('() => { const b = document.querySelector("[data-test=board]").getBoundingClientRect(); return ["a7", "g1"].map((id) => { const r = document.querySelector("[data-point=" + id + "]").getBoundingClientRect(); return r.left >= b.left && r.right <= b.right && r.top >= b.top && r.bottom <= b.bottom; }); }');
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($result)->toContain($anna->displayName().' wins')->toContain('No move left')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=moves] li").length'))->toBe(19)
            ->and($page->evaluate('() => document.querySelector("[data-test=board-game] h1").innerText'))->toBe("Nine Men's Morris")
            ->and($corners)->toBe([true, true])
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

    shellShot($black, "morris-{$width}-over-black");
    shellShot($guest, "morris-{$width}-over-guest");
    fwrite(STDERR, "morris {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $white->evaluate('() => { setTimeout(() => { throw new Error("morris positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($white, '() => window.__errors.some((e) => e.includes("morris positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390' => [390, 844],
    'desktop 1440' => [1440, 900],
]);

function morrisState(Page $page, string $expression): mixed
{
    return $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return '.$expression.'; }');
}

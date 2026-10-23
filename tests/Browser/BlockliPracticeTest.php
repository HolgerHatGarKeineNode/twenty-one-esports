<?php

use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockli practice against bots on the league's board (DerCaddy, 2026-10-23)
|--------------------------------------------------------------------------
|
| A guest plays the first lesson to the top row, sets the third lesson's block right under Black (a tap in the groove
| and a second tap, or a mouse's click), then a game against the Hodler: the bot answers, the tip button nudges without
| the move, the move is taken back. All on the board of a real game, with the game kept in the browser. Phone (touch)
| at 390, desktop (mouse) at 1440; console, uncaught errors and answers >= 400 stay empty, nothing runs off the side.
|
*/

/** Where a board point plus an offset in board units lies on the drawn board, from its top left corner (Black's board is turned). */
const PRACTICE_AT = <<<'JS'
    ([id, dx, dy]) => {
        const g = Alpine.$data(document.querySelector('[data-test=board-game]'));
        const p = g.layout.points.find((q) => q.id === id);
        const box = document.querySelector('[data-test=board]').getBoundingClientRect();
        const s = box.width / g.layout.width;
        let [x, y] = [p.x + dx, p.y + dy];
        if (g.color === 'b') [x, y] = [g.layout.width - x, g.layout.height - y];
        return { x: x * s, y: y * s };
    }
    JS;

/** The board's Alpine data, for a condition on it. */
const PRACTICE_BOARD = 'Alpine.$data(document.querySelector("[data-test=board-game]"))';

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    BlockliOn::play();
});

function practicePage(int $width, int $height, bool $touch): Page
{
    $visit = visit('/');
    $page = ($touch ? $visit->on()->mobile() : $visit)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.practice', 'blockli', false)));
    BrowserWait::until($page, '() => window.Alpine && '.PRACTICE_BOARD.'?.source !== null', 8_000);

    return $page;
}

function practiceTap(Page $page, bool $touch, string $point, int $dx = 0, int $dy = 0): void
{
    $position = $page->evaluate(PRACTICE_AT, [$point, $dx, $dy]);
    $board = $page->locator('[data-test=board]');
    $touch ? $board->tap(['position' => $position]) : $board->click(['position' => $position]);
}

function practicePress(Page $page, bool $touch, string $selector): void
{
    $touch ? $page->locator($selector)->tap() : $page->locator($selector)->click();
}

/** Until the player may move again: the lesson's Black or the bot has answered. */
function practiceMyMove(Page $page): void
{
    BrowserWait::until($page, '() => '.PRACTICE_BOARD.'.canMove', 8_000);
}

test('a guest learns on the league\'s board and plays the Hodler: lesson, block, bot, tip, take back; clean console, nothing overflows', function (int $width, int $height, bool $touch) {
    $page = practicePage($width, $height, $touch);

    // Lesson 1: two steps to the top row, Black answering in between.
    practiceMyMove($page);
    practiceTap($page, $touch, 'e8');
    practiceMyMove($page);
    practiceTap($page, $touch, 'e9');
    BrowserWait::until($page, '() => document.querySelector("[data-test=lesson-note]")?.textContent.includes("That is all it takes to win")', 5_000);
    expect($page->evaluate('() => '.PRACTICE_BOARD.'.state.status'))->toBe('finished');

    // Lesson 3: the block in the groove right under Black, on e1/f2 (the second tap or the mouse's click sets it).
    practicePress($page, $touch, '[data-test=lesson-dot] >> nth=2');
    practiceMyMove($page);
    practiceTap($page, $touch, 'e1/f2', -45);
    practiceTap($page, $touch, 'e1/f2', -45);
    BrowserWait::until($page, '() => document.querySelector("[data-test=lesson-note]")?.textContent.includes("two steps instead of one")', 5_000);
    expect($page->evaluate('() => '.PRACTICE_BOARD.'.state.moves.map((m) => m.notation)'))->toBe(['e1h']);

    // The Hodler answers the first move; the tip button says what kind of move fits; the move is taken back.
    practicePress($page, $touch, '[data-test=tab-bots]');
    practicePress($page, $touch, '[data-test=bot-play]');
    practiceMyMove($page);
    practiceTap($page, $touch, 'e2');
    BrowserWait::until($page, '() => '.PRACTICE_BOARD.'.state.ply === 2 && '.PRACTICE_BOARD.'.canMove', 8_000);
    practicePress($page, $touch, '[data-test=ask-tip]');
    BrowserWait::until($page, '() => /shortest way|block|side/.test(document.querySelector("[data-test=tip]").textContent)', 8_000);
    practicePress($page, $touch, '[data-test=undo]');
    BrowserWait::until($page, '() => '.PRACTICE_BOARD.'.state.ply === 0', 5_000);

    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    expect($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone' => [390, 844, true],
    'desktop' => [1440, 900, false],
]);

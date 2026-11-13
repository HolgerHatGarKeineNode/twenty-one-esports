<?php

use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\GoOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Go practice against bots on the league's board (DerCaddy, 2026-10-27)
|--------------------------------------------------------------------------
|
| A guest learns on the board of a real game, with the game kept in the
| browser and every move judged by the server: the first lesson's capture, the
| ko lesson (the point shows red until a move elsewhere was made), the count
| lesson (take the stone in your area, pass, pass again to end, win by the
| count), then a game against the Hodler: the bot answers, the tip button says
| what kind of move fits without the point, the move is taken back. Phone
| (touch) at 390, desktop (mouse) at 1440; console, uncaught errors and answers
| >= 400 stay empty, nothing runs off the side.
|
*/

/** The board's Alpine data, for a condition on it. */
const GO_LEARN_BOARD = 'Alpine.$data(document.querySelector("[data-test=board-game]"))';

/** Where an intersection lies on the drawn board, from its top left corner (Go's board is never turned). */
const GO_LEARN_AT = <<<'JS'
    (id) => {
        const g = Alpine.$data(document.querySelector('[data-test=board-game]'));
        const p = g.layout.points.find((q) => q.id === id);
        const box = document.querySelector('[data-test=board]').getBoundingClientRect();
        const s = box.width / g.layout.width;
        return { x: p.x * s, y: p.y * s };
    }
    JS;

/** Buttons under 44 px (R16): label and height. */
const GO_LEARN_LOW = <<<'JS'
    () => [...document.querySelectorAll('main button, main a.btn-p, main a.btn-s, main a.btn-w, [data-test=stone-input] button')]
        .filter((el) => el.checkVisibility() && el.getBoundingClientRect().height < 44)
        .map((el) => (el.dataset.test ?? el.tagName) + ' ' + el.getBoundingClientRect().height.toFixed(1))
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    GoOn::play();
});

function goLearnPage(int $width, int $height, bool $touch): Page
{
    $visit = visit('/');
    $page = ($touch ? $visit->on()->mobile() : $visit)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.go-practice', absolute: false)));
    BrowserWait::until($page, '() => window.Alpine && '.GO_LEARN_BOARD.'?.source !== null', 8_000);

    return $page;
}

/** Until the board stands still: a tap on it starts focus mode on a phone (focusMode.js), and the board refits. */
function goLearnSettle(Page $page): void
{
    $page->evaluate(<<<'JS'
        () => new Promise((resolve) => {
            let [last, same] = ['', 0];
            const check = () => {
                const r = document.querySelector('[data-test=board]').getBoundingClientRect();
                const now = [r.x, r.y, r.width].map(Math.round).join();
                same = now === last ? same + 1 : 0;
                last = now;
                if (same >= 20 && document.getAnimations().every((a) => a.playState !== 'running')) resolve(true);
                else requestAnimationFrame(check);
            };
            check();
        })
        JS);
}

/** A tap (touch) or a click (mouse) on an intersection; a finger's waits until the board stands still. */
function goLearnTap(Page $page, bool $touch, string $point): void
{
    if ($touch) {
        goLearnSettle($page);
    }

    $position = $page->evaluate(GO_LEARN_AT, $point);
    $board = $page->locator('[data-test=board]');
    $touch ? $board->tap(['position' => $position]) : $board->click(['position' => $position]);
}

/** A stone set: a finger taps twice (the first tap shows it), a mouse clicks once. */
function goLearnPlace(Page $page, bool $touch, string $point): void
{
    goLearnTap($page, $touch, $point);

    if ($touch) {
        goLearnTap($page, true, $point);
    }
}

function goLearnPress(Page $page, bool $touch, string $selector): void
{
    $touch ? $page->locator($selector)->tap() : $page->locator($selector)->click();
}

/** Until the player may move again: the lesson's White or the bot has answered. */
function goLearnMyMove(Page $page): void
{
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.canMove', 8_000);
}

function goLearnNote(Page $page, string $text): void
{
    BrowserWait::until($page, '() => document.querySelector("[data-test=lesson-note]")?.textContent.includes('.json_encode($text).')', 5_000);
}

test('a guest learns Go on the league\'s board and plays the Hodler: capture, ko, count, bot, tip, take back; clean console, nothing overflows', function (int $width, int $height, bool $touch) {
    $page = goLearnPage($width, $height, $touch);

    // Lesson 1: the white stone's last liberty is E4.
    goLearnMyMove($page);
    expect($page->locator('[data-test=lesson-title]')->innerText())->toBe('Liberties and capturing');
    goLearnPlace($page, $touch, 'e4');
    goLearnNote($page, 'Captured!');
    expect($page->evaluate('() => document.querySelector("[data-test=board] [data-point=e5]").dataset.piece'))->toBe('');

    // Lesson 5, the ko: D5 would take back at once, so it shows red; after a stone elsewhere and White's answer it takes.
    goLearnPress($page, $touch, '[data-test=lesson-dot] >> nth=4');
    goLearnMyMove($page);
    expect($page->locator('[data-test=lesson-title]')->innerText())->toBe('Ko');
    goLearnTap($page, $touch, 'd5');
    if ($touch) {
        goLearnSettle($page);
    }
    expect($page->evaluate('() => document.querySelector("[data-test=board] [data-preview]")?.dataset.preview'))->toBe('illegal')
        ->and($page->evaluate('() => '.GO_LEARN_BOARD.'.state.ply'))->toBe(0);
    goLearnPlace($page, $touch, 'h8');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    goLearnPlace($page, $touch, 'd5');
    goLearnNote($page, 'That is how ko works');
    expect($page->evaluate(GO_LEARN_LOW))->toBe([], 'lesson: buttons under 44 px');

    // Lesson 8, the count: White has passed; take the white stone in your area, then pass, and pass again to end it.
    goLearnPress($page, $touch, '[data-test=lesson-dot] >> nth=7');
    goLearnMyMove($page);
    expect($page->locator('[data-test=lesson-title]')->innerText())->toBe('The end and the count')
        ->and($page->locator('[data-test=count-line]')->isVisible())->toBeTrue();
    goLearnPlace($page, $touch, 'a8');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    goLearnPress($page, $touch, '[data-test=pass]');
    expect($page->locator('[data-test=pass]')->innerText())->toBe('Pass and end')
        ->and($page->locator('[data-test=stone-status]')->innerText())->toStartWith('Pass again to end the game? Black wins by');
    goLearnPress($page, $touch, '[data-test=pass]');
    goLearnNote($page, 'Won by 1.5 points');
    expect($page->evaluate('() => document.querySelectorAll("[data-test=board] [data-area]").length'))->toBeGreaterThan(0);

    // The Hodler answers the first move; the tip button says what kind of move fits, never the point; the move is taken back.
    goLearnPress($page, $touch, '[data-test=tab-bots]');
    goLearnPress($page, $touch, '[data-test=bot-play]');
    goLearnMyMove($page);
    goLearnPlace($page, $touch, 'e5');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    // A phone asks in the row above the board, in view with it; a desktop in the tips card.
    goLearnPress($page, $touch, $touch ? '[data-test=ask-tip-phone]' : '[data-test=ask-tip]');
    BrowserWait::until($page, '() => /take stones|atari|most is still open|Nothing is left/.test(document.querySelector("[data-test=tip]").textContent)', 15_000);
    $tip = $page->locator('[data-test=tip]')->innerText();
    expect($tip)->not->toMatch('/\b[A-HJ][1-9]\b/')
        ->and($page->evaluate(GO_LEARN_LOW))->toBe([], 'game: buttons under 44 px');
    goLearnPress($page, $touch, '[data-test=undo]');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 0', 5_000);

    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    expect($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone' => [390, 844, true],
    'desktop' => [1440, 900, false],
]);

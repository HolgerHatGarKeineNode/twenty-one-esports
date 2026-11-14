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
| The page's frame (design pass 2026-11-14), in German: at 320 x 640 the task
| and the board, then the verdict with its next action, stand on the first
| screen, the rail of the eight lessons is one row inside the page, every
| target has 44 px both ways; at 390 and 1440 no label is clipped. A game the
| player gave up names the bot as its winner and offers the same bot again
| (it said "You beat" in green: the board's `won(side)` hid the page's `won`).
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

/** Targets under 44 px in either direction (R16): every button and link of the page's body, and Go's bar. */
const GO_LEARN_SMALL = <<<'JS'
    () => [...document.querySelectorAll('main button, main a[href], [data-test=stone-input] button')]
        .filter((el) => el.checkVisibility() && (el.getBoundingClientRect().height < 44 || el.getBoundingClientRect().width < 44))
        .map((el) => (el.dataset.test ?? el.tagName) + ' ' + Math.round(el.getBoundingClientRect().width) + 'x' + Math.round(el.getBoundingClientRect().height))
    JS;

/**
 * How far the bottom of what `selector` shows lies under the first screen (0 or less: on it; null: not shown). The
 * first screen ends at the tab bar, or at the window's edge once focus mode has slid it away, and at Go's bar.
 */
const GO_LEARN_UNDER = <<<'JS'
    (selector) => {
        const el = [...document.querySelectorAll(selector)].find((node) => node.checkVisibility() && node.getBoundingClientRect().height > 0);
        if (!el) return null;
        const tabbar = document.documentElement.classList.contains('is-focus') ? 0 : parseFloat(getComputedStyle(document.body).paddingBottom) || 0;
        const bar = [...document.querySelectorAll('[data-test=stone-input], [data-page-bar]')].find((node) => node.checkVisibility() && getComputedStyle(node).position === 'fixed');
        const fold = Math.min(innerHeight - tabbar, bar ? bar.getBoundingClientRect().top : Infinity);
        return Math.round(el.getBoundingClientRect().bottom + scrollY - fold);
    }
    JS;

/** The rail of the lessons: its points, the rows they stand in, and how far it runs over its own box and the page. */
const GO_LEARN_RAIL = <<<'JS'
    () => {
        const rail = [...document.querySelectorAll('[data-test=lesson-progress-phone], [data-test=lesson-progress]')].find((el) => el.checkVisibility());
        if (!rail) return null;
        const points = [...rail.children].filter((el) => el.tagName === 'LI');
        return {
            points: points.length,
            rows: new Set(points.map((el) => Math.round(el.getBoundingClientRect().top))).size,
            over: Math.max(0, rail.scrollWidth - rail.clientWidth, Math.round(rail.getBoundingClientRect().right - document.documentElement.clientWidth)),
        };
    }
    JS;

/** Labels cut by their own box: an element with text of its own that is taller or wider than the box shows (an ellipsis is meant, and so is text only a screen reader gets, in a box of 1 px). */
const GO_LEARN_CLIPPED = <<<'JS'
    () => [...document.querySelectorAll('main *')]
        .filter((el) => el.checkVisibility() && !el.closest('svg') && !el.closest('[data-test=moves]') && [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== ''))
        .filter((el) => {
            const s = getComputedStyle(el);
            return el.clientWidth > 1 && el.clientHeight > 1 && s.display !== 'inline' && s.textOverflow !== 'ellipsis' && !['auto', 'scroll'].includes(s.overflowY) && (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1);
        })
        .map((el) => (el.dataset.test ?? el.tagName) + ': ' + el.innerText.trim().slice(0, 40))
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    GoOn::play();
});

function goLearnPage(int $width, int $height, bool $touch, ?string $lang = null): Page
{
    $visit = visit('/');
    $page = ($touch ? $visit->on()->mobile() : $visit)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.go-practice', $lang === null ? [] : ['lang' => $lang], false)));
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

/** The lesson's head: above the board on a phone, in the lesson's card from lg. */
function goLearnTitle(Page $page, bool $touch): string
{
    return $page->locator($touch ? '[data-test=lesson-title-phone]' : '[data-test=lesson-title]')->innerText();
}

/** A lesson picked from the list the head opens (`$index` from 0). */
function goLearnLesson(Page $page, bool $touch, int $index): void
{
    $suffix = $touch ? '-phone' : '';
    goLearnPress($page, $touch, '[data-test=lesson-menu'.$suffix.']');
    goLearnPress($page, $touch, '[data-test=lesson-dot'.$suffix.'] >> nth='.$index);
}

function goLearnNote(Page $page, string $text): void
{
    BrowserWait::until($page, '() => document.querySelector("[data-test=lesson-note]")?.textContent.includes('.json_encode($text).')', 5_000);
}

test('a guest learns Go on the league\'s board and plays the Hodler: capture, ko, count, bot, tip, take back; clean console, nothing overflows', function (int $width, int $height, bool $touch) {
    $page = goLearnPage($width, $height, $touch);

    // Lesson 1: the white stone's last liberty is E4.
    goLearnMyMove($page);
    expect(goLearnTitle($page, $touch))->toBe('Liberties and capturing');
    goLearnPlace($page, $touch, 'e4');
    goLearnNote($page, 'Captured!');
    expect($page->evaluate('() => document.querySelector("[data-test=board] [data-point=e5]").dataset.piece'))->toBe('');

    // Lesson 5, the ko: D5 would take back at once, so it shows red; after a stone elsewhere and White's answer it takes.
    goLearnLesson($page, $touch, 4);
    goLearnMyMove($page);
    expect(goLearnTitle($page, $touch))->toBe('Ko');
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
    goLearnLesson($page, $touch, 7);
    goLearnMyMove($page);
    expect(goLearnTitle($page, $touch))->toBe('The end and the count')
        ->and($page->locator('[data-test=count-line]')->isVisible())->toBeTrue();
    goLearnPlace($page, $touch, 'a8');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    goLearnPress($page, $touch, '[data-test=pass]');
    expect($page->locator('[data-test=pass]')->innerText())->toBe('Pass and end')
        ->and($page->locator('[data-test=stone-status]')->innerText())->toStartWith('Your pass ends the game: Black wins by');
    goLearnPress($page, $touch, '[data-test=pass]');
    goLearnNote($page, 'Won by 1.5 points');
    expect($page->evaluate('() => document.querySelectorAll("[data-test=board] [data-area]").length'))->toBeGreaterThan(0);

    // The Hodler answers the first move; the tip button says what kind of move fits, never the point; the move is taken back.
    goLearnPress($page, $touch, '[data-test=tab-bots]');
    goLearnPress($page, $touch, '[data-test=bot-play]');
    goLearnMyMove($page);
    goLearnPlace($page, $touch, 'e5');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    // A phone asks in the bar and reads the tip under the board, in view with it; a desktop in the tips card.
    $shown = $touch ? '[data-test=tip-phone]' : '[data-test=tip]';
    goLearnPress($page, $touch, $touch ? '[data-test=ask-tip-phone]' : '[data-test=ask-tip]');
    BrowserWait::until($page, '() => /take stones|atari|most is still open|Nothing is left/.test(document.querySelector("'.$shown.'").textContent)', 15_000);
    $tip = $page->locator($shown)->innerText();
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

test('Go practice in German at 320 x 640: the task and the board, then the verdict with its next action, are on the first screen; the rail is one row; every target has 44 px', function () {
    $page = goLearnPage(320, 640, true, 'de');
    goLearnMyMove($page);
    goLearnSettle($page);

    // The task: what to do, and the board to do it on.
    expect($page->evaluate(GO_LEARN_UNDER, '[data-test=lesson-task-phone]'))->toBeLessThanOrEqual(0, 'the task lies under the first screen')
        ->and($page->evaluate(GO_LEARN_UNDER, '[data-test=board]'))->toBeLessThanOrEqual(0, 'the board lies under the first screen')
        ->and($page->evaluate(GO_LEARN_RAIL))->toBe(['points' => 8, 'rows' => 1, 'over' => 0])
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'task: targets under 44 px');

    // A wrong stone: why, and "Von vorn" under it.
    goLearnPlace($page, true, 'c3');
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=practice]")).lessonState === "retry"', 5_000);
    goLearnSettle($page);
    expect($page->locator('[data-test=lesson-note-phone]')->innerText())->toStartWith('Der weiße Stein hat noch eine Freiheit')
        ->and($page->evaluate(GO_LEARN_UNDER, '[data-test=lesson-again-phone]'))->toBeLessThanOrEqual(0, '"Von vorn" lies under the first screen')
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'wrong stone: targets under 44 px');

    // Solved: the verdict waits with "Nächste Lektion" (R17: the page does not move on), a stone lies on the rail.
    goLearnPress($page, true, '[data-test=lesson-again-phone]');
    goLearnMyMove($page);
    goLearnPlace($page, true, 'e4');
    goLearnNote($page, 'Gefangen!');
    goLearnSettle($page);
    expect($page->evaluate(GO_LEARN_UNDER, '[data-test=lesson-next-phone]'))->toBeLessThanOrEqual(0, '"Nächste Lektion" lies under the first screen')
        ->and($page->evaluate(GO_LEARN_UNDER, '[data-test=board]'))->toBeLessThanOrEqual(0, 'solved: the board lies under the first screen')
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=lesson-progress-phone] > li[data-solved]").length'))->toBe(1)
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'solved: targets under 44 px')
        ->and(goLearnTitle($page, true))->toBe('Freiheiten und Schlagen');

    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    expect($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

test('Go practice in German clips no label, and a game given up names the bot as its winner with the same bot as the next game', function (int $width, int $height, bool $touch) {
    $page = goLearnPage($width, $height, $touch, 'de');
    goLearnMyMove($page);
    goLearnSettle($page);
    expect($page->evaluate(GO_LEARN_CLIPPED))->toBe([], 'lesson: clipped labels')
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'lesson: targets under 44 px');

    goLearnPress($page, $touch, '[data-test=tab-bots]');
    goLearnSettle($page);
    expect($page->evaluate(GO_LEARN_CLIPPED))->toBe([], 'bots: clipped labels')
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'bots: targets under 44 px');

    goLearnPress($page, $touch, '[data-test=bot-play]');
    goLearnMyMove($page);
    goLearnPlace($page, $touch, 'e5');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    goLearnSettle($page);
    expect($page->evaluate(GO_LEARN_CLIPPED))->toBe([], 'game: clipped labels');

    // Given up: the board's resign asks once, then ends the game.
    $page->evaluate('() => { const g = '.GO_LEARN_BOARD.'; g.confirmResign = true; return g.resign(); }');
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=practice]")).stage === "over" && '.GO_LEARN_BOARD.'.state.status !== "active"', 8_000);
    goLearnSettle($page);
    expect($page->locator('[data-test=bots]')->innerText())->not->toContain('geschlagen')
        ->and($page->locator('[data-test=result-head]')->innerText())->toBe('Hodler gewinnt')
        ->and($page->locator('[data-test=result-why]')->innerText())->toBe('Du hast aufgegeben.')
        ->and($page->locator('[data-test=play-again]')->isVisible())->toBeTrue()
        ->and($page->locator('[data-test=next-bot]')->isVisible())->toBeFalse()
        ->and($page->evaluate(GO_LEARN_UNDER, '[data-test=play-again]'))->toBeLessThanOrEqual(0, '"Nochmal" lies under the first screen')
        ->and($page->evaluate(GO_LEARN_CLIPPED))->toBe([], 'result: clipped labels')
        ->and($page->evaluate(GO_LEARN_SMALL))->toBe([], 'result: targets under 44 px')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone' => [390, 844, true],
    'desktop' => [1440, 900, false],
]);

test('Go practice in German on a phone: a tip has its own place under the board and moves nothing, and a game that is over has no tip and no take back', function (int $width, int $height) {
    $page = goLearnPage($width, $height, true, 'de');
    goLearnMyMove($page);
    goLearnPress($page, true, '[data-test=tab-bots]');
    goLearnPress($page, true, '[data-test=bot-play]');
    goLearnMyMove($page);
    goLearnPlace($page, true, 'e5');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    $page->evaluate('() => { Alpine.$data(document.querySelector("[data-test=practice]")).tip = null; scrollTo({ top: 0, behavior: "instant" }); }');
    goLearnSettle($page);

    $board = '() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return [r.left, r.top, r.width, r.height].map((n) => Math.round(n * 10) / 10); }';
    // The tip's place: as wide as the page's column, whole between the board and the bar.
    $place = <<<'JS'
        () => {
            const tip = document.querySelector('[data-test=tip-phone]').getBoundingClientRect();
            const board = document.querySelector('[data-test=board]').getBoundingClientRect();
            const bar = document.querySelector('[data-test=stone-input]').getBoundingClientRect();
            const range = document.createRange();
            range.selectNodeContents(document.querySelector('[data-test=tip-phone]'));
            const text = range.getBoundingClientRect();
            return { width: Math.round(tip.width), column: innerWidth - 32, above: Math.round(tip.top - board.bottom), cut: Math.round(text.bottom - bar.top), spill: Math.round(text.bottom - tip.bottom) };
        }
        JS;
    $before = $page->evaluate($board);

    // The tip button, in the bar: the tip stands under the board, and the board is where it was.
    goLearnPress($page, true, '[data-test=ask-tip-phone]');
    BrowserWait::until($page, '() => /schlagen|Atari|offen|nichts mehr/.test(document.querySelector("[data-test=tip-phone]").textContent)', 15_000);
    goLearnSettle($page);
    expect($page->evaluate($board))->toBe($before, 'the board moved under an asked tip');

    // The longest tip that comes by itself (the bot has passed), with the longest bot's name.
    $page->evaluate('() => { const p = Alpine.$data(document.querySelector("[data-test=practice]")); p.tip = { key: "botPassed", auto: true, by: "bot", at: Date.now(), text: p.cfg.labels.tips.botPassed.replaceAll(":name", "Hodler") }; }');
    goLearnSettle($page);
    $at = $page->evaluate($place);
    expect($page->evaluate($board))->toBe($before, 'the board moved under a tip that came by itself')
        ->and($at['width'])->toBe($at['column'], 'the tip is not as wide as the column')
        ->and($at['above'])->toBeGreaterThanOrEqual(0, 'the tip lies over the board')
        ->and($at['cut'])->toBeLessThanOrEqual(0, 'the tip reaches under the bar')
        ->and($at['spill'])->toBeLessThanOrEqual(0, 'the tip is taller than its place')
        ->and($page->locator('[data-test=turn-phone]')->count())->toBe(0);

    // "Von selbst zeigen" at rest: whole above the bar or wholly behind it, never half.
    $half = $page->evaluate(<<<'JS'
        () => {
            const bar = document.querySelector('[data-test=stone-input]').getBoundingClientRect();
            return [...document.querySelectorAll('[data-test=tips-on], [data-test=toggle-area], [data-test=undo]')].filter((el) => el.checkVisibility())
                .map((el) => [el.dataset.test, el.getBoundingClientRect()]).filter(([, r]) => r.top < bar.top && r.bottom > bar.top).map(([name]) => name);
        }
        JS);
    expect($half)->toBe([], 'half under the bar at rest');

    // Given up: the tips card, the tip's place and button and "Zurücknehmen" are gone with the game.
    $page->evaluate('() => { const g = '.GO_LEARN_BOARD.'; g.confirmResign = true; return g.resign(); }');
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=practice]")).stage === "over" && '.GO_LEARN_BOARD.'.state.status !== "active"', 8_000);
    goLearnSettle($page);
    $left = $page->evaluate('() => [...document.querySelectorAll("[data-test=tips], [data-test=tips-on], [data-test=tip-slot], [data-test=ask-tip], [data-test=ask-tip-phone], [data-test=undo]")].filter((el) => el.checkVisibility()).map((el) => el.dataset.test)');
    expect($left)->toBe([], 'tip controls or take back after the end')
        ->and($page->locator('[data-test=play-again]')->isVisible())->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    '320 x 640' => [320, 640],
    '390 x 844' => [390, 844],
]);

test('Go practice from lg: a game that is over has no tips card and no take back', function () {
    $page = goLearnPage(1440, 900, false, 'de');
    goLearnMyMove($page);
    goLearnPress($page, false, '[data-test=tab-bots]');
    goLearnPress($page, false, '[data-test=bot-play]');
    goLearnMyMove($page);
    goLearnPlace($page, false, 'e5');
    BrowserWait::until($page, '() => '.GO_LEARN_BOARD.'.state.ply === 2 && '.GO_LEARN_BOARD.'.canMove', 8_000);
    expect($page->locator('[data-test=tips]')->isVisible())->toBeTrue()
        ->and($page->locator('[data-test=undo]')->isVisible())->toBeTrue();

    $page->evaluate('() => { const g = '.GO_LEARN_BOARD.'; g.confirmResign = true; return g.resign(); }');
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=practice]")).stage === "over" && '.GO_LEARN_BOARD.'.state.status !== "active"', 8_000);
    goLearnSettle($page);
    $left = $page->evaluate('() => [...document.querySelectorAll("[data-test=tips], [data-test=tips-on], [data-test=ask-tip], [data-test=undo]")].filter((el) => el.checkVisibility()).map((el) => el.dataset.test)');
    expect($left)->toBe([], 'tip controls or take back after the end');
});

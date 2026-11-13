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
use Tests\Support\GoOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Go played to the count on the live board (DerCaddy, 2026-10-27)
|--------------------------------------------------------------------------
|
| Two players play a short 9 x 9 ending over Reverb with every way of the stone
| input: a tap shows the stone and a second tap or "Set stone" sets it (a
| finger), the stone under the pointer and its click (a mouse), the arrow keys
| and Enter on the focused board (a keyboard), a red point where no stone may
| go (a ko), and the pass that asks once more before it ends the game. Then
| the count decides, and both see each side's area. Phone (touch) at 390,
| desktop (mouse) at 1440; a 19 x 19 board on a phone fits the screen and its
| tap lands on the intersection meant. Console, uncaught errors and answers
| >= 400 stay empty, nothing runs off the side.
|
*/

const GO_LIVE_READY = '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"';

/** The board's Alpine data, for a condition on it. */
const GO_LIVE_BOARD = 'Alpine.$data(document.querySelector("[data-test=board-game]"))';

/** Where an intersection plus an offset in board units lies on the drawn board, from its top left corner (Go's board is never turned). */
const GO_LIVE_AT = <<<'JS'
    ([id, dx, dy]) => {
        const g = Alpine.$data(document.querySelector('[data-test=board-game]'));
        const p = g.layout.points.find((q) => q.id === id);
        const box = document.querySelector('[data-test=board]').getBoundingClientRect();
        const s = box.width / g.layout.width;
        let [x, y] = [p.x + dx, p.y + dy];
        if (g.color === 'b' && !g.layout.upright) [x, y] = [g.layout.width - x, g.layout.height - y];
        return { x: x * s, y: y * s };
    }
    JS;

/** The stone shown before it is set: [its intersection, legal or illegal], null when none is shown. */
const GO_LIVE_PREVIEW = '() => { const p = document.querySelector("[data-test=board] [data-preview]"); const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return p ? [g.stone, p.dataset.preview] : null; }';

/** Buttons of the stone input and the count under 44 px (R16): label and height. */
const GO_LIVE_LOW = <<<'JS'
    () => [...document.querySelectorAll('[data-test=stone-input] button, [data-test=count] button')]
        .filter((el) => el.checkVisibility() && el.getBoundingClientRect().height < 44)
        .map((el) => (el.dataset.test ?? el.tagName) + ' ' + el.getBoundingClientRect().height.toFixed(1))
    JS;

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

    GoOn::play();
});

function goLivePage(User $user, string $to, int $width, int $height, bool $touch): Page
{
    $visit = visit(BrowserLogin::url($user));
    $page = ($touch ? $visit->on()->mobile() : $visit)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/** A tap (touch) or a click (mouse) on the board at an intersection plus an offset in board units; a finger's waits until the board stands still. */
function goLiveTap(Page $page, bool $touch, string $point, int $dx = 0, int $dy = 0): void
{
    if ($touch) {
        goLiveSettle($page);
    }

    $position = $page->evaluate(GO_LIVE_AT, [$point, $dx, $dy]);
    $board = $page->locator('[data-test=board]');
    $touch ? $board->tap(['position' => $position]) : $board->click(['position' => $position]);
}

function goLiveHover(Page $page, string $point, int $dx = 0, int $dy = 0): void
{
    $page->locator('[data-test=board]')->hover(['position' => $page->evaluate(GO_LIVE_AT, [$point, $dx, $dy])]);
}

function goLivePress(Page $page, bool $touch, string $selector): void
{
    $touch ? $page->locator($selector)->tap() : $page->locator($selector)->click();
}

/** @param  list<Page>  $pages */
function goLivePly(array $pages, int $ply): void
{
    foreach ($pages as $page) {
        BrowserWait::until($page, '() => '.GO_LIVE_BOARD.'.state.ply === '.$ply, 5_000);
    }
}

function goLiveCanMove(Page $page): void
{
    BrowserWait::until($page, '() => '.GO_LIVE_BOARD.'.canMove', 5_000);
}

/**
 * Until the board stands still: on a phone the first tap on it starts focus mode (focusMode.js), the bars slide away and
 * the board refits, so the next tap is measured on the board where it ends up.
 */
function goLiveSettle(Page $page): void
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

test('two players play Go to the count with every way to set a stone: tap twice, Set stone, mouse, keyboard, a red ko, the pass that asks; clean console, no overflow', function (int $width, int $height, bool $touch) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    // A ko: Black's E5 takes the white stone on D5, and White may not take back at once.
    $game = GoOn::setUp(app(BoardGameService::class)->start('go-9', $anna, $bert), '9/9/9/3xo4/2xo1o3/3xo4/9/9/9 x - 0');
    $path = route('board.show', $game, false);

    $black = goLivePage($anna, $path, $width, $height, $touch);
    $white = goLivePage($bert, $path, $width, $height, $touch);
    $pages = [$black, $white];

    foreach ($pages as $page) {
        BrowserWait::until($page, GO_LIVE_READY, 10_000);
    }

    // Both see the board the same way round, A1 bottom left, with the coordinates of a go board: A to J without I, 1 to 9.
    $corner = '() => { const b = document.querySelector("[data-test=board]").getBoundingClientRect(); const a = document.querySelector("[data-test=board] [data-point=a1]").getBoundingClientRect(); return [a.left + a.width / 2 < b.left + b.width / 2 ? "left" : "right", a.top + a.height / 2 > b.top + b.height / 2 ? "bottom" : "top"]; }';
    $names = '() => [...document.querySelectorAll("[data-test=board] [data-coordinate]")].map((t) => t.dataset.coordinate).sort().join("")';
    expect($black->evaluate($corner))->toBe(['left', 'bottom'])
        ->and($white->evaluate($corner))->toBe(['left', 'bottom'])
        ->and($black->evaluate($names))->toBe('123456789ABCDEFGHJ')
        // The count as it stands: E5, surrounded by White's stones, is White's area for now.
        ->and($black->evaluate('() => document.querySelector("[data-test=count-line]").innerText'))->toBe('Count now: Black 3 · White 5 + 7.5 = 12.5 → White +9.5');

    // Ply 1, Black takes on E5: a finger sees the stone first and sets it with the second tap; a mouse sees it under the
    // pointer and sets it with its click.
    goLiveCanMove($black);
    if ($touch) {
        goLiveTap($black, true, 'e5', 12, -10);
        goLiveSettle($black);
        expect($black->evaluate(GO_LIVE_PREVIEW))->toBe(['e5', 'legal'])
            ->and($black->locator('[data-test=stone-status]')->innerText())->toBe('Tap the stone again or press Set stone.')
            ->and($black->locator('[data-test=place-stone]')->isVisible())->toBeTrue()
            ->and($game->refresh()->ply)->toBe(0);
        shellShot($black, "go-{$width}-preview");
        goLiveTap($black, true, 'e5');
    } else {
        goLiveHover($black, 'e5', 12, -10);
        expect($black->evaluate(GO_LIVE_PREVIEW))->toBe(['e5', 'legal'])
            ->and($black->locator('[data-test=stone-status]')->innerText())->toBe('Click a point to place your stone.')
            ->and($black->locator('[data-test=place-stone]')->isVisible())->toBeFalse();
        goLiveTap($black, false, 'e5');
    }
    goLivePly($pages, 1);

    // The white stone on D5 came off, on both boards; E5 carries the ring of the last stone.
    foreach ($pages as $page) {
        expect($page->evaluate('() => [document.querySelector("[data-test=board] [data-point=d5]").dataset.piece, document.querySelector("[data-test=board] [data-point=e5]").dataset.piece, !!document.querySelector("[data-test=board] [data-point=e5] [data-last]")]'))
            ->toBe(['', 'w:black', true]);
    }

    // Ply 2, White: D5 retakes the ko at once, so the stone shows red, "Set stone" stays away and the line says why; set
    // anyway, it is refused on the board without asking the server.
    goLiveCanMove($white);
    $touch ? goLiveTap($white, true, 'd5') : goLiveHover($white, 'd5');
    if ($touch) {
        goLiveSettle($white);
    }
    $illegal = 'No stone may go here (no liberty, or a ko).';
    expect($white->evaluate(GO_LIVE_PREVIEW))->toBe(['d5', 'illegal'])
        ->and($white->locator('[data-test=stone-status]')->innerText())->toBe($illegal)
        ->and($white->locator('[data-test=place-stone]')->isVisible())->toBeFalse();
    shellShot($white, "go-{$width}-red");
    goLiveTap($white, $touch, 'd5');
    BrowserWait::until($white, '() => document.querySelector("[data-test=board-error]")?.innerText === '.json_encode($illegal), 3_000);
    expect($game->refresh()->ply)->toBe(1);

    if ($touch) {
        // A finger: G5 shows, "Set stone" sets it.
        goLiveTap($white, true, 'g5');
        expect($white->evaluate(GO_LIVE_PREVIEW))->toBe(['g5', 'legal']);
        $white->locator('[data-test=place-stone]')->tap();
    } else {
        // The keyboard alone: Escape takes the red stone away, an arrow shows one in the middle (on Black's E5), two to the
        // right reach G5, Enter sets it.
        $board = $white->locator('[data-test=board]');
        $board->press('Escape');
        $board->press('ArrowUp');
        expect($white->evaluate('() => '.GO_LIVE_BOARD.'.stone'))->toBe('e5');
        $board->press('ArrowRight');
        $board->press('ArrowRight');
        expect($white->evaluate(GO_LIVE_PREVIEW))->toBe(['g5', 'legal'])
            ->and($white->locator('[data-test=stone-hint]')->innerText())->toBe('Stone at G5. Enter places it.')
            ->and($white->evaluate('() => document.activeElement === document.querySelector("[data-test=board]") && document.activeElement.matches(":focus-visible")'))->toBeTrue();
        shellShot($white, "go-{$width}-keyboard");
        $board->press('Enter');
    }
    goLivePly($pages, 2);

    // Ply 3, Black passes: nothing to confirm, White has not passed.
    goLiveCanMove($black);
    expect($black->locator('[data-test=pass]')->innerText())->toBe('Pass');
    goLivePress($black, $touch, '[data-test=pass]');
    goLivePly($pages, 3);

    // Ply 4, White: the line says Black passed; the first press of Pass only asks, with the count the game would end in.
    goLiveCanMove($white);
    expect($white->locator('[data-test=stone-status]')->innerText())->toBe('Black passed. If you pass too, the game ends and is counted.');
    goLivePress($white, $touch, '[data-test=pass]');
    expect($white->locator('[data-test=pass]')->innerText())->toBe('Pass and end')
        ->and($white->locator('[data-test=stone-status]')->innerText())->toBe('Pass again to end the game? White wins by 6.5.')
        ->and($game->refresh()->ply)->toBe(3)
        ->and($white->evaluate(GO_LIVE_LOW))->toBe([], 'pass: buttons under 44 px');
    shellShot($white, "go-{$width}-pass");
    goLivePress($white, $touch, '[data-test=pass]');

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => '.GO_LIVE_BOARD.'.state.status === "finished"', 5_000);
    }

    $game->refresh();
    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('0-1')
        ->and($game->end_reason)->toBe('score')
        ->and($game->moves()->pluck('notation')->all())->toBe(['E5', 'G5', 'Pass', 'Pass']);

    foreach (['black' => $black, 'white' => $white] as $who => $page) {
        $board = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; }');
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($page->evaluate('() => document.querySelector("[data-test=result]")?.innerText'))->toContain($bert->displayName().' wins')->toContain('Counted after both passed')
            ->and($page->evaluate('() => document.querySelector("[data-test=count-line]").innerText'))->toBe('Final count: Black 5 · White 4 + 7.5 = 11.5 → White +6.5')
            // The counted game shows each side's area: D5, the point Black's stones surround.
            ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-area]")].map((m) => m.closest("[data-point]").dataset.point + ":" + m.dataset.area)'))->toBe(['d5:w'])
            // The board is square and inside the viewport; the page does not scroll sideways.
            ->and($board['left'])->toBeGreaterThanOrEqual(0)
            ->and($board['right'])->toBeLessThanOrEqual($width)
            ->and(abs($board['height'] - $board['width']))->toBeLessThanOrEqual(2)
            ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
            ->and($page->evaluate('() => window.__errors'))->toBe([], "{$who}: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$who}: responses");
    }

    goLivePress($black, $touch, '[data-test=toggle-area]');
    expect($black->evaluate('() => document.querySelectorAll("[data-test=board] [data-area]").length'))->toBe(0)
        ->and($black->locator('[data-test=toggle-area]')->innerText())->toBe('Show area');
    shellShot($black, "go-{$width}-over");

    // Positive control: the collector sees a throw, a console error and a failed answer on this very page.
    $white->evaluate('() => { console.error("go console control"); setTimeout(() => { throw new Error("go positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($white, '() => window.__errors.some((e) => e.includes("go positive control")) && window.__errors.some((e) => e.includes("go console control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

test('19 x 19 on a phone: the board fits the screen, a tap shows the stone with its lines on the intersection meant, and the second tap sets it', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start('go-19', $anna, $bert, 'correspondence');
    $black = goLivePage($anna, route('board.show', $game, false), $width, $height, true);
    BrowserWait::until($black, '() => window.Alpine && '.GO_LIVE_BOARD.'?.canMove', 10_000);
    goLiveSettle($black);

    $box = $black->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) }; }');
    // A line is about 15 px apart at 320: a tap a third of a line off still lands on the intersection meant.
    goLiveTap($black, true, 'q16', 30, 30);
    goLiveSettle($black);
    expect($box['left'])->toBeGreaterThanOrEqual(0)
        ->and($box['right'])->toBeLessThanOrEqual($width)
        ->and($black->evaluate(GO_LIVE_PREVIEW))->toBe(['q16', 'legal'])
        // The finger's stone gets the row and the file through it.
        ->and($black->evaluate('() => document.querySelectorAll("[data-test=board] > line[pointer-events=none]").length'))->toBe(2)
        ->and($black->evaluate(GO_LIVE_LOW))->toBe([]);
    shellShot($black, "go19-{$width}-preview");

    goLiveTap($black, true, 'q16');
    BrowserWait::until($black, '() => '.GO_LIVE_BOARD.'.state.ply === 1', 5_000);
    [$scrollWidth, $clientWidth] = $black->evaluate(BrowserConsole::WIDTHS);

    expect($game->refresh()->moves()->pluck('notation')->all())->toBe(['Q16'])
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($black->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
]);

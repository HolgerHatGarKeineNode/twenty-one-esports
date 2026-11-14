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
| go (a ko), a first pass that asks once and the pass that asks once more
| before it ends the game. Then
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
    // The refusal stands once, in the bar's line: no second red line under it (it stood twice on a desktop).
    expect($white->evaluate('() => [...document.querySelectorAll("[data-test=stone-status], [data-test=board-error]")].filter((el) => el.checkVisibility() && el.innerText.trim() !== "").map((el) => el.innerText)'))->toBe([$illegal])
        ->and($white->evaluate(GO_LIVE_PREVIEW))->toBe(['d5', 'illegal'])
        ->and($game->refresh()->ply)->toBe(1);

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

    // Ply 3, Black passes for the first time in this game: the first press only asks, and says that the board is counted
    // exactly as it stands, with the count both passing would end in (a newcomer loses stones left standing otherwise).
    goLiveCanMove($black);
    expect($black->locator('[data-test=pass]')->innerText())->toBe('Pass');
    goLivePress($black, $touch, '[data-test=pass]');
    expect($black->locator('[data-test=pass]')->innerText())->toBe('Pass anyway')
        ->and($black->locator('[data-test=stone-status]')->innerText())->toBe('Counted as it stands, dead stones too. If both pass, White wins by 6.5.')
        ->and($black->evaluate(GO_LIVE_BAR)['cut'])->toBeFalse('first pass: the line is cut')
        ->and($game->refresh()->ply)->toBe(2)
        ->and($black->evaluate(GO_LIVE_LOW))->toBe([], 'first pass: buttons under 44 px');
    shellShot($black, "go-{$width}-first-pass");
    goLivePress($black, $touch, '[data-test=pass]');
    goLivePly($pages, 3);

    // Ply 4, White: the line says Black passed; the first press of Pass only asks, with the count the game would end in.
    goLiveCanMove($white);
    expect($white->locator('[data-test=stone-status]')->innerText())->toBe('Black passed. If you pass too, the game ends and is counted.');
    goLivePress($white, $touch, '[data-test=pass]');
    expect($white->locator('[data-test=pass]')->innerText())->toBe('Pass and end')
        ->and($white->locator('[data-test=stone-status]')->innerText())->toBe('Your pass ends the game: White wins by 6.5.')
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

/** Go's bar as it stands: its line (text, box, cut or not, type size), its buttons shown (name, width, height, label cut or not), and what runs off the side. */
const GO_LIVE_BAR = <<<'JS'
    () => {
        const bar = document.querySelector('[data-test=stone-input]');
        const line = bar.querySelector('[data-test=stone-status]');
        const box = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; };
        const buttons = [...bar.querySelectorAll('button')].filter((el) => el.checkVisibility());
        return {
            text: line.innerText, line: box(line), bar: box(bar), size: parseFloat(getComputedStyle(line).fontSize),
            cut: line.scrollHeight > line.clientHeight || line.scrollWidth > line.clientWidth,
            buttons: buttons.map((el) => [el.dataset.test, ...box(el), el.scrollWidth > el.clientWidth]),
            outside: buttons.filter((el) => el.getBoundingClientRect().right > innerWidth || el.getBoundingClientRect().left < 0).length,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

test('on a phone in German every line of Go\'s bar shows in full beside buttons 44 px high: the hint, a point taken, the first pass, a take-back, the other side\'s pass, the pass that ends', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    // White holds D5 and has closed the corner: A9 has no liberty for Black.
    $game = GoOn::setUp(app(BoardGameService::class)->start('go-9', $anna, $bert), '1o7/o8/9/9/3o5/9/9/9/9 x - 0');
    $black = goLivePage($anna, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width, $height, true);
    BrowserWait::until($black, GO_LIVE_READY, 10_000);
    goLiveCanMove($black);
    $bars = [];
    $measure = function (string $state) use ($black, &$bars): void {
        goLiveSettle($black);
        $bars[$state] = $black->evaluate(GO_LIVE_BAR);
    };

    // A stone shown where it may go: the hint beside "Setzen" and "Passen".
    goLiveTap($black, true, 'e5');
    $measure('hint');
    // A point that holds a stone: no stone is shown, and the line says why.
    goLiveTap($black, true, 'd5');
    $measure('taken');
    // A point without a liberty: the stone shows red.
    goLiveTap($black, true, 'a9');
    $measure('no liberty');
    // The first pass asks, with the count: the longest line of the bar.
    $black->locator('[data-test=pass]')->tap();
    $measure('first pass');
    // A stone instead: right after it "Zurücknehmen" stands in the pass's place.
    goLiveTap($black, true, 'e5');
    goLiveTap($black, true, 'e5');
    goLivePly([$black], 1);
    $measure('take back');
    // White passes: the line says so, and Black's pass asks once more before it ends the game.
    app(BoardGameService::class)->move($game->refresh(), $bert, 'pass');
    goLivePly([$black], 2);
    goLiveCanMove($black);
    $measure('passed');
    $black->locator('[data-test=pass]')->tap();
    $measure('pass and end');
    shellShot($black, "go-bar-de-{$width}-pass-and-end");

    fwrite(STDERR, PHP_EOL.implode(PHP_EOL, array_map(fn (string $state, array $m): string => sprintf('%d %-12s bar %s line %s %dpx cut %d  %s  "%s"', $width, $state, implode('x', $m['bar']), implode('x', $m['line']), $m['size'], $m['cut'], implode(' ', array_map(fn (array $b): string => "{$b[0]} {$b[1]}x{$b[2]}", $m['buttons'])), $m['text']), array_keys($bars), $bars)).PHP_EOL);

    expect(array_map(fn (array $m): string => $m['text'], $bars))->toBe([
        'hint' => 'Tippe den Stein nochmal an oder drücke „Setzen“.',
        'taken' => 'Hier liegt schon ein Stein.',
        'no liberty' => 'Hier darf kein Stein hin (keine Freiheit oder Ko).',
        'first pass' => 'Es zählt, was steht, auch tote Steine. Passen beide, gewinnt Weiß mit 88,5.',
        'take back' => 'Fernpartie · Zug 2 · Weiß am Zug',
        'passed' => 'Weiß hat gepasst. Passt du auch, endet die Partie und wird gezählt.',
        'pass and end' => 'Dein Pass beendet die Partie: Weiß gewinnt mit 10,5 Punkten.',
    ])
        // The bar never grows or shrinks with its line, so the board keeps its size from one line to the next.
        ->and(array_values(array_unique(array_map(fn (array $m): int => $m['bar'][1], $bars))))->toHaveCount(1);

    foreach ($bars as $state => $m) {
        expect($m['cut'])->toBeFalse("{$state}: the line is cut")
            ->and($m['size'])->toBeGreaterThanOrEqual(12, "{$state}: type under 12 px")
            ->and(array_filter($m['buttons'], fn (array $b): bool => $b[2] < 44 || $b[3]))->toBe([], "{$state}: a button under 44 px or with a cut label")
            ->and($m['outside'])->toBe(0, "{$state}: a button off the screen")
            ->and($m['overflow'])->toBeLessThanOrEqual(0, "{$state}: sideways overflow");
    }

    expect(array_map(fn (array $b): string => $b[0], $bars['hint']['buttons']))->toBe(['place-stone', 'pass'])
        ->and($black->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
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

test('stepping back through a go game shows the stones as they stood, a captured stone too', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $games = app(BoardGameService::class);
    $game = $games->start('go-9', $anna, $bert, 'correspondence');

    // Black's A2 takes the white stone on A1.
    foreach (['b1', 'a1', 'a2', 'e5'] as $ply => $move) {
        $game = $games->move($game->refresh(), $game->player($game->turn), $move, $ply + 1);
    }

    // The page gets the record without the pieces after every move (BoardGameService::snapshot(): what each move took
    // instead) and builds an earlier position from it.
    $page = goLivePage($anna, route('board.show', $game, false), 1440, 900, false);
    BrowserWait::until($page, '() => window.Alpine && '.GO_LIVE_BOARD.'?.state.moves.length === 4 && '.GO_LIVE_BOARD.'.state.moves.every((move) => !move.pieces)', 10_000);
    $stones = '() => ['.GO_LIVE_BOARD.'.shownPly, ...["a1", "a2", "b1", "e5"].map((id) => document.querySelector(`[data-test=board] [data-point=${id}]`).dataset.piece)]';

    expect($page->evaluate($stones))->toBe([4, '', 'w:black', 'w:black', 'b:white']);
    $page->locator('body')->press('Home');
    expect($page->evaluate($stones))->toBe([0, '', '', '', '']);
    $page->locator('body')->press('ArrowRight');
    $page->locator('body')->press('ArrowRight');
    expect($page->evaluate($stones))->toBe([2, 'b:white', '', 'w:black', ''])
        ->and($page->evaluate('() => !!document.querySelector("[data-test=board] [data-point=a1] [data-last]")'))->toBeTrue();
    $page->locator('body')->press('ArrowRight');
    expect($page->evaluate($stones))->toBe([3, '', 'w:black', 'w:black', '']);
    $page->locator('body')->press('ArrowLeft');
    expect($page->evaluate($stones))->toBe([2, 'b:white', '', 'w:black', '']);
    $page->locator('body')->press('End');
    expect($page->evaluate($stones))->toBe([4, '', 'w:black', 'w:black', 'b:white'])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

/** What of the actions under the board the bar cuts (px of each), where the names of the stone shown stand, and whether a point's node is still the one marked. */
const GO_LIVE_SLAB = <<<'JS'
    () => {
        const svg = document.querySelector('[data-test=board]');
        const top = document.querySelector('[data-test=stone-input]').getBoundingClientRect().top;
        const box = svg.getBoundingClientRect();
        return {
            peek: [...document.querySelectorAll('[data-fit-skip] button, [data-fit-skip] a')].filter((el) => el.checkVisibility())
                .map((el) => el.getBoundingClientRect()).filter((r) => r.top < top && r.bottom > top).map((r) => Math.round(top - r.top)),
            tags: [...svg.querySelectorAll(':scope > [data-tag]')].map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.tag, r.left >= box.left - 0.5 && r.right <= box.right + 0.5 && r.top >= box.top - 0.5 && r.bottom <= box.bottom + 0.5, Math.round(r.height)]; }),
            kept: svg.querySelector('[data-point=a1]').dataset.kept === '1',
            stones: svg.querySelectorAll('[data-stone]').length,
            ring: svg.querySelector('[data-point=e5] [data-last]')?.getAttribute('stroke') ?? null,
        };
    }
    JS;

test('on a phone in German nothing under Go\'s board is cut by the bar, the stone shown is named inside the board at its file and rank, and a stone set redraws its own point only', function (int $width, int $height, string $kind) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start($kind, $anna, $bert, 'correspondence');
    $black = goLivePage($anna, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width, $height, true);
    BrowserWait::until($black, '() => window.Alpine && '.GO_LIVE_BOARD.'?.canMove', 10_000);
    goLiveSettle($black);
    $black->evaluate('() => { document.querySelector("[data-test=board] [data-point=a1]").dataset.kept = "1"; }');

    goLiveTap($black, true, 'e5');
    goLiveSettle($black);
    $shown = $black->evaluate(GO_LIVE_SLAB);
    goLiveTap($black, true, 'e5');
    BrowserWait::until($black, '() => '.GO_LIVE_BOARD.'.state.ply === 1', 5_000);
    goLiveSettle($black);
    $set = $black->evaluate(GO_LIVE_SLAB);

    // 320 x 640 cut two of the buttons under the board by 5 px (measured 2026-11-14): they begin under the bar now.
    expect($shown['peek'])->toBe([])
        ->and($set['peek'])->toBe([])
        // The file and the rank of the stone shown, whole inside the board (the SVG cuts what leaves it), at least 9 px high.
        ->and(array_column($shown['tags'], 0))->toBe(['E', '5'])
        ->and(array_column($shown['tags'], 1))->toBe([true, true])
        ->and(min(array_column($shown['tags'], 2)))->toBeGreaterThanOrEqual(9)
        // The board is kept: the point marked before the stone is the same node after it.
        ->and($set['kept'])->toBeTrue()
        ->and($set['stones'])->toBe(1)
        // The last stone's ring on a black stone is the league's orange.
        ->and($set['ring'])->toBe('#F7931A')
        ->and($black->evaluate('() => window.__errors'))->toBe([])
        ->and($black->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    '9 x 9 at 320' => [320, 640, 'go-9'],
    '19 x 19 at 320' => [320, 640, 'go-19'],
    '19 x 19 at 390' => [390, 844, 'go-19'],
]);

test('a counted Go game on a phone in German: the winner, the margin and the reason stand under the board with "Nächsten Gegner suchen" on the first screen, each card carries its side\'s score', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = GoOn::setUp(app(BoardGameService::class)->start('go-9', $anna, $bert), '9/9/9/3xo4/2xo1o3/3xo4/9/9/9 x - 0');
    app(BoardGameService::class)->move($game->refresh(), $anna, 'pass');
    app(BoardGameService::class)->move($game->refresh(), $bert, 'pass');
    $page = goLivePage($anna, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width, $height, true);
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=next-opponent]")?.getClientRects().length > 0', 10_000);
    goLiveSettle($page);

    $end = $page->evaluate(<<<'JS'
        () => {
            const box = (selector) => document.querySelector(selector)?.getBoundingClientRect();
            const sheet = [...document.querySelectorAll('body *')].filter((el) => getComputedStyle(el).position === 'fixed' && el.getBoundingClientRect().height > 0 && el.getBoundingClientRect().top > innerHeight / 2);
            const edge = Math.min(innerHeight, ...sheet.map((el) => el.getBoundingClientRect().top));
            const button = box('[data-test=next-opponent]');
            const board = box('[data-test=board]');
            const cut = [...document.querySelectorAll('[data-test=result] *')].filter((el) => el.children.length === 0 && el.innerText?.trim() && !el.closest('.sr-only') && (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1) && getComputedStyle(el).display !== 'inline');

            return {
                button: [Math.round(button.height), button.bottom <= edge, button.top >= board.bottom],
                board: Math.round(board.width),
                head: document.querySelector('[data-test=result-head]').innerText,
                margin: document.querySelector('[data-test=result-margin]').innerText,
                why: document.querySelector('[data-test=result-why]').innerText,
                scores: [document.querySelector('[data-test=score-top]').innerText, document.querySelector('[data-test=score-bottom]').innerText],
                cut: cut.map((el) => el.innerText),
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            };
        }
    JS);

    // Before the design pass of 2026-11-14 the button stood below the first screen at 320 x 640 and at 390 x 844 (measured).
    expect($end['button'])->toBe([44, true, true])
        ->and($end['board'])->toBeGreaterThanOrEqual(200)
        ->and($end['head'])->toBe($bert->displayName().' gewinnt')
        ->and($end['margin'])->toBe('+9,5')
        ->and($end['why'])->toBe('Gezählt, nachdem beide gepasst haben')
        ->and($end['scores'])->toBe(['12,5', '3'])
        ->and($end['cut'])->toBe([])
        ->and($end['overflow'])->toBe(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
]);

/*
| The game's page around the board (design pass 2026-11-14, each measured red against the page before it).
*/

/** A go game of `$moves`, Black first. */
function goLivePlayed(User $black, User $white, array $moves): BoardGame
{
    $games = app(BoardGameService::class);
    $game = $games->start('go-9', $black, $white);

    foreach ($moves as $index => $move) {
        $game = $games->move($game->refresh(), $index % 2 === 0 ? $black : $white, $move);
    }

    return $game->refresh();
}

/** Everything to press in the game's part of the page (the title row, the board's column, the middle column, the chat), the board itself aside: name, width, height. */
const GO_LIVE_CONTROLS = <<<'JS'
    () => {
        return [...document.querySelectorAll('[data-test=board-game] button, [data-test=board-game] a[href]')]
            .filter((el) => el.checkVisibility() && el.getBoundingClientRect().width > 1 && !el.closest('svg'))
            .map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.test ?? el.innerText.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; });
    }
    JS;

test('beside a finished Go game\'s board the move list shows at least three whole rows', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = goLivePlayed($anna, $bert, ['e5', 'g5', 'c3', 'g3', 'pass', 'pass']);
    $page = goLivePage($anna, route('board.show', $game, false), $width, $height, false);
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=next-opponent]")?.getClientRects().length > 0', 10_000);
    goLiveSettle($page);

    $rows = $page->evaluate(<<<'JS'
        () => {
            const list = document.querySelector('[data-test=moves]').getBoundingClientRect();
            const whole = [...document.querySelectorAll('[data-test=move]')].map((el) => el.getBoundingClientRect()).filter((r) => r.top >= list.top - 0.5 && r.bottom <= list.bottom + 0.5);
            return new Set(whole.map((r) => Math.round(r.top))).size;
        }
    JS);

    // Before: half a row at 1440 x 900 (the list had 37 px between the result, the score and the player).
    expect($rows)->toBeGreaterThanOrEqual(3, "{$width} x {$height}: whole rows of the move list")
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'desktop 1440' => [1440, 900],
    'desktop 1280' => [1280, 720],
    'desktop 1024' => [1024, 768],
]);

test('on a phone nothing to press on a finished Go game\'s page is cut by the bars at the bottom, and everything can be scrolled clear of them', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = goLivePlayed($anna, $bert, ['e5', 'g5', 'c3', 'g3', 'pass', 'pass']);
    $page = goLivePage($anna, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width, $height, true);
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=next-opponent]")?.getClientRects().length > 0', 10_000);
    goLiveSettle($page);

    $under = $page->evaluate(<<<'JS'
        () => {
            const fixed = (el) => { for (let n = el; n && n !== document.body; n = n.parentElement) if (getComputedStyle(n).position === 'fixed') return true; return false; };
            const all = [...document.querySelectorAll('[data-test=board-page] button, [data-test=board-page] a[href]')]
                .filter((el) => el.checkVisibility() && el.getBoundingClientRect().width > 1 && !el.disabled && !el.closest('svg') && !el.closest('[data-test=moves]') && !fixed(el));
            const hit = (el, x, y) => { const at = document.elementFromPoint(x, y); return !!at && (el.contains(at) || at.contains(el)); };
            const name = (el) => el.dataset.test ?? el.innerText.trim().slice(0, 24);
            // As the page stands: in the window, and partly under something else.
            const cut = all.filter((el) => {
                const r = el.getBoundingClientRect();
                if (r.bottom <= 0 || r.top >= innerHeight) return false;
                const seen = [r.top + 2, r.top + r.height / 2, r.bottom - 2].filter((y) => y >= 0 && y < innerHeight).map((y) => hit(el, r.left + r.width / 2, y));
                return seen.some(Boolean) && !seen.every(Boolean);
            }).map(name);
            // Scrolled to: its middle and its lower edge can be pressed.
            const lost = all.filter((el) => {
                el.scrollIntoView({ block: 'center', behavior: 'instant' });
                const r = el.getBoundingClientRect();
                return !hit(el, r.left + r.width / 2, r.top + r.height / 2) || !hit(el, r.left + r.width / 2, r.bottom - 3);
            }).map(name);
            scrollTo({ top: 0, behavior: 'instant' });
            return { controls: all.map(name), cut, lost };
        }
    JS);

    // Before: "Fläche zeigen" stood half under the chat sheet at 390 x 844.
    expect($under['controls'])->toContain('toggle-area')->toContain('next-opponent')
        ->and($under['cut'])->toBe([], "{$width} x {$height}: cut by the bars")
        ->and($under['lost'])->toBe([], "{$width} x {$height}: cannot be scrolled clear");
})->with([
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
]);

test('everything to press on a Go game\'s page is at least 44 px wide and high, in the game and after it', function (int $width, int $height, bool $touch) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = goLivePlayed($anna, $bert, ['e5', 'g5', 'c3', 'g3']);
    $page = goLivePage($anna, route('board.show', $game, false), $width, $height, $touch);
    BrowserWait::until($page, GO_LIVE_READY, 10_000);
    goLiveCanMove($page);
    goLiveSettle($page);
    $small = fn (array $controls): array => array_values(array_filter($controls, fn (array $c): bool => $c[1] < 44 || $c[2] < 44));

    // Before: the "Lobby" link 39 x 20, a move's row 40 px high.
    $running = $page->evaluate(GO_LIVE_CONTROLS);
    expect(array_column($running, 0))->toContain('board-lobby-link')->toContain('move')->toContain('pass')
        ->and($small($running))->toBe([], "{$width} x {$height}, running: under 44 px");

    app(BoardGameService::class)->move($game->refresh(), $anna, 'pass');
    app(BoardGameService::class)->move($game->refresh(), $bert, 'pass');
    BrowserWait::until($page, '() => document.querySelector("[data-test=next-opponent]")?.getClientRects().length > 0', 10_000);
    goLiveSettle($page);
    $over = $page->evaluate(GO_LIVE_CONTROLS);
    expect(array_column($over, 0))->toContain('next-opponent')->toContain('toggle-area')
        ->and($small($over))->toBe([], "{$width} x {$height}, over: under 44 px");
})->with([
    'phone 320' => [320, 640, true],
    'phone 390' => [390, 844, true],
    'desktop 1440' => [1440, 900, false],
]);

test('the pass that asks is one question with two answers: "Keep playing" has the focus, Escape and "Keep playing" keep the game going', function (int $width, int $height, bool $touch) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = goLivePlayed($anna, $bert, ['e5', 'g5']);
    $page = goLivePage($anna, route('board.show', $game, false), $width, $height, $touch);
    BrowserWait::until($page, GO_LIVE_READY, 10_000);
    goLiveCanMove($page);
    $focus = '() => document.activeElement?.dataset?.test ?? document.activeElement?.tagName';
    $answered = '() => !document.querySelector("[data-test=keep-playing]").checkVisibility()';
    $shown = '() => [...document.querySelectorAll("[data-test=stone-input] button")].filter((el) => el.checkVisibility()).map((el) => el.dataset.test + ": " + el.innerText.trim())';

    // Black's first pass of the game asks: two answers, the safe one first and focused (R18).
    goLivePress($page, $touch, '[data-test=pass]');
    goLiveSettle($page);
    expect($page->evaluate($focus))->toBe('keep-playing')
        ->and($page->evaluate($shown))->toBe(['keep-playing: Keep playing', 'pass: Pass anyway'])
        ->and($page->locator('[data-test=stone-status]')->innerText())->toBe('Counted as it stands, dead stones too. If both pass, White wins by 7.5.')
        ->and($page->evaluate(GO_LIVE_LOW))->toBe([], 'the question: buttons under 44 px')
        ->and(array_filter($page->evaluate(GO_LIVE_BAR)['buttons'], fn (array $button): bool => $button[3]))->toBe([], 'the question: a label is cut');

    // Escape keeps the game going: the question is gone, nothing was sent, the focus is back on "Pass".
    $page->locator('[data-test=keep-playing]')->press('Escape');
    BrowserWait::until($page, $answered, 3_000);
    expect($page->evaluate($shown))->toBe(['pass: Pass'])
        ->and($page->evaluate($focus))->toBe('pass')
        ->and($game->refresh()->ply)->toBe(2);

    // Asked again, "Keep playing" does the same; the pass itself still passes.
    goLivePress($page, $touch, '[data-test=pass]');
    goLivePress($page, $touch, '[data-test=keep-playing]');
    BrowserWait::until($page, $answered, 3_000);
    expect($page->evaluate($shown))->toBe(['pass: Pass'])
        ->and($game->refresh()->ply)->toBe(2);
    goLivePress($page, $touch, '[data-test=pass]');
    goLivePress($page, $touch, '[data-test=pass]');
    goLivePly([$page], 3);

    expect($game->refresh()->moves()->pluck('notation')->all())->toBe(['E5', 'G5', 'Pass'])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 320 (touch)' => [320, 640, true],
    'desktop 1440 (keys)' => [1440, 900, false],
]);

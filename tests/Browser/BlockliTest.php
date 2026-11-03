<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Lottery;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockli played to a win on the live board (plan "Blockli", P2 and P6)
|--------------------------------------------------------------------------
|
| Two players play a short Blockli ending over Reverb with every way of the
| block input of DerCaddy's test board: a tap in a groove and a second tap,
| "Set a block" with a red place that cannot be set, Rotate and Confirm (a
| finger) or hover, R and click (a mouse), and a block set with the keyboard
| alone on Black's turned board (arrows, R, Enter). Phone (touch) at 390,
| desktop (mouse) at 1440. Console, uncaught errors and answers >= 400 stay
| empty, with a positive control on the same page.
|
*/

const BLOCKLI_READY = '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"';

/** Where a board point plus an offset in board units lies on the drawn board, from its top left corner (Black's board is turned). */
const BLOCKLI_AT = <<<'JS'
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

const BLOCKLI_PREVIEW = '() => { const p = Alpine.$data(document.querySelector("[data-test=board-game]")).preview; return p ? [p.crossing, p.dir, p.move !== null] : null; }';

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

    BlockliOn::play();
});

function blockliPage(User $user, string $to, int $width, int $height, bool $touch): Page
{
    $visit = visit(BrowserLogin::url($user));
    $page = ($touch ? $visit->on()->mobile() : $visit)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // The last `focus-mode` event (focusMode.js), from the first moment: the board refits on it (BlockliTournamentPhoneTest).
    $page->context()->addInitScript(<<<'JS'
        window.__lastFocusModeHidden = null;
        addEventListener('focus-mode', (event) => { window.__lastFocusModeHidden = event.detail.hidden; });
        JS);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/** A tap (touch) or a click (mouse) on the board at a point plus an offset in board units. */
function blockliTap(Page $page, bool $touch, string $point, int $dx = 0, int $dy = 0): void
{
    $position = $page->evaluate(BLOCKLI_AT, [$point, $dx, $dy]);
    $board = $page->locator('[data-test=board]');
    $touch ? $board->tap(['position' => $position]) : $board->click(['position' => $position]);
}

function blockliHover(Page $page, string $point, int $dx = 0, int $dy = 0): void
{
    $page->locator('[data-test=board]')->hover(['position' => $page->evaluate(BLOCKLI_AT, [$point, $dx, $dy])]);
}

/** @param  list<Page>  $pages */
function blockliPly(array $pages, int $ply): void
{
    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === '.$ply, 5_000);
    }
}

function blockliCanMove(Page $page): void
{
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 5_000);
}

test('two players play Blockli to a win with every way to set a block: groove tap, red place, rotate, hover, keyboard on the turned board; clean console, no overflow', function (int $width, int $height, bool $touch) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $path = route('board.show', $game, false);

    $white = blockliPage($anna, $path, $width, $height, $touch);
    $black = blockliPage($bert, $path, $width, $height, $touch);
    $pages = [$white, $black];

    foreach ($pages as $page) {
        BrowserWait::until($page, BLOCKLI_READY, 10_000);
    }

    // Each player sees the board from its own side: a1 bottom left for White, top right for Black.
    $corner = '() => { const b = document.querySelector("[data-test=board]").getBoundingClientRect(); const a = document.querySelector("[data-test=board] [data-point=a1]").getBoundingClientRect(); return [a.left + a.width / 2 < b.left + b.width / 2 ? "left" : "right", a.top + a.height / 2 > b.top + b.height / 2 ? "bottom" : "top"]; }';
    // The square names stand on the edge as on a chessboard (Ben, 2026-10-11): files along each player's bottom row from
    // the left, ranks up the left column; on Black's turned board they read upright, i and 9 at its bottom left.
    $names = '() => { const b = document.querySelector("[data-test=board]").getBoundingClientRect(); const at = (t) => { const r = document.querySelector(`[data-test=board] [data-coordinate="${t}"]`).getBoundingClientRect(); return [r.left + r.width / 2 < b.left + b.width / 2 ? "left" : "right", r.top + r.height / 2 > b.top + b.height / 2 ? "bottom" : "top"]; }; return { count: document.querySelectorAll("[data-test=board] [data-coordinate]").length, a: at("a"), i: at("i"), one: at("1"), nine: at("9") }; }';
    expect($white->evaluate($corner))->toBe(['left', 'bottom'])
        ->and($black->evaluate($corner))->toBe(['right', 'top'])
        ->and($white->evaluate($names))->toBe(['count' => 18, 'a' => ['left', 'bottom'], 'i' => ['right', 'bottom'], 'one' => ['left', 'bottom'], 'nine' => ['left', 'top']])
        ->and($black->evaluate($names))->toBe(['count' => 18, 'a' => ['right', 'bottom'], 'i' => ['left', 'bottom'], 'one' => ['left', 'top'], 'nine' => ['left', 'bottom']])
        ->and($white->evaluate('() => document.querySelector("[data-test=mode-block]").innerText.replace(/\s+/g, " ").trim()'))->toBe('Set a block 10');

    // Ply 1, White: a tap in the groove left of the crossing a1/b2 shows a horizontal block there; a second tap sets it.
    blockliTap($white, $touch, 'a1/b2', -30);
    expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['a1/b2', 'h', true])
        ->and($white->evaluate('() => document.querySelector("[data-test=board] [data-preview]")?.dataset.preview'))->toBe('legal')
        ->and($game->refresh()->ply)->toBe(0);
    shellShot($white, "blockli-{$width}-preview");
    blockliTap($white, $touch, 'a1/b2', -30);
    blockliPly($pages, 1);

    // Ply 2, Black: a tap on d3 moves the pawn (Black's board is turned).
    blockliCanMove($black);
    blockliTap($black, $touch, 'd3');
    blockliPly($pages, 2);

    // Ply 3, White: Set a block; the vertical block on a1/b2 would cross a1h, so it shows red and Confirm stays off.
    blockliCanMove($white);
    $touch ? $white->locator('[data-test=mode-block]')->tap() : $white->locator('[data-test=mode-block]')->click();
    $touch ? blockliTap($white, true, 'a1/b2', 0, 30) : blockliHover($white, 'a1/b2', 0, 30);
    expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['a1/b2', 'v', false])
        ->and($white->evaluate('() => document.querySelector("[data-test=board] [data-preview]")?.dataset.preview'))->toBe('illegal')
        ->and($white->locator('[data-test=set-block]')->isDisabled())->toBeTrue()
        ->and($white->locator('[data-test=block-hint]')->innerText())->toBe('No block fits here.');
    shellShot($white, "blockli-{$width}-red");

    if ($touch) {
        // A finger: tap the groove of c1/d2, Rotate, Confirm.
        blockliTap($white, true, 'c1/d2', -30);
        $white->locator('[data-test=rotate-block]')->tap();
        expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c1/d2', 'v', true]);
        $white->locator('[data-test=set-block]')->tap();
    } else {
        // A mouse: the block follows the pointer, R turns it, a click sets it.
        blockliHover($white, 'c1/d2', -30);
        expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c1/d2', 'h', true]);
        $white->locator('[data-test=board]')->press('r');
        expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c1/d2', 'v', true]);
        blockliTap($white, false, 'c1/d2', -30);
    }
    blockliPly($pages, 3);

    // Ply 4, Black, keyboard alone: Enter on "Set a block", an arrow shows the block in the middle, R turns it,
    // the right arrow moves it one crossing to the right as Black sees the board (towards the a-file), Enter sets it.
    blockliCanMove($black);
    $mode = $black->locator('[data-test=mode-block]');
    $mode->press('Enter');
    expect($black->locator('[data-test=block-hint]')->innerText())->toBe('Arrow keys move the block, R turns it, Enter sets it, Escape cancels.');
    $mode->press('ArrowUp');
    [$start, $dir] = $black->evaluate(BLOCKLI_PREVIEW);
    $mode->press('r');
    $mode->press('ArrowRight');
    [$moved, $turned, $legal] = $black->evaluate(BLOCKLI_PREVIEW);
    expect($turned)->toBe($dir === 'h' ? 'v' : 'h')
        ->and($legal)->toBeTrue()
        ->and(ord($moved[0]))->toBe(ord($start[0]) - 1)
        ->and(substr($moved, 1, 1))->toBe(substr($start, 1, 1))
        ->and($black->locator('[data-test=block-hint]')->innerText())->toBe("Block at {$moved}, ".($turned === 'h' ? 'horizontal' : 'vertical').'. Enter sets it, R turns it.');
    shellShot($black, "blockli-{$width}-keyboard-black");
    $mode->press('Enter');
    blockliPly($pages, 4);

    // Plies 5 to 7: White walks e8, Black steps aside, White reaches e9.
    blockliCanMove($white);
    blockliTap($white, $touch, 'e8');
    blockliPly($pages, 5);
    blockliCanMove($black);
    blockliTap($black, $touch, 'c3');
    blockliPly($pages, 6);
    blockliCanMove($white);
    blockliTap($white, $touch, 'e9');

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.status === "finished"', 5_000);
    }

    $notations = $game->refresh()->moves()->pluck('notation')->all();
    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('goal')
        ->and([$notations[0], $notations[1], $notations[2], $notations[4], $notations[5], $notations[6]])->toBe(['a1h', 'd3', 'c1v', 'e8', 'c3', 'e9'])
        ->and($notations[3])->toBe(substr($moved, 0, 2).$turned);

    foreach (['white' => $white, 'black' => $black] as $who => $page) {
        $board = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; }');
        [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

        expect($page->evaluate('() => document.querySelector("[data-test=result]")?.innerText'))->toContain($anna->displayName().' wins')->toContain('Reached the far side')
            // The drawing keeps its 880 x 1020 proportions inside the viewport, and the page does not scroll sideways.
            ->and($board['left'])->toBeGreaterThanOrEqual(0)
            ->and($board['right'])->toBeLessThanOrEqual($width)
            ->and(abs($board['height'] - $board['width'] * 1020 / 880))->toBeLessThanOrEqual(2)
            ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
            ->and($page->evaluate('() => window.__errors'))->toBe([], "{$who}: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$who}: responses");
    }

    shellShot($black, "blockli-{$width}-over-black");

    // Positive control: the collector sees a throw, a console error and a failed answer on this very page.
    $white->evaluate('() => { console.error("blockli console control"); setTimeout(() => { throw new Error("blockli positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($white, '() => window.__errors.some((e) => e.includes("blockli positive control")) && window.__errors.some((e) => e.includes("blockli console control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

test('while a block is shown, Rotate, Confirm and Move the pawn stand in Blockli\'s bar, every button is at least 44 px high and Confirm is the only filled one; a mouse sets it by its click, without them', function (int $width, int $height, bool $touch) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    // A second game of Anna's, so the match dock has something to carry on this page.
    app(BoardGameService::class)->start('blockli', $anna, User::factory()->create());
    // In German, the longest labels ("Figur bewegen"): R11, a button never wraps its text.
    $white = blockliPage($anna, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width, $height, $touch);
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);

    $touch ? $white->locator('[data-test=mode-block]')->tap() : $white->locator('[data-test=mode-block]')->click();
    $touch ? blockliTap($white, true, 'c4/d5', -30) : blockliHover($white, 'c4/d5', -30);
    // Filled = the orange primary ground (bg-btc); the pressed toggle keeps a dark tint (bg-btc-press).
    // Nothing stands over the board (a player on an iPhone SE, 2026-10-14): on a phone Rotate, Confirm and Move the pawn
    // take the bar's row and "Set a block" gives way to them; a mouse keeps the toggle alone.
    $buttons = $white->evaluate('() => [...document.querySelectorAll("[data-test=block-input] button")].filter((b) => b.checkVisibility()).map((b) => { const range = document.createRange(); range.selectNodeContents(b); return { test: b.dataset.test, h: Math.round(b.getBoundingClientRect().height), filled: b.classList.contains("bg-btc"), lines: Math.round(range.getBoundingClientRect().height / (parseFloat(getComputedStyle(b).lineHeight) || parseFloat(getComputedStyle(b).fontSize) * 1.5)), text: b.innerText.trim() }; })');
    fwrite(STDERR, PHP_EOL."[bar buttons {$width}] ".json_encode($buttons).PHP_EOL);

    expect(collect($buttons)->pluck('test')->all())->toBe($touch ? ['rotate-block', 'set-block', 'mode-move'] : ['mode-block'])
        ->and(collect($buttons)->every(fn (array $b): bool => $b['h'] >= 44))->toBeTrue()
        ->and(collect($buttons)->where('filled', true)->pluck('test')->all())->toBe($touch ? ['set-block'] : [])
        ->and(collect($buttons)->where('lines', '>', 1)->pluck('text')->all())->toBe([])
        ->and($white->evaluate('() => window.__errors'))->toBe([]);
    // From lg Blockli's bar heads the side column, not fixed: the dock must not take it for a page bar and reserve room
    // for its tab under it (BitcoinCaddy, PR #8: the root's scroll-padding-bottom grew to about 270 px).
    $padding = $white->evaluate('() => parseFloat(getComputedStyle(document.documentElement).scrollPaddingBottom) || 0');
    fwrite(STDERR, "[scroll-padding-bottom {$width}] {$padding}".PHP_EOL);
    expect($touch || $padding < 100)->toBeTrue("scroll-padding-bottom {$padding} px on a desktop board page");
})->with([
    'phone 320 (touch)' => [320, 640, true],
    'phone 360 (touch)' => [360, 740, true],
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

/** Whatever stands over the board, apart from the drawing itself: test name or tag and height of every visible element in its box. */
const BLOCKLI_OVER_BOARD = <<<'JS'
    () => [...document.querySelectorAll('[data-board-box] *')].filter((el) => !el.closest('svg') && el.checkVisibility() && el.getBoundingClientRect().height > 0).map((el) => (el.dataset.test ?? el.tagName.toLowerCase()) + ' ' + Math.round(el.getBoundingClientRect().height))
    JS;

/** Blockli's bar and what it holds, as [top, bottom]; `line` is the text of its line while it shows; null for what is hidden. */
const BLOCKLI_BAR = <<<'JS'
    () => {
        const box = (selector) => {
            const el = document.querySelector(selector);
            if (!el || !el.checkVisibility()) return null;
            const r = el.getBoundingClientRect();
            return [Math.round(r.top), Math.round(r.bottom)];
        };
        const line = document.querySelector('[data-test=block-status]');
        return { bar: box('[data-test=block-input]'), line: line.checkVisibility() ? line.innerText : null, toggle: box('[data-test=mode-block]'), rotate: box('[data-test=rotate-block]'), confirm: box('[data-test=set-block]'), move: box('[data-test=mode-move]') };
    }
    JS;

test('nothing of the block input stands over the board: the hint and, with a block shown, Rotate, Confirm and Move the pawn stand in Blockli\'s bar, on an iPhone SE too', function (int $width, int $height, bool $touch) {
    // A player on an iPhone SE (3rd generation), 2026-10-14: the hint over the board covered it so that no block could be
    // set there. On its 200 px board the hint took the top rows and the buttons' card half of the rest.
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $white = blockliPage($anna, route('board.show', $game, false), $width, $height, $touch);
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    $before = $white->evaluate(BLOCKLI_BAR);

    // "Set a block": the hint stands in the bar's line (on a phone in place of whose move it is, from lg under the toggle).
    $touch ? $white->locator('[data-test=mode-block]')->tap() : $white->locator('[data-test=mode-block]')->click();
    expect($white->evaluate(BLOCKLI_OVER_BOARD))->toBe([])
        ->and($white->evaluate(BLOCKLI_BAR)['line'])->toBe($touch ? 'Tap a groove: the block shows where it lands.' : 'Point at a groove: the block follows.');

    // A block on the 8th rank, right under the board's top edge, where the hint used to stand. On a phone the tap on the
    // board also starts focus mode (focusMode.js): the tab bar and the page's bars slide down, then the board refits.
    if ($touch) {
        // On an iPhone SE focus mode is on from the start (boardGame.js fitBoard, PR #9: the board is too short for its floor otherwise).
        // Settled only once its `focus-mode` event came: `data-focus` is set 220 ms before the board refits (2026-10-24).
        $white->evaluate('() => { window.__focusSettled = window.__lastFocusModeHidden === true; addEventListener("focus-mode", () => { window.__focusSettled = true; }, { once: true }); }');
    }
    $touch ? blockliTap($white, true, 'c8/d9', -30) : blockliHover($white, 'c8/d9', -30);
    if ($touch) {
        BrowserWait::until($white, '() => window.__focusSettled && document.getAnimations().every((a) => a.playState !== "running")', 5_000);
    }
    $shown = $white->evaluate(BLOCKLI_BAR);

    expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c8/d9', 'h', true])
        ->and($white->evaluate(BLOCKLI_OVER_BOARD))->toBe([]);

    if ($touch) {
        // The three buttons take the bar's row, which does not grow; the line and the toggle give way to them.
        expect([$shown['line'], $shown['toggle']])->toBe([null, null])
            ->and($shown['rotate'][0])->toBeGreaterThanOrEqual($shown['bar'][0])
            ->and(max($shown['rotate'][1], $shown['confirm'][1], $shown['move'][1]))->toBeLessThanOrEqual($shown['bar'][1])
            ->and($shown['bar'][1] - $shown['bar'][0])->toBe($before['bar'][1] - $before['bar'][0], 'the bar keeps its height')
            ->and($shown['bar'][1])->toBeLessThanOrEqual($height);
    } else {
        expect([$shown['rotate'], $shown['line']])->toBe([null, 'Click to set the block.']);
    }

    // Confirm in the bar sets the block; a mouse clicks it.
    $touch ? $white->locator('[data-test=set-block]')->tap() : blockliTap($white, false, 'c8/d9', -30);
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === 1', 5_000);

    expect($game->refresh()->moves()->pluck('notation')->all())->toBe(['c8h'])
        // Back to whose move it is.
        ->and($white->evaluate(BLOCKLI_BAR)['line'])->toBe($touch ? $white->evaluate('() => Alpine.$data(document.querySelector("[data-test=board-game]")).statusLine') : null)
        ->and($white->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'iPhone SE in Safari (touch)' => [375, 553, true],
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

test('one time before the repetition draw the board warns over it and beside it, the 21st time draws; the race standing stands beside the board', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $game = $service->start('blockli', $anna, $bert);
    $play = function (array $moves) use ($service, &$game): void {
        foreach ($moves as $move) {
            $game->refresh();
            $game = $service->move($game, $game->player($game->turn), $move, $game->ply + 1);
        }
    };

    // To and fro nineteen times: the start stands there for the 20th time, White to move.
    foreach (range(1, 19) as $round) {
        $play(['e1-e2', 'e9-e8', 'e2-e1', 'e8-e9']);
    }

    $white = blockliPage($anna, route('board.show', $game, false), 390, 844, true);
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);

    // Over the board for a few seconds, like a push message; beside the board for as long as the position stands.
    expect($game->refresh()->ply)->toBe(76)
        ->and($white->locator('[data-test=repetition-warning]')->innerText())->toBe('The same position 20 times. Once more and the game is drawn.')
        ->and($white->locator('[data-test=repetition-note]')->innerText())->toBe('The same position 20 times. Once more and the game is drawn.')
        ->and($white->locator('[data-test=standing]')->innerText())->toBe('Race: '.$anna->displayName().' needs 8 steps and has 10 blocks, '.$bert->displayName().' needs 8 steps and has 10 blocks. A block counts 1.5 steps: Both are level.');
    shellShot($white, 'blockli-390-repetition-warning');

    // The warning stands over the board and the pawn still moves.
    blockliTap($white, true, 'e2');
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === 77', 5_000);

    $play(['e9-e8', 'e2-e1', 'e8-e9']);

    expect($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1/2-1/2')
        ->and($game->end_reason)->toBe('repetition');

    $white->goto(ComputeUrl::from(route('board.show', $game, false)));
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.state.status === "finished"', 10_000);

    expect($white->evaluate('() => document.querySelector("[data-test=result]")?.innerText'))->toContain('The same position 21 times')
        ->and($white->locator('[data-test=repetition-warning]')->isVisible())->toBeFalse()
        ->and($white->locator('[data-test=repetition-note]')->isVisible())->toBeFalse()
        ->and($white->evaluate('() => window.__errors'))->toBe([]);
});

/*
| P6: the Blockli pages measured as an admin in German (and English for the
| shots) at six widths: the board page while it is the admin's move with a
| block shown, the lobby (the game's page) and the Blockli card on /play.
*/

/** Overflow, cut text, squeezed sentences, words spilling out of their cell, low buttons; inside `main`. */
const BLOCKLI_MEASURE = <<<'JS'
    () => {
        const main = document.querySelector('main') ?? document.body;
        const visible = (el) => el.checkVisibility();
        const label = (el) => (el.dataset.test ?? el.tagName.toLowerCase()) + ' "' + (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';
        // Screen-reader-only text (sr-only, a 1 px box) is no visible text.
        const all = [...main.querySelectorAll('*')].filter((el) => !(el instanceof SVGElement) && visible(el) && el.getBoundingClientRect().width > 1);
        const scrolls = (el) => ['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(el).overflowX);
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            // Text wider than its own box (truncate with an ellipsis is deliberate and not counted).
            cut: all.filter((el) => el.children.length === 0 && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== 'ellipsis').map((el) => label(el) + ' ' + el.scrollWidth + '>' + el.clientWidth + ' in ' + (el.parentElement?.dataset.test ?? el.parentElement?.className ?? '')),
            // A sentence of more than 6 words wrapped into a box under 240 px wide and over 60 px high (user, 2026-10-07).
            // The whole page: the game chat beside main squeezed its line once.
            squeezed: [...document.querySelectorAll('p, h1, h2, h3, li, dd, header span')].filter((el) => visible(el) && el.innerText.trim().split(/\s+/).length > 6 && el.getBoundingClientRect().width < 240 && el.getBoundingClientRect().height > 60).map(label),
            // A word running out of its cell into the next one: a text leaf whose right edge passes its parent's.
            spill: all.filter((el) => el.children.length === 0 && el.innerText?.trim() && el.parentElement && !scrolls(el.parentElement) && getComputedStyle(el).position !== 'absolute'
                && el.getBoundingClientRect().right > el.parentElement.getBoundingClientRect().right + 1).map(label),
            low: [...main.querySelectorAll('button, a.btn-p, a.btn-s, a.btn-w')].filter((el) => visible(el) && el.getBoundingClientRect().height < 44).map((el) => label(el) + ' ' + el.getBoundingClientRect().height.toFixed(1) + ' px, parent ' + el.parentElement.getBoundingClientRect().height.toFixed(1) + ' ' + getComputedStyle(el.parentElement.parentElement).display + '/' + getComputedStyle(el.parentElement.parentElement).flexDirection),
            // From lg the lobby names the game in the header's context bar, outside main.
            credit: [...document.querySelectorAll('[data-game-credit="blockli"]')].filter(visible).length,
        };
    }
    JS;

function blockliShot(Page $page, string $name): void
{
    $dir = getenv('BLOCKLI_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('as an admin in German the board page, the lobby and the /play card squeeze, cut and spill nothing at six widths; buttons 44 px; quiet console and network', function () {
    $admin = User::factory()->withPubkey(str_repeat('ad', 32))->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $admin, User::factory()->create()), 'e7 e3 10 10 w - 0');
    // A running cup game, so the lobby's "Live now" shows its cup row (DerCaddy 2026-10-15).
    $cup = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Watch Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-watch-cup',
    ]);
    foreach (['Clara Langername-Spielerin', 'Dirk Cup'] as $index => $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($cup, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($cup);
    $rows = [];

    foreach ([[390, 844], [1024, 768], [1280, 800], [1440, 900], [1600, 900], [1920, 1080]] as [$width, $height]) {
        foreach (['de', 'en'] as $lang) {
            if ($lang === 'en' && ! in_array($width, [390, 1440], true)) {
                continue;
            }

            $pages = [
                'board' => [route('board.show', ['boardGame' => $game, 'lang' => $lang], false), '[data-test=board-game]'],
                'lobby' => [route('board.lobby', ['board' => 'blockli', 'lang' => $lang], false), '[data-test=board-lobby]'],
                'play' => [route('play', ['lang' => $lang], false), '[data-test=play-game-blockli]'],
            ];

            foreach ($pages as $name => [$to, $ready]) {
                $page = blockliPage($admin, $to, $width, $height, false);
                BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.querySelector('.json_encode($ready).') !== null', 10_000);

                if ($name === 'board') {
                    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
                    $page->locator('[data-test=mode-block]')->click();
                    blockliHover($page, 'c4/d5', -30);
                }

                if ($name === 'play') {
                    $page->evaluate('() => document.querySelector("[data-test=play-game-blockli]").scrollIntoView({ block: "center" })');
                }

                if ($name === 'lobby') {
                    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=live-game-cup]")].filter((el) => el.checkVisibility()).length'))->toBe(1, "lobby {$lang} {$width}: cup row");
                }

                $m = $page->evaluate(BLOCKLI_MEASURE);
                $label = "{$name} {$lang} {$width}";
                $rows[] = sprintf('%-6s %s %4d  overflow %d  cut %d  squeezed %d  spill %d  low %d  credit %d', $name, $lang, $width, $m['overflow'], count($m['cut']), count($m['squeezed']), count($m['spill']), count($m['low']), $m['credit']);

                if (in_array($width, [390, 1440], true)) {
                    blockliShot($page, "blockli-{$name}-{$lang}-{$width}");
                }

                expect($m['overflow'])->toBeLessThanOrEqual(0, "{$label}: sideways overflow")
                    ->and($m['cut'])->toBe([], "{$label}: cut text")
                    ->and($m['squeezed'])->toBe([], "{$label}: squeezed text")
                    ->and($m['spill'])->toBe([], "{$label}: spilled words")
                    ->and($m['low'])->toBe([], "{$label}: buttons under 44 px")
                    ->and($m['credit'])->toBeGreaterThanOrEqual(1, "{$label}: credit")
                    ->and($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
                    ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$label}: responses");
                $page->close();
            }
        }
    }

    fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL);
});

test('a drawn decider settled by the race standing says so with its margin in the bracket, at 390 and 1440', function () {
    // User, 2026-10-11 (DerCaddy's proposal, PR #5): the score alone would read like a draw; the box names the standing.
    Lottery::alwaysLose();
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Race Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-standing-label', 'draw_decider' => Tournament::DRAW_DECIDER_STANDING,
    ]);
    foreach (['Anna Racer', 'Bert Racer'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);
    $service = app(BoardGameService::class);
    $live = fn (): BoardGame => BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    $match = fn (): TournamentMatch => TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots.participant')->sole();
    foreach ([1, 0] as $loserSlot) {
        $service->resign($live(), User::query()->findOrFail((int) $match()->slots[$loserSlot]->participant->memberIds()[0]));
    }
    $decider = BlockliOn::setUp($live(), 'e8 e3 10 10 w - 0');
    $service->offerDraw($decider, $decider->white);
    $service->acceptDraw($decider->refresh(), $decider->black);
    expect($match()->result['decided'])->toBe('standing');

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page = visit(route('tournaments.show', $tournament, false).'?lang=de')->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament, false).'?lang=de'));
        BrowserWait::until($page, '() => !!document.querySelector("[data-test=match-decision]")', 10_000);
        $m = $page->evaluate('() => { const el = document.querySelector("[data-test=match-decision]"); el.scrollIntoView(); const r = el.getBoundingClientRect(); const box = el.closest("[data-test=match-box]").getBoundingClientRect(); const w = el.closest("[data-test=match-box]").querySelector("[data-test=match-box-watch]"), wr = w?.getBoundingClientRect(); return { text: el.innerText.trim(), visible: el.checkVisibility(), inside: r.left >= box.left && r.right <= box.right + 0.5, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, watch: w ? { href: new URL(w.href).pathname, label: w.getAttribute("aria-label"), visible: w.checkVisibility(), inside: wr.left >= box.left && wr.right <= box.right + 0.5, h: Math.round(wr.height), hit: Math.round(parseFloat(getComputedStyle(w, "::after").height)) } : null }; }');
        fwrite(STDERR, PHP_EOL."[standing label {$width}] ".json_encode($m).PHP_EOL);

        expect($m['text'])->toBe('1–1, Entscheidungspartie remis: Rennstand, 1 Schritt vorn')
            ->and($m['visible'])->toBeTrue()
            ->and($m['inside'])->toBeTrue()
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            // DerCaddy 2026-10-15: a board game's match has no number, so its key opens the board for anyone watching.
            ->and($m['watch'])->toMatchArray(['href' => route('board.show', $decider, false), 'label' => 'M1-1 zuschauen', 'visible' => true, 'inside' => true])
            ->and($m['watch']['hit'])->toBeGreaterThanOrEqual(44)
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
    Lottery::determineResultNormally();
});

test('after the first leg the bracket, the live page and the game banner show the standing and the game under way, at 390 and 1440', function () {
    // DerCaddy 2026-10-15: a won first leg showed nowhere, so players took their results for lost.
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Legs Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-legs-standing',
    ]);
    foreach (['Anna Racer', 'Bert Racer'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $live = fn (): BoardGame => BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots.participant')->sole();
    $first = $live();
    app(BoardGameService::class)->resign($first, User::query()->findOrFail((int) $match->slots[1]->participant->memberIds()[0]));
    $second = $live();
    $admin = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $box = '() => { const el = document.querySelector("[data-test=match-legs]"); el.scrollIntoView(); const r = el.getBoundingClientRect(), box = el.closest("[data-test=match-box]"), b = box.getBoundingClientRect(); return { text: el.innerText.trim(), visible: el.checkVisibility(), inside: r.left >= b.left - 0.5 && r.right <= b.right + 0.5, h: Math.round(r.height), boxW: Math.round(b.width), scores: [...box.querySelectorAll(".font-display")].map((s) => s.innerText.trim()), href: new URL(box.querySelector("[data-test=match-box-watch]").href).pathname, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }';
    $banner = '() => { const el = document.querySelector("[data-test=tournament-banner-step]"); if (el.checkVisibility()) el.scrollIntoView(); const r = el.getBoundingClientRect(); return { text: el.innerText.trim(), visible: el.checkVisibility(), right: Math.round(r.right), vw: innerWidth, h: Math.round(r.height), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }';

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        foreach (['show' => route('tournaments.show', $tournament, false), 'live' => route('tournaments.live', $tournament, false)] as $where => $url) {
            $page = blockliPage($admin, $url.'?lang=de', $width, $height, false);
            BrowserWait::until($page, '() => !!document.querySelector("[data-test=match-legs]")', 10_000);
            $m = $page->evaluate($box);
            fwrite(STDERR, PHP_EOL."[legs {$where} {$width}] ".json_encode($m).PHP_EOL);

            // DerCaddy 2026-10-30: every game of the pairing opens from the box, the first leg too.
            $g = $page->evaluate('() => { const el = document.querySelector("[data-test=match-games]"); const b = el.closest("[data-test=match-box]").getBoundingClientRect(), r = el.getBoundingClientRect(); return { text: el.innerText.replace(/\s+/g, " ").trim(), visible: el.checkVisibility(), inside: r.left >= b.left - 0.5 && r.right <= b.right + 0.5, links: [...el.querySelectorAll("[data-test=match-game]")].map((a) => ({ href: new URL(a.href).pathname, label: a.getAttribute("aria-label"), hit: Math.round(parseFloat(getComputedStyle(a, "::after").height)), inside: a.getBoundingClientRect().right <= b.right + 0.5 })) }; }');
            fwrite(STDERR, PHP_EOL."[games {$where} {$width}] ".json_encode($g).PHP_EOL);

            expect($m)->toMatchArray(['text' => 'Stand 1–0 · Partie 2 läuft', 'visible' => true, 'inside' => true, 'scores' => ['1', '0'], 'href' => route('board.show', $second, false)])
                ->and($g)->toMatchArray(['text' => 'Partien 1: 1–0 2 live', 'visible' => true, 'inside' => true])
                ->and(array_column($g['links'], 'href'))->toBe([route('board.show', $first, false), route('board.show', $second, false)])
                ->and(array_column($g['links'], 'label'))->toBe(['Partie 1 von M1-1 ansehen', 'Partie 2 von M1-1 ansehen'])
                ->and(min(array_column($g['links'], 'hit')))->toBeGreaterThanOrEqual(44)
                ->and(array_column($g['links'], 'inside'))->toBe([true, true])
                ->and($m['overflow'])->toBeLessThanOrEqual(0)
                ->and($page->evaluate('() => window.__errors'))->toBe([], "{$where} {$width}: console")
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$where} {$width}: responses");
            $page->close();
        }

        $page = blockliPage($admin, route('board.show', $second, false).'?lang=de', $width, $height, false);
        BrowserWait::until($page, '() => !!document.querySelector("[data-test=tournament-banner-step]")', 10_000);
        $m = $page->evaluate($banner);
        // Below lg the one-line banner row stands in the title row: no room for the standing, its label reads it out.
        $row = $page->evaluate('() => { const el = document.querySelector("[data-test=tournament-banner-row]"); return el.checkVisibility() ? el.getAttribute("aria-label") : null; }');
        fwrite(STDERR, PHP_EOL."[legs banner {$width}] ".json_encode([...$m, 'row' => $row]).PHP_EOL);

        if ($width >= 1024) {
            expect($m)->toMatchArray(['text' => 'Anna Racer führt 1–0 · Partie 2 läuft', 'visible' => true])
                ->and($m['right'])->toBeLessThanOrEqual($m['vw']);
        } else {
            expect($row)->toContain('Anna Racer führt 1–0 · Partie 2 läuft');
        }

        // DerCaddy 2026-10-30: the games of the pairing on the game's page, this one marked, the other one link (on a
        // phone in the panel the banner row opens).
        if ($width < 1024) {
            $page->locator('[data-test=tournament-banner-row]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=banner-sheet] [data-test=pairing-games]")?.checkVisibility()', 5_000);
        }
        $switch = $page->evaluate('() => { const nav = [...document.querySelectorAll("[data-test=pairing-games]")].find((el) => el.checkVisibility()); return nav ? { items: [...nav.querySelectorAll("[data-test=pairing-game]")].map((el) => { const r = el.getBoundingClientRect(); return { text: el.innerText.trim(), current: el.hasAttribute("aria-current"), href: el.href ? new URL(el.href).pathname : null, h: Math.round(r.height), right: Math.round(r.right) }; }), vw: innerWidth } : null; }');
        fwrite(STDERR, PHP_EOL."[pairing games {$width}] ".json_encode($switch).PHP_EOL);

        expect($switch)->not->toBeNull()
            ->and(array_column($switch['items'], 'text'))->toBe(['Partie 1 · gewonnen von Anna Racer', 'Partie 2 · läuft'])
            ->and(array_column($switch['items'], 'current'))->toBe([false, true])
            ->and(array_column($switch['items'], 'href'))->toBe([route('board.show', $first, false), null])
            ->and(min(array_column($switch['items'], 'h')))->toBeGreaterThanOrEqual($width < 1024 ? 44 : 32)
            ->and(max(array_column($switch['items'], 'right')))->toBeLessThanOrEqual($switch['vw']);

        if ($width < 1024) {
            // DerCaddy 2026-10-31: a long name ends in "…" on one line, never a second one.
            $long = $page->evaluate('() => { const a = document.querySelector("[data-test=banner-sheet] a[data-test=pairing-game]"), t = a.querySelector(".truncate"); t.textContent = "Partie 1 · gewonnen von Satoshi Nakamoto der Allererste von allen Hodlern"; const r = a.getBoundingClientRect(), s = getComputedStyle(t); return { h: Math.round(r.height), right: Math.round(r.right), vw: innerWidth, cut: t.scrollWidth > t.clientWidth, ellipsis: s.textOverflow, lines: Math.round(t.getBoundingClientRect().height / parseFloat(s.lineHeight)), title: a.title }; }');
            fwrite(STDERR, PHP_EOL."[pairing long name {$width}] ".json_encode($long).PHP_EOL);
            expect($long)->toMatchArray(['h' => 44, 'cut' => true, 'ellipsis' => 'ellipsis', 'lines' => 1, 'title' => 'Partie 1 · gewonnen von Anna Racer'])
                ->and($long['right'])->toBeLessThanOrEqual($long['vw'] - 16);

            // The first leg's board opens from the panel.
            $page->locator('[data-test=banner-sheet] a[data-test=pairing-game]')->click();
            BrowserWait::until($page, '() => location.pathname === '.json_encode(route('board.show', $first, false)), 10_000);
        }

        expect($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($page->evaluate('() => window.__errors'))->toBe([], "banner {$width}: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "banner {$width}: responses");
        $page->close();
    }
});

test('a Blockli rapid game plays live on a running 10+5 clock, from the lobby\'s Rapid tile to moves on both boards; clean console', function (int $width, int $height, bool $touch) {
    // User, 2026-10-11: "Blockli braucht unbedingt einen Rapid Modus, wie Schach ihn auch hat" (and blitz beside it).
    [$anna, $bert] = User::factory()->count(2)->create();

    // The lobby: the Rapid tile opens the quick play in rapid.
    $lobby = blockliPage($anna, route('board.lobby', 'blockli', false), $width, $height, $touch);
    BrowserWait::until($lobby, '() => !!document.querySelector("[data-test=play-rapid]")', 10_000);
    $touch ? $lobby->locator('[data-test=play-rapid]')->tap() : $lobby->locator('[data-test=play-rapid]')->click();
    BrowserWait::until($lobby, '() => document.querySelector("[data-test=find-opponent]")?.checkVisibility() && document.querySelector("[data-test=find-opponent]").dataset.mode === "rapid"', 5_000);
    $tiles = $lobby->evaluate('() => ["play-blitz", "play-rapid", "play-correspondence"].map((t) => { const r = document.querySelector("[data-test=" + t + "]").getBoundingClientRect(); return [t, Math.round(r.height), Math.round(r.right) <= innerWidth]; })');
    $lobbyErrors = $lobby->evaluate('() => window.__errors');
    $lobby->close();

    $game = app(BoardGameService::class)->start('blockli', $anna, $bert, 'rapid');
    $path = route('board.show', $game, false);
    $white = blockliPage($game->white, $path, $width, $height, $touch);
    $black = blockliPage($game->black, $path, $width, $height, $touch);
    $pages = [$white, $black];
    blockliCanMove($white);

    $clock = '() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return [g.state.daily, g.clock("w"), g.clock("b")]; }';
    [$daily, $whiteClock] = $white->evaluate($clock);
    blockliTap($white, $touch, 'e2');
    blockliPly($pages, 1);
    blockliCanMove($black);
    blockliTap($black, $touch, 'e8');
    blockliPly($pages, 2);
    $after = $white->evaluate($clock);
    // White to move: its clock runs down on the page while it thinks.
    $white->evaluate('() => new Promise((r) => setTimeout(r, 2500))');
    $running = $white->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return [g.state.clock, g.clock("w")]; }');
    fwrite(STDERR, PHP_EOL."[rapid {$width}] ".json_encode(['tiles' => $tiles, 'start' => $whiteClock, 'after' => $after, 'running' => $running]).PHP_EOL);

    expect($daily)->toBeFalse()
        ->and($whiteClock)->toMatch('/^(10:00|9:5\d)$/')
        ->and($after[1])->toMatch('/^\d{1,2}:\d{2}$/')
        ->and($running[1])->not->toBe($after[1], 'the clock of the side to move runs')
        ->and($game->refresh()->moves()->pluck('notation')->all())->toBe(['e2', 'e8'])
        ->and(collect($tiles)->every(fn (array $tile): bool => $tile[1] >= 44 && $tile[2]))->toBeTrue()
        ->and($lobbyErrors)->toBe([]);

    foreach ($pages as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([])
            ->and($page->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth'))->toBeLessThanOrEqual(0);
    }
})->with([
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

test('an organizer switches a running Blockli tournament to rapid in the editor; the tournament page says so; at 390 and 1440', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Switch Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-switch', 'published_at' => now()->subDay(),
    ]);
    foreach (['Anna Switch', 'Bert Switch'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('34', 32));
    app(TournamentRunner::class)->sync($tournament);

    $page = blockliPage($admin, route('admin.tournaments.edit', $tournament, false).'?lang=de', 1440, 900, false);
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=mode-switch-rapid]")', 10_000);
    $page->locator('[data-test=mode-switch-rapid]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=mode-switch-rapid]").getAttribute("aria-checked") === "true"', 10_000);
    // Deferred (user, 2026-10-12): the choice is saved by "Save changes", never by the click.
    expect($tournament->refresh()->mode)->toBe('correspondence');
    $page->locator('[data-test=edit-save]')->click();
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=edit-notice]")', 10_000);
    expect($tournament->refresh()->mode)->toBe('rapid')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $show = blockliPage($admin, route('tournaments.show', $tournament, false).'?lang=de', $width, $height, false);
        BrowserWait::until($show, '() => !!document.querySelector("[data-test=mode-switched]")', 10_000);
        $m = $show->evaluate('() => { const el = document.querySelector("[data-test=mode-switched]"); el.scrollIntoView(); const r = el.getBoundingClientRect(); return { text: el.innerText, right: Math.round(r.right), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');
        fwrite(STDERR, PHP_EOL."[switch line {$width}] ".json_encode($m).PHP_EOL);

        expect($m['text'])->toContain('von Fernpartie auf Rapid 10+5 umgestellt')
            ->and($m['right'])->toBeLessThanOrEqual($width)
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($show->evaluate('() => window.__errors'))->toBe([])
            ->and($show->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('right after a move its player can take it back for 21 seconds: the button counts down in the toggle\'s place, the opponent is told, the board is as before', function (int $width, int $height, bool $touch) {
    // DerCaddy and the players, 2026-10-15: a misclick, 21 seconds, no accept step (BoardGameService::takeBack()).
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    // From the start position: the move taken back is the first, so the board goes back to it.
    $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
    $path = route('board.show', $game, false);
    $white = blockliPage($anna, $path, $width, $height, $touch);
    $black = blockliPage($bert, $path, $width, $height, $touch);

    foreach ([$white, $black] as $page) {
        BrowserWait::until($page, BLOCKLI_READY, 10_000);
    }

    $start = $white->evaluate('() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.pieces');
    blockliTap($white, $touch, 'e2');
    blockliPly([$white, $black], 1);
    BrowserWait::until($white, '() => !!document.querySelector("[data-test=take-back]")?.checkVisibility()', 5_000);
    $offered = $white->evaluate(<<<'JS'
        () => {
            const button = document.querySelector('[data-test=take-back]');
            const r = button.getBoundingClientRect();
            const g = Alpine.$data(document.querySelector('[data-test=board-game]'));
            return { left: g.takeBackLeft, h: Math.round(r.height), inBar: !!button.closest('[data-test=block-input]'), toggle: document.querySelector('[data-test=mode-block]').checkVisibility(), label: button.getAttribute('aria-label') };
        }
        JS);
    $blackOffered = $black->evaluate('() => !!document.querySelector("[data-test=take-back]")?.checkVisibility()');

    $touch ? $white->locator('[data-test=take-back]')->tap() : $white->locator('[data-test=take-back]')->click();
    blockliPly([$white, $black], 0);
    blockliCanMove($white);
    $after = $white->evaluate('() => ({ pieces: Alpine.$data(document.querySelector("[data-test=board-game]")).state.pieces, button: !!document.querySelector("[data-test=take-back]")?.checkVisibility(), toggle: document.querySelector("[data-test=mode-block]").checkVisibility(), notice: Alpine.$data(document.querySelector("[data-test=board-game]")).noticeShown })');
    BrowserWait::until($black, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).noticeShown', 5_000);
    $told = $black->evaluate('() => ({ notice: Alpine.$data(document.querySelector("[data-test=board-game]")).notice, shown: [...document.querySelectorAll("[data-test=board-notice], [data-test=block-status]")].filter((el) => el.checkVisibility()).map((el) => el.innerText) })');
    fwrite(STDERR, PHP_EOL."take back {$width}: ".json_encode(compact('offered', 'blackOffered', 'after', 'told')).PHP_EOL);

    expect($offered['left'])->toBeGreaterThanOrEqual(18)->toBeLessThanOrEqual(21)
        ->and($offered['h'])->toBeGreaterThanOrEqual(44)
        ->and($offered['inBar'])->toBeTrue('in Blockli\'s bar')
        ->and($offered['toggle'])->toBeFalse('in the place of "Set a block", off anyway while the opponent is to move')
        ->and($offered['label'])->toContain('s')
        ->and($blackOffered)->toBeFalse('only the player who moved can take it back')
        ->and($after['pieces'])->toBe($start)
        ->and($after['button'])->toBeFalse()
        ->and($after['toggle'])->toBeTrue()
        ->and($after['notice'])->toBeFalse('the player who took it back needs no notice')
        ->and($game->refresh()->ply)->toBe(0)
        ->and($game->moves()->count())->toBe(0)
        ->and($told['notice'])->not->toBe('')
        ->and($told['shown'])->toContain($told['notice']);

    foreach ([$white, $black] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
})->with([
    'phone, touch' => [390, 844, true],
    'desktop, mouse' => [1440, 900, false],
]);

test('a Blockli group table says it counts pairings and lists the pairings under way in two-column groups, at 390, 1024 and 1920; clean console', function () {
    // Nidan21 2026-10-17: a won and a lost leg read as a wrong draw, a lost first leg showed nowhere in the table.
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::TwoStage, 'name' => 'Blockli Group Cup',
        'options' => FormatOptions::fromArray(['groupSize' => 4, 'advance' => 2, 'groupStage' => 'round-robin', 'finalStage' => 'single-elimination'], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 8, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-group-table',
    ]);
    foreach (['Nidan21', 'Anomark', 'aHeck13', 'npub1rrc278e5x7kq9wlongname', 'DerCaddy', 'Noerd', 'Julian Jones', 'Zwo1Risiko⚡'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('12', 32));
    app(TournamentRunner::class)->sync($tournament);
    $service = app(BoardGameService::class);
    foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->with('slots.participant')->orderBy('id')->get() as $match) {
        // Every pairing's first leg ends (all six per group start at once): White's side resigns.
        $game = BoardGame::query()->where('tournament_match_id', $match->id)->where('status', BoardGameStatus::Active)->first();
        if ($game !== null) {
            $service->resign($game, User::query()->findOrFail((int) $game->white_id));
        }
    }
    $admin = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $measure = '() => { const note = document.querySelector("[data-test=standings-legs-note]"); note.scrollIntoView(); const n = note.getBoundingClientRect(), card = note.parentElement.getBoundingClientRect(); const rows = [...document.querySelectorAll("[data-test=standings-open-row]")].map((r) => { const b = r.getBoundingClientRect(), own = r.closest("[data-test=standings-open]").parentElement.getBoundingClientRect(), score = r.querySelector("b"); return { h: Math.round(b.height), inside: b.left >= own.left - 0.5 && b.right <= own.right + 0.5, score: score.innerText.trim(), scoreClipped: score.scrollWidth > score.clientWidth + 0.5, names: [...r.querySelectorAll("span")].map((s) => ({ text: s.innerText.trim(), w: Math.round(s.getBoundingClientRect().width) })) }; }); return { notes: document.querySelectorAll("[data-test=standings-legs-note]").length, note: note.innerText.trim(), noteInside: n.left >= card.left - 0.5 && n.right <= card.right + 0.5, noteH: Math.round(n.height), cardW: Math.round(card.width), rows, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }';

    foreach ([[390, 844], [1024, 800], [1920, 1080]] as [$width, $height]) {
        $page = blockliPage($admin, route('tournaments.show', $tournament, false).'?lang=de', $width, $height, false);
        BrowserWait::until($page, '() => !!document.querySelector("[data-test=standings-legs-note]")', 10_000);
        $m = $page->evaluate($measure);
        fwrite(STDERR, PHP_EOL."[group table {$width}] ".json_encode($m).PHP_EOL);

        expect($m['notes'])->toBe(2)
            ->and($m['note'])->toBe('S R N zählt Paarungen: Hin- und Rückspiel zusammen. Eine Paarung kommt erst nach beiden Partien in die Tabelle.')
            ->and($m['noteInside'])->toBeTrue()
            ->and($m['rows'])->toHaveCount(12)
            ->and($m['overflow'])->toBeLessThanOrEqual(0);
        foreach ($m['rows'] as $row) {
            expect($row['inside'])->toBeTrue()
                ->and($row['score'])->toBeIn(['1–0', '0–1'])
                ->and($row['scoreClipped'])->toBeFalse()
                // Each name keeps room to be read, the long npub truncates instead of pushing the row wider.
                ->and(min(array_column($row['names'], 'w')))->toBeGreaterThanOrEqual(80);
        }
        expect($page->evaluate('() => window.__errors'))->toBe([], "{$width}: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$width}: responses");
        $page->close();
    }
});

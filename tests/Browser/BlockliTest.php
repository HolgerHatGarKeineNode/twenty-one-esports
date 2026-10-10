<?php

use App\Enums\BoardGameStatus;
use App\Models\Admin;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
    expect($white->evaluate($corner))->toBe(['left', 'bottom'])
        ->and($black->evaluate($corner))->toBe(['right', 'top'])
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

test('while a block is shown, Rotate, Confirm and Move the pawn stand in the pop-up, every button is at least 44 px high and Confirm is the only filled one; a mouse sets it by its click, without them', function (int $width, int $height, bool $touch) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $white = blockliPage($anna, route('board.show', $game, false), $width, $height, $touch);
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);

    $touch ? $white->locator('[data-test=mode-block]')->tap() : $white->locator('[data-test=mode-block]')->click();
    $touch ? blockliTap($white, true, 'c4/d5', -30) : blockliHover($white, 'c4/d5', -30);
    // Filled = the orange primary ground (bg-btc); the pressed toggle keeps a dark tint (bg-btc-press).
    // Rotate, Confirm and Move the pawn stand in the pop-up over the board (DerCaddy, 2026-10-09), so they come first;
    // under the board only "Set a block" is left.
    $buttons = $white->evaluate('() => [...document.querySelectorAll("[data-test=block-popup] button, [data-test=block-input] button")].filter((b) => b.checkVisibility()).map((b) => ({ test: b.dataset.test, h: Math.round(b.getBoundingClientRect().height), filled: b.classList.contains("bg-btc") }))');

    expect(collect($buttons)->pluck('test')->all())->toBe($touch ? ['rotate-block', 'set-block', 'mode-move', 'mode-block'] : ['mode-block'])
        ->and(collect($buttons)->every(fn (array $b): bool => $b['h'] >= 44))->toBeTrue()
        ->and(collect($buttons)->where('filled', true)->pluck('test')->all())->toBe($touch ? ['set-block'] : [])
        ->and($white->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'phone 390 (touch)' => [390, 844, true],
    'desktop 1440 (mouse)' => [1440, 900, false],
]);

/**
 * The vertical extent [top, bottom] of the board, of its part in view (below the sticky header, above the tab bar: the
 * root's scroll padding; above the bars fixed over the board on a phone: Blockli's bar, the chat sheet, the dock), the
 * block pop-up (the stretch it stands in), its card (the buttons' box, or the hint), its buttons and the block shown,
 * rounded; null where there is none.
 */
const BLOCKLI_BOXES = <<<'JS'
    () => {
        const box = (selector) => {
            const el = document.querySelector(selector);
            if (!el || !el.checkVisibility()) return null;
            const r = el.getBoundingClientRect();
            return [Math.round(r.top), Math.round(r.bottom)];
        };
        const root = getComputedStyle(document.documentElement);
        const board = document.querySelector('[data-test=board]').getBoundingClientRect();
        let floor = innerHeight - (parseFloat(root.scrollPaddingBottom) || 0);
        document.querySelectorAll('[data-page-bar], [data-live-floor]').forEach((el) => {
            if (!el.checkVisibility() || getComputedStyle(el).position !== 'fixed') return;
            const r = el.getBoundingClientRect();
            if (r.height > 0 && r.left < board.right && r.right > board.left && r.top > board.top) floor = Math.min(floor, r.top);
        });
        const view = [Math.round(Math.max(board.top, parseFloat(root.scrollPaddingTop) || 0)), Math.round(Math.min(board.bottom, floor))];
        const card = box('[data-test=block-popup] > div') ?? box('[data-test=block-popup] > p');
        return { board: box('[data-test=board]'), view, popup: box('[data-test=block-popup]'), card, buttons: box('[data-test=set-block]'), block: box('[data-test=board] [data-preview]') };
    }
    JS;

test('the block pop-up stands over the board, never over the block: a finger gets Rotate, Confirm and Move the pawn in the middle of the board on the other side of the block, a mouse the hint at the edge', function (int $width, int $height, bool $touch) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $white = blockliPage($anna, route('board.show', $game, false), $width, $height, $touch);
    BrowserWait::until($white, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);

    // "Set a block" (on a phone in the bar fixed above the chat sheet, from lg at the head of the side column): at the top
    // of the page, the hint alone stands at the top of the part of the board in view.
    $touch ? $white->locator('[data-test=mode-block]')->tap() : $white->locator('[data-test=mode-block]')->click();
    $white->evaluate('() => window.scrollTo(0, 0)');
    BrowserWait::until($white, '() => { const b = ('.BLOCKLI_BOXES.')(); return b.popup !== null && Math.abs(b.popup[0] - b.view[0] - 8) <= 1; }', 5_000);
    expect($white->evaluate(BLOCKLI_BOXES)['buttons'])->toBeNull();

    // A block on the 7th rank: the pop-up below it, a finger's buttons in the middle between the block and the bottom of the
    // board in view (DerCaddy, 2026-10-09), a mouse's hint at that bottom.
    $touch ? blockliTap($white, true, 'c7/d8', -30) : blockliHover($white, 'c7/d8', -30);
    $boxes = $white->evaluate(BLOCKLI_BOXES);
    expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c7/d8', 'h', true])
        ->and($boxes['buttons'] !== null)->toBe($touch)
        ->and($boxes['card'][0])->toBeGreaterThan($boxes['block'][1])
        ->and($boxes['card'][1])->toBeLessThanOrEqual($boxes['view'][1])
        ->and($touch
            ? abs(($boxes['card'][0] + $boxes['card'][1]) / 2 - ($boxes['block'][1] + 12 + $boxes['view'][1] - 8) / 2)
            : abs($boxes['view'][1] - 8 - $boxes['card'][1]))->toBeLessThanOrEqual(3);

    // A block on the 2nd rank, reached below the pop-up: it moves above the block.
    $touch ? blockliTap($white, true, 'c1/d2', -30) : blockliHover($white, 'c1/d2', -30);
    $boxes = $white->evaluate(BLOCKLI_BOXES);
    expect($white->evaluate(BLOCKLI_PREVIEW))->toBe(['c1/d2', 'h', true])
        ->and($boxes['card'][1])->toBeLessThan($boxes['block'][0])
        ->and($boxes['card'][0])->toBeGreaterThanOrEqual($boxes['view'][0])
        ->and($touch
            ? abs(($boxes['card'][0] + $boxes['card'][1]) / 2 - ($boxes['view'][0] + 8 + $boxes['block'][0] - 12) / 2)
            : abs($boxes['card'][0] - $boxes['view'][0] - 8))->toBeLessThanOrEqual(3);

    // Confirm in the pop-up sets the block; a mouse clicks it.
    $touch ? $white->locator('[data-test=set-block]')->tap() : blockliTap($white, false, 'c1/d2', -30);
    BrowserWait::until($white, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === 1', 5_000);

    expect($game->refresh()->moves()->pluck('notation')->all())->toBe(['c1h'])
        ->and($white->evaluate('() => window.__errors'))->toBe([]);
})->with([
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

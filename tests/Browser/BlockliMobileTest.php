<?php

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
| Blockli on a phone without scrolling (plan "Blockli-Optimierung", P2)
|--------------------------------------------------------------------------
|
| On a phone the whole board, the switch between pawn and block (with the blocks left) and Rotate/Confirm sit in
| the first screen above the chat sheet and the app's tab bar, as on the daily chess page: measured in pixels at 390x844 and 360x740
| with a block shown, without any scroll. Rotate, Confirm and Move the pawn stand in the pop-up over the board, so
| Blockli's bar is one row ("Set a block") and the board gets the height back (DerCaddy, 2026-10-10: "das Spielfeld
| wieder vergrößern"): the full width at 390x844. The match dock's tab sits beside the chat bar, in its row.
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

    BlockliOn::play();
});

function blockliPhone(User $user, string $to, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/** Where the parts of the board page lie, at the top of the page. */
const BLOCKLI_FOLD = <<<'JS'
() => {
    scrollTo(0, 0);
    const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), h: Math.round(r.height) }; };
    const sheet = box('[data-test=chat-sheet-toggle]');
    return {
        tabbar: parseFloat(getComputedStyle(document.body).paddingBottom) || 0, height: innerHeight, width: innerWidth, sheet: sheet ? sheet.top : innerHeight,
        board: box('[data-test=board]'), chatPanel: box('[data-test=chat-panel]'), chatInput: box('#chatin'), moves: box('[data-test=moves]'), side: Math.round(Math.max(...[...document.querySelector('[data-test=board-game] > .grid > div:nth-child(2)').children].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect().bottom))), dock: box('[data-test=block-input]'), modeMove: box('[data-test=mode-move]'), modeBlock: box('[data-test=mode-block]'),
        rotate: box('[data-test=rotate-block]'), confirm: box('[data-test=set-block]'), top: box('[data-test=player-top]'), bottomCard: box('[data-test=player-bottom]'),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        // The narrowest field on the drawn board: the step between two neighbouring squares' points (a1, b1, ...).
        field: (() => { const g = Alpine.$data(document.querySelector('[data-test=board-game]')); const xs = [...new Set(g.layout.points.filter((p) => /^[a-z]\d+$/.test(p.id)).map((p) => p.x))].sort((a, b) => a - b); const s = document.querySelector('[data-test=board]').getBoundingClientRect().width / g.layout.width; return Math.round(Math.min(...xs.slice(1).map((x, i) => x - xs[i])) * s); })(), main: box('main'), header: box('header'), title: box('[data-test=board-game] > div:first-child'), status: box('[data-test=status-line]'), conn: box('[data-test=connection]'), hint: box('[data-test=block-hint]'), lobby: box('[data-test=board-lobby-link]'),
    };
}
JS;

test('the board, the pawn/block switch and Confirm fit the first phone screen above the chat sheet', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $page = blockliPhone($anna, route('board.show', $game, false), $width, $height);
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    $page->locator('[data-test=mode-block]')->tap();
    // A tap in the groove left of the crossing c4/d5 shows a block (BlockliTest's BLOCKLI_AT, White's board).
    $position = $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); const p = g.layout.points.find((q) => q.id === "c4/d5"); const s = document.querySelector("[data-test=board]").getBoundingClientRect().width / g.layout.width; return { x: (p.x - 30) * s, y: p.y * s }; }');
    $page->locator('[data-test=board]')->tap(['position' => $position]);
    BrowserWait::until($page, '() => !!Alpine.$data(document.querySelector("[data-test=board-game]")).preview?.move', 5_000);
    $m = $page->evaluate(BLOCKLI_FOLD);
    fwrite(STDERR, PHP_EOL."{$width}x{$height} ".json_encode($m).PHP_EOL);

    expect($m['sheet'])->toBe($height - (int) $m['tabbar'] - 72, 'the chat sheet sits on the tab bar')
        ->and($m['board']['top'])->toBeGreaterThanOrEqual(0)
        // One row: "Set a block" beside whose move it is; the board takes what the second row and the tab on it took.
        ->and($m['dock']['h'])->toBeLessThanOrEqual(58, 'Blockli\'s bar is one row')
        ->and($m['board']['right'] - $m['board']['left'])->toBeGreaterThanOrEqual($width === 390 ? $width - 32 : 300, 'the board keeps the width')
        ->and($m['rotate']['bottom'])->toBeLessThanOrEqual($m['board']['bottom'], 'Rotate stands over the board')
        ->and($m['confirm']['bottom'])->toBeLessThanOrEqual($m['board']['bottom'], 'Confirm stands over the board')
        ->and(max($m['board']['bottom'], $m['bottomCard']['bottom']))->toBeLessThanOrEqual($m['dock']['top'], 'board and player cards end above the bar')
        ->and($m['dock']['bottom'])->toBeLessThanOrEqual($m['sheet'], 'the bar ends above the chat sheet')
        ->and($m['confirm']['bottom'])->toBeLessThanOrEqual($m['sheet'])
        ->and($m['modeBlock']['h'])->toBeGreaterThanOrEqual(44)
        ->and($m['confirm']['h'])->toBeGreaterThanOrEqual(44)
        // The board stays playable by finger: every field at least 28 px (plan, risks).
        ->and($m['field'])->toBeGreaterThanOrEqual(28)
        ->and($m['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 390' => [390, 844],
    'phone 360' => [360, 740],
]);

test('the board keeps its size while the phone browser shows and hides its address bar', function () {
    // Players: "the board size keeps jumping between two sizes" (2026-10-09). Chrome on Android shows and hides its
    // address bar while scrolling and fires `resize` each time with another innerHeight; a board that followed it
    // changed the page's height, which moved the bar again. Same width, other height: the board stays.
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $page = blockliPhone($anna, route('board.show', $game, false), 390, 844);
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    $box = '() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }';
    $before = $page->evaluate($box);
    $sizes = [];

    foreach ([788, 900, 760, 844] as $height) {
        $page->setViewportSize(390, $height);
        $page->evaluate('() => new Promise((r) => setTimeout(r, 200))');
        $sizes[$height] = $page->evaluate($box);
    }

    // Turning the phone (another width) still fits the board anew.
    $page->setViewportSize(360, 740);
    $page->evaluate('() => new Promise((r) => setTimeout(r, 200))');

    expect(array_values(array_unique(array_map('json_encode', $sizes))))->toBe([json_encode($before)])
        ->and($page->evaluate($box))->not->toBe($before)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

/** The closed chat bar's row: the dock's tab in it, the chat's room, Blockli's bar and the board above. */
const BLOCKLI_DOCK_ROW = <<<'JS'
() => {
    const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { left: Math.round(r.left), top: Math.round(r.top), right: Math.round(r.right), bottom: Math.round(r.bottom) }; };
    const toggle = document.querySelector('[data-test=chat-sheet-toggle]');
    return {
        tab: box('[data-test=dock-row-tab]'), onTop: box('[data-test=dock-bar-tab]'), chat: box('[data-test=chat-sheet-toggle]'), chatLabel: box('#sheet-h'),
        bar: box('[data-test=block-input]'), board: box('[data-test=board]'), pad: Math.round(parseFloat(getComputedStyle(toggle).paddingLeft)),
        sheet: !!document.querySelector('[data-test=dock-sheet]')?.checkVisibility(),
    };
}
JS;

test('beside the closed chat bar the match dock\'s tab sits in its row and opens the open matches; the chat opens its own sheet', function () {
    // DerCaddy, 2026-10-10: "die Leiste '10 wartet' soll neben der Leiste zum Chat unten nebeneinander erscheinen, also
    // wenn sie eingeklappt sind. Klicken darauf öffnet einen der Reiter." On this game's page the dock's tab sits at the
    // left end of the chat bar's row, not on top of Blockli's bar.
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $service = app(BoardGameService::class);
    $game = BlockliOn::setUp($service->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $page = blockliPhone($anna, route('board.show', $game, false), 390, 844);
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    $refresh = '() => Alpine.$data(document.querySelector("[data-test=match-dock-root]")).requestRefresh()';
    $empty = $page->evaluate(BLOCKLI_DOCK_ROW);

    // No other match yet: no tab, the chat has the row. Then a second game starts and the dock's refresh brings the tab:
    // rendered after the page loaded, it still gets its room (looked up, not a $ref cached while there was none).
    $service->start('blockli', $anna, $carl);
    $page->evaluate($refresh);
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=dock-row-tab]")?.checkVisibility() && parseFloat(getComputedStyle(document.querySelector("[data-test=chat-sheet-toggle]")).paddingLeft) > 16', 10_000);
    $closed = $page->evaluate(BLOCKLI_DOCK_ROW);

    expect([$empty['tab'], $empty['pad']])->toBe([null, 16])
        ->and($closed['onTop'])->toBeNull('no tab on top of Blockli\'s bar')
        ->and([$closed['tab']['left'], $closed['tab']['top'], $closed['tab']['bottom']])->toBe([0, $closed['chat']['top'], $closed['chat']['bottom']], 'the tab in the chat bar\'s row')
        ->and($closed['pad'])->toBe($closed['tab']['right'] + 16, 'the chat leaves the tab its room')
        ->and($closed['chatLabel']['left'])->toBeGreaterThanOrEqual($closed['tab']['right'])
        ->and($closed['board']['bottom'])->toBeLessThanOrEqual($closed['bar']['top'], 'nothing of the dock over the board');

    // The tab opens the open matches, and closes them.
    $page->locator('[data-test=dock-row-tab]')->tap();
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=dock-sheet]")?.checkVisibility()', 5_000);
    $page->locator('[data-test=dock-sheet] .dk-x')->tap();
    BrowserWait::until($page, '() => !document.querySelector("[data-test=dock-sheet]")?.checkVisibility()', 5_000);

    // The chat opens its own sheet: the tab gives way and the chat gets its room back. A refresh of the dock meanwhile
    // (it starts the dock anew) keeps it away. Closed again, the tab is back in the row.
    $page->locator('[data-test=chat-sheet-toggle]')->tap();
    BrowserWait::until($page, '() => !document.querySelector("[data-test=dock-row-tab]").checkVisibility() && parseFloat(getComputedStyle(document.querySelector("[data-test=chat-sheet-toggle]")).paddingLeft) === 16', 5_000);
    $page->evaluate($refresh);
    $page->evaluate('() => new Promise((r) => setTimeout(r, 1500))');
    expect($page->evaluate('() => !!document.querySelector("[data-test=dock-row-tab]")?.checkVisibility()'))->toBeFalse('no tab inside the open chat');
    $page->locator('[data-test=chat-sheet-toggle]')->tap();
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=dock-row-tab]")?.checkVisibility()', 5_000);

    expect($page->evaluate(BLOCKLI_DOCK_ROW))->toBe($closed)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

/*
| P4: the board page (with the players' chat) and the lobby of every board game, measured as an admin in German at
| 390, 768, 1440 and 1920: no sideways overflow, no cut text, buttons at least 44 px, quiet console and network.
*/

const BOARD_PAGE_MEASURE = <<<'JS'
() => {
    const main = document.querySelector('main') ?? document.body;
    const visible = (el) => el.checkVisibility();
    const label = (el) => (el.dataset.test ?? el.tagName.toLowerCase()) + ' "' + (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';
    const all = [...document.querySelectorAll('main *, [data-test=chat] *, [data-test=block-input] *')].filter((el) => !(el instanceof SVGElement) && visible(el) && el.getBoundingClientRect().width > 1);
    return {
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        cut: all.filter((el) => el.children.length === 0 && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== 'ellipsis').map(label),
        low: [...main.querySelectorAll('button, a.btn-p, a.btn-s, a.btn-w')].filter((el) => visible(el) && el.getBoundingClientRect().height < 44).map((el) => label(el) + ' ' + Math.round(el.getBoundingClientRect().height)),
        chat: !!document.querySelector('[data-test=chat]'),
    };
}
JS;

test('as an admin in German the board page with its chat and the lobby of every board game fit at four widths', function () {
    config(['esports.board_games.games.checkers.enabled' => true, 'esports.board_games.games.nine-mens-morris.enabled' => true]);
    BlockliOn::play();
    $admin = User::factory()->withPubkey(str_repeat('ad', 32))->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $rows = [];

    foreach (['blockli', 'nine-mens-morris', 'checkers'] as $slug) {
        $game = app(BoardGameService::class)->start($slug, $admin, User::factory()->create());

        foreach ([[390, 844], [768, 1024], [1440, 900], [1920, 1080]] as [$width, $height]) {
            foreach (['board' => route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), 'lobby' => route('board.lobby', ['board' => $slug, 'lang' => 'de'], false)] as $name => $to) {
                $page = blockliPhone($admin, $to, $width, $height);
                BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && window.Alpine !== undefined', 10_000);
                $m = $page->evaluate(BOARD_PAGE_MEASURE);
                $label = "{$slug} {$name} {$width}";
                $rows[] = sprintf('%-17s %-5s %4d  overflow %d  cut %d  low %d  chat %d', $slug, $name, $width, $m['overflow'], count($m['cut']), count($m['low']), $m['chat']);

                expect($m['overflow'])->toBeLessThanOrEqual(0, "{$label}: sideways overflow")
                    ->and($m['cut'])->toBe([], "{$label}: cut text")
                    ->and($m['low'])->toBe([], "{$label}: buttons under 44 px")
                    ->and($m['chat'])->toBe($name === 'board', "{$label}: chat")
                    ->and($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
                    ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$label}: responses");

                if ($slug === 'checkers' && $name === 'board' && $width === 1920) {
                    // Positive control: the collector on this very page sees a thrown error.
                    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); return new Promise((r) => setTimeout(r, 50)); }');
                    expect(implode(' ', $page->evaluate('() => window.__errors')))->toContain('positive control');
                }

                $page->close();
            }
        }
    }

    fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL);
});

/*
| Desktop too (user, 2026-10-09: "hier muss ich immer noch scrollen bei Desktop?", "bei Schach hat das geklappt"):
| from lg the board column is as wide as the window's height allows, the switch and Confirm head the side column, and
| the chat ends with the board (from 87.5rem its own column, as on daily chess), its input in the first screen.
*/

test('on a desktop the board, the lower player card, the switch and Confirm fit the first screen', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');
    $page = visit(BrowserLogin::url($anna))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.canMove', 10_000);
    $before = $page->evaluate(BLOCKLI_FOLD);
    $page->locator('[data-test=mode-block]')->click();
    // A mouse sets with its click and keeps the hint; an arrow key shows a block in the middle with Rotate and Confirm over the board.
    $page->locator('[data-test=mode-block]')->press('ArrowUp');
    BrowserWait::until($page, '() => !!Alpine.$data(document.querySelector("[data-test=board-game]")).preview', 5_000);
    $m = $page->evaluate(BLOCKLI_FOLD);
    fwrite(STDERR, PHP_EOL."desktop {$width}x{$height} ".json_encode(['board' => $m['board'], 'card' => $m['bottomCard'], 'confirm' => $m['confirm'], 'chat' => $m['chatPanel'], 'side' => $m['side'], 'field' => $m['field']]).PHP_EOL);

    if (is_string($dir = getenv('BLOCKLI_SHOTS')) && $dir !== '') {
        $page->screenshot(false, "blockli-desktop-{$width}");
        File::ensureDirectoryExists($dir);
        File::move(base_path("tests/Browser/Screenshots/blockli-desktop-{$width}.png"), "{$dir}/blockli-desktop-{$width}.png");
    }

    expect($m['bottomCard']['bottom'])->toBeLessThanOrEqual($height, 'lower player card inside the first screen')
        // The side column ends with the board; from 87.5rem the chat is its own column ending with it too (daily chess),
        // below that it stands under both columns, 400 px tall.
        ->and($m['side'])->toBeLessThanOrEqual($m['bottomCard']['bottom'] + 1, 'side column ends with the board')
        ->and($width >= 1400 ? $m['chatPanel']['bottom'] : $m['chatPanel']['top'])->toBe($width >= 1400 ? $m['bottomCard']['bottom'] : $m['chatPanel']['top'])
        ->and($width >= 1400 ? $m['chatPanel']['top'] < $m['board']['top'] : $m['chatPanel']['top'] > $m['bottomCard']['bottom'])->toBeTrue('chat beside the board from 1400, under it below')
        ->and($m['chatPanel']['h'])->toBeGreaterThanOrEqual(400, 'the chat keeps room for messages')
        ->and($m['confirm']['bottom'])->toBeLessThanOrEqual($height, 'Confirm inside the first screen')
        ->and($m['confirm']['bottom'])->toBeLessThanOrEqual($m['board']['bottom'], 'Confirm over the board')
        ->and($m['modeBlock']['bottom'])->toBeLessThanOrEqual($height)
        // The switch to block mode does not move the board.
        ->and($m['board'])->toBe($before['board'])
        ->and($m['field'])->toBeGreaterThanOrEqual(28)
        ->and($m['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'the user\'s 1893x929' => [1893, 929],
    '1440x900' => [1440, 900],
    '1280x720' => [1280, 720],
    '1024x768' => [1024, 768],
    '1920x1080' => [1920, 1080],
]);

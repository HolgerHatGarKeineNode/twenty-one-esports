<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Board\BoardGameService;
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
| with a block shown, without any scroll.
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
        board: box('[data-test=board]'), dock: box('[data-test=block-input]'), modeMove: box('[data-test=mode-move]'), modeBlock: box('[data-test=mode-block]'),
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

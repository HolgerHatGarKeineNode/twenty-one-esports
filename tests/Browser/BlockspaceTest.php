<?php

use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BlockspaceRules;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockspaceOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockspace in the browser (external PR #18, integrated 2026-11-07)
|--------------------------------------------------------------------------
|
| The game page of a live rapid game and the tutorial, as an admin in German (the widest labels) from a phone to a
| wide desktop: the board, the mempool ladder and the yields are on screen, nothing runs off the side, and the console,
| uncaught errors and answers >= 400 stay empty, on the first load and after a Livewire roundtrip.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    BlockspaceOn::play();
});

/** A live rapid game some moves in (three, unless told), the admin playing Orange. */
function blockspaceBrowserGame(User $admin, int $plies = 3): BoardGame
{
    $game = app(BoardGameService::class)->start('blockspace', $admin, User::factory()->create(['name' => 'Hal Finney']), 'rapid');
    $rules = new BlockspaceRules;

    foreach (range(1, $plies) as $ply) {
        $game->refresh();
        $stones = array_values(array_filter($rules->legalMoves($rules->deserialize($game->position)), fn (string $move): bool => $move !== BlockspaceRules::PASS));
        // A long game for the move list: two passes between the stones keep the block open, whatever the deal.
        $move = $plies > 3 && ($ply % 3 !== 0 || $stones === []) ? BlockspaceRules::PASS : $stones[0];
        app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

function blockspaceAdmin(): User
{
    $admin = User::factory()->withPubkey(str_repeat('b5', 32))->create(['name' => 'Satoshi Nakamoto']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function blockspacePage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 15_000);

    return $page;
}

/** The box of a selector when it is drawn, else null. */
const BLOCKSPACE_BOX = '(sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), right: Math.round(r.right) }; }';

test('the game page shows the board, the mempool ladder and both yields at every width, with a clean console before and after a roundtrip', function (int $width) {
    $admin = blockspaceAdmin();
    $game = blockspaceBrowserGame($admin);
    $page = blockspacePage($admin, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width);
    // Drawn, not only laid out, and soon: the block's cells and the five rungs of the ladder within 2.5 s of the page
    // (an empty box measured visible for seconds while the lazily loaded board hung on its stylesheet, 2026-11-07).
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=mempool] > *").length >= 5 && document.querySelector("[data-board-box] svg")?.childElementCount > 0', 2_500);

    $board = $page->evaluate(BLOCKSPACE_BOX, '[data-board-box]');
    $ladder = $page->evaluate(BLOCKSPACE_BOX, '[data-test=mempool]');
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

    expect($board)->not->toBeNull("board at {$width}")
        ->and($board['w'])->toBeGreaterThan(120, "board width at {$width}")
        ->and($ladder)->not->toBeNull("ladder at {$width}")
        ->and($ladder['right'])->toBeLessThanOrEqual($clientWidth, "ladder inside at {$width}")
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth, "no sideways scroll at {$width}")
        ->and($page->evaluate('() => document.body.innerText'))->toContain('Orange')->toContain('Blau')->not->toContain('Gebühr')
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}");

    $page->evaluate('() => { window.__rt = false; window.Livewire.all()[0].$wire.$refresh().then(() => { window.__rt = true; }); }');
    BrowserWait::until($page, '() => window.__rt === true', 10_000);

    expect($page->evaluate(BLOCKSPACE_BOX, '[data-test=mempool]'))->not->toBeNull("ladder after roundtrip at {$width}")
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console after roundtrip at {$width}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers after roundtrip at {$width}");
})->with([390, 820, 1280, 1920]);

test('the tutorial opens its first lesson on the board at every width, with a clean console', function (int $width) {
    $page = blockspacePage(blockspaceAdmin(), route('board.blockspace-practice', ['lang' => 'de'], false), $width);
    BrowserWait::until($page, '() => document.querySelector("[data-test=lesson-dot]") !== null && document.querySelectorAll("[data-test=mempool] > *").length >= 5', 2_500);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);

    expect($page->evaluate(BLOCKSPACE_BOX, '[data-board-box]')['w'] ?? 0)->toBeGreaterThan(120, "board width at {$width}")
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth, "no sideways scroll at {$width}")
        ->and($page->evaluate('() => document.body.innerText'))->not->toContain('Gebühr')->not->toContain('Leiter')
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}");
})->with([390, 1440]);

test('a fresh load draws the block and the ladder within 2.5 s, from a phone to a wide desktop', function (int $width) {
    // As a player's browser opens it: no init script on the page. The board's lazily loaded script once hung here on its
    // stylesheet and left the block and the ladder empty for seconds (2026-11-07); the console collector's timing hid it.
    $admin = blockspaceAdmin();
    $game = blockspaceBrowserGame($admin);
    $page = visit(BrowserLogin::url($admin))->page();
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('board.show', ['boardGame' => $game, 'lang' => 'de'], false)));
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=mempool] > *").length >= 5 && document.querySelector("[data-board-box] svg")?.childElementCount > 0', 2_500);

    expect($page->evaluate('() => document.querySelectorAll("[data-test=mempool] > *").length'))->toBeGreaterThanOrEqual(5);
})->with([390, 1440]);

test('the one column of moves stays a list of its own on a desktop: the page does not grow with the game and the newest move is on screen', function (int $width) {
    // One column is twice as long as two: without a cap the list grew by a row per move, pushed the page past the
    // screen and left the newest move below it (measured 2026-11-17, PR #33: 943 px of list at 22 moves).
    $admin = blockspaceAdmin();
    $game = blockspaceBrowserGame($admin, 24);
    $page = blockspacePage($admin, route('board.show', ['boardGame' => $game, 'lang' => 'de'], false), $width);
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=moves] [data-test=move]").length >= 24 && document.querySelector("[data-test=moves] [aria-current=step]") !== null', 5_000);
    // The board loads lazily and opens the list on the newest move once it is in.
    BrowserWait::until($page, '() => document.querySelector("[data-board-box] svg")?.childElementCount > 0 && document.querySelector("[data-test=moves]").scrollTop > 0', 5_000);

    $list = $page->evaluate('() => { const list = document.querySelector("[data-test=moves]"); const box = list.getBoundingClientRect(); const row = list.querySelector("[aria-current=step]").closest("li").getBoundingClientRect(); const first = list.querySelector("li").getBoundingClientRect(); const second = list.querySelectorAll("li")[1].getBoundingClientRect(); return { h: Math.round(box.height), bottom: Math.round(box.bottom), rowTop: Math.round(row.top), rowBottom: Math.round(row.bottom), boxTop: Math.round(box.top), oneColumn: second.top >= first.bottom - 1, scrolls: list.scrollHeight > list.clientHeight, screen: window.innerHeight }; }');

    expect($list['oneColumn'])->toBeTrue("one column at {$width}")
        ->and($list['h'])->toBeLessThanOrEqual(492, "list height at {$width}")
        ->and($list['scrolls'])->toBeTrue("the list scrolls, not the page, at {$width}")
        ->and($list['rowTop'])->toBeGreaterThanOrEqual($list['boxTop'] - 1, "newest move inside the list at {$width}")
        ->and($list['rowBottom'])->toBeLessThanOrEqual($list['bottom'] + 1, "newest move inside the list at {$width}")
        ->and($list['rowBottom'])->toBeLessThanOrEqual($list['screen'], "newest move on screen at {$width}")
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}");
})->with([1280, 1920]);

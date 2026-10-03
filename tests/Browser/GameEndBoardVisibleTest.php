<?php

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FixtureBoardGame;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The end of a game leaves the board in view (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Ebenso das Modal nach Ende des Spiels, nicht verdecken, sonst sieht man
| gar nicht, wie es zum Schachmatt zustande kam": a casual blitz game ends in
| a mate and a board game in a resignation, live on the open page. The
| result card sits beside the board (lg) or under it, never on it: no
| result element intersects the board's box, and every sampled point of the
| board hits the board itself. The moves stay steppable: a real click on the
| first move shows that earlier position. At en 375 and 1440 and de 375.
|
| Collected (BrowserConsole): console.error, uncaught errors, rejected
| promises, fetch and XHR >= 400; a thrown error injected at the end proves
| the collector sees one. BOARD_VISIBLE_SHOTS=<dir> writes the screenshots.
|
*/

/**
 * The board's box against every visible result element, and a 7 x 7 grid of
 * points on the board: each must hit the board, not something painted over it.
 */
const BOARD_VISIBLE = <<<'JS'
    ([boardSelector, resultSelector]) => {
        const board = document.querySelector(boardSelector);
        board.scrollIntoView({ block: 'center' });
        const b = board.getBoundingClientRect();
        const results = [...document.querySelectorAll(resultSelector)].filter((el) => el.getClientRects().length > 0).map((el) => el.getBoundingClientRect());
        const overlaps = results.filter((r) => r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top).length;
        let covered = 0;
        for (let i = 0; i < 7; i++) {
            for (let j = 0; j < 7; j++) {
                const hit = document.elementFromPoint(b.left + b.width * (i + 0.5) / 7, b.top + b.height * (j + 0.5) / 7);
                if (hit === null || !(hit === board || board.contains(hit))) {
                    covered++;
                }
            }
        }
        return {
            board: { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right) },
            result: results.length === 0 ? null : { top: Math.round(results[0].top), bottom: Math.round(results[0].bottom), left: Math.round(results[0].left), right: Math.round(results[0].right) },
            results: results.length,
            overlaps,
            covered,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function boardVisibleShot(Page $page, string $name): void
{
    $dir = getenv('BOARD_VISIBLE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function boardVisiblePage(User $user, string $to, int $width, int $height): Page
{
    TestSigner::forBrowser($user);
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

test('a mated blitz game shows its result beside or under the board, the final position in full view and steppable, at en 375 and 1440 and de 375', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en'], [1440, 900, 'en'], [375, 812, 'de']] as [$width, $height, $locale]) {
        $game = ChessGame::factory()->create();
        [$white, $black] = [$game->white, $game->black];
        $white->forceFill(['locale' => $locale])->save();

        $page = boardVisiblePage($white, route('games.show', $game), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=abort], [data-test=resign]") !== null', 8_000);

        // Fool's mate: Black mates on move 2.
        $service = app(ChessGameService::class);
        foreach ([[$white, 'f2f3'], [$black, 'e7e5'], [$white, 'g2g4'], [$black, 'd8h4']] as [$player, $uci]) {
            $game = $service->move($game->refresh(), $player, $uci);
        }
        $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=chess-game]")).resync()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=game-over]") !== null', 8_000);

        $seen = $page->evaluate(BOARD_VISIBLE, ['[data-test=live-board]', '[data-test=game-over], [data-test=tournament-panel]']);
        boardVisibleShot($page, "chess-end-{$locale}-{$width}");

        // Step back: a real click on White's first move shows that position.
        $page->locator($width < 1024 ? '[data-test=move-strip] button' : '[data-test=move-list] button')->first()->click();
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=chess-game]")).browsing === true', 3_000);
        $stepped = $page->evaluate('() => document.querySelector("[data-test=live-board]").classList.contains("is-past")');

        expect($game->status->value)->toBe('finished')
            ->and($seen['results'])->toBeGreaterThanOrEqual(1)
            ->and($seen['overlaps'])->toBe(0)
            ->and($seen['covered'])->toBe(0)
            ->and($stepped)->toBeTrue()
            ->and($seen['overflow'])->toBeLessThanOrEqual(0)
            ->and($seen['errors'])->toBe([])
            ->and($page->evaluate('() => window.__errors'))->toBe([]);

        if ($width >= 1024) {
            // Desktop: the result heads the side column, on screen next to the board.
            expect($seen['result']['left'])->toBeGreaterThanOrEqual($seen['board']['right'])
                ->and($seen['result']['top'])->toBeLessThan($height);
        } else {
            expect($seen['result']['top'])->toBeGreaterThanOrEqual($seen['board']['bottom']);
        }

        $measured["chess-{$locale}-{$width}"] = array_diff_key($seen, ['errors' => 1]);
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[board-visible-chess] ".json_encode($measured)."\n");
});

test('a resigned board game shows its result beside or under the board, the board in full view and steppable, at en 375 and 1440 and de 375', function () {
    FixtureBoardGame::play();
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en'], [1440, 900, 'en'], [375, 812, 'de']] as [$width, $height, $locale]) {
        [$anna, $bert] = User::factory()->count(2)->create(['locale' => $locale]);
        $service = app(BoardGameService::class);
        $game = $service->start(FixtureBoardGame::SLUG, $anna, $bert);

        $page = boardVisiblePage($anna, route('board.show', $game), $width, $height);
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.state.status === "active"', 8_000);

        $service->move($game->refresh(), $anna, 'a1', 1);
        $service->resign($game->refresh(), $bert);
        $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=board-game]")).resync()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=result]") !== null', 8_000);

        $seen = $page->evaluate(BOARD_VISIBLE, ['[data-test=board]', '[data-test=board-result], [data-test=tournament-panel]']);
        boardVisibleShot($page, "board-end-{$locale}-{$width}");

        $page->locator('[data-test=history-first]')->click();
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).viewIndex !== null', 3_000);

        expect(BoardGame::query()->findOrFail($game->id)->status->value)->toBe('finished')
            ->and($seen['results'])->toBeGreaterThanOrEqual(1)
            ->and($seen['overlaps'])->toBe(0)
            ->and($seen['covered'])->toBe(0)
            ->and($seen['overflow'])->toBeLessThanOrEqual(0)
            ->and($seen['errors'])->toBe([])
            ->and($page->evaluate('() => window.__errors'))->toBe([]);

        $measured["board-{$locale}-{$width}"] = array_diff_key($seen, ['errors' => 1]);
    }

    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[board-visible-board] ".json_encode($measured)."\n");
});

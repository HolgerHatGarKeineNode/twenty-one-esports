<?php

use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Correspondence board games (plan "Mühle und Dame", P8)
|--------------------------------------------------------------------------
|
| For nine men's morris and for checkers: Anna challenges Bert on the board
| game's correspondence page, Bert accepts there and lands on the board,
| both play a few moves (the other side sees each over Reverb), and the
| board steps back to the start and forward again. The correspondence page
| and both boards are measured at 390 and 1440 px, in English and German:
| no sideways overflow, the deadline in hours, and console, uncaught errors
| and every answer >= 400 empty (BrowserConsole), with a positive control.
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

    NineMensMorrisOn::play();
    CheckersGame::play();
});

function correspondencePage(User $user, string $to, int $width, int $height, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * @return array{lang: string, scroll: int, client: int, errors: list<string>, bad: list<string>}
 */
function correspondenceMeasure(Page $page): array
{
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    return [
        'lang' => (string) $page->evaluate('() => document.documentElement.lang'),
        'scroll' => (int) $scroll,
        'client' => (int) $client,
        'errors' => (array) $page->evaluate('() => window.__errors'),
        'bad' => (array) $page->evaluate(BrowserConsole::BAD_RESPONSES),
    ];
}

/** The board's Alpine state, as JSON-able values. */
function correspondenceBoard(Page $page, string $expression): mixed
{
    return $page->evaluate('() => { const g = Alpine.$data(document.querySelector("[data-test=board-game]")); return '.$expression.'; }');
}

/**
 * Clicks one move on the mover's board and waits until both pages show it.
 *
 * @param  list<Page>  $pages
 */
function correspondenceMove(Page $mover, array $pages, string $move, int $ply): void
{
    BrowserWait::until($mover, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).canMove', 10_000);

    foreach (preg_split('/[-x]/', $move) as $point) {
        $mover->locator('[data-point="'.$point.'"]')->click();
    }

    foreach ($pages as $page) {
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).state.ply === '.$ply, 10_000);
    }
}

test('a correspondence game of nine men\'s morris and of checkers starts from a challenge and is played a few moves, measured clean', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $games = [NineMensMorris::SLUG => ['d2', 'd6', 'b2'], Checkers::SLUG => ['c3-d4', 'f6-g5', 'b2-c3']];
    $measured = [];
    $firstPage = null;

    foreach ($games as $slug => $moves) {
        $path = route('board.correspondence', $slug, false);

        // Anna challenges Bert, playing White.
        $challenger = correspondencePage($anna, $path, $width, $height, $locale);
        $firstPage ??= $challenger;
        BrowserWait::until($challenger, '() => document.querySelector("[data-test=pick-player]") !== null', 10_000);
        $challenger->locator('[data-test=player-search]')->fill($bert->name);
        BrowserWait::until($challenger, '() => document.querySelectorAll("[data-test=pick-player]").length === 1', 10_000);
        $challenger->locator('[data-test=pick-player]')->click();
        BrowserWait::until($challenger, '() => document.querySelector("[data-test=pick-player]")?.getAttribute("aria-checked") === "true"', 10_000);
        $challenger->locator('[data-test=color-white]')->click();
        BrowserWait::until($challenger, '() => document.querySelector("[data-test=color-white]")?.getAttribute("aria-checked") === "true"', 10_000);
        $challenger->locator('[data-test=send-challenge]')->click();
        BrowserWait::until($challenger, '() => document.querySelector("[data-test=outgoing-challenge]") !== null', 10_000);
        $measured["{$slug}-challenge"] = correspondenceMeasure($challenger);
        shellShot($challenger, "board-correspondence-{$slug}-{$locale}-{$width}");

        expect(BoardChallenge::query()->where('game', $slug)->sole()->only(['challenger_id', 'challenged_id', 'color', 'rated']))
            ->toBe(['challenger_id' => $anna->id, 'challenged_id' => $bert->id, 'color' => 'white', 'rated' => false]);

        // Bert accepts on his correspondence page and lands on the board.
        $challenged = correspondencePage($bert, $path, $width, $height, $locale);
        BrowserWait::until($challenged, '() => document.querySelector("[data-test=accept-challenge]") !== null', 10_000);
        $challenged->locator('[data-test=accept-challenge]')->click();
        BrowserWait::until($challenged, '() => location.pathname.startsWith("/board/") && document.querySelector("[data-test=board-game]") !== null', 15_000);

        $game = BoardGame::query()->where('game', $slug)->sole();
        expect($game->only(['mode', 'white_id', 'black_id']))->toBe(['mode' => BoardGame::CORRESPONDENCE, 'white_id' => $anna->id, 'black_id' => $bert->id]);

        $challenger->goto(ComputeUrl::from(route('board.show', $game, false)));
        $pages = [$challenger, $challenged];

        foreach ($pages as $page) {
            BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"', 10_000);
        }

        foreach ($moves as $index => $move) {
            correspondenceMove($index % 2 === 0 ? $challenger : $challenged, $pages, $move, $index + 1);
        }

        // A day per move: the side to move sees hours left and the deadline written out.
        $clock = (string) $challenged->evaluate('() => document.querySelector("[data-test=clock-bottom]").innerText');
        $deadline = (string) $challenged->evaluate('() => document.querySelector("[data-test=deadline]").innerText');
        $status = (string) $challenged->evaluate('() => document.querySelector("[data-test=status-line]").innerText');

        expect($clock)->toMatch('/^2[34] h \d\d min$/')
            ->and($deadline)->not->toBe('')
            ->and($status)->toContain($locale === 'de' ? 'Fernpartie' : 'Correspondence');

        // Back to the start and forward again: the board shows the pieces of that position, and no move can be made there.
        $challenged->locator('[data-test=history-first]')->click();
        BrowserWait::until($challenged, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).viewIndex === 0 && getComputedStyle(document.querySelector("[data-test=history-pinned]")).display !== "none"', 5_000);
        $atStart = [
            'pieces' => (int) $challenged->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-piece]")].filter((e) => e.dataset.piece !== "").length'),
            'canMove' => correspondenceBoard($challenged, 'g.canMove'),
            'pinned' => (string) $challenged->evaluate('() => document.querySelector("[data-test=history-pinned] span").innerText'),
        ];
        $challenged->locator('[data-test=history-last]')->click();
        BrowserWait::until($challenged, '() => Alpine.$data(document.querySelector("[data-test=board-game]")).viewIndex === null', 5_000);
        $atEnd = (int) $challenged->evaluate('() => [...document.querySelectorAll("[data-test=board] [data-piece]")].filter((e) => e.dataset.piece !== "").length');

        expect($atStart)->toBe(['pieces' => $slug === Checkers::SLUG ? 24 : 0, 'canMove' => false, 'pinned' => $locale === 'de' ? 'Zug 0 von 3' : 'Showing move 0 of 3'])
            ->and($atEnd)->toBe($slug === Checkers::SLUG ? 24 : 3)
            ->and(correspondenceBoard($challenged, 'g.canMove'))->toBeTrue();

        $measured["{$slug}-white"] = correspondenceMeasure($challenger);
        $measured["{$slug}-black"] = correspondenceMeasure($challenged);
        shellShot($challenged, "board-correspondence-game-{$slug}-{$locale}-{$width}");

        // The correspondence page lists the game as Bert's move.
        $challenged->goto(ComputeUrl::from($path));
        BrowserWait::until($challenged, '() => document.querySelector("[data-test=correspondence-game]") !== null', 10_000);
        expect($challenged->evaluate('() => document.querySelector("[data-test=correspondence-game]").dataset.mine'))->toBe('true');
        $measured["{$slug}-list"] = correspondenceMeasure($challenged);
    }

    fwrite(STDERR, "board correspondence {$locale} {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    foreach ($measured as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $firstPage->evaluate('() => { setTimeout(() => { throw new Error("board correspondence positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($firstPage, '() => window.__errors.some((e) => e.includes("board correspondence positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'phone 390, de' => [390, 844, 'de'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);

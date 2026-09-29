<?php

use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
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
| The board games in the league (plan "Mühle und Dame", P5)
|--------------------------------------------------------------------------
|
| Two players meet in the lobby of nine men's morris: one searches, the
| other's "Find opponent" pairs them, and both land on the board (the first
| by push or by the searching page's poll). The lobby, the board and the
| board game's ladder are measured at 390 and 1440 px, in English and
| German; a guest's board keeps its lower player card inside the first
| viewport at 1440 x 900 under the "New here?" strip (the P2 review note).
| Console, uncaught errors and every answer >= 400 are collected
| (BrowserConsole) and stay empty, with a positive control.
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

function boardLeaguePage(?User $user, string $to, int $width, int $height, string $locale): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * The page as measured: language, sideways overflow, and the collector.
 *
 * @return array{lang: string, scroll: int, client: int, errors: list<string>, bad: list<string>}
 */
function boardLeagueMeasure(Page $page): array
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

/** Top and bottom of an element in the viewport, rounded, or null. */
function boardLeagueBox(Page $page, string $selector): ?array
{
    return $page->evaluate('() => { const e = document.querySelector('.json_encode($selector).'); if (! e) return null; const r = e.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right) }; }');
}

test('two players meet in a board game lobby and land on the board; lobby, board and ladder measured clean', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = route('board.lobby', NineMensMorris::SLUG, false);
    $title = $locale === 'de' ? 'Mühle' : "Nine Men's Morris";
    $find = $locale === 'de' ? 'Gegner finden' : 'Find opponent';

    $first = boardLeaguePage($anna, $lobby, $width, $height, $locale);
    expect($first->evaluate('() => document.querySelector("[data-test=board-lobby] h1").innerText'))->toBe($title)
        ->and($first->evaluate('() => document.querySelector("[data-test=find-opponent]").innerText.trim()'))->toBe($find);
    $lobbyBeforeSearch = boardLeagueMeasure($first);
    shellShot($first, "board-lobby-{$locale}-{$width}");

    $first->locator('[data-test=find-opponent]')->click();
    BrowserWait::until($first, '() => document.querySelector("[data-test=lobby-searching]") !== null', 10_000);

    $second = boardLeaguePage($bert, $lobby, $width, $height, $locale);
    $second->locator('[data-test=find-opponent]')->click();

    foreach ([$second, $first] as $page) {
        BrowserWait::until($page, '() => location.pathname.startsWith("/board/") && document.querySelector("[data-test=board-game]") !== null', 15_000);
    }

    $game = BoardGame::query()->sole();
    expect($game->game)->toBe(NineMensMorris::SLUG)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id]);

    $measured = ['lobby' => $lobbyBeforeSearch];

    foreach (['first' => $first, 'second' => $second] as $who => $page) {
        BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]"))?.connection === "connected"', 10_000);
        $measured["board-{$who}"] = boardLeagueMeasure($page) + [
            'bottomCard' => boardLeagueBox($page, '[data-test=player-bottom]'),
            // The board game's own bar is the active one, never chess's.
            'active' => $page->evaluate('() => [...document.querySelectorAll("[aria-current=page]")].map((e) => e.innerText.trim()).filter(Boolean)'),
        ];
    }

    shellShot($first, "board-game-{$locale}-{$width}");

    // The board game's ladder, as the lobby links it.
    $second->goto(ComputeUrl::from(route('ladder.show', [Checkers::SLUG, 'blitz'], false)));
    BrowserWait::until($second, '() => document.readyState === "complete"', 10_000);
    $measured['ladder'] = boardLeagueMeasure($second);
    shellShot($second, "board-ladder-{$locale}-{$width}");

    fwrite(STDERR, "board league {$locale} {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    foreach ($measured as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }

    // A player's board with both player cards fits the first viewport on the desktop.
    if ($width >= 1024) {
        expect($measured['board-first']['bottomCard']['bottom'])->toBeLessThanOrEqual($height)
            ->and(implode(' ', $measured['board-first']['active']))->toContain($locale === 'de' ? 'Mühle' : 'Morris');
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $first->evaluate('() => { setTimeout(() => { throw new Error("board league positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($first, '() => window.__errors.some((e) => e.includes("board league positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'phone 390, de' => [390, 844, 'de'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);

test('a guest watching a board game at 1440 x 900 sees both player cards under the "New here?" strip, and a guest lobby is clean', function (string $locale) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = app(BoardGameService::class)->start(Checkers::SLUG, $anna, $bert);

    $guest = boardLeaguePage(null, route('board.show', $game, false), 1440, 900, $locale);
    BrowserWait::until($guest, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=board-game]")) !== undefined', 10_000);

    $strip = boardLeagueBox($guest, '[data-test=first-steps]');
    $bottom = boardLeagueBox($guest, '[data-test=player-bottom]');
    $board = boardLeagueBox($guest, '[data-test=board]');
    $watch = boardLeagueMeasure($guest);

    $guest->goto(ComputeUrl::from(route('board.lobby', Checkers::SLUG, false)));
    BrowserWait::until($guest, '() => document.querySelector("[data-test=lobby-login]") !== null', 10_000);
    $lobby = boardLeagueMeasure($guest);
    shellShot($guest, "board-lobby-guest-{$locale}-1440");

    fwrite(STDERR, "board guest {$locale} 1440x900: ".json_encode(compact('strip', 'board', 'bottom', 'watch', 'lobby')).PHP_EOL);

    expect($strip)->not->toBeNull()
        ->and($bottom['bottom'])->toBeLessThanOrEqual(900)
        ->and($board['bottom'] - $board['top'])->toBeGreaterThanOrEqual(420);

    foreach (['watch' => $watch, 'lobby' => $lobby] as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }
})->with(['en', 'de']);

<?php

use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\InviteLink;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\ChessLobby;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The chess lobby's overview (lobby v2) at 375 x 667 and 1440 x 900
|--------------------------------------------------------------------------
|
| The tile row lies in the first viewport, above the fixed chrome at the
| bottom (the tab bar, and the match dock's bar over it). Blitz opens its
| panel and "Find opponent" starts and cancels a search; Daily chess leads
| into the challenge; the invite tile makes a link; "Challenge a player"
| brings the online list and its first Invite into focus, and only a player
| who is looking to play has an Invite on their row.
|
| Collected on every page: console errors, uncaught errors, rejected
| promises, fetch/XHR and resource answers >= 400 (Tests\Support\
| BrowserConsole), with the positive control below.
|
| LOBBY_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);
    // Pairing must not happen by the lobby's own checks while the test searches.
    config(['esports.chess.lobby_poll_seconds' => 3600, 'esports.chess.queue.range.every_seconds' => 3600]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** Top of the fixed chrome at the bottom: the tab bar, and the dock's bar over it (below lg); else the window. */
const LOBBY_FLOOR = <<<'JS'
    () => Math.round(Math.min(innerHeight, ...['[data-test=tab-bar]', '[data-test=dock-mobile-bar]']
        .map((s) => document.querySelector(s)).filter((el) => el && el.checkVisibility()).map((el) => el.getBoundingClientRect().top)))
    JS;

/** The visible tile lines that do not fit their box (their text is cut). */
const LOBBY_CUT = <<<'JS'
    () => [...document.querySelectorAll('[data-test=play-grid] .truncate')].filter((el) => el.checkVisibility() && el.getBoundingClientRect().width > 1 && el.scrollWidth > el.clientWidth).map((el) => el.textContent.trim())
    JS;

/** The element's box and whether a tap at its centre reaches it. */
const LOBBY_HIT = <<<'JS'
    (selector) => {
        const el = document.querySelector(selector);
        const r = el.getBoundingClientRect();
        const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right),
            hit: !!top && (top === el || el.contains(top)), over: top ? (top.dataset?.test || top.closest('[data-test]')?.dataset.test || top.tagName) : null };
    }
    JS;

function lobbyPage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 10_000);

    return $page;
}

function lobbyShot(Page $page, string $name): void
{
    $dir = getenv('LOBBY_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * The element lies in the first viewport, above the chrome at the bottom, and takes a tap at its centre.
 *
 * @return array<string, mixed>
 */
function lobbyAboveFloor(Page $page, string $selector, string $label): array
{
    $box = $page->evaluate(LOBBY_HIT, $selector);
    $floor = $page->evaluate(LOBBY_FLOOR);
    fwrite(STDERR, "\n[lobby] {$label}: ".json_encode($box + ['floor' => $floor])."\n");

    expect($box['top'])->toBeGreaterThanOrEqual(0, $label)
        ->and($box['bottom'])->toBeLessThanOrEqual($floor, $label)
        ->and($box['left'])->toBeGreaterThanOrEqual(0, $label)
        ->and($box['hit'])->toBeTrue("{$label}: the centre of {$selector} is covered by {$box['over']}");

    return $box;
}

function lobbyClean(Page $page, string $label): void
{
    $widths = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "\n[lobby] {$label}: scrollWidth/clientWidth ".json_encode($widths)."\n");

    expect($page->evaluate('() => window.__errors'))->toBe([], $label)
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label)
        ->and($widths[0])->toBeLessThanOrEqual($widths[1], $label);
}

test('every way to play is in the first viewport, and each tile does its job at 375 and 1440 px', function () {
    $player = User::factory()->create(['name' => 'lena.k', 'locale' => 'en']);
    $rival = User::factory()->lookingToPlay()->create(['name' => 'pillpusher', 'locale' => 'en']);
    $quiet = User::factory()->create(['name' => 'quiet.carl', 'locale' => 'en']);
    // Two daily games wait for Lena's move: the Daily chess tile counts them (and the dock shows below lg).
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'black_id' => $rival->id]);
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'black_id' => $quiet->id]);
    // The tournaments tile names the next one and when its sign-up closes.
    Tournament::factory()->signup()->create(['name' => 'Friday Blitz Kempten', 'signup_closes_at' => now()->addDays(3)->setTime(18, 5)]);

    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        // Two others online: one looking to play, one not.
        $others = [lobbyPage($rival, route('chess.lobby', absolute: false), $width, $height), lobbyPage($quiet, route('chess.lobby', absolute: false), $width, $height)];

        $page = lobbyPage($player, route('chess.lobby', absolute: false), $width, $height);
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=online-player]").length === 2', 10_000);

        // The tile row and each tile: in the first viewport, above the chrome at the bottom.
        lobbyAboveFloor($page, '[data-test=play-grid]', "grid {$width}");
        foreach (['play-rapid', 'play-blitz', 'play-daily', 'play-challenge', 'play-invite', 'play-tournaments'] as $tile) {
            lobbyAboveFloor($page, "[data-test={$tile}]", "{$tile} {$width}");
        }
        // The Team match teaser (not playable yet) shows only in the wide grid: a fourth row of tiles pushed "Find opponent" under the tab bar.
        if ($width >= 1024) {
            lobbyAboveFloor($page, '[data-test=play-team]', "play-team {$width}");
        } else {
            expect($page->evaluate('() => document.querySelector("[data-test=play-team]").checkVisibility()'))->toBeFalse();
        }
        // No tile cuts its words: every visible line of a tile fits its box.
        expect($page->evaluate(LOBBY_CUT))->toBe([], "cut tile text at {$width}");
        expect($page->evaluate('() => document.querySelector("[data-test=play-daily-count]").textContent.trim()'))->toStartWith('2')
            ->and($page->evaluate('() => document.querySelector("[data-test=play-online-count]").textContent'))->toBe('2')
            // Nothing opened yet: the panels wait for their tiles.
            ->and($page->evaluate('() => [document.querySelector("#lobby-quick").checkVisibility(), document.querySelector("#lobby-invite").checkVisibility()]'))->toBe([false, false]);
        lobbyShot($page, "lobby-{$width}");
        lobbyClean($page, "lobby {$width}");

        // Only a player who is looking has an Invite on the row; the other row has none.
        $rows = $page->evaluate('() => [...document.querySelectorAll("[data-test=online-player]")].map((li) => [li.querySelector("[data-test=online-player-link]").innerText.trim(), li.querySelector("[data-test=invite]") !== null])');
        expect(collect($rows)->sortBy(0)->values()->all())->toBe([['pillpusher', true], ['quiet.carl', false]]);

        // "Challenge a player" brings the online list and its first Invite into focus.
        $page->locator('[data-test=play-challenge]')->click();
        BrowserWait::until($page, '() => document.activeElement?.dataset.test === "invite"', 5_000);

        // Blitz: the panel opens in place, "Find opponent" above the floor, the search starts and stops.
        $page->evaluate('() => scrollTo(0, 0)');
        ChessLobby::openBlitz($page);
        expect($page->evaluate('() => document.querySelector("[data-test=play-blitz]").getAttribute("aria-expanded")'))->toBe('true')
            ->and($page->evaluate('() => document.querySelector("[data-test=kind-rated]").disabled'))->toBeTrue();
        lobbyAboveFloor($page, '[data-test=find-opponent-button]', "find opponent {$width}");
        lobbyShot($page, "lobby-blitz-{$width}");
        $page->locator('[data-test=find-opponent-button]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=searching]") !== null', 10_000);
        expect(ChessQueueEntry::query()->where('user_id', $player->id)->exists())->toBeTrue()
            ->and($page->evaluate('() => document.querySelector("[data-test=play-blitz-state]") !== null'))->toBeTrue();
        $page->locator('[data-test=cancel-search]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=searching]") === null', 10_000);
        expect(ChessQueueEntry::query()->where('user_id', $player->id)->exists())->toBeFalse();
        lobbyClean($page, "blitz {$width}");

        // The invite tile: its module opens in place and makes a link.
        $page->locator('[data-test=play-invite]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-module]").checkVisibility() && ! document.querySelector("#lobby-quick").checkVisibility()', 5_000);
        lobbyAboveFloor($page, '[data-test=invite-create]', "invite create {$width}");
        lobbyShot($page, "lobby-invite-{$width}");
        lobbyClean($page, "invite {$width}");
        $before = InviteLink::query()->count();
        $page->locator('[data-test=invite-create]')->click();
        BrowserWait::until($page, '() => location.pathname.startsWith("/i/") && document.querySelector("[data-test=invite-url]") !== null', 10_000);
        expect(InviteLink::query()->count())->toBe($before + 1)
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-url]").value'))->toBe(InviteLink::query()->latest('id')->firstOrFail()->url());
        lobbyClean($page, "invite landing {$width}");

        // Daily chess: into the challenge flow.
        $page = lobbyPage($player, route('chess.lobby', absolute: false), $width, $height);
        $page->locator('[data-test=play-daily]')->click();
        BrowserWait::until($page, '() => location.pathname === "/chess/challenge" && document.querySelector("[data-test=chess-challenge]") !== null', 10_000);
        lobbyClean($page, "daily {$width}");

        foreach ($others as $other) {
            $other->close();
        }
    }

    // A guest: the same tiles, in their logged-out state, still in the first viewport.
    $page = lobbyPage(null, route('chess.lobby', ['lang' => 'en'], false), 375, 667);
    lobbyAboveFloor($page, '[data-test=play-grid]', 'guest grid 375');
    ChessLobby::openBlitz($page);
    expect($page->evaluate('() => document.querySelector("[data-test=find-opponent-login]").getAttribute("href")'))->toEndWith('/login');
    lobbyShot($page, 'lobby-guest-375');
    lobbyClean($page, 'guest 375');
});

test('the lobby collector sees a thrown error and a missing asset (positive control)', function () {
    $page = lobbyPage(null, route('chess.lobby', absolute: false), 375, 667);
    $page->evaluate('() => { const img = new Image(); img.src = "/__lobby-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__lobby-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toMatch('#^404 http://\S+/__lobby-missing\.png$#m');

    // The cut-text probe sees a line that does not fit.
    expect($page->evaluate(LOBBY_CUT))->toBe([]);
    $page->evaluate('() => { document.querySelector("[data-test=play-invite] .truncate").textContent = "a line far too long for any tile of the lobby"; }');
    expect($page->evaluate(LOBBY_CUT))->toBe(['a line far too long for any tile of the lobby']);
});

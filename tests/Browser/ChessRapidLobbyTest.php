<?php

use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Rapid in the chess lobby (plan "Schach Rapid und Clan", P2) at 390 and 1440 px
|--------------------------------------------------------------------------
|
| Rapid 10+5 is the first tile and twice as wide, Blitz 5+3 the second; each
| tile counts who searches its mode. "Either" pairs with the first fitting
| searcher of either mode. After switch_hint_seconds alone the searching card
| names the other mode's searchers and offers to switch: the hint is the
| server's (joined_at), so the test places the entry 29 s in the past and
| waits for the card's own check at 30 s, never 30 real seconds.
|
| Collected on every page: console errors, uncaught errors, rejected
| promises, fetch/XHR and resource answers >= 400 (BrowserConsole), with the
| positive control below. RAPID_SHOTS=<dir> writes the English screenshots.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);
    // Pairings arrive by push; the lobby's own checks and the widening range are an hour away.
    config(['esports.chess.lobby_poll_seconds' => 3600, 'esports.chess.queue.range.every_seconds' => 3600]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** Every element's box (top, bottom, left, right, width), rounded. */
const RAPID_BOX = <<<'JS'
    (selector) => {
        const el = document.querySelector(selector);
        if (! el) return null;
        const r = el.getBoundingClientRect();
        return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
    }
    JS;

/** The visible tile lines that do not fit their box. */
const RAPID_CUT = <<<'JS'
    () => [...document.querySelectorAll('[data-test=play-grid] .truncate, [data-test=switch-hint] *, [data-test=either-option] *')].filter((el) => el.checkVisibility() && el.getBoundingClientRect().width > 1 && el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent.trim())
    JS;

function rapidPage(User $user, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('chess.lobby', absolute: false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 10_000);

    return $page;
}

/** @return array<string, int>|null */
function rapidBox(Page $page, string $selector, string $label): ?array
{
    $box = $page->evaluate(RAPID_BOX, $selector);
    fwrite(STDERR, "\n[rapid] {$label}: ".json_encode($box)."\n");

    return $box;
}

function rapidClean(Page $page, string $label): void
{
    $widths = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "\n[rapid] {$label}: scrollWidth/clientWidth ".json_encode($widths)."\n");

    expect($page->evaluate('() => window.__errors'))->toBe([], $label)
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label)
        ->and($widths[0])->toBeLessThanOrEqual($widths[1], $label)
        ->and($page->evaluate(RAPID_CUT))->toBe([], "cut text {$label}");
}

/** The viewport, with `$selector` (if given) scrolled to its centre: a full-page shot paints the sticky header mid-page. */
function rapidShot(Page $page, string $name, ?string $selector = null): void
{
    $dir = getenv('RAPID_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    if ($selector !== null) {
        $page->evaluate('(s) => document.querySelector(s).scrollIntoView({ block: "center" })', $selector);
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** A searcher far away in rating: counted, never paired with the test's players (range 150, widening an hour away). */
function farSearcher(string $mode, ?array $modes = null): User
{
    $user = User::factory()->create();
    ChessQueueEntry::query()->create(['user_id' => $user->id, 'mode' => $mode, 'modes' => $modes, 'rated' => false, 'rating' => 2400, 'joined_at' => now()]);

    return $user;
}

test('rapid is the first tile with the searching counts, and "either" pairs with a blitz searcher, at 390 and 1440 px', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        ChessQueueEntry::query()->delete();
        ChessGame::query()->delete();
        farSearcher('rapid');
        farSearcher('rapid');
        farSearcher('blitz');
        [$anna, $bert] = [User::factory()->create(['name' => 'anna.rapid', 'locale' => 'en']), User::factory()->create(['name' => 'bert.blitz', 'locale' => 'en'])];

        $page = rapidPage($anna, $width, $height);

        // Rapid first (from a 48rem grid twice as wide), Blitz beside it; both counts are the queue's.
        $grid = rapidBox($page, '[data-test=play-grid]', "grid {$width}");
        $rapid = rapidBox($page, '[data-test=play-rapid]', "rapid tile {$width}");
        $blitz = rapidBox($page, '[data-test=play-blitz]', "blitz tile {$width}");
        expect($rapid['left'])->toBe($grid['left'])
            ->and($rapid['top'])->toBe($blitz['top'])
            ->and($rapid['right'])->toBeLessThan($blitz['left'])
            ->and($width < 1024 || $rapid['width'] > $blitz['width'] * 1.8)->toBeTrue()
            ->and($rapid['bottom'])->toBeLessThanOrEqual($height)
            ->and($page->evaluate('() => document.querySelector("[data-test=play-rapid-searching]").textContent.trim()'))->toBe('2')
            ->and($page->evaluate('() => document.querySelector("[data-test=play-blitz-searching]").textContent.trim()'))->toBe('1');
        rapidShot($page, "rapid-lobby-{$width}");
        rapidClean($page, "lobby {$width}");

        // Anna opens Rapid and takes "either": the panel says the mode, the search takes both.
        $page->locator('[data-test=play-rapid]')->click();
        BrowserWait::until($page, '() => document.querySelector("#lobby-quick")?.checkVisibility() === true && document.querySelector("#lobby-quick").dataset.mode === "rapid"', 5_000);
        $page->locator('[data-test=either]')->check();
        $either = rapidBox($page, '[data-test=either-option]', "either {$width}");
        $find = rapidBox($page, '[data-test=find-opponent-button]', "find opponent {$width}");
        expect($either['bottom'])->toBeLessThanOrEqual($height)->and($find['bottom'])->toBeLessThanOrEqual($height);
        rapidShot($page, "rapid-panel-{$width}", '#lobby-quick');
        $page->locator('[data-test=find-opponent-button]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=searching]") !== null', 10_000);
        expect(ChessQueueEntry::query()->where('user_id', $anna->id)->sole()->takes())->toBe(['rapid', 'blitz'])
            ->and($page->evaluate('() => document.querySelector("[data-test=searching-title]").textContent.trim()'))->toBe('Finding opponent … 10+5 / 5+3');
        rapidClean($page, "either searching {$width}");

        // Bert searches blitz only: the pairing is blitz, the one mode both take, and reaches Anna by push.
        $other = rapidPage($bert, $width, $height);
        $other->locator('[data-test=play-blitz]')->click();
        BrowserWait::until($other, '() => document.querySelector("#lobby-quick")?.dataset.mode === "blitz"', 5_000);
        $other->locator('[data-test=find-opponent-button]')->click();
        BrowserWait::until($other, '() => location.pathname.startsWith("/games/")', 10_000);
        BrowserWait::until($page, '() => location.pathname.startsWith("/games/")', 5_000);

        $game = ChessGame::query()->sole();
        expect($game->mode)->toBe('blitz')
            ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id]);
        rapidClean($page, "paired {$width}");
        $other->close();
    }
});

test('after 30 s alone the searching card names the other mode\'s searchers, and switching keeps searching, at 390 and 1440 px', function () {
    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        ChessQueueEntry::query()->delete();
        farSearcher('blitz');
        farSearcher('blitz');
        $anna = User::factory()->create(['name' => 'anna.waits', 'locale' => 'en']);
        // Anna has searched rapid for 25 s: the card's own check at 30 s brings the hint, no push, no 30 s wait.
        ChessQueueEntry::query()->create(['user_id' => $anna->id, 'mode' => 'rapid', 'rated' => false, 'rating' => 1000, 'joined_at' => now()->subSeconds(25)]);

        $page = rapidPage($anna, $width, $height);
        expect($page->evaluate('() => document.querySelector("[data-test=switch-hint]") === null'))->toBeTrue();
        BrowserWait::until($page, '() => document.querySelector("[data-test=switch-hint]") !== null', 10_000);

        expect($page->evaluate('() => document.querySelector("[data-test=switch-hint]").dataset.mode'))->toBe('blitz')
            ->and($page->evaluate('() => document.querySelector("[data-test=switch-hint] b").textContent.trim()'))->toBe('2 players search Blitz 5+3 right now.');
        $hint = rapidBox($page, '[data-test=switch-hint]', "hint {$width}");
        $switch = rapidBox($page, '[data-test=switch-hint-switch]', "hint switch {$width}");
        expect($hint['right'])->toBeLessThanOrEqual($width)->and($hint['bottom'])->toBeLessThanOrEqual($height)->and($switch['bottom'] - $switch['top'])->toBeGreaterThanOrEqual(44);
        rapidShot($page, "rapid-hint-{$width}", '[data-test=switch-hint]');
        rapidClean($page, "hint {$width}");

        $page->locator('[data-test=switch-hint-switch]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=play-blitz-state]") !== null && document.querySelector("[data-test=switch-hint]") === null', 10_000);
        expect(ChessQueueEntry::query()->where('user_id', $anna->id)->sole()->only(['mode', 'modes']))->toBe(['mode' => 'blitz', 'modes' => null]);
        rapidClean($page, "switched {$width}");
    }
});

test('the rapid lobby collector sees a thrown error and a missing asset (positive control)', function () {
    $page = rapidPage(User::factory()->create(['locale' => 'en']), 390, 844);
    $page->evaluate('() => { const img = new Image(); img.src = "/__rapid-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__rapid-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toMatch('#^404 http://\S+/__rapid-missing\.png$#m');

    // The cut-text probe sees a tile line that does not fit.
    expect($page->evaluate(RAPID_CUT))->toBe([]);
    $page->evaluate('() => { document.querySelector("[data-test=play-blitz] .truncate").textContent = "a line far too long for any tile of the lobby"; }');
    expect($page->evaluate(RAPID_CUT))->toBe(['a line far too long for any tile of the lobby']);
});

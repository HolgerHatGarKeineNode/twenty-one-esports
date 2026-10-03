<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Support\Chess\ChessGameService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The end of a tournament game in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A player wins a knockout chess game: the game-over card shows the
| tournament panel (result, "wait for the next round", the other match still
| playing, "Back to the tournament") with the countdown instead of a
| rematch or a new search, and the countdown takes the player to the
| tournament page. "Stay here" stops it. At en 375 and 1440 and de 375.
|
| Collected on the game page and on the tournament page: console.error/warn,
| uncaught errors, rejected promises, fetch and XHR >= 400, and horizontal
| overflow; a thrown error injected at the end proves the collector sees
| one. GAME_END_SHOTS=<dir> writes the screenshots there.
|
*/

const GAME_END_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const GAME_END_STATE = <<<'JS'
    () => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        errors: window.__errors ?? ['collector missing'],
        path: window.location.pathname,
    })
    JS;

/** The panel on the game-over card: its box, the dialog's box, the countdown and the text. */
const GAME_END_PANEL = <<<'JS'
    () => {
        const panel = document.querySelector('[data-test=tournament-panel]');
        const dialog = document.querySelector('[data-test=game-over] [role=dialog]').getBoundingClientRect();
        const box = panel.getBoundingClientRect();
        const back = panel.querySelector('[data-test=back-to-tournament]').getBoundingClientRect();
        return {
            panel: { left: Math.round(box.left), right: Math.round(box.right), height: Math.round(box.height), scrollWidth: panel.scrollWidth, clientWidth: panel.clientWidth },
            dialog: { top: Math.round(dialog.top), bottom: Math.round(dialog.bottom), left: Math.round(dialog.left), right: Math.round(dialog.right), height: Math.round(dialog.height) },
            back: { height: Math.round(back.height), width: Math.round(back.width) },
            state: panel.dataset.state,
            countdown: document.querySelector('[data-test=tournament-countdown-text]')?.innerText ?? null,
            // The countdown and the button are on screen and on top: not under the phone's chat bar or bottom navigation.
            onTop: ['[data-test=tournament-countdown-text]', '[data-test=back-to-tournament]', '[data-test=tournament-stay]'].map((selector) => {
                const el = panel.querySelector(selector);
                const r = el.getBoundingClientRect();
                const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                return hit !== null && (el === hit || el.contains(hit));
            }),
            // A cup game: no casual "Hashrate" line; the rating row only because the rating moved.
            rows: ['game-over-rating-row', 'game-over-mining-row'].map((name) => { const el = document.querySelector('[data-test=' + name + ']'); return el !== null && el.offsetParent !== null; }),
            rematch: document.querySelector('[data-test=rematch], [data-test=find-next], [data-test=accept-rematch]') !== null,
            text: panel.innerText,
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.tournaments.end_redirect_seconds' => 4]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function gameEndShot(Page $page, string $name): void
{
    $dir = getenv('GAME_END_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('a won knockout game ends on the tournament panel with a countdown to the tournament page, at en 375 and 1440 and de 375', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en', false], [1440, 900, 'en', false], [375, 812, 'de', true]] as [$width, $height, $locale, $stay]) {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
        $tournament->forceFill(['name' => 'Halving Blitz Cup'])->save();
        $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();
        [$white, $black] = [$game->white, $game->black];
        $white->forceFill(['locale' => $locale])->save();
        TestSigner::forBrowser($white);

        $page = visit(BrowserLogin::url($white))->page();
        $page->context()->addInitScript(GAME_END_COLLECTOR);
        $page->context()->addInitScript(TestSigner::browserStub($white));
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('games.show', $game)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=resign]") !== null', 8_000);

        // Black resigns: White wins and waits for the next round, the other semi-final still plays.
        app(ChessGameService::class)->resign($game, $black);
        $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=chess-game]")).resync()');
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-panel]") !== null', 8_000);
        BrowserWait::until($page, '() => { const r = document.querySelector("[data-test=tournament-countdown]").getBoundingClientRect(); return r.bottom <= window.innerHeight; }', 3_000);
        $panel = $page->evaluate(GAME_END_PANEL);
        gameEndShot($page, "game-end-{$locale}-{$width}");
        $before = $page->evaluate(GAME_END_STATE);

        expect($panel['state'])->toBe('waiting')
            ->and($panel['rematch'])->toBeFalse()
            ->and($panel['panel']['left'])->toBeGreaterThanOrEqual(0)
            ->and($panel['panel']['right'])->toBeLessThanOrEqual($width)
            ->and($panel['panel']['scrollWidth'])->toBeLessThanOrEqual($panel['panel']['clientWidth'])
            ->and($panel['dialog']['top'])->toBeGreaterThanOrEqual(0)
            ->and($panel['back']['height'])->toBeGreaterThanOrEqual(44)
            ->and($panel['countdown'])->not->toBeNull()
            ->and($panel['onTop'])->toBe([true, true, true])
            ->and($panel['rows'])->toBe([true, false])
            ->and($before['overflow'])->toBeLessThanOrEqual(0)
            ->and($before['errors'])->toBe([]);

        if ($locale === 'en') {
            expect($panel['text'])->toContain('Halving Blitz Cup')->and($panel['text'])->toContain('Round 1')
                ->and($panel['text'])->toContain('Your match is done: wait for the next round')
                ->and($panel['text'])->toContain('You won this game')
                ->and($panel['text'])->toContain('1 other match is still being played.')
                ->and($panel['countdown'])->toMatch('/^The tournament page opens in [1-4]\x{00A0}s\.$/u');
        } else {
            expect($panel['text'])->toContain('Dein Match ist fertig: Warte auf die nächste Runde')
                ->and($panel['text'])->toContain('Du hast diese Partie gewonnen')
                ->and($panel['text'])->toContain('Zurück zum Turnier')
                ->and($panel['countdown'])->toMatch('/^Die Turnierseite öffnet sich in [1-4]\x{00A0}s\.$/u');
        }

        if ($stay) {
            // "Stay here" stops the countdown: two seconds past its end the page is still the game.
            $page->locator('[data-test=tournament-stay]')->click();
            usleep(6_000_000);
            $after = $page->evaluate(GAME_END_STATE);

            expect($after['path'])->toBe(parse_url(route('games.show', $game), PHP_URL_PATH))
                ->and($after['errors'])->toBe([]);

            $measured["{$locale}-{$width}"] = $panel + ['stayed' => $after['path']];

            continue;
        }

        // The countdown takes the player to the tournament page.
        BrowserWait::until($page, '() => window.location.pathname === '.json_encode(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH)), 10_000);
        BrowserWait::until($page, '() => document.readyState === "complete"', 8_000);
        $landed = $page->evaluate(GAME_END_STATE);
        gameEndShot($page, "game-end-landed-{$locale}-{$width}");

        expect($landed['errors'])->toBe([])
            ->and($landed['overflow'])->toBeLessThanOrEqual(0);

        $measured["{$locale}-{$width}"] = $panel + ['landed' => $landed['path']];
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate(GAME_END_STATE)['errors']))->toContain('probe');

    fwrite(STDERR, "\n[game-end] ".json_encode($measured)."\n");
});

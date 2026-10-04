<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\TournamentMatch;
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
| "What to do now" in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A player of a knockout whose semi-final is won waits ("Wait for the next
| round", the round's live count). The other semi-final ends: the final and
| its game are created on the server, and the open page turns into "Play
| now" without a reload (the tournament's push, or the page's poll) and
| flips. It stays on the tournament page ("Das bitte ausmachen": no automatic
| opening); the big button opens the game. Measured at en 375 and 1440 and
| de 375: the hero is the first thing on the page and ends above the phone's
| tab bar.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400; a thrown error at the end proves the collector sees one.
| NOW_SHOTS=<dir> writes the screenshots there.
|
*/

const NOW_COLLECTOR = <<<'JS'
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

/** The hero's box, the phone's tab bar, what is above the hero, and the page's errors. */
const NOW_MEASURE = <<<'JS'
    () => {
        const hero = document.querySelector('[data-test=now-hero]');
        const box = hero.getBoundingClientRect();
        const action = hero.querySelector('[data-test=now-action]')?.getBoundingClientRect() ?? null;
        const title = hero.querySelector('[data-test=now-title]');
        // The fixed bar at the bottom of a phone's screen, if any.
        const bars = [...document.querySelectorAll('body *')].filter((el) => {
            const style = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return style.position === 'fixed' && r.height > 0 && r.height < 160 && r.bottom >= innerHeight - 1 && r.width >= innerWidth * 0.9;
        });
        const tab = bars.length ? Math.round(Math.min(...bars.map((el) => el.getBoundingClientRect().top))) : null;
        const main = hero.closest('[data-test=tournament-show]');
        return {
            state: hero.dataset.state,
            top: Math.round(box.top + scrollY),
            bottom: Math.round(box.bottom + scrollY),
            left: Math.round(box.left),
            right: Math.round(box.right),
            action: action && { top: Math.round(action.top), bottom: Math.round(action.bottom), height: Math.round(action.height), width: Math.round(action.width) },
            titleSize: parseFloat(getComputedStyle(title).fontSize),
            titleOverflow: title.scrollWidth - title.clientWidth,
            first: main.firstElementChild === hero,
            tab,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            text: hero.innerText,
            errors: window.__errors ?? ['collector missing'],
            path: location.pathname,
            avatars: hero.querySelectorAll('[data-test=now-cover] img[data-avatar]').length,
        };
    }
    JS;

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

    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function nowShot(Page $page, string $name): void
{
    $dir = getenv('NOW_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('a waiting player sees the hero first, above the tab bar, and it flips to "Play now" without a reload and stays on the page', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en', true], [1440, 900, 'en', false], [375, 812, 'de', false]] as [$width, $height, $locale, $flip]) {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
        $tournament->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()])->save();
        // The players are read below: loaded with the games, as the lazy-loading guard (local and testing) wants.
        [$semi, $other] = ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->get()->all();
        [$white, $black] = [$semi->white, $semi->black];
        $white->forceFill(['locale' => $locale])->save();
        app(ChessGameService::class)->resign($semi, $black);

        $page = visit(BrowserLogin::url($white))->page();
        $page->context()->addInitScript(NOW_COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]") !== null && document.readyState === "complete"', 8_000);
        $wait = $page->evaluate(NOW_MEASURE);
        nowShot($page, "now-wait-{$locale}-{$width}");

        expect($wait['state'])->toBe('wait')
            ->and($wait['first'])->toBeTrue()
            ->and($wait['left'])->toBeGreaterThanOrEqual(0)
            ->and($wait['right'])->toBeLessThanOrEqual($width)
            ->and($wait['overflow'])->toBeLessThanOrEqual(0)
            ->and($wait['titleOverflow'])->toBeLessThanOrEqual(0)
            ->and($wait['bottom'])->toBeLessThanOrEqual($wait['tab'] ?? $height)
            ->and($wait['avatars'])->toBe(1)
            ->and($wait['errors'])->toBe([]);

        if ($locale === 'en') {
            expect($wait['text'])->toContain('Wait for the next round')->toContain('Round 1: 1 match still playing')->toContain('This page tells you as soon as your game starts.');
        } else {
            expect($wait['text'])->toContain('Warte auf die nächste Runde')->toContain('Runde 1: 1 Match läuft noch');
        }

        if (! $flip) {
            $measured["{$locale}-{$width}"] = $wait;

            continue;
        }

        // The other semi-final ends: the server creates the final and its game. The page is NOT reloaded.
        app(ChessGameService::class)->resign($other, $other->black);
        $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('chessGame', fn ($query) => $query->where('status', 'active'))->firstOrFail();
        $game = $final->chessGame;

        BrowserWait::until($page, '() => document.querySelector("[data-test=now-hero]")?.dataset.state === "play"', 25_000);
        // The flip runs 300 ms: measure the settled hero.
        usleep(500_000);
        $play = $page->evaluate(NOW_MEASURE);
        nowShot($page, "now-play-{$locale}-{$width}");

        expect($play['text'])->toContain('Play now')->toContain('Go to your game')->not->toContain('Your game opens in')
            ->and($play['path'])->toBe(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH))
            ->and($play['action']['height'])->toBeGreaterThanOrEqual(56)
            ->and($play['action']['bottom'])->toBeLessThanOrEqual($play['tab'] ?? $height)
            ->and($play['avatars'])->toBe(2)
            ->and($play['errors'])->toBe([]);

        // No automatic opening (user, 2026-10-03): six seconds later the page is still the tournament.
        usleep(6_000_000);
        $landed = $page->evaluate('() => ({ errors: window.__errors ?? ["collector missing"], path: location.pathname })');

        expect($landed['errors'])->toBe([])
            ->and($landed['path'])->toBe(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH));

        $measured["{$locale}-{$width}"] = $wait + ['play' => array_diff_key($play, ['text' => 1]), 'landed' => $landed['path']];
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, "\n[now] ".json_encode(array_map(fn (array $row): array => array_diff_key($row, ['text' => 1]), $measured))."\n");
});

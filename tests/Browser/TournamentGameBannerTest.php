<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
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
| A tournament game says so at the top of its page, in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A live knockout chess game: the tournament banner is the first thing on
| the page, above the "Game" title, inside the viewport, with the
| tournament page one tap away; under the board the first-move deadline
| counts down as the cup rule ("Make your first move within 4:59 or you
| lose this cup game"). At en 375 and 1440 and de 375.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400, and horizontal overflow; a thrown error injected at the
| end proves the collector sees one. BANNER_SHOTS=<dir> writes the screenshots there.
|
*/

const BANNER_COLLECTOR = <<<'JS'
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

const BANNER_MEASURE = <<<'JS'
    () => {
        const banner = document.querySelector('[data-test=tournament-banner]');
        const box = banner.getBoundingClientRect();
        const title = document.querySelector('[data-test=chess-game] h1').getBoundingClientRect();
        const link = banner.querySelector('[data-test=tournament-banner-link]').getBoundingClientRect();
        const cup = document.querySelector('[data-test=first-move-cup]');
        return {
            banner: { top: Math.round(box.top), left: Math.round(box.left), right: Math.round(box.right), height: Math.round(box.height), scrollWidth: banner.scrollWidth, clientWidth: banner.clientWidth },
            titleTop: Math.round(title.top),
            link: { height: Math.round(link.height), bottom: Math.round(link.bottom) },
            text: banner.innerText,
            firstMove: cup?.innerText ?? null,
            firstMoveFont: cup === null ? 0 : parseFloat(getComputedStyle(cup).fontSize),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
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

function bannerShot(Page $page, string $name): void
{
    $dir = getenv('BANNER_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('a live tournament chess game opens with the tournament banner above the game and the cup first-move countdown, at en 375 and 1440 and de 375', function () {
    $measured = [];
    $page = null;

    foreach ([[375, 812, 'en'], [1440, 900, 'en'], [375, 812, 'de']] as [$width, $height, $locale]) {
        $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
        $tournament->forceFill(['name' => 'Halving Blitz Cup'])->save();
        $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();
        $white = $game->white;
        $white->forceFill(['locale' => $locale])->save();
        TestSigner::forBrowser($white);

        $page = visit(BrowserLogin::url($white))->page();
        $page->context()->addInitScript(BANNER_COLLECTOR);
        $page->context()->addInitScript(TestSigner::browserStub($white));
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('games.show', $game)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=first-move-cup]")?.innerText.length > 0', 8_000);
        $m = $page->evaluate(BANNER_MEASURE);
        bannerShot($page, "banner-{$locale}-{$width}");

        expect($m['banner']['top'])->toBeLessThan($m['titleTop'])
            ->and($m['banner']['top'])->toBeGreaterThanOrEqual(0)
            ->and($m['banner']['left'])->toBeGreaterThanOrEqual(0)
            ->and($m['banner']['right'])->toBeLessThanOrEqual($width)
            ->and($m['banner']['scrollWidth'])->toBeLessThanOrEqual($m['banner']['clientWidth'])
            ->and($m['link']['height'])->toBeGreaterThanOrEqual(44)
            ->and($m['link']['bottom'])->toBeLessThanOrEqual($height)
            ->and($m['firstMoveFont'])->toBeGreaterThanOrEqual(16)
            ->and($m['overflow'])->toBeLessThanOrEqual(0)
            ->and($m['errors'])->toBe([]);

        if ($locale === 'en') {
            expect($m['text'])->toContain('Halving Blitz Cup')->and($m['text'])->toContain('Round 1')
                ->and($m['text'])->toContain('Tournament game — counts for the tournament')
                ->and($m['text'])->toContain('Tournament page')
                ->and($m['firstMove'])->toMatch('/^Make your first move within \d+:\d\d or you lose this cup game$/');
        } else {
            expect($m['text'])->toContain('Runde 1')->and($m['text'])->toContain('Turnierpartie — zählt für das Turnier')->and($m['text'])->toContain('Turnierseite')
                ->and($m['firstMove'])->toMatch('/^Mach deinen ersten Zug innerhalb von \d+:\d\d, sonst verlierst du diese Cup-Partie$/u');
        }

        $measured["{$locale}-{$width}"] = $m;
    }

    // Positive control: an error thrown on the page reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 5_000);

    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe');

    fwrite(STDERR, json_encode(array_map(fn (array $m): array => array_diff_key($m, ['errors' => 1]), $measured), JSON_UNESCAPED_UNICODE)."\n");
});

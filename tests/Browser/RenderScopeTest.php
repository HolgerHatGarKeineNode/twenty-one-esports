<?php

use App\Models\ChessGame;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Render scope in the browser (performance plan P4)
|--------------------------------------------------------------------------
|
| /games builds a blitz board's 64 squares only when the board comes into
| view: a phone (boards hidden) builds none, a desktop the boards near the
| screen, the rest while it scrolls, and a board keeps its height while its
| squares arrive. Collected on every view: console.error/warn, uncaught
| errors, rejected promises, fetch/XHR >= 400 (Livewire's roundtrips are
| fetches); a thrown error and a failing fetch at the end prove the collector
| sees both. RENDER_SCOPE_REPORT=<file> writes the numbers there.
|
*/

const RENDER_SCOPE_COLLECTOR = <<<'JS'
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
    JS;

const RENDER_SCOPE_BOARDS = <<<'JS'
    () => {
        const boards = [...document.querySelectorAll('[data-test=live-board]')];
        const last = boards.at(-1)?.querySelector('[role=img]');
        return {
            boards: boards.length,
            squares: boards.reduce((n, b) => n + b.querySelectorAll('.grid > div').length, 0),
            nodes: document.getElementsByTagName('*').length,
            lastHeight: last ? Math.round(last.getBoundingClientRect().height) : null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
        };
    }
    JS;

function renderScopePage(): Page
{
    $page = visit('/')->page();
    $page->context()->addInitScript(RENDER_SCOPE_COLLECTOR);

    return $page;
}

function renderScopeReport(string $line): void
{
    $file = getenv('RENDER_SCOPE_REPORT');

    if (is_string($file) && $file !== '') {
        file_put_contents($file, $line."\n", FILE_APPEND);
    }

    fwrite(STDERR, "\n[render-scope] {$line}\n");
}

test('/games builds a board when it comes into view: none on a phone, the rest while a desktop scrolls, at 390 and 1440 px', function () {
    ChessGame::factory()->count(48)->create();
    $page = renderScopePage();
    $measured = [];

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('games.index')));
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=live-game]").length === 48 && window.Alpine !== undefined', 10_000);
        $page->evaluate('() => new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => resolve(true)), 300))');
        $measured[$width]['load'] = $page->evaluate(RENDER_SCOPE_BOARDS);

        // Scroll down a screen at a time, the way a reader does: a board builds when it passes the screen.
        $page->evaluate('async () => { const frame = () => new Promise((r) => setTimeout(() => requestAnimationFrame(r), 60)); while (scrollY + innerHeight < document.documentElement.scrollHeight - 1) { scrollBy(0, innerHeight / 2); await frame(); } await frame(); }');
        $measured[$width]['scrolled'] = $page->evaluate(RENDER_SCOPE_BOARDS);

        foreach (['load', 'scrolled'] as $moment) {
            expect([$width, $moment, $measured[$width][$moment]['errors'], $measured[$width][$moment]['overflow']])->toBe([$width, $moment, [], 0]);
        }
    }

    renderScopeReport('games '.json_encode($measured));

    expect($measured[390]['load']['boards'])->toBe(48)
        ->and($measured[390]['load']['squares'])->toBe(0)
        ->and($measured[390]['scrolled']['squares'])->toBe(0)
        ->and($measured[1440]['load']['squares'])->toBeGreaterThan(0)
        ->and($measured[1440]['load']['squares'])->toBeLessThan(48 * 64)
        ->and($measured[1440]['scrolled']['squares'])->toBe(48 * 64)
        // The last board keeps its height while its squares arrive: no layout shift.
        ->and($measured[1440]['scrolled']['lastHeight'])->toBe($measured[1440]['load']['lastHeight']);

    // Positive control: a thrown error and a failing fetch reach the collector.
    $page->evaluate('() => { fetch("/this-route-does-not-exist"); setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);

    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('probe')->toContain('404');

});

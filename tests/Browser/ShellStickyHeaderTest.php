<?php

use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The sticky header
|--------------------------------------------------------------------------
|
| The header stays at the top while the page scrolls: row 1 at every width,
| row 1 and the context bar from lg. A guest's "New here?" strip scrolls
| away under it. An anchor jump and a focused control land below it
| (scroll-padding-top on the root), never under it. Measured with real
| rects after scrolling, at 375, 768 and 1440 px; the console stays clean,
| with its positive control in the last test.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    // The rules carry a Blockfill section (#blockfill) only while Blockfill is on.
    BlockfillOn::play();
});

/** The edge appears through a 150 ms transition once the header is stuck: wait for its end. */
const STICKY_EDGE_SETTLED = '() => window.scrollY < 24 || getComputedStyle(document.querySelector("[data-test=shell-edge]")).opacity === "1"';

/** The header, its rows and a probe element, after the page has settled. */
const STICKY_MEASURE = <<<'JS'
    (selector) => {
        const box = (el) => { if (!el || !el.checkVisibility({ checkVisibilityCSS: true })) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) }; };
        const header = document.querySelector('body > header');

        return {
            scrollY: Math.round(window.scrollY),
            header: box(header),
            context: box(document.querySelector('[data-test=context-bar]')),
            steps: box(document.querySelector('[data-test=first-steps]')),
            target: selector ? box(document.querySelector(selector)) : null,
            edge: getComputedStyle(document.querySelector('[data-test=shell-edge]')).opacity,
            reach: Math.min(2000, document.documentElement.scrollHeight - window.innerHeight),
            doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        };
    }
    JS;

test('the header stays at the top after a 2000 px scroll, 52 px below lg and 56 from lg, and lifts off the page with its edge', function (int $width, int $height, bool $context) {
    $problems = [];
    $page = shellPage(shellPlayer(), $width, $height);
    shellOpen($page, '/rules', $problems);

    $top = $page->evaluate(STICKY_MEASURE, null);
    $page->evaluate('() => window.scrollTo(0, 2000)');
    BrowserWait::until($page, STICKY_EDGE_SETTLED, 3_000);
    $scrolled = $page->evaluate(STICKY_MEASURE, null);
    fwrite(STDERR, "\n[sticky] @{$width}: ".json_encode(compact('top', 'scrolled')));

    // /rules is shorter than 2000 px below lg (its sections are closed accordions there): as far as it goes, at least 300 px.
    expect($scrolled['scrollY'])->toBe($top['reach'])
        ->and($scrolled['scrollY'])->toBeGreaterThan(300)
        ->and($scrolled['header']['top'])->toBe(0)
        ->and($scrolled['header']['height'])->toBe($context ? 104 : ($width >= 1024 ? 56 : 52))
        ->and($scrolled['doc'][0])->toBeLessThanOrEqual($scrolled['doc'][1])
        // Flat at the top, lifted once the page runs under it.
        ->and($top['edge'])->toBe('0')
        ->and($scrolled['edge'])->toBe('1');

    if ($context) {
        expect($scrolled['context'])->toBe(['top' => 56, 'bottom' => 104, 'height' => 48]);
    } else {
        expect($scrolled['context'])->toBeNull();
    }

    expect($problems)->toBe([]);
})->with([
    'phone 375' => [375, 667, false],
    'tablet 768' => [768, 1024, false],
    // /rules is no game page: one 56 px row (Header.dc.html); a game page adds its 48 px context bar until P4.
    'desktop 1440' => [1440, 900, false],
]);

test('an anchor jump and a focused link land below the sticky header, and a guest\'s "New here?" strip scrolls away', function (int $width, int $height) {
    $problems = [];
    $page = shellPage(null, $width, $height);
    // A real load with the fragment: ComputeUrl drops it from a path, so it goes on the computed URL.
    shellOpen($page, ComputeUrl::from('/rules').'#blockfill', $problems);
    BrowserWait::until($page, '() => window.scrollY > 0', 5_000);

    $anchor = $page->evaluate(STICKY_MEASURE, '#blockfill-h');
    fwrite(STDERR, "\n[sticky-anchor] guest @{$width}: ".json_encode($anchor));

    expect($anchor['header']['top'])->toBe(0)
        ->and($anchor['target']['top'])->toBeGreaterThanOrEqual($anchor['header']['bottom'])
        ->and($anchor['target']['top'])->toBeLessThanOrEqual($anchor['header']['bottom'] + 48)
        // The strip is not part of the sticky header: scrolled, it has left the viewport.
        ->and($anchor['steps'] === null || $anchor['steps']['bottom'] <= 0)->toBeTrue();

    // A link half under the header, then focused the way Tab focuses it: the page scrolls it out from under the header.
    $focus = $page->evaluate(<<<'JS'
        () => {
            const header = document.querySelector('body > header').getBoundingClientRect();
            // A control of the sections (a link, or a section's toggle on a phone) below the viewport; not the sticky section list, which never sits under the header.
            const link = [...document.querySelectorAll('[data-doc-section] a[href], [data-doc-section] button')].find((el) => el.checkVisibility() && el.getBoundingClientRect().top > window.innerHeight);
            window.scrollTo(0, link.getBoundingClientRect().top + window.scrollY - header.bottom + 10);
            const before = Math.round(link.getBoundingClientRect().top);
            link.focus();
            return { before, after: Math.round(link.getBoundingClientRect().top), header: Math.round(header.bottom) };
        }
        JS);
    fwrite(STDERR, "\n[sticky-focus] guest @{$width}: ".json_encode($focus));

    expect($focus['before'])->toBeLessThan($focus['header'])
        ->and($focus['after'])->toBeGreaterThanOrEqual($focus['header']);

    expect($problems)->toBe([]);
})->with([
    'phone 375' => [375, 667],
    'desktop 1440' => [1440, 900],
]);

test('positive control: the collector of this file sees a thrown error on a scrolled page', function () {
    $problems = [];
    $page = shellPage(null, 375, 667);
    shellOpen($page, '/rules', $problems);
    $page->evaluate('() => { window.scrollTo(0, 400); setTimeout(() => { throw new Error("sticky positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("sticky positive control"))', 5_000);

    expect($problems)->toBe([]);
});

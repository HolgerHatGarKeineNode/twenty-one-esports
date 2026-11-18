<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

require_once __DIR__.'/../Support/key_eras.php';

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Every game in its own halving (`season-chain-v2`), in the browser
|--------------------------------------------------------------------------
|
| A live season in which chess stands in its era 2 and the board games in
| their era 1 (the season of KeyErasPagesTest), read by an admin in German:
| "Halving je Spiel" on /mining and the season card of the start page, at
| 390 and 1440 px. Nothing is wider than the window, every halving line and
| every row of a game stays inside its card, no text is squeezed into a
| narrow column, and the console, the uncaught errors and every answer
| >= 400 stay empty, on the first load and after a Livewire round-trip, with
| a positive control on the same page.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    BlockliOn::play();
});

/**
 * The card, the rows of the games in it and its lines of text: the card's
 * box, each row's `data-key` and era, and every line whose text (a range
 * around it, not its box) runs past the card or the window.
 */
const KEY_HALVINGS_READ = <<<'JS'
([card, rows, lines]) => {
    const root = [...document.querySelectorAll(card)].find((el) => el.checkVisibility()) ?? null;
    if (! root) return { card: null, rows: [], lines: 0, outside: ['no ' + card] };
    const box = root.getBoundingClientRect();
    const width = document.documentElement.clientWidth;
    const shown = (selector) => [...root.querySelectorAll(selector)].filter((el) => el.checkVisibility());
    const outside = [];
    const texts = shown(lines);
    for (const el of texts) {
        const range = document.createRange();
        range.selectNodeContents(el);
        for (const r of [...range.getClientRects()].filter((r) => r.width > 0)) {
            if (r.left < box.left - 1 || r.right > box.right + 1 || r.left < 0 || r.right > width) {
                outside.push((el.dataset.test || el.tagName.toLowerCase()) + ' ' + Math.round(r.left) + '..' + Math.round(r.right) + ' card ' + Math.round(box.left) + '..' + Math.round(box.right) + ': ' + el.textContent.trim().slice(0, 40));
                break;
            }
        }
    }
    return {
        card: [Math.round(box.left), Math.round(box.right), Math.round(box.width), Math.round(box.height)],
        rows: shown(rows).map((el) => [el.dataset.key ?? '', el.dataset.era ?? '', el.textContent.replace(/\s+/g, ' ').trim()]),
        lines: texts.length,
        outside,
    };
}
JS;

test('in German an admin reads chess in era 2 and the board games in era 1 on /mining and on the start page; every halving line stays inside its card and the pages stay quiet', function (int $width, int $height) {
    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot', 'timezone' => 'UTC']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $season = keySeason();
    // Three chess blitz wins: 6 300 sats, past the 5 250 of era 1. One Blockli blitz win: 2 100 of 3 150.
    $chain = keyWins($season, ['chess/blitz', 'chess/blitz', 'chess/blitz', 'blockli/blitz']);

    expect($chain->mined())->toBe(8_400);

    $phone = $width < 1024;
    $pages = [
        'mining' => [route('mining', [], false), '[data-test=mining-keys]', '[data-test=key-halving]', '[data-test=key-era], [data-test=key-mined], [data-test=key-pays], [data-test=key-next-halving]'],
        // The start page: the season table from lg on, the lines under "Season" on a phone (the game of the big tag has no line of its own there).
        'home' => $phone
            ? ['/', '[data-test=home-season-phone]', '[data-test=season-phone-key]', '[data-test=season-phone-pays] span, [data-test=season-phone-key]']
            : ['/', '[data-test=home-season]', '[data-test=season-key]', '[data-test=season-key] td, [data-test=season-key-halving]'],
    ];

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $read = [];
    $controlled = false;

    foreach ($pages as $name => [$path, $card, $rows, $lines]) {
        $page->goto(ComputeUrl::from($path));
        $page->waitForLoadState('networkidle');
        BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);

        if (! $controlled) {
            // Positive control: a thrown error and a failed answer reach the collector on this very page.
            $page->evaluate('() => { setTimeout(() => { throw new Error("key halvings positive control"); }); fetch("/key-halvings-positive-control-missing"); }');
            BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("key halvings positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
            $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
            $controlled = true;
        }

        $page->evaluate('([s]) => { document.querySelector(s)?.scrollIntoView({ block: "center" }); return new Promise((r) => setTimeout(r, 300)); }', [$card]);
        [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
        $read[$name] = [
            'lang' => (string) $page->evaluate('() => document.documentElement.lang'),
            'scroll' => (int) $scroll,
            'client' => (int) $client,
            ...$page->evaluate(KEY_HALVINGS_READ, [$card, $rows, $lines]),
            'squeezed' => (array) $page->evaluate(BrowserConsole::SQUEEZED, [$card]),
            'first load' => [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)],
        ];

        // A Livewire round-trip on the page keeps it quiet too.
        $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); window.Livewire?.all().forEach((c) => c.$wire.$refresh()); }');
        $page->waitForLoadState('networkidle');
        $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
        $read[$name]['round-trip'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
        $read[$name]['after'] = $page->evaluate(KEY_HALVINGS_READ, [$card, $rows, $lines])['outside'];
        $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
    }

    fwrite(STDERR, "key halvings de {$width}x{$height}: ".json_encode($read, JSON_UNESCAPED_UNICODE).PHP_EOL);

    expect($controlled)->toBeTrue();

    foreach ($read as $name => $m) {
        expect($m['lang'])->toBe('de', $name)
            ->and($m['scroll'])->toBe($m['client'], "{$name}: the page is wider than the window")
            ->and($m['card'])->not->toBeNull("{$name}: the card is missing")
            ->and($m['card'][0])->toBeGreaterThanOrEqual(0, $name)
            ->and($m['card'][1])->toBeLessThanOrEqual($width, $name)
            ->and($m['lines'])->toBeGreaterThan(0, "{$name}: no line was measured")
            ->and($m['outside'])->toBe([], "{$name}: a line runs out of its card")
            ->and($m['after'])->toBe([], "{$name}: a line runs out of its card after a round-trip")
            ->and($m['squeezed'])->toBe([], "{$name}: squeezed text")
            ->and($m['first load'])->toBe([], "{$name}: first load")
            ->and($m['round-trip'])->toBe([], "{$name}: round-trip");
    }

    // /mining: one row per game with a share, each in its own era, with its halving line.
    $mining = array_column($read['mining']['rows'], null, 0);

    expect(array_map(fn (array $row): string => $row[1], $mining))->toBe(['chess' => '2', 'board-games' => '1'])
        ->and($mining['chess'][2])->toContain('Epoche 2 von 6')->toContain('Schach Blitz 1.050')
        ->and($mining['board-games'][2])->toContain('Epoche 1 von 6')->toContain('2.100');

    // The start page: both games are there with their own reward (the phone names the big tag's game above the lines).
    $home = array_column($read['home']['rows'], null, 0);

    if ($phone) {
        expect(array_keys($home))->toContain('board-games')
            ->and($home['board-games'][2])->toContain('2.100');
    } else {
        expect(array_keys($home))->toContain('chess')->toContain('board-games')
            ->and($home['chess'][2])->toContain('Schach Blitz')->toContain('1.050')
            ->and($home['board-games'][2])->toContain('2.100');
    }
})->with([
    'phone 390' => [390, 844],
    'desktop 1440' => [1440, 900],
]);

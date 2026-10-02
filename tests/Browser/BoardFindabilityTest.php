<?php

use App\Games\Checkers;
use App\Games\NineMensMorris;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Finding the board games (plan "Mühle und Dame", P7)
|--------------------------------------------------------------------------
|
| The user could not find Mühle and Dame on /play: they were not in the
| casual block at the top. Now they are two tiles in the casual block and
| one "Board games" group on /play; since 2026-10-03 that group, home's
| tiles and the phone's chips put them at the very end again (user: „bitte
| stelle [Mühle, Dame] überall ganz nach hinten"). Measured at 390 and 1440 px in
| English and German, for a guest and a player: order, no sideways scroll,
| console and answers clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    NineMensMorrisOn::play();
    CheckersGame::play();
});

test('the board games are a group at the end of /play, in the casual block and at the end on home', function (int $width, int $height, string $locale, bool $player) {
    $user = $player ? shellPlayer() : null;
    $page = shellPage($user, $width, $height);
    $problems = [];
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    shellOpen($page, '/play', $problems);

    $play = $page->evaluate(<<<'JS'
        () => {
            const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) }; };
            const group = document.querySelector('[data-test=play-board-games]');
            return {
                lang: document.documentElement.lang,
                order: [...document.querySelectorAll('[data-test^=play-game-]')].map((el) => el.dataset.test.replace('play-game-', '')),
                grouped: group ? [...group.querySelectorAll('[data-test^=play-game-]')].map((el) => el.dataset.test.replace('play-game-', '')) : null,
                groupHeading: group?.querySelector('h2')?.innerText.trim() ?? null,
                cardHeadings: group ? [...group.querySelectorAll('h3')].map((el) => el.innerText.trim()) : null,
                casual: [...document.querySelectorAll('[data-test^=casual-board-]')].map((el) => ({ slug: el.dataset.test.replace('casual-board-', ''), href: new URL(el.href).pathname, text: el.innerText.trim(), box: box(el), cut: [...el.querySelectorAll('b span')].some((s) => s.checkVisibility() && s.scrollWidth > s.clientWidth + 1) })),
                casualBlock: box(document.querySelector('[data-test=casual-play]')),
                groupBox: box(group),
                chessBox: box(document.querySelector('[data-test=play-game-chess]')),
                scroll: document.documentElement.scrollWidth,
                client: document.documentElement.clientWidth,
                viewport: innerHeight,
            };
        }
        JS);
    shellShot($page, "play-boards-{$locale}-{$width}".($player ? '-player' : ''));
    if (getenv('SHELL_SHOTS')) {
        $page->evaluate('() => document.querySelector("[data-test=play-board-games]").scrollIntoView()');
        shellShot($page, "play-boards-group-{$locale}-{$width}".($player ? '-player' : ''));
    }

    shellOpen($page, '/', $problems);
    $home = $page->evaluate('() => ({ tiles: [...document.querySelectorAll("[data-test=play-tile]")].map((el) => el.dataset.game), scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth })');

    // The header: the phone's game chips put the board games at the end.
    $chips = $page->evaluate(<<<'JS'
        () => {
            const row = document.getElementById('game-chips');
            if (!row || !row.checkVisibility()) return null;
            const r = row.getBoundingClientRect();
            return [...row.querySelectorAll('a.gchip')].map((chip) => ({ test: chip.dataset.test ?? 'chess', visible: chip.getBoundingClientRect().right <= r.right + 1 }));
        }
        JS);
    // Row 1 on the desktop: the room left beside the tabs (a board games tab would need about 160 px).
    $spare = $page->evaluate('() => { const g = document.querySelector("[data-test=game-tabs] > span.grow"); return g && g.checkVisibility() ? Math.round(g.getBoundingClientRect().width) : null; }');
    $shell = $page->evaluate(SHELL_MEASURE);
    fwrite(STDERR, "\n[board findability] {$locale} {$width}".($player ? ' player' : ' guest').': '.json_encode(compact('play', 'home', 'chips', 'spare')));

    $boards = [NineMensMorris::SLUG, Checkers::SLUG];

    expect($play['lang'])->toBe($locale)
        // At the end, as one group with its own heading; the cards' names one level below it.
        ->and(array_slice($play['order'], -2))->toBe($boards)
        ->and($play['grouped'])->toBe($boards)
        ->and($play['groupHeading'])->toBe($locale === 'de' ? 'Brettspiele' : 'Board games')
        ->and($play['cardHeadings'])->toBe($locale === 'de' ? ['Mühle', 'Dame'] : ["Nine Men's Morris", 'Checkers'])
        ->and($play['groupBox']['top'])->toBeGreaterThan($play['chessBox']['bottom'])
        // Two tiles in the casual block at the top, to the lobbies, labels whole, inside the block.
        ->and(array_column($play['casual'], 'slug'))->toBe($boards)
        ->and(array_column($play['casual'], 'href'))->toBe(['/games/'.NineMensMorris::SLUG, '/games/'.Checkers::SLUG])
        ->and(array_column($play['casual'], 'cut'))->toBe([false, false])
        ->and($play['casual'][1]['box']['right'])->toBeLessThanOrEqual($play['casualBlock']['right'])
        ->and($play['casual'][0]['box']['height'])->toBeGreaterThanOrEqual(44)
        ->and($play['scroll'])->toBeLessThanOrEqual($play['client'])
        // Home: the board games' tiles last.
        ->and(array_slice($home['tiles'], -2))->toBe($boards)
        ->and($home['scroll'])->toBeLessThanOrEqual($home['client'])
        ->and($shell['squeezed'])->toBe([])
        ->and($problems)->toBe([]);

    if ($width < 1024) {
        $order = array_column($chips, 'test');

        // The strip scrolls (at 390 px it shows one chip whole); the board games are the last chips.
        expect(array_slice($order, -2))->toBe(['mobile-'.NineMensMorris::SLUG, 'mobile-'.Checkers::SLUG]);
    }

    if ($width >= 1024) {
        // On the desktop the casual block's board tiles are in the first viewport.
        expect($play['casual'][0]['box']['bottom'])->toBeLessThanOrEqual($play['viewport']);
    }

    // Positive control: the collector sees a throw, a failed fetch and a broken image on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("board findability positive control"); }); fetch("/board/0"); document.body.append(Object.assign(document.createElement("img"), { src: "/board-findability-control.png" })); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("board findability positive control")) && window.__errors.some((e) => e.startsWith("404 ")) && window.__errors.some((e) => e.includes("board-findability-control.png"))', 5_000);
})->with([
    'phone 390, en, guest' => [390, 844, 'en', false],
    'desktop 1440, en, guest' => [1440, 900, 'en', false],
    'phone 390, de, player' => [390, 844, 'de', true],
    'desktop 1440, de, player' => [1440, 900, 'de', true],
]);

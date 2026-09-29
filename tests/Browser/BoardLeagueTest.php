<?php

use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\CasualCups;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

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
        ->and($first->evaluate('() => document.querySelector("[data-test=find-opponent-button]").textContent.trim()'))->toBe($find);
    $lobbyBeforeSearch = boardLeagueMeasure($first);
    shellShot($first, "board-lobby-{$locale}-{$width}");

    // As in the chess lobby, the Blitz tile opens the panel with "Find opponent" (P5 of plan mempool-streifen).
    $first->locator('[data-test=play-blitz]')->click();
    $first->locator('[data-test=find-opponent-button]')->click();
    BrowserWait::until($first, '() => document.querySelector("[data-test=lobby-searching]") !== null', 10_000);

    $second = boardLeaguePage($bert, $lobby, $width, $height, $locale);
    $second->locator('[data-test=play-blitz]')->click();
    $second->locator('[data-test=find-opponent-button]')->click();

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

/*
| P5 of plan mempool-streifen (user, 2026-09-29): a board game's lobby has
| the chess lobby's arrangement, shows who is online, and its casual cups
| carry a head that says they are tournaments. Measured on the chess lobby
| and the nine men's morris lobby side by side: the sections in the same
| order, nothing wider than the window, no text box of the new parts cut,
| no word of a tile broken mid-word.
|
| Online now (review 2026-09-30: next to the tag and Invite a name shrank
| to 0 px on a 375 px phone): Cleo looks for chess and Dora for nine men's
| morris from the moment they join; Bert, with a 39-character name and no
| break in it, switches "Looking to play" on while Anna watches. On both
| lobbies every row keeps its picture, its name (a short one whole, a long
| one at least 48 px) and its action inside the list. Anna invites Bert: a
| Livewire round trip for her, a push for him, and "Waiting for <Bert>"
| wraps instead of widening the page. The collector stays empty, with a
| positive control.
*/

/** The lobby sections present, in reading order (top, then left), with their top and left (document px). */
const BOARD_PARITY_SECTIONS = <<<'JS'
    () => Object.entries({
        title: '[data-test=lobby-title]', play: '[data-test=play]', next: '[data-test=next-tournament], [data-test=next-tournament-empty]',
        cups: '[data-test=cup-mentions]', games: '#your-games', live: 'section[aria-labelledby=live-h]', online: '[data-test=online-now]',
        ladder: '[data-test=lobby-ladder]', follows: '[data-test=follows-here]', chat: '[data-test=game-chat]',
    }).map(([key, selector]) => [key, document.querySelector(selector)]).filter(([, el]) => el && el.checkVisibility())
        .map(([key, el]) => [key, Math.round(el.getBoundingClientRect().top + scrollY), Math.round(el.getBoundingClientRect().left)])
        .sort((a, b) => a[1] - b[1] || a[2] - b[2])
    JS;

/** Every visible leaf text box of the new parts whose text is wider than its box. */
const BOARD_PARITY_CUT = <<<'JS'
    () => [...document.querySelectorAll('[data-test=cup-head] *, [data-test=online-now] *, [data-test=play-grid] b, [data-test=play-grid] span, [data-test=lobby-title] *')]
        // An sr-only box (1 px wide on purpose) is text for screen readers; the rows of Online now have their own check below.
        .filter((el) => el.checkVisibility() && el.clientWidth > 1 && ! el.closest('[data-test=online-player]') && el.children.length === 0 && el.textContent.trim() !== '' && el.scrollWidth > el.clientWidth + 1)
        .map((el) => el.textContent.trim().slice(0, 40) + ' ' + el.scrollWidth + '>' + el.clientWidth)
    JS;

/** Every word of a tile's label and line that the browser split over two lines (a soft hyphen counts as a word break). */
const BOARD_PARITY_SPLIT = <<<'JS'
    () => [...document.querySelectorAll('[data-test=play-grid] b, [data-test=play-grid] span')].filter((el) => el.checkVisibility() && el.clientWidth > 1)
        .flatMap((el) => [...el.childNodes].filter((n) => n.nodeType === 3).flatMap((node) => {
            const found = [];
            for (const match of node.textContent.matchAll(/[^\s­]+/g)) {
                // Character by character: the line each letter sits on.
                const tops = new Set([...match[0]].map((_, i) => {
                    const range = document.createRange();
                    range.setStart(node, match.index + i);
                    range.setEnd(node, match.index + i + 1);
                    // A letter right after a soft hyphen also reports a caret box at the end of the line before: take its glyph's box.
                    const boxes = [...range.getClientRects()].filter((r) => r.width > 0);
                    return Math.round((boxes.at(-1) ?? range.getBoundingClientRect()).top);
                }));
                if (tops.size > 1) found.push(match[0]);
            }
            return found;
        }))
    JS;

/** Each visible row of Online now: picture, name box, whether the name shows whole, and whether its action stays inside the list. */
const BOARD_PARITY_ROWS = <<<'JS'
    () => [...document.querySelectorAll('[data-test=online-player]')].filter((row) => row.checkVisibility()).map((row) => {
        const list = row.closest('ul');
        const box = list.getBoundingClientRect();
        const name = row.querySelector('[data-test=online-name]');
        const action = row.querySelector('[data-test=invite], [data-test=invited]')?.getBoundingClientRect();
        return {
            name: name.textContent, width: Math.round(name.getBoundingClientRect().width), whole: name.scrollWidth <= name.clientWidth + 1,
            picture: Math.round(row.querySelector('img').getBoundingClientRect().width),
            inside: ! action || (action.left >= box.left - 0.5 && action.right <= box.right + 0.5),
            listScroll: list.scrollWidth - list.clientWidth,
        };
    })
    JS;

test('the board lobby has the chess lobby\'s arrangement, shows who is online and invites them; the cup head reads as tournaments', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', NineMensMorris::SLUG]]);
    app(CasualCups::class)->tick();
    $long = 'Satoshinakamotohalfinneyadambackszabonick';
    $anna = User::factory()->create(['name' => 'Anna']);
    $bert = User::factory()->create(['name' => $long]);
    $cleo = User::factory()->create(['name' => 'Cleo', 'looking_to_play' => 'chess/blitz']);
    $dora = User::factory()->create(['name' => 'Dora', 'looking_to_play' => NineMensMorris::SLUG.'/blitz']);
    app(BoardGameService::class)->start(NineMensMorris::SLUG, User::factory()->create(['name' => 'Wei']), User::factory()->create(['name' => 'Len']));
    $board = route('board.lobby', NineMensMorris::SLUG, false);

    // Cleo and Dora are online (the channel carries what they look for as they join).
    $others = [boardLeaguePage($cleo, route('chess.lobby', [], false), $width, $height, $locale), boardLeaguePage($dora, $board, $width, $height, $locale)];
    foreach ($others as $other) {
        BrowserWait::until($other, '() => window.esportsPresence?.ready === true', 10_000);
    }

    $measured = [];
    $pages = [];
    foreach (['chess' => route('chess.lobby', [], false), 'board' => $board] as $which => $path) {
        $pages[$which] = $page = boardLeaguePage($anna, $path, $width, $height, $locale);
        BrowserWait::until($page, '() => window.Alpine && document.fonts.status === "loaded" && document.querySelector("[data-test=cup-head]") !== null && document.querySelectorAll("[data-test=online-player]").length >= 2', 10_000);
        $measured[$which] = ['sections' => $page->evaluate(BOARD_PARITY_SECTIONS), 'cut' => $page->evaluate(BOARD_PARITY_CUT), 'split' => $page->evaluate(BOARD_PARITY_SPLIT)] + boardLeagueMeasure($page);
        if ($locale === 'en') {
            shellShot($page, "parity-{$which}-{$width}");
        }
    }
    $page = $pages['board'];

    // Bert opens the lobby and switches "Looking to play" on; Anna's list has him with the tag and an Invite.
    // Both are on the channel and see each other before the switch: a member's data is fixed when it joins, only
    // the push that follows updates it (the pages' own joins and leaves while they load must be over by then).
    BrowserWait::until($page, '() => window.esportsPresence?.ready === true && Alpine.$data(document.querySelector("[data-test=board-lobby]")).connection === "connected"', 10_000);
    $bertPage = boardLeaguePage($bert, $board, $width, $height, $locale);
    BrowserWait::until($bertPage, '() => window.esportsPresence?.members.some((m) => m.name === "Anna")', 10_000);
    BrowserWait::until($page, '() => window.esportsPresence.members.some((m) => m.name === '.json_encode($long).')', 10_000);
    $bertPage->evaluate('() => document.querySelector("[data-test=looking-toggle]").click()');
    BrowserWait::until($bertPage, '() => Alpine.$data(document.querySelector("[data-test=board-lobby]")).savedLooking === true', 10_000);
    $bertRow = '[...document.querySelectorAll("[data-test=online-player]")].find((row) => row.innerText.includes('.json_encode($long).'))';
    BrowserWait::until($page, '() => { const row = '.$bertRow.'; return row && row.querySelector("[data-test=invite]") && row.querySelector("[data-test=online-looking]").checkVisibility(); }', 10_000);
    $online = $page->evaluate('() => ({ count: document.querySelector("[data-test=online-count]").innerText, tile: document.querySelector("[data-test=play-online-count]").innerText, tag: '.$bertRow.'.querySelector("[data-test=online-looking]").textContent.trim() })');
    $rows = ['board' => $page->evaluate(BOARD_PARITY_ROWS), 'chess' => $pages['chess']->evaluate(BOARD_PARITY_ROWS)];
    $measured['online'] = ['cut' => $page->evaluate(BOARD_PARITY_CUT)] + boardLeagueMeasure($page);
    if ($locale === 'en') {
        $page->evaluate('() => document.getElementById("online-now").scrollIntoView({ block: "center" })');
        shellShot($page, "parity-board-online-{$width}");
        $pages['chess']->evaluate('() => document.getElementById("online-now").scrollIntoView({ block: "center" })');
        shellShot($pages['chess'], "parity-chess-online-{$width}");
    }

    // The invite: a Livewire round trip for Anna, a push for Bert. His whole name wraps in "Waiting for …".
    $page->evaluate('() => '.$bertRow.'.querySelector("[data-test=invite]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=lobby-invited]") !== null', 10_000);
    BrowserWait::until($bertPage, '() => document.querySelector("[data-test=incoming-invite]") !== null', 10_000);
    $waiting = $page->evaluate('() => { const r = document.querySelector("[data-test=waiting-name]").getBoundingClientRect(); return { right: Math.round(r.right), text: document.querySelector("[data-test=waiting-name]").textContent }; }');
    $rows['invited'] = $page->evaluate(BOARD_PARITY_ROWS);
    $measured['invited'] = boardLeagueMeasure($page);
    $measured['bert'] = boardLeagueMeasure($bertPage);
    if ($locale === 'en') {
        $page->evaluate('() => document.querySelector("[data-test=lobby-invited]").scrollIntoView({ block: "center" })');
        shellShot($page, "parity-board-waiting-{$width}");
    }

    fwrite(STDERR, "board parity {$locale} {$width}x{$height}: ".json_encode(compact('measured', 'online', 'rows', 'waiting')).PHP_EOL);

    $order = fn (array $rows): array => array_column($rows, 0);
    // Below lg one column; from lg Your games | Live now | ladder share a row, "Online now" sits in Live now under the boards.
    $expected = $width >= 1024 ? ['title', 'play', 'next', 'cups', 'games', 'live', 'ladder', 'online'] : ['title', 'play', 'next', 'cups', 'games', 'live', 'online', 'ladder'];
    // From lg the title is the header's context bar: the lobby's own title row is hidden on both.
    $expected = $width >= 1024 ? array_values(array_diff($expected, ['title'])) : $expected;

    expect($order($measured['board']['sections']))->toBe($expected)
        ->and(array_values(array_diff($order($measured['chess']['sections']), ['follows', 'chat'])))->toBe($expected)
        ->and($online['tag'])->toBe($locale === 'de' ? 'sucht: Mühle' : "looking: Nine Men's Morris")
        ->and($online['tile'])->toBe($online['count'])
        ->and($waiting['text'])->toContain($long)
        ->and($waiting['right'])->toBeLessThanOrEqual($width);

    foreach ($rows as $where => $list) {
        expect(array_diff(['Cleo', 'Dora', $long], array_column($list, 'name')))->toBe([], $where);

        foreach ($list as $row) {
            $label = "{$where}: {$row['name']}";
            expect($row['picture'])->toBe(24, $label)
                ->and($row['inside'])->toBeTrue($label)
                ->and($row['listScroll'])->toBe(0, $label)
                ->and($row['name'] === $long ? $row['width'] >= 48 : $row['whole'])->toBeTrue($label.' '.json_encode($row));
        }
    }

    foreach ($measured as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['cut'] ?? [])->toBe([], $where)
            ->and($m['split'] ?? [])->toBe([], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("board parity positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("board parity positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 320, en' => [320, 700, 'en'],
    'phone 320, de' => [320, 700, 'de'],
    'phone 375, en' => [375, 667, 'en'],
    'phone 375, de' => [375, 667, 'de'],
    'phone 390, en' => [390, 844, 'en'],
    'phone 390, de' => [390, 844, 'de'],
    'desktop 1280, en' => [1280, 800, 'en'],
    'desktop 1280, de' => [1280, 800, 'de'],
]);

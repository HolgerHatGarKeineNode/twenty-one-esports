<?php

use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChatMute;
use App\Models\Rating;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\GameChat\GameChannels;
use App\Support\Tournaments\CasualCups;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

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

test('a board game lobby offers correspondence and no blitz; a correspondence game\'s board and the ladder measured clean', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = route('board.lobby', NineMensMorris::SLUG, false);
    $title = $locale === 'de' ? 'Mühle' : "Nine Men's Morris";

    // Correspondence only since 2026-10-07: no Blitz tile, no "Find opponent"; Correspondence is the orange tile.
    $first = boardLeaguePage($anna, $lobby, $width, $height, $locale);
    expect($first->evaluate('() => document.querySelector("[data-test=board-lobby] h1").innerText'))->toBe($title)
        ->and($first->evaluate('() => [!! document.querySelector("[data-test=play-blitz]"), !! document.querySelector("[data-test=find-opponent-button]"), !! document.querySelector("[data-test=play-correspondence]")]'))->toBe([false, false, true]);
    $lobbyBeforeSearch = boardLeagueMeasure($first);
    shellShot($first, "board-lobby-{$locale}-{$width}");

    $game = app(BoardGameService::class)->start(NineMensMorris::SLUG, $anna, $bert);
    expect($game->mode)->toBe('correspondence');
    $first->goto(ComputeUrl::from(route('board.show', $game, false)));
    $second = boardLeaguePage($bert, route('board.show', $game, false), $width, $height, $locale);

    foreach ([$second, $first] as $page) {
        BrowserWait::until($page, '() => location.pathname.startsWith("/board/") && document.querySelector("[data-test=board-game]") !== null', 15_000);
    }

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
    $second->goto(ComputeUrl::from(route('ladder.show', [Checkers::SLUG, 'correspondence'], false)));
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
    BrowserWait::until($guest, '() => document.querySelector("[data-test=play-challenge]")?.getAttribute("href")?.includes("/login") === true', 10_000);
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
| "Your follows here" sits in chess's slot on both (plan
| brettspiel-chat-und-follows, P2; its rows are measured in
| BoardFollowsTest); the game chat is chess's own until P1 of that plan.
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

/**
 * The expected section order with the game chat in its place (2026-10-03, user: "weiter oben"): below xl right
 * after "play"; from xl (1280 px) the chat is the side column, so it leaves the reading order and must stand
 * right of every other section instead.
 *
 * @param  list<string>  $expected
 * @return array{0: list<string>, 1: Closure(list<array{0: string, 1: int, 2: int}>): list<string>}
 */
function boardLeagueChatOrder(array $expected, int $width, Closure $order): array
{
    if ($width < 1280) {
        $at = (int) array_search('play', $expected, true) + 1;

        return [[...array_slice($expected, 0, $at), 'chat', ...array_slice($expected, $at)], $order];
    }

    // From xl to 2xl the lobby row is 5 | 7 beside the chat column, with the ladder under both: after "online".
    if ($width < 1536 && in_array('ladder', $expected, true) && in_array('online', $expected, true)) {
        $expected = array_values(array_diff($expected, ['ladder']));
        array_splice($expected, (int) array_search('online', $expected, true) + 1, 0, ['ladder']);
    }

    return [$expected, function (array $rows) use ($order): array {
        $chat = collect($rows)->firstWhere(0, 'chat');
        $others = array_values(array_filter($rows, fn (array $row): bool => $row[0] !== 'chat'));

        expect($chat)->not->toBeNull('the chat is on the page')
            ->and($chat[2] ?? 0)->toBeGreaterThan(max(array_column($others, 2)), 'the chat stands right of every section, in its own column');

        return $order($others);
    }];
}

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

test('the board lobby has the chess lobby\'s arrangement, shows who is online and invites them into a correspondence game; chess\'s cup head reads as tournaments', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', NineMensMorris::SLUG]]);
    app(CasualCups::class)->tick();
    $long = 'Satoshinakamotohalfinneyadambackszabonick';
    $anna = User::factory()->create(['name' => 'Anna']);
    $bert = User::factory()->create(['name' => $long]);
    $cleo = User::factory()->create(['name' => 'Cleo', 'looking_to_play' => 'chess/blitz']);
    $dora = User::factory()->create(['name' => 'Dora', 'looking_to_play' => NineMensMorris::SLUG.'/correspondence']);
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
        // The board games run no casual cup since 2026-10-07: only chess's lobby has the cup head.
        BrowserWait::until($page, '() => window.Alpine && document.fonts.status === "loaded" && ('.($which === 'board' ? 'true' : 'document.querySelector("[data-test=cup-head]") !== null').') && document.querySelectorAll("[data-test=online-player]").length >= 2', 10_000);
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

    // Bert accepts: he lands on the board of a correspondence game, one move a day (no blitz since 2026-10-07). A
    // correspondence game pulls nobody off the page (BoardGameService::start): Anna's lobby drops its waiting card by push
    // and lists the game under "Your games".
    $bertPage->evaluate('() => document.querySelector("[data-test=accept-invite]").click()');
    BrowserWait::until($bertPage, '() => location.pathname.startsWith("/board/") && document.querySelector("[data-test=board-game]") !== null', 15_000);
    BrowserWait::until($page, '() => document.querySelector("[data-test=lobby-invited]") === null && document.querySelector("[data-test=lobby-correspondence-game]") !== null', 15_000);
    $accepted = BoardGame::query()->where('game', NineMensMorris::SLUG)->whereIn('white_id', [$anna->id, $bert->id])->whereIn('black_id', [$anna->id, $bert->id])->sole();
    expect($accepted->mode)->toBe('correspondence');

    fwrite(STDERR, "board parity {$locale} {$width}x{$height}: ".json_encode(compact('measured', 'online', 'rows', 'waiting')).PHP_EOL);

    $order = fn (array $rows): array => array_column($rows, 0);
    // Below lg one column; from lg Your games | Live now | ladder share a row, "Online now" sits in Live now under the boards.
    // Then "Your follows here" (P2) and the game chat (P1 of plan brettspiel-chat-und-follows), as in chess.
    $expected = $width >= 1024 ? ['title', 'play', 'next', 'cups', 'games', 'live', 'ladder', 'online'] : ['title', 'play', 'next', 'cups', 'games', 'live', 'online', 'ladder'];
    // From lg the title is the header's context bar: the lobby's own title row is hidden on both.
    $expected = $width >= 1024 ? array_values(array_diff($expected, ['title'])) : $expected;
    $expected[] = 'follows';
    // The game chat (2026-10-03, "weiter oben"): right under the way to play below xl; from xl the side column beside all.
    [$expected, $order] = boardLeagueChatOrder($expected, $width, $order);

    expect($order($measured['board']['sections']))->toBe(array_values(array_diff($expected, ['cups'])))
        ->and($order($measured['chess']['sections']))->toBe($expected)
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

/*
| P1 of plan brettspiel-chat-und-follows (user, 2026-09-30: "ich hätte genau
| die selbe Anordnung erwartet"): each board game has its own game chat, in
| chess's slot (since 2026-10-03 right under the way to play, a bar opened in
| place below xl, the side column from xl). Filled over a
| real websocket to the in-memory relay (never a real relay) with 40
| messages by league players whose names are 41 and 42 characters without a
| break, by two keys the league does not know, a 280-character word, a long
| link, and a poll with a long answer: nothing wider than the window, no
| name squeezed below 48 px, no message wider than the list. A player mutes
| an outsider (a Livewire round trip); a guest's page looks up the authors
| (one too). Measured at 320, 375 and 1280 px, English and German, guest and
| logged in; the collector stays empty, with a positive control.
*/

/** The chat as measured: widths, the narrowest name, and every row that is squeezed or wider than its list. */
const BOARD_CHAT_MEASURE = <<<'JS'
    () => {
        const chat = document.querySelector('[data-test=game-chat]');
        const box = chat.getBoundingClientRect();
        const list = chat.querySelector('[data-test=game-chat-list]');
        const rows = [...chat.querySelectorAll('[data-test=game-chat-message]')].filter((row) => row.checkVisibility());
        const names = rows.map((row) => row.querySelector('button')).filter((name) => name.checkVisibility());
        const cut = names.filter((name) => name.scrollWidth > name.clientWidth + 1);
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            inside: box.left >= -0.5 && box.right <= innerWidth + 0.5,
            listScroll: list.scrollWidth - list.clientWidth,
            messages: rows.length,
            // A name shows whole, or, cut, keeps at least 48 px; the narrowest cut one, and the names cut below that.
            names: names.length,
            narrowestCut: cut.length ? Math.round(Math.min(...cut.map((name) => name.getBoundingClientRect().width))) : null,
            nameSqueezed: cut.filter((name) => name.getBoundingClientRect().width < 48).map((name) => name.textContent.slice(0, 20)),
            outsideTag: Math.round(Math.min(...[...chat.querySelectorAll('[data-test=game-chat-outside]')].filter((tag) => tag.checkVisibility()).map((tag) => tag.getBoundingClientRect().width))),
            squeezed: rows.filter((row) => row.querySelector('[data-test=game-chat-text]').getBoundingClientRect().width < 1).length,
            wider: rows.filter((row) => row.getBoundingClientRect().right > list.getBoundingClientRect().right + 0.5 || row.querySelector('[data-test=game-chat-text]').scrollWidth > row.querySelector('[data-test=game-chat-text]').clientWidth + 1).length,
            polls: [...chat.querySelectorAll('[data-test=game-chat-poll]')].filter((poll) => poll.checkVisibility()).map((poll) => Math.round(poll.getBoundingClientRect().right - box.right)),
            // The room between an answer's text and its box, top or bottom, at its tightest: a long answer wraps inside the box.
            optionRoom: Math.round(Math.min(...[...chat.querySelectorAll('[data-test=game-chat-poll-option]')].filter((option) => option.checkVisibility()).map((option) => {
                const outer = option.getBoundingClientRect();
                const label = option.querySelector('span.grow').getBoundingClientRect();
                return Math.min(label.top - outer.top, outer.bottom - label.bottom);
            }))),
            heading: chat.querySelector('#game-chat-h').innerText,
            guest: chat.querySelector('[data-test=game-chat-guest]')?.innerText ?? null,
            form: chat.querySelector('[data-test=game-chat-form]') !== null,
            livewire: performance.getEntriesByType('resource').filter((e) => e.initiatorType === 'fetch' && e.name.includes('/livewire')).length,
        };
    }
    JS;

test('each board game chat sits in chess\'s slot and holds long names, long words and a crowd at every width', function (int $width, int $height, string $locale, bool $signedIn) {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', NineMensMorris::SLUG]]);
    app(CasualCups::class)->tick();
    $creator = new TestSigner;
    config(['esports.game_chat.creator' => $creator->pubkey]);
    $channel = (string) GameChannels::channelId(NineMensMorris::SLUG);
    $root = ['e', $channel, '', 'root'];
    $now = now()->getTimestamp();

    // League players with names that do not break, and two keys the league does not know.
    $keys = [new TestSigner, new TestSigner, new TestSigner];
    $names = ['Satoshinakamotohalfinneyadambackszabonick', 'Donaudampfschifffahrtsgesellschaftskapitän', 'Anna'];
    foreach ($keys as $i => $key) {
        $user = User::factory()->create(['name' => $names[$i], 'pubkey' => $key->pubkey]);
        Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => NineMensMorris::SLUG, 'mode' => 'correspondence', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
    }
    $outsiders = [new TestSigner, new TestSigner];
    $texts = [str_repeat('Mühle', 56), 'https://esports.einundzwanzig.space/games/nine-mens-morris/correspondence?from='.str_repeat('x', 180), 'gg, rematch at 21:00?', 'Who plays the flying phase better, with three men left?'];
    $events = [];
    for ($i = 0; $i < 40; $i++) {
        $author = $i % 5 < 3 ? $keys[$i % 3] : $outsiders[$i % 2];
        $events[] = $author->sign(42, [$root], $texts[$i % 4].' #'.$i, $now - 600 + $i * 10);
    }
    $events[] = $keys[0]->sign(1068, [$root, ['option', 'a', str_repeat('Zwickmühle', 6)], ['option', 'b', 'Mill'], ['polltype', 'singlechoice'], ['endsAt', (string) ($now + 3600)]], 'Best opening '.str_repeat('square', 12).'?', $now - 5);

    $seed = (string) tempnam(sys_get_temp_dir(), 'board-chat-seed');
    file_put_contents($seed, json_encode($events));
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port], 'esports.profile_relays' => []]);
        $viewer = $signedIn ? User::factory()->create(['name' => 'Viewer']) : null;
        $live = '() => window.Alpine && document.fonts.status === "loaded" && Alpine.$data(document.querySelector("[data-test=game-chat]"))?.status === "live"';

        $chess = boardLeaguePage($viewer, route('chess.lobby', [], false), $width, $height, $locale);
        BrowserWait::until($chess, $live, 10_000);
        $page = boardLeaguePage($viewer, route('board.lobby', NineMensMorris::SLUG, false), $width, $height, $locale);
        BrowserWait::until($page, $live, 10_000);
        // Below xl the chat is one bar under the way to play (2026-10-03): opened, as a reader does; from xl it is open.
        $page->evaluate('() => { const toggle = document.querySelector("[data-test=game-chat-toggle]"); if (toggle.checkVisibility()) toggle.click(); }');
        // All 40 in, each author looked up: the 16 messages of the two unknown keys carry "not in the league" once the answer is in.
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=game-chat-message]").length === 40 && [...document.querySelectorAll("[data-test=game-chat-outside]")].filter((tag) => tag.checkVisibility()).length === 16', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=game-chat]").scrollIntoView({ block: "start" })');

        $sections = ['chess' => $chess->evaluate(BOARD_PARITY_SECTIONS), 'board' => $page->evaluate(BOARD_PARITY_SECTIONS)];
        $filled = $page->evaluate(BOARD_CHAT_MEASURE);
        if ($locale === 'en') {
            shellShot($page, 'board-chat-'.($signedIn ? 'player' : 'guest').'-'.$width);
        }

        // The Livewire round trip: a player mutes an outsider from the name menu; a guest's page has already asked for the authors.
        if ($signedIn) {
            $calls = (int) $page->evaluate('() => performance.getEntriesByType("resource").filter((e) => e.initiatorType === "fetch" && e.name.includes("/livewire") && e.responseStatus === 200).length');
            $page->evaluate('() => [...document.querySelectorAll("[data-test=game-chat-message]")].find((row) => row.querySelector("[data-test=game-chat-outside]")?.checkVisibility()).querySelector("button").click()');
            BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=game-chat-mute]")].some((button) => button.checkVisibility())', 5_000);
            $page->evaluate('() => [...document.querySelectorAll("[data-test=game-chat-mute]")].find((button) => button.checkVisibility()).click()');
            BrowserWait::until($page, '() => document.querySelector("[data-test=game-chat-muted]") !== null', 10_000);
            // The mute is shown at once and saved by one more Livewire call.
            BrowserWait::until($page, '() => performance.getEntriesByType("resource").filter((e) => e.initiatorType === "fetch" && e.name.includes("/livewire") && e.responseStatus === 200).length > '.$calls, 10_000);
            expect(ChatMute::query()->where('user_id', $viewer->id)->count())->toBe(1);
        }
        $after = $page->evaluate(BOARD_CHAT_MEASURE);
        $collected = ['chess' => boardLeagueMeasure($chess), 'board' => boardLeagueMeasure($page)];

        fwrite(STDERR, 'board chat '.$locale.' '.$width.' '.($signedIn ? 'player' : 'guest').': '.json_encode(compact('sections', 'filled', 'after', 'collected')).PHP_EOL);

        [$expected, $order] = boardLeagueChatOrder([...($width >= 1024 ? ['play', 'next', 'cups', 'games', 'live', 'ladder', 'online'] : ['title', 'play', 'next', 'cups', 'games', 'live', 'online', 'ladder']), ...($signedIn ? ['follows'] : [])], $width, fn (array $rows): array => array_column($rows, 0));
        // No casual cup for a board game since 2026-10-07.
        expect($order($sections['board']))->toBe(array_values(array_diff($expected, ['cups'])))
            ->and($order($sections['chess']))->toBe($expected)
            ->and($filled['heading'])->toBe($locale === 'de' ? 'Mühle-Chat' : "Nine Men's Morris chat")
            ->and($signedIn ? $filled['guest'] : str_replace("\n", ' ', (string) $filled['guest']))->toBe($signedIn ? null : ($locale === 'de' ? 'Zum Chatten anmelden Mitlesen kann jeder.' : 'Log in to chat Reading is open to everyone.'))
            ->and($filled['form'])->toBe($signedIn)
            ->and($filled['messages'])->toBe(40)
            ->and($filled['polls'])->not->toBe([])
            ->and($after['livewire'])->toBeGreaterThanOrEqual($signedIn ? 2 : 1);

        foreach (['filled' => $filled, 'after' => $after] as $where => $m) {
            expect($m['overflow'])->toBe(0, $where)
                ->and($m['inside'])->toBeTrue($where)
                ->and($m['listScroll'])->toBe(0, $where)
                ->and($m['names'])->toBeGreaterThan(0, $where)
                ->and($m['nameSqueezed'])->toBe([], $where)
                ->and($m['outsideTag'])->toBeGreaterThanOrEqual(48, $where)
                ->and($m['squeezed'])->toBe(0, $where)
                ->and($m['wider'])->toBe(0, $where)
                ->and(max($m['polls'] ?: [0]))->toBeLessThanOrEqual(0, $where)
                ->and($m['optionRoom'])->toBeGreaterThanOrEqual(6, $where);
        }

        foreach ($collected as $where => $m) {
            expect($m['lang'])->toBe($locale, $where)
                ->and($m['errors'])->toBe([], $where)
                ->and($m['bad'])->toBe([], $where);
        }

        // Positive control: the collector sees a throw and a failed answer on this very page.
        $page->evaluate('() => { setTimeout(() => { throw new Error("board chat positive control"); }); fetch("/board/0"); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("board chat positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
    } finally {
        $relay->stop(1);
        @unlink($seed);
    }
})->with([
    'phone 320, en, guest' => [320, 700, 'en', false],
    'phone 320, de, player' => [320, 700, 'de', true],
    'phone 320, en, player' => [320, 700, 'en', true],
    'phone 320, de, guest' => [320, 700, 'de', false],
    'phone 375, en, guest' => [375, 667, 'en', false],
    'phone 375, de, player' => [375, 667, 'de', true],
    'phone 375, en, player' => [375, 667, 'en', true],
    'phone 375, de, guest' => [375, 667, 'de', false],
    'desktop 1280, en, guest' => [1280, 800, 'en', false],
    'desktop 1280, de, player' => [1280, 800, 'de', true],
    'desktop 1280, en, player' => [1280, 800, 'en', true],
    'desktop 1280, de, guest' => [1280, 800, 'de', false],
]);

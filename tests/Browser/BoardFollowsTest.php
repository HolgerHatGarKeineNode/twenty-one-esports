<?php

use App\Games\NineMensMorris;
use App\Models\BoardChallenge;
use App\Models\BoardInvite;
use App\Models\Clan;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Nostr\PlayerProfile;
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
use WebSocket\Client;
use WebSocket\Message\Text;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Your follows here" in a board game's lobby (plan brettspiel-chat-und-follows, P2)
|--------------------------------------------------------------------------
|
| Anna follows 25 league players and two strangers (her kind 3 on a local
| relay). Bert, first in her list, has a 41-character name without a break
| and looks for nine men's morris; he is really online. The other 24 are put
| on the page's presence (window.esportsPresence, what the channel would
| carry), every third looking for nine men's morris, so every row state is
| filled at once. Measured on the chess lobby and the nine men's morris lobby
| side by side at 320, 375 and 1280 px, English and German: the sections in
| the same order, nothing wider than the window, every name keeps its room
| (a short one whole, Bert's at least 48 px, never squeezed to 0 next to the
| buttons), every button inside its row and at least 44 x 44 px. Anna invites
| Bert from his row (a Livewire round trip to the lobby's invite, a push for
| him), then opens his correspondence challenge: the form has him picked and
| nothing is sent. A guest's two lobbies have the same sections. The
| collector (console, uncaught errors, answers >= 400) stays empty, with a
| positive control. SHELL_SHOTS=<dir> writes the English screenshots.
|
| Every measurement waits for what it measures to be on screen (review of
| P2: Alpine's x-show reveals on the next animation frame, so a wait on the
| component's `done` alone measured hidden rows in a busy shard), then two
| frames. BOARD_FOLLOWS_SLOW_RAF=<ms> delays every animation frame by that
| much, the way the flake was forced; the file stays green with it.
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

    $this->port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $this->relay = Process::start(['nak', 'serve', '--hostname', '127.0.0.1', '--port', (string) $this->port]);
    WaitForPort::open('127.0.0.1', $this->port);
    $this->relayUrl = 'ws://127.0.0.1:'.$this->port;
    config(['esports.profile_relays' => [$this->relayUrl], 'esports.relays' => [$this->relayUrl], 'esports.chat.relays' => []]);

    NineMensMorrisOn::play();
    CheckersGame::play();
});

afterEach(function () {
    $this->relay->stop(1);
});

function boardFollowsPage(?User $user, string $to, int $width, int $height, string $locale): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $slow = (int) getenv('BOARD_FOLLOWS_SLOW_RAF');
    if ($slow > 0) {
        $page->context()->addInitScript('(() => { const raf = window.requestAnimationFrame.bind(window); window.requestAnimationFrame = (callback) => setTimeout(() => raf(callback), '.$slow.'); })();');
    }
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 15_000);

    return $page;
}

function boardFollowsSend(string $url, array $event): void
{
    $client = new Client($url);
    $client->setTimeout(5);
    $client->text(json_encode(['EVENT', $event]));
    $answer = $client->receive();
    $client->close();

    expect($answer instanceof Text ? json_decode($answer->getContent(), true) : null)->toMatchArray([0 => 'OK', 1 => $event['id'], 2 => true]);
}

/**
 * The page as measured: language, sideways overflow, and the collector.
 *
 * @return array{lang: string, scroll: int, client: int, errors: list<string>, bad: list<string>}
 */
function boardFollowsClean(Page $page): array
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

/** Two animation frames: what an x-show or x-if decided is painted by then. */
const BOARD_FOLLOWS_FRAMES = '() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))';

/**
 * The follows list as it must be before it is measured, on screen: 12 rows,
 * each with its visible presence line, `looking` visible tags, and the
 * invite way of the page (chess: the invite DM, a board game: the way to the
 * own page) visible.
 */
function boardFollowsShown(string $which, int $looking): string
{
    $invite = $which === 'chess' ? 'follows-invite' : 'follows-invite-elsewhere';

    return '() => { const s = document.querySelector("[data-test=follows-here]"); if (! s || s.dataset.state !== "done") return false;'
        .' const rows = [...s.querySelectorAll("[data-test=follows-here-player]")];'
        .' return rows.length === 12 && rows.every((r) => r.querySelector("[data-test=follows-here-presence]")?.checkVisibility())'
        .' && [...s.querySelectorAll("[data-test=follows-here-looking]")].filter((e) => e.checkVisibility()).length === '.$looking
        .' && s.querySelector("[data-test='.$invite.']")?.checkVisibility() === true && document.fonts.status === "loaded"; }';
}

/** The lobby sections present and visible, in reading order (top, then left). */
const BOARD_FOLLOWS_SECTIONS = <<<'JS'
    () => Object.entries({
        title: '[data-test=lobby-title]', play: '[data-test=play]', next: '[data-test=next-tournament], [data-test=next-tournament-empty]',
        cups: '[data-test=cup-mentions]', games: '#your-games', live: 'section[aria-labelledby=live-h]', online: '[data-test=online-now]',
        ladder: '[data-test=lobby-ladder]', follows: '[data-test=follows-here]', chat: '[data-test=game-chat]', weekly: '[data-test=weekly-events]',
    }).map(([key, selector]) => [key, document.querySelector(selector)]).filter(([, el]) => el && el.checkVisibility())
        .map(([key, el]) => [key, Math.round(el.getBoundingClientRect().top + scrollY), Math.round(el.getBoundingClientRect().left)])
        .sort((a, b) => a[1] - b[1] || a[2] - b[2]).map(([key]) => key)
    JS;

/**
 * Each follow row: its name box (width, whole or not), its picture, its
 * buttons inside the row, the row not scrolling sideways, what its presence
 * line says; and the section's visible targets under 44 px. No width filter:
 * a name squeezed to 0 px shows as width 0.
 */
const BOARD_FOLLOWS_ROWS = <<<'JS'
    () => {
        const section = document.querySelector('[data-test=follows-here]');
        const rows = [...section.querySelectorAll('[data-test=follows-here-player]')].map((row) => {
            const box = row.getBoundingClientRect();
            const link = row.querySelector('[data-test=follows-here-name]');
            const actions = [...row.querySelectorAll('[data-test=follows-here-invite], [data-test=follows-here-invited], [data-test=follows-here-challenge]')].filter((el) => el.checkVisibility());
            const looking = row.querySelector('[data-test=follows-here-looking]');
            const online = row.querySelector('[data-test=follows-here-presence]');
            return {
                name: link.textContent.trim(), width: Math.round(link.getBoundingClientRect().width), whole: link.scrollWidth <= link.clientWidth + 1,
                ellipsis: getComputedStyle(link).textOverflow === 'ellipsis',
                picture: Math.round(row.querySelector('img').getBoundingClientRect().width),
                inside: actions.every((el) => { const r = el.getBoundingClientRect(); return r.left >= box.left - 0.5 && r.right <= box.right + 0.5; }),
                actions: actions.map((el) => el.dataset.test.replace('follows-here-', '')),
                // Picture, name and buttons on one line: a wrapped row keeps every box inside and still reads wrong.
                sameLine: (() => { const n = link.parentElement.closest('span').getBoundingClientRect(); const pic = row.querySelector('img').getBoundingClientRect(); return [pic, ...actions.map((el) => el.getBoundingClientRect())].every((r) => r.top < n.bottom && r.bottom > n.top); })(),
                rowScroll: row.scrollWidth - row.clientWidth,
                online: online ? online.checkVisibility() : null,
                looking: looking && looking.checkVisibility() ? looking.textContent.trim() : null,
                tagWhole: ! looking || ! looking.checkVisibility() || looking.getBoundingClientRect().right <= box.right + 0.5,
            };
        });
        const small = [...section.querySelectorAll('a, button')].filter((el) => el.checkVisibility())
            .map((el) => { const r = el.getBoundingClientRect(); return [el.dataset.test ?? el.textContent.trim().slice(0, 24), Math.round(r.width), Math.round(r.height)]; })
            .filter(([, w, h]) => w < 44 || h < 44);
        const box = section.getBoundingClientRect();
        return { rows, small, more: section.querySelector('[data-test=follows-here-more]')?.textContent.trim() ?? null, right: Math.round(box.right), elsewhere: section.querySelector('[data-test=follows-invite-elsewhere]')?.checkVisibility() ?? false };
    }
    JS;

test('the board lobby lists your follows as chess does, with who is online, the invite (one move a day) and the correspondence challenge', function (int $width, int $height, string $locale) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    $long = 'Satoshinakamotohalfinneyadambackszabonick';
    $anna = User::factory()->create(['name' => 'Anna', 'locale' => $locale]);
    $signer = TestSigner::forBrowser($anna);
    $anna->refresh();
    // Bert: first in her list, a name without a break, online and looking for nine men's morris from the moment he joins.
    $bert = User::factory()->create(['name' => $long, 'looking_to_play' => NineMensMorris::SLUG.'/correspondence']);
    // 24 more: every other one in a clan, one clan with a long name.
    $more = collect(range(1, 24))->map(function (int $i): User {
        if ($i % 2 === 1) {
            $owner = Clan::factory()->create($i === 1 ? ['name' => 'Bitcoinfestungsbaumeisterinnenvereinigung'] : [])->owner;
            $owner->forceFill(['name' => 'Follow '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->save();

            return $owner;
        }

        return User::factory()->create(['name' => 'Follow '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    });
    $strangers = [new TestSigner, new TestSigner];
    $at = now()->getTimestamp() - 600;
    boardFollowsSend($this->relayUrl, $signer->sign(10002, [['r', $this->relayUrl]], '', $at));
    boardFollowsSend($this->relayUrl, $signer->sign(3, array_map(fn (string $pubkey): array => ['p', $pubkey], [$bert->pubkey, ...$more->pluck('pubkey')->all(), $strangers[0]->pubkey, $strangers[1]->pubkey]), '', $at));

    // What the channel would carry for the other 24 (routes/channels.php): online, every third looking for nine men's morris.
    $members = $more->values()->map(fn (User $user, int $i): array => [
        'id' => $user->id, 'name' => $user->displayName(), 'avatar' => null, 'generated' => PlayerProfile::generatedAvatarUrl($user->pubkey),
        'npub' => $user->npub, 'pubkey' => $user->pubkey, 'looking' => $i % 3 === 0 ? NineMensMorris::SLUG.'/correspondence' : null, 'elo' => 1000, 'provisional' => true,
    ])->all();
    $inject = '() => window.esportsPresence.set([...window.esportsPresence.members.filter((m) => ! '.json_encode(array_column($members, 'id')).'.includes(m.id)), ...'.json_encode($members).'])';

    $board = route('board.lobby', NineMensMorris::SLUG, false);
    $bertPage = boardFollowsPage($bert, $board, $width, $height, $locale);
    BrowserWait::until($bertPage, '() => window.esportsPresence?.ready === true', 10_000);

    $measured = [];
    $rows = [];
    $pages = [];
    foreach (['chess' => route('chess.lobby', [], false), 'board' => $board] as $which => $path) {
        $pages[$which] = $page = boardFollowsPage($anna, $path, $width, $height, $locale);
        BrowserWait::until($page, '() => window.esportsPresence?.ready === true && window.esportsPresence.members.some((m) => m.name === '.json_encode($long).')', 10_000);
        $page->evaluate($inject);
        $page->evaluate('() => document.querySelector("[data-test=follows-here]").scrollIntoView({ block: "start" })');
        // Bert and the four of the shown eleven who look for nine men's morris carry the tag on its lobby; none on chess's.
        BrowserWait::until($page, boardFollowsShown($which, $which === 'board' ? 5 : 0), 20_000);
        $page->evaluate(BOARD_FOLLOWS_FRAMES);
        $rows[$which] = $page->evaluate(BOARD_FOLLOWS_ROWS);
        $measured[$which] = ['sections' => $page->evaluate(BOARD_FOLLOWS_SECTIONS)] + boardFollowsClean($page);
        if ($locale === 'en') {
            shellShot($page, "follows-{$which}-{$width}");
            $page->evaluate('() => scrollTo(0, 0)');
            shellShot($page, "follows-{$which}-{$width}-top");
        }
    }
    $page = $pages['board'];

    // The invite from Bert's row: the lobby's own invite (a Livewire round trip), "Invited" in his row, his incoming invite by push.
    $bertRow = '[...document.querySelectorAll("[data-test=follows-here-player]")].find((row) => row.innerText.includes('.json_encode($long).'))';
    $page->evaluate('() => '.$bertRow.'.scrollIntoView({ block: "center" })');
    $page->evaluate('() => '.$bertRow.'.querySelector("[data-test=follows-here-invite]").click()');
    BrowserWait::until($page, '() => '.$bertRow.'.querySelector("[data-test=follows-here-invited]")?.checkVisibility() === true && document.querySelector("[data-test=lobby-invited]")?.checkVisibility() === true', 10_000);
    BrowserWait::until($bertPage, '() => document.querySelector("[data-test=incoming-invite]") !== null', 10_000);
    $page->evaluate(BOARD_FOLLOWS_FRAMES);
    $rows['invited'] = $page->evaluate(BOARD_FOLLOWS_ROWS);
    $measured['invited'] = boardFollowsClean($page);
    $measured['bert'] = boardFollowsClean($bertPage);
    if ($locale === 'en') {
        shellShot($page, "follows-board-invited-{$width}");
    }
    $invite = BoardInvite::query()->sole();

    // His correspondence challenge: the form with him picked, nothing sent.
    $page->evaluate('() => '.$bertRow.'.querySelector("[data-test=follows-here-challenge]").click()');
    BrowserWait::until($page, '() => location.pathname.endsWith("/correspondence") && document.querySelector("[data-test=send-challenge]")?.checkVisibility() === true && document.fonts.status === "loaded"', 10_000);
    $page->evaluate(BOARD_FOLLOWS_FRAMES);
    $form = $page->evaluate('() => { const b = document.querySelector("[data-test=send-challenge]").getBoundingClientRect(); const picked = document.querySelector("[data-test=pick-player][aria-checked=true]"); return { to: new URLSearchParams(location.search).get("to"), button: document.querySelector("[data-test=send-challenge]").textContent.trim(), right: Math.round(b.right), buttonWhole: document.querySelector("[data-test=send-challenge]").scrollWidth <= document.querySelector("[data-test=send-challenge]").clientWidth + 1, disabled: document.querySelector("[data-test=send-challenge]").disabled, picked: picked?.textContent.trim() ?? null, problem: document.querySelector("[data-test=challenge-to-problem]")?.textContent ?? null, wide: [...document.querySelectorAll("body *")].filter((e) => { if (! e.checkVisibility() || e.getBoundingClientRect().right <= innerWidth + 0.5) return false; for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) { if (getComputedStyle(p).overflowX !== "visible") return false; } return true; }).slice(-6).map((e) => e.tagName + " " + (e.dataset.test ?? "") + " " + String(e.className).slice(0, 80) + " " + Math.round(e.getBoundingClientRect().right)) }; }');
    $measured['correspondence'] = boardFollowsClean($page);
    if ($locale === 'en') {
        $page->evaluate('() => document.querySelector("[data-test=correspondence-challenge]").scrollIntoView({ block: "start" })');
        shellShot($page, "follows-correspondence-{$width}");
    }

    fwrite(STDERR, "board follows {$locale} {$width}x{$height}: ".json_encode(compact('measured', 'rows', 'form')).PHP_EOL);

    // The same sections in the same order on both lobbies (the chat is chess's own until P1 of the plan lands).
    expect(array_values(array_diff($measured['board']['sections'], ['chat'])))->toBe(array_values(array_diff($measured['chess']['sections'], ['chat'])))
        ->and($measured['board']['sections'])->toContain('follows')
        ->and($invite->inviter_id)->toBe($anna->id)
        ->and($invite->invitee_id)->toBe($bert->id)
        ->and($form['to'])->toBe($bert->npub)
        ->and($form['picked'])->toBe($long)
        ->and($form['button'])->toContain($long)
        ->and($form['disabled'])->toBeFalse()
        ->and($form['problem'])->toBeNull()
        ->and($form['right'])->toBeLessThanOrEqual($width)
        ->and($form['buttonWhole'])->toBeTrue()
        ->and(BoardChallenge::query()->count())->toBe(0);

    $tag = $locale === 'de' ? 'sucht: Mühle' : "looking: Nine Men's Morris";
    foreach ($rows as $where => $list) {
        expect($list['rows'])->toHaveCount(12, $where)
            ->and($list['more'])->toBe($locale === 'de' ? 'und 13 weitere' : 'and 13 more', $where)
            ->and($list['small'])->toBe([], $where)
            ->and($list['right'])->toBeLessThanOrEqual($width, $where)
            // A board game has no invite link: the way to the own page, where chess has its invite DM.
            ->and($list['elsewhere'])->toBe($where !== 'chess', $where);

        foreach ($list['rows'] as $row) {
            $label = "{$where}: {$row['name']} ".json_encode($row);
            expect($row['picture'])->toBe(36, $label)
                ->and($row['inside'])->toBeTrue($label)
                ->and($row['sameLine'])->toBeTrue($label)
                ->and($row['rowScroll'])->toBe(0, $label)
                ->and($row['tagWhole'])->toBeTrue($label)
                ->and($row['online'])->toBeTrue($label)
                ->and($row['ellipsis'])->toBeTrue($label)
                ->and($row['name'] === $long ? $row['width'] >= 48 && ! $row['whole'] : $row['whole'])->toBeTrue($label);
        }

        // Bert and every third of the others look for nine men's morris: the board lobby's tag and invite; chess's lobby says they are online.
        $lookingRows = array_values(array_filter($list['rows'], fn (array $row): bool => $row['looking'] !== null));
        if ($where === 'chess') {
            expect($lookingRows)->toBe([], $where)
                ->and(array_unique(array_merge(...array_column($list['rows'], 'actions'))))->toBe(['challenge'], $where);
        } else {
            expect(array_column($lookingRows, 'looking'))->toBe(array_fill(0, count($lookingRows), $tag), $where)
                ->and(count($lookingRows))->toBe(1 + 4, $where)
                ->and(array_column($list['rows'], 'actions')[0])->toBe($where === 'invited' ? ['invited', 'challenge'] : ['invite', 'challenge'], $where);
        }
    }

    foreach ($measured as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("board follows positive control"); }); fetch("/board/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("board follows positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 320, en' => [320, 700, 'en'],
    'phone 320, de' => [320, 700, 'de'],
    'phone 375, en' => [375, 667, 'en'],
    'phone 375, de' => [375, 667, 'de'],
    'desktop 1280, en' => [1280, 800, 'en'],
    'desktop 1280, de' => [1280, 800, 'de'],
]);

test('a guest\'s board lobby has the chess lobby\'s sections, without follows, and is clean', function (int $width, int $height, string $locale) {
    $measured = [];
    foreach (['chess' => route('chess.lobby', [], false), 'board' => route('board.lobby', NineMensMorris::SLUG, false)] as $which => $path) {
        $page = boardFollowsPage(null, $path, $width, $height, $locale);
        BrowserWait::until($page, '() => document.querySelector("[data-test=online-now]")?.checkVisibility() === true && document.fonts.status === "loaded"', 10_000);
        $page->evaluate(BOARD_FOLLOWS_FRAMES);
        $measured[$which] = ['sections' => $page->evaluate(BOARD_FOLLOWS_SECTIONS)] + boardFollowsClean($page);
    }

    fwrite(STDERR, "board follows guest {$locale} {$width}x{$height}: ".json_encode($measured).PHP_EOL);

    expect(array_values(array_diff($measured['board']['sections'], ['chat'])))->toBe(array_values(array_diff($measured['chess']['sections'], ['chat'])))
        ->and($measured['board']['sections'])->not->toContain('follows');

    foreach ($measured as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);
    }
})->with([
    'phone 320, en' => [320, 700, 'en'],
    'phone 320, de' => [320, 700, 'de'],
    'phone 375, en' => [375, 667, 'en'],
    'phone 375, de' => [375, 667, 'de'],
    'desktop 1280, en' => [1280, 800, 'en'],
    'desktop 1280, de' => [1280, 800, 'de'],
]);

/*
| Review of P2: in a live game the viewer cannot invite (the invite would only
| lead back to that game), so the follow row offers no blitz invite, from the
| same source as "Online now" (the lobby's canInvite). Carl looks for chess
| and Dora for nine men's morris, both really online; Anna follows both and
| plays a live game of the lobby's own kind: neither list offers her an
| Invite, the row still says who looks, and its challenge carries the label.
*/

test('in a live game the follow row offers no blitz invite, as "Online now" offers none', function (string $lobby) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    $anna = User::factory()->create(['name' => 'Anna']);
    $signer = TestSigner::forBrowser($anna);
    $anna->refresh();
    $key = $lobby === 'chess' ? 'chess/blitz' : NineMensMorris::SLUG.'/correspondence';
    $friend = User::factory()->create(['name' => $lobby === 'chess' ? 'Carl' : 'Dora', 'looking_to_play' => $key]);
    $at = now()->getTimestamp() - 600;
    boardFollowsSend($this->relayUrl, $signer->sign(10002, [['r', $this->relayUrl]], '', $at));
    boardFollowsSend($this->relayUrl, $signer->sign(3, [['p', $friend->pubkey]], '', $at));

    // Anna's live game, of the lobby's own kind.
    $rival = User::factory()->create(['name' => 'Rival']);
    // A live board game is one left from before the board games' blitz was dropped (2026-10-07): correspondence is never live.
    $lobby === 'chess' ? app(ChessGameService::class)->start($anna, $rival) : app(BoardGameService::class)->start(NineMensMorris::SLUG, $anna, $rival)->forceFill(['mode' => 'blitz', 'deadline_ms' => null])->save();
    $path = $lobby === 'chess' ? route('chess.lobby', [], false) : route('board.lobby', NineMensMorris::SLUG, false);

    $friendPage = boardFollowsPage($friend, $path, 375, 667, 'en');
    BrowserWait::until($friendPage, '() => window.esportsPresence?.ready === true', 10_000);
    $page = boardFollowsPage($anna, $path, 375, 667, 'en');
    BrowserWait::until($page, '() => window.esportsPresence?.members.some((m) => m.name === '.json_encode($friend->name).')', 10_000);
    $page->evaluate('() => document.querySelector("[data-test=follows-here]").scrollIntoView({ block: "start" })');
    BrowserWait::until($page, '() => document.querySelector("[data-test=follows-here]")?.dataset.state === "done" && document.querySelector("[data-test=follows-here-presence]")?.checkVisibility() === true && [...document.querySelectorAll("[data-test=online-player]")].some((r) => r.checkVisibility())', 20_000);
    $page->evaluate(BOARD_FOLLOWS_FRAMES);

    $seen = $page->evaluate('() => ({
        canInvite: Alpine.$data(document.querySelector("[data-test=follows-here]")).$wire.$parent.canInvite,
        rowInvite: document.querySelectorAll("[data-test=follows-here-invite], [data-test=follows-here-invited]").length,
        onlineInvite: document.querySelectorAll("[data-test=online-now] [data-test=invite], [data-test=online-now] [data-test=invited]").length,
        presence: document.querySelector("[data-test=follows-here-presence]").innerText.trim(),
        challengeLabel: [...document.querySelector("[data-test=follows-here-challenge]").querySelectorAll("span")].map((e) => e.className),
    })');
    $clean = boardFollowsClean($page);
    fwrite(STDERR, "board follows live-game {$lobby}: ".json_encode(compact('seen', 'clean')).PHP_EOL);

    expect($seen['canInvite'])->toBeFalse()
        ->and($seen['rowInvite'])->toBe(0)
        ->and($seen['onlineInvite'])->toBe(0)
        ->and($seen['presence'])->toBe($lobby === 'chess' ? 'looking: live chess' : "looking: Nine Men's Morris")
        // Without the invite next to it, the challenge is the row's labelled action again.
        ->and(implode(' ', $seen['challengeLabel']))->not->toMatch('/(^|\s)sr-only(\s|$)/')
        ->and(BoardInvite::query()->count())->toBe(0)
        ->and($clean['errors'])->toBe([])
        ->and($clean['bad'])->toBe([]);

    // Positive control: the collector sees a throw on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("live game positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("live game positive control"))', 5_000);
})->with(['chess', 'board']);

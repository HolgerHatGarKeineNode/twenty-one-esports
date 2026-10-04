<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament desk in the browser (user, 2026-10-03 / 2026-10-04)
|--------------------------------------------------------------------------
|
| A player and the tournament's creator (the direction) on the running
| tournament page, each with a stubbed window.nostr (TestSigner::browserStub)
| over a real websocket to an in-memory relay (tests/Support/MiniRelay.php).
| The desk is open without a click (user, 2026-10-04: "versteckt hinter
| einem Button-Klick, das ist schlechte UX"): the player writes, the
| direction reads and answers, the answer is marked "Tournament direction".
| A game banner's desk button counts it and leads to the open desk. After a
| reload both messages are still there. Measured at en 1440 and 1920 (a
| sticky side column beside the content, under the header, 24 px above the
| dock, also scrolled), en 375 and de 375 (right under the "What to do now"
| hero; the hero's desk button brings the whole desk between the header and
| the tab bar; no sideways scroll). During sign-up the desk stands under the
| sign-up box; with the champion its column starts level with that hero.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400; a thrown error at the end proves the collector sees one.
| DESK_SHOTS=<dir> writes the screenshots there.
|
*/

const DESK_COLLECTOR = <<<'JS'
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

/** The open desk, its column, the hero above it, the content beside it, the sticky header, the dock and the tab bar. */
const DESK_MEASURE = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const chat = document.querySelector('[data-test=desk-chat]');
        const rail = chat.closest('.chat-rail');
        const host = chat.closest('.chat-rail-host');
        const hero = document.querySelector('[data-test=now-hero]');
        const content = [...host.querySelectorAll('*')].filter((el) => ! rail.contains(el) && el.children.length === 0 && el.checkVisibility() && el.getBoundingClientRect().width > 0);
        const dock = [document.querySelector('[data-test=match-dock]'), document.querySelector('[data-test=dock-cup]')].filter((el) => el && el.checkVisibility());
        const tabbar = [...document.querySelectorAll('body *')].filter((el) => {
            const r = el.getBoundingClientRect();
            return ! chat.contains(el) && getComputedStyle(el).position === 'fixed' && r.height > 0 && r.height < 160 && r.bottom >= innerHeight - 1 && r.width >= innerWidth * 0.9;
        });
        return {
            chat: box(chat), rail: box(rail), field: box(document.querySelector('#deskchat')),
            list: box(chat.querySelector('[data-test=desk-messages]')),
            visible: chat.checkVisibility() && document.querySelector('[data-test=desk-form]').checkVisibility(),
            hero: hero ? box(hero) : null,
            afterHero: hero !== null && (rail.previousElementSibling === hero || rail.previousElementSibling?.contains(hero) === true),
            contentRight: Math.round(Math.max(...content.map((el) => el.getBoundingClientRect().right))),
            header: Math.round(document.querySelector('.shell-header')?.getBoundingClientRect().bottom ?? 0),
            dockTop: dock.length ? Math.round(Math.min(...dock.map((el) => el.getBoundingClientRect().top))) : null,
            tab: tabbar.length ? Math.round(Math.min(...tabbar.map((el) => el.getBoundingClientRect().top))) : null,
            position: getComputedStyle(chat).position,
            focused: document.activeElement?.id ?? null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            vw: innerWidth, vh: innerHeight, scrollY: Math.round(scrollY),
            text: chat.innerText,
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

function deskPage(User $user, string $to, int $width = 1440, int $height = 900): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(DESK_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=desk-chat]"))?.status === "live"', 10_000);

    return $page;
}

function deskSees(string $from, string $text, ?bool $manager = null): string
{
    $marked = $manager === null ? '' : '[data-manager="'.($manager ? '1' : '0').'"]';

    return '() => [...document.querySelectorAll("[data-test=desk-messages] li[data-from='.$from.']'.str_replace('"', '\\"', $marked).'")].some((li) => li.innerText.includes('.json_encode($text).'))';
}

/** A real reload of the page (a goto to the same URL would only scroll); the desk is open again without a click. */
function deskReload(Page $page): void
{
    $before = $page->evaluate('() => performance.timeOrigin');
    $page->reload();
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=desk-chat]"))?.status === "live" && document.querySelector("[data-test=desk-chat]").checkVisibility()', 10_000);
    expect($page->evaluate('() => performance.timeOrigin'))->toBeGreaterThan($before);
}

/**
 * The column at the top of the page and scrolled halfway along its travel.
 *
 * @return array{top: array<string, mixed>, scrolled: array<string, mixed>}
 */
function deskRail(Page $page): array
{
    $page->evaluate('() => scrollTo(0, 0)');
    Execution::instance()->wait(0.4);
    $top = $page->evaluate(DESK_MEASURE);
    $page->evaluate('() => { const chat = document.querySelector("[data-test=desk-chat]"); const travel = chat.closest(".chat-rail").getBoundingClientRect().height - chat.getBoundingClientRect().height; scrollTo(0, Math.max(0, Math.round(travel / 2)) + 8); }');
    Execution::instance()->wait(0.4);

    return ['top' => $top, 'scrolled' => $page->evaluate(DESK_MEASURE)];
}

function deskShot(Page $page, string $name): void
{
    $dir = getenv('DESK_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the desk is open without a click: a player and the direction talk there, the banner counts, both messages survive a reload', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port], 'esports.profile_relays' => []]);

        $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
        $tournament->forceFill(['name' => 'Halving Blitz Cup', 'published_at' => now()->subDay()])->save();
        $anna = User::query()->findOrFail($tournament->participants()->orderBy('id')->firstOrFail()->user_id);
        $anna->forceFill(['name' => 'Anna'])->save();
        $director = $tournament->creator;
        $director->forceFill(['name' => 'Dora'])->save();
        // Every member gets a real key: a message is encrypted to each of them (a factory pubkey is random bytes).
        foreach ($tournament->participants()->get() as $entry) {
            TestSigner::forBrowser(User::query()->findOrFail($entry->user_id));
        }
        TestSigner::forBrowser($director);
        $anna->refresh();
        $url = route('tournaments.show', $tournament, false);

        $pageA = deskPage($anna, $url);
        $pageD = deskPage($director, $url);

        // No click: the desk, its field and its send button are on screen for both.
        foreach (['player' => $pageA, 'direction' => $pageD] as $who => $page) {
            $m = $page->evaluate(DESK_MEASURE);
            expect($m['visible'])->toBeTrue($who)
                ->and($m['chat']['top'])->toBeGreaterThanOrEqual($m['header'], $who)
                ->and($m['field']['bottom'])->toBeLessThanOrEqual($m['vh'], $who);
        }

        $pageA->locator('#deskchat')->fill('My clock froze in round 1');
        $pageA->locator('[data-test=desk-send]')->click();
        BrowserWait::until($pageA, deskSees('me', 'My clock froze in round 1', false), 10_000);

        // The direction reads it where it is, without opening anything, and answers.
        BrowserWait::until($pageD, deskSees('them', 'My clock froze in round 1', false), 10_000);
        $pageD->locator('#deskchat')->fill('We are on it, the game is paused');
        $pageD->locator('[data-test=desk-send]')->click();
        BrowserWait::until($pageD, deskSees('me', 'We are on it, the game is paused', true), 10_000);

        // The answer reaches the player, marked as the tournament direction.
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        $mark = $pageA->evaluate('() => [...document.querySelectorAll("[data-test=desk-messages] li[data-manager=\"1\"] [data-test=desk-direction]")].filter((el) => el.checkVisibility()).map((el) => el.innerText.trim())');
        expect($mark)->toBe(['Tournament direction']);
        // On screen is read: the hero's desk button counts nothing while the open desk shows the answer.
        $state = '() => { const d = Alpine.$data(document.querySelector("[data-test=desk-chat]")); return JSON.stringify({ inView: d.inView, pageVisible: d.pageVisible, unread: d.unread, badge: document.querySelector("[data-test=now-hero] [data-test=desk-unread]").checkVisibility() }); }';
        try {
            BrowserWait::until($pageA, '() => ! document.querySelector("[data-test=now-hero] [data-test=desk-unread]").checkVisibility()', 5_000);
        } catch (Throwable $e) {
            throw new RuntimeException('desk not read while on screen: '.$pageA->evaluate($state), 0, $e);
        }
        deskShot($pageA, 'desk-en-1440');
        deskShot($pageD, 'desk-direction-en-1440');

        // On the game page (another browser, nothing read there yet) the banner's desk button counts the direction's
        // answer on its own; it leads to the open desk on the tournament page, the cursor in its field.
        $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();
        $pageG = visit(BrowserLogin::url($anna))->page();
        $pageG->context()->addInitScript(DESK_COLLECTOR);
        $pageG->context()->addInitScript(TestSigner::browserStub($anna));
        $pageG->setViewportSize(1440, 900);
        $pageG->goto(ComputeUrl::from(route('games.show', $game, false)));
        BrowserWait::until($pageG, '() => document.querySelector("[data-test=tournament-banner] [data-test=desk-unread]")?.checkVisibility() && document.querySelector("[data-test=tournament-banner] [data-test=desk-unread]").innerText.trim() === "1"', 10_000);
        deskShot($pageG, 'desk-banner-en-1440');
        expect($pageG->evaluate('() => window.__errors'))->toBe([]);
        $pageG->locator('[data-test=tournament-banner] [data-test=desk-button]')->click();
        BrowserWait::until($pageG, '() => location.hash === "#desk" && document.querySelector("[data-test=desk-chat]")?.checkVisibility() === true && document.activeElement?.id === "deskchat"', 10_000);
        BrowserWait::until($pageG, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        expect($pageG->evaluate('() => window.__errors'))->toBe([]);

        // Away with wire:navigate: the desk lets go of its page hooks (visibilitychange listener, observer).
        $pageG->evaluate('() => { window.__desk = Alpine.$data(document.querySelector("[data-test=desk-chat]")); window.__deskAborted = () => window.__desk.teardown.signal.aborted; Livewire.navigate("/"); }');
        BrowserWait::until($pageG, '() => location.pathname === "/" && document.querySelector("[data-test=desk-chat]") === null && window.__deskAborted() === true', 10_000);
        expect($pageG->evaluate('() => window.__errors'))->toBe([]);

        // A reload: the desk is open again, both messages there.
        deskReload($pageA);
        BrowserWait::until($pageA, deskSees('me', 'My clock froze in round 1', false), 10_000);
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);

        $rails = ['en-1440' => deskRail($pageA)];
        deskShot($pageA, 'desk-en-1440-scrolled');
        $pageA->setViewportSize(1920, 1080);
        $rails['en-1920'] = deskRail($pageA);
        deskShot($pageA, 'desk-en-1920-scrolled');
        $pageA->evaluate('() => scrollTo(0, 0)');
        Execution::instance()->wait(0.3);
        deskShot($pageA, 'desk-en-1920');

        // A phone: the desk right under the hero, open; the hero's desk button brings all of it into view.
        $pageA->setViewportSize(375, 812);
        $pageA->evaluate('() => scrollTo(0, 0)');
        Execution::instance()->wait(0.4);
        $phones = ['en-375' => $pageA->evaluate(DESK_MEASURE)];
        deskShot($pageA, 'desk-en-375-top');
        $pageA->locator('[data-test=now-hero] [data-test=desk-button]')->click();
        Execution::instance()->wait(0.8);
        $phones['en-375-jump'] = $pageA->evaluate(DESK_MEASURE);
        deskShot($pageA, 'desk-en-375');

        $anna->forceFill(['locale' => 'de'])->save();
        deskReload($pageA);
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        $pageA->evaluate('() => scrollTo(0, 0)');
        Execution::instance()->wait(0.4);
        $phones['de-375'] = $pageA->evaluate(DESK_MEASURE);
        deskShot($pageA, 'desk-de-375-top');
        $pageA->locator('[data-test=now-hero] [data-test=desk-button]')->click();
        Execution::instance()->wait(0.8);
        $phones['de-375-jump'] = $pageA->evaluate(DESK_MEASURE);
        deskShot($pageA, 'desk-de-375');

        foreach ($rails as $key => ['top' => $top, 'scrolled' => $scrolled]) {
            foreach (['top' => $top, 'scrolled' => $scrolled] as $where => $m) {
                $label = "{$key} {$where}";
                expect($m['visible'])->toBeTrue($label)
                    ->and($m['position'])->toBe('sticky', $label)
                    // Beside the content, never over it, inside the window.
                    ->and($m['chat']['left'])->toBeGreaterThanOrEqual($m['contentRight'] + 24, $label)
                    ->and($m['chat']['right'])->toBeLessThanOrEqual($m['vw'] - 24, $label)
                    ->and($m['chat']['top'])->toBeGreaterThanOrEqual($m['header'], $label)
                    // 24 px above the dock, or above the window's edge where there is none.
                    ->and(($m['dockTop'] ?? $m['vh']) - $m['chat']['bottom'])->toBeGreaterThanOrEqual(24, $label.': '.json_encode(array_diff_key($m, ['text' => 1])))
                    ->and($m['field']['bottom'])->toBeLessThanOrEqual($m['chat']['bottom'], $label)
                    ->and($m['list']['height'])->toBeGreaterThanOrEqual(300, $label)
                    ->and($m['text'])->toContain('My clock froze in round 1')->toContain('We are on it, the game is paused')
                    ->and($m['overflow'])->toBe(0, $label)
                    ->and($m['errors'])->toBe([], $label);
            }

            // Sticky: scrolled down, it stands exactly where it started.
            expect($scrolled['scrollY'])->toBeGreaterThan(0, $key)
                ->and(abs($scrolled['chat']['top'] - $top['chat']['top']))->toBeLessThanOrEqual(1, $key);
        }

        expect($rails['en-1440']['top']['chat']['width'])->toBe(360)
            ->and($rails['en-1920']['top']['chat']['width'])->toBe(400);

        foreach ($phones as $key => $m) {
            expect($m['visible'])->toBeTrue($key)
                ->and($m['afterHero'])->toBeTrue($key)
                ->and($m['chat']['top'])->toBeGreaterThanOrEqual($m['hero']['bottom'], $key)
                ->and($m['chat']['left'])->toBeGreaterThanOrEqual(16, $key)
                ->and($m['chat']['right'])->toBeLessThanOrEqual(375 - 16, $key)
                // A fixed height with the history scrolling inside: the panel fits a phone's screen.
                ->and($m['chat']['height'])->toBeLessThanOrEqual(480, $key)
                ->and($m['list']['height'])->toBeGreaterThanOrEqual(240, $key)
                ->and($m['tab'])->not->toBeNull($key)
                ->and($m['overflow'])->toBeLessThanOrEqual(0, $key)
                ->and($m['errors'])->toBe([], $key);
        }

        // After the jump: the whole desk between the sticky header and the tab bar, its field above the dock.
        foreach (['en-375-jump', 'de-375-jump'] as $key) {
            expect($phones[$key]['chat']['top'])->toBeGreaterThanOrEqual($phones[$key]['header'], $key)
                ->and($phones[$key]['chat']['bottom'])->toBeLessThanOrEqual($phones[$key]['tab'], $key)
                ->and($phones[$key]['field']['bottom'])->toBeLessThanOrEqual($phones[$key]['dockTop'] ?? $phones[$key]['tab'], $key);
        }

        expect($phones['en-375']['text'])->toContain('Tournament desk')->toContain('Tournament direction')
            ->and($phones['de-375']['text'])->toContain('Turnierleiter-Chat')->toContain('Turnierleitung')
            ->and($pageD->evaluate('() => window.__errors'))->toBe([])
            // The league server stores nothing of the chat.
            ->and(NostrEvent::query()->where('kind', 1059)->count())->toBe(0);

        // Positive control: an error thrown on the page reaches the collector.
        $pageA->evaluate('() => { setTimeout(() => { throw new Error("desk-probe"); }, 0); }');
        BrowserWait::until($pageA, '() => window.__errors.length > 0', 5_000);
        expect(implode(' | ', $pageA->evaluate('() => window.__errors')))->toContain('desk-probe');

        $strip = fn (array $m): array => array_diff_key($m, ['text' => 1, 'errors' => 1]);
        fwrite(STDERR, "\n[desk] ".json_encode(['rails' => array_map(fn (array $r): array => array_map($strip, $r), $rails), 'phones' => array_map($strip, $phones)])."\n");
    } finally {
        $relay->stop(1);
    }
});

/** Where the desk stands on a page without "What to do now": its box, the hero's, the sign-up box's, the content beside it. */
const DESK_PLACE = <<<'JS'
    () => {
        const r = (sel) => { const el = document.querySelector(sel); if (! el) return null; const b = el.getBoundingClientRect(); return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right) }; };
        const rail = document.querySelector('.chat-rail');
        // Leaves outside the column; decoration (aria-hidden, e.g. the champion's falling blocks, clipped by its stage) is no content.
        const content = [...document.querySelectorAll('[data-test=tournament-show] *')].filter((el) => (! rail || ! rail.contains(el)) && ! el.closest('[aria-hidden=true]') && el.children.length === 0 && el.checkVisibility() && el.getBoundingClientRect().width > 0);
        // Words of the champion's name that the line breaks inside (overflow-wrap: anywhere breaks a word rather than overflow).
        const split = (() => {
            const h = document.querySelector('#champion-h');
            if (! h) return null;
            const walker = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
            let n = 0;
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                for (const m of node.textContent.matchAll(/\S+/g)) {
                    const range = document.createRange();
                    range.setStart(node, m.index);
                    range.setEnd(node, m.index + m[0].length);
                    if (new Set([...range.getClientRects()].map((r) => Math.round(r.top))).size > 1) n++;
                }
            }
            return n;
        })();
        const host = document.querySelector('[data-test=tournament-show]');
        return {
            split, vw: innerWidth, rail: rail !== null, hostPadRight: parseFloat(getComputedStyle(host).paddingRight),
            chat: r('[data-test=desk-chat]'), heroBox: r('[data-test=tournament-hero]'), hero: r('[data-test=tournament-hero]'), champion: r('[data-test=champion-hero]'),
            title: r('#t-name'), signup: r('[data-test=signup-cta]'), header: r('.shell-header').bottom,
            contentRight: Math.round(Math.max(...content.map((el) => el.getBoundingClientRect().right))),
            // Which leaf that is, so a failing bound names its element.
            contentRightBy: (() => { const el = content.reduce((a, b) => (b.getBoundingClientRect().right > a.getBoundingClientRect().right ? b : a)); return el.tagName + '.' + String(el.className).slice(0, 80) + ' ' + (el.closest('[data-test]')?.dataset.test ?? ''); })(),
            position: document.querySelector('[data-test=desk-chat]') ? getComputedStyle(document.querySelector('[data-test=desk-chat]')).position : null,
            row: document.querySelector('[data-test=desk-row]') !== null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, vh: innerHeight,
            errors: window.__errors,
        };
    }
    JS;

/** The tournament page for `$user` at 375 and at 1440, measured with DESK_PLACE, screenshots named `$shot`. @return array{phone: array<string, mixed>, wide: array<string, mixed>} */
function deskPlaces(User $user, string $url, string $shot): array
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(DESK_COLLECTOR);
    $page->setViewportSize(375, 812);
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=desk-chat]"))?.status !== undefined', 10_000);
    $phone = $page->evaluate(DESK_PLACE);
    deskShot($page, $shot.'-en-375');
    // The sign-up box's own size on a phone: scrolled to the desk.
    $page->evaluate('() => document.querySelector("[data-test=desk-chat]").scrollIntoView({ block: "start" })');
    Execution::instance()->wait(0.3);
    deskShot($page, $shot.'-en-375-desk');
    $page->setViewportSize(1440, 900);
    $page->evaluate('() => scrollTo(0, 0)');
    Execution::instance()->wait(0.4);
    $wide = $page->evaluate(DESK_PLACE);
    deskShot($page, $shot.'-en-1440');
    $page->evaluate('() => scrollTo(0, 600)');
    Execution::instance()->wait(0.4);
    $wide['scrolled'] = $page->evaluate(DESK_PLACE);

    fwrite(STDERR, "\n[desk {$shot}] ".json_encode(compact('phone', 'wide'))."\n");

    return compact('phone', 'wide');
}

test('during sign-up a phone opens on the tournament\'s name and the open desk stands under the sign-up box; from 1440 beside the page', function () {
    config(['esports.chat.relays' => [], 'esports.profile_relays' => []]);
    $tournament = openTournament();
    [$solo, $soloKey] = keyedPlayer();
    soloSignup($tournament, $solo, $soloKey);

    ['phone' => $phone, 'wide' => $wide] = deskPlaces($solo, route('tournaments.show', $tournament, false), 'desk-signup');

    expect($phone['row'])->toBeFalse()
        // The first screen: the tournament's name under the header, the sign-up box, then the desk.
        ->and($phone['title']['top'])->toBeGreaterThanOrEqual($phone['header'])
        ->and($phone['title']['bottom'])->toBeLessThanOrEqual($phone['vh'])
        ->and($phone['chat']['top'])->toBeGreaterThan($phone['signup']['bottom'])
        ->and($phone['chat']['top'] - $phone['signup']['bottom'])->toBeLessThanOrEqual(24)
        ->and($phone['overflow'])->toBe(0)
        // From 1440: a sticky column beside the content, its top on the hero's, staying there when scrolled.
        ->and($wide['position'])->toBe('sticky')
        ->and($wide['chat']['left'])->toBeGreaterThanOrEqual($wide['contentRight'] + 24)
        ->and($wide['chat']['top'])->toBe($wide['hero']['top'])
        ->and($wide['scrolled']['chat']['top'])->toBe($wide['chat']['top'])
        ->and($wide['overflow'])->toBe(0)
        ->and($phone['errors'])->toBe([])
        ->and($wide['errors'])->toBe([]);
});

test('a day after the end the open desk\'s column starts level with the champion hero', function () {
    config(['esports.chat.relays' => [], 'esports.profile_relays' => []]);
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Director);
    playOutAsDirector($tournament);
    $player = User::query()->findOrFail($tournament->participants()->orderBy('id')->firstOrFail()->user_id);

    ['phone' => $phone, 'wide' => $wide] = deskPlaces($player, route('tournaments.show', $tournament, false), 'desk-champion');

    expect($phone['chat']['top'])->toBeGreaterThanOrEqual($phone['champion']['bottom'])
        ->and($phone['overflow'])->toBe(0)
        // The champion's name never breaks inside a word, also with the column beside it.
        ->and($phone['split'])->toBe(0)
        ->and($wide['split'])->toBe(0)
        ->and($wide['chat']['top'])->toBe($wide['champion']['top'])
        ->and($wide['chat']['left'])->toBeGreaterThanOrEqual($wide['contentRight'] + 24)
        ->and($wide['overflow'])->toBe(0)
        ->and($phone['errors'])->toBe([])
        ->and($wide['errors'])->toBe([]);
});

test('a guest and a logged-in non-member get the tournament page in full width at 1440 and 1920, no empty column on the right', function () {
    config(['esports.chat.relays' => [], 'esports.profile_relays' => []]);
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $url = ComputeUrl::from(route('tournaments.show', $tournament, false));
    $measured = [];

    foreach (['guest' => null, 'stranger' => User::factory()->create()] as $who => $user) {
        $page = $user === null ? visit('/')->page() : visit(BrowserLogin::url($user))->page();
        $page->context()->addInitScript(DESK_COLLECTOR);

        foreach ([1440, 1920] as $width) {
            $page->setViewportSize($width, 900);
            $page->goto($url);
            BrowserWait::until($page, '() => window.Alpine !== undefined && document.readyState === "complete"', 10_000);
            Execution::instance()->wait(0.3);
            $m = $measured["{$who}-{$width}"] = $page->evaluate(DESK_PLACE);
            deskShot($page, "desk-none-{$who}-en-{$width}");

            expect($m['rail'])->toBeFalse("{$who} {$width}")
                ->and($m['chat'])->toBeNull("{$who} {$width}")
                ->and($m['hostPadRight'])->toEqual(0, "{$who} {$width}")
                // The tournament's hero spans the window and its content reaches the page's right padding (48 px).
                ->and($m['heroBox']['right'])->toBe($width, "{$who} {$width}")
                ->and($m['contentRight'])->toBe($width - 48, "{$who} {$width}")
                ->and($m['overflow'])->toBe(0, "{$who} {$width}")
                ->and($m['errors'])->toBe([], "{$who} {$width}");
        }
    }

    fwrite(STDERR, "\n[desk none] ".json_encode(array_map(fn (array $m): array => array_intersect_key($m, array_flip(['heroBox', 'contentRight', 'hostPadRight', 'rail'])), $measured))."\n");
});

<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament desk in the browser (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| A player and the tournament's creator (the direction) on the running
| tournament page, each with a stubbed window.nostr (TestSigner::browserStub)
| over a real websocket to an in-memory relay (tests/Support/MiniRelay.php).
| The player opens the desk from the "What to do now" hero and writes; the
| direction's desk button counts it, the direction answers, and the answer
| is marked "Tournament direction". After a reload (#desk opens the drawer)
| both messages are still there. Measured at en 1440, en 375 and de 375: the
| drawer sits under the sticky header and above the phone's tab bar, its
| field in view, no sideways scroll.
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

/** The open drawer, its field, the sticky header, the phone's tab bar and the page's sideways overflow. */
const DESK_MEASURE = <<<'JS'
    () => {
        const drawer = document.querySelector('[data-test=desk-drawer]');
        const box = drawer.getBoundingClientRect();
        const field = document.querySelector('#deskchat').getBoundingClientRect();
        const header = document.querySelector('.shell-header')?.getBoundingClientRect() ?? null;
        const tabbar = [...document.querySelectorAll('body *')].filter((el) => {
            const style = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return el !== drawer && !drawer.contains(el) && style.position === 'fixed' && r.height > 0 && r.height < 160 && r.bottom >= innerHeight - 1 && r.width >= innerWidth * 0.9;
        });
        return {
            top: Math.round(box.top),
            bottom: Math.round(box.bottom),
            left: Math.round(box.left),
            right: Math.round(box.right),
            fieldTop: Math.round(field.top),
            fieldBottom: Math.round(field.bottom),
            headerBottom: header && Math.round(header.bottom),
            tab: tabbar.length ? Math.round(Math.min(...tabbar.map((el) => el.getBoundingClientRect().top))) : null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            text: drawer.innerText,
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

/** A real reload of the page with #desk in the address (a goto to the same URL plus a hash would only scroll). */
function deskReload(Page $page): void
{
    $page->evaluate('() => { history.replaceState(null, "", location.pathname + "#desk"); }');
    $before = $page->evaluate('() => performance.timeOrigin');
    $page->reload();
    BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=desk-chat]"))?.status === "live" && document.querySelector("[data-test=desk-drawer]").checkVisibility()', 10_000);
    expect($page->evaluate('() => performance.timeOrigin'))->toBeGreaterThan($before);
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

test('a player and the direction talk at the tournament desk: the badge counts, the direction is marked, and both messages survive a reload', function () {
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

        // The player opens the desk from the hero and writes.
        $pageA->locator('[data-test=now-hero] [data-test=desk-button]')->click();
        BrowserWait::until($pageA, '() => document.querySelector("[data-test=desk-drawer]").checkVisibility()', 5_000);
        $pageA->locator('#deskchat')->fill('My clock froze in round 1');
        $pageA->locator('[data-test=desk-send]')->click();
        BrowserWait::until($pageA, deskSees('me', 'My clock froze in round 1', false), 10_000);

        // The direction's closed desk counts it on the button, then opening it reads it.
        BrowserWait::until($pageD, '() => document.querySelector("[data-test=desk-button] [data-test=desk-unread]")?.checkVisibility() && document.querySelector("[data-test=desk-button] [data-test=desk-unread]").innerText.trim() === "1"', 10_000);
        deskShot($pageD, 'desk-badge-en-1440');
        $pageD->locator('[data-test=desk-button]')->first()->click();
        BrowserWait::until($pageD, deskSees('them', 'My clock froze in round 1', false), 5_000);
        BrowserWait::until($pageD, '() => ! document.querySelector("[data-test=desk-button] [data-test=desk-unread]").checkVisibility()', 5_000);
        $pageD->locator('#deskchat')->fill('We are on it, the game is paused');
        $pageD->locator('[data-test=desk-send]')->click();
        BrowserWait::until($pageD, deskSees('me', 'We are on it, the game is paused', true), 10_000);

        // The answer reaches the player, marked as the tournament direction.
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        $mark = $pageA->evaluate('() => [...document.querySelectorAll("[data-test=desk-messages] li[data-manager=\"1\"] [data-test=desk-direction]")].filter((el) => el.checkVisibility()).map((el) => el.innerText.trim())');
        expect($mark)->toBe(['Tournament direction']);
        deskShot($pageA, 'desk-en-1440');

        // On the game page (another browser, nothing read there yet) the banner's desk button counts the direction's
        // answer on its own, without a drawer on the page; it leads to the open desk on the tournament page.
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
        BrowserWait::until($pageG, '() => location.hash === "#desk" && document.querySelector("[data-test=desk-drawer]")?.checkVisibility() === true', 10_000);
        BrowserWait::until($pageG, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        expect($pageG->evaluate('() => window.__errors'))->toBe([]);

        // A reload: #desk opens the drawer, and both messages are there again.
        deskReload($pageA);
        BrowserWait::until($pageA, deskSees('me', 'My clock froze in round 1', false), 10_000);
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);

        $measured = ['en-1440' => $pageA->evaluate(DESK_MEASURE)];

        // A phone: the drawer between the sticky header and the tab bar, its field in view.
        $pageA->setViewportSize(375, 812);
        usleep(300_000);
        $measured['en-375'] = $pageA->evaluate(DESK_MEASURE);
        deskShot($pageA, 'desk-en-375');

        // Closed again, the hero carries the desk button under its action, inside the screen.
        $pageA->locator('[data-test=desk-close]')->click();
        BrowserWait::until($pageA, '() => ! document.querySelector("[data-test=desk-drawer]").checkVisibility()', 5_000);
        $pageA->evaluate('() => window.scrollTo(0, 0)');
        $button = $pageA->evaluate('() => { const r = document.querySelector("[data-test=now-hero] [data-test=desk-button]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) }; }');
        expect($button['left'])->toBeGreaterThanOrEqual(0)->and($button['right'])->toBeLessThanOrEqual(375)->and($button['height'])->toBeGreaterThanOrEqual(44);
        deskShot($pageA, 'desk-hero-en-375');

        $anna->forceFill(['locale' => 'de'])->save();
        deskReload($pageA);
        BrowserWait::until($pageA, deskSees('them', 'We are on it, the game is paused', true), 10_000);
        $measured['de-375'] = $pageA->evaluate(DESK_MEASURE);
        deskShot($pageA, 'desk-de-375');

        foreach ($measured as $key => $row) {
            $width = str_ends_with($key, '1440') ? 1440 : 375;

            expect($row['errors'])->toBe([], $key)
                ->and($row['overflow'])->toBeLessThanOrEqual(0, $key)
                ->and($row['left'])->toBeGreaterThanOrEqual(0, $key)
                ->and($row['right'])->toBeLessThanOrEqual($width, $key)
                ->and($row['top'])->toBeGreaterThanOrEqual($row['headerBottom'] ?? 0, $key)
                ->and($row['bottom'])->toBeLessThanOrEqual($row['tab'] ?? 900, $key)
                ->and($row['fieldBottom'])->toBeLessThanOrEqual($row['tab'] ?? 900, $key);
        }

        expect($measured['en-375']['tab'])->not->toBeNull()
            ->and($measured['en-375']['text'])->toContain('Tournament desk')->toContain('Tournament direction')
            ->and($measured['de-375']['text'])->toContain('Turnierleiter-Chat')->toContain('Turnierleitung')
            ->and($pageD->evaluate('() => window.__errors'))->toBe([])
            // The league server stores nothing of the chat.
            ->and(NostrEvent::query()->where('kind', 1059)->count())->toBe(0);

        // Positive control: an error thrown on the page reaches the collector.
        $pageA->evaluate('() => { setTimeout(() => { throw new Error("desk-probe"); }, 0); }');
        BrowserWait::until($pageA, '() => window.__errors.length > 0', 5_000);
        expect(implode(' | ', $pageA->evaluate('() => window.__errors')))->toContain('desk-probe');

        fwrite(STDERR, "\n[desk] ".json_encode(array_map(fn (array $row): array => array_diff_key($row, ['text' => 1]), $measured))."\n");
    } finally {
        $relay->stop(1);
    }
});

<?php

use App\Enums\Platform;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualQueue;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The visible casual 1v1 (P23 S3)
|--------------------------------------------------------------------------
|
| Two players on the Rocket League page, each in their own context with a
| stubbed window.nostr (TestSigner::browserStub) and the chat on a real
| websocket relay (tests/Support/MiniRelay.php): "Find opponent" for both,
| the ready prompt for both, both ready, and the room at its second step
| with a running clock; then the same through an invite from the list of
| players looking. Measured at 1440 and 375 px: the rects of the module,
| the prompt and the room's timeline, and no page wider than the window.
| Every page runs the console and network collector (BrowserConsole), with
| a positive control that throws and answers 500 on purpose.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    // Database sessions: the list of players looking counts only who made a request lately.
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/**
 * @return array{0: Symfony\Component\Process\Process|InvokedProcess, 1: int}
 */
function casualPlayRelay(): array
{
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);
    WaitForPort::open('127.0.0.1', $port);
    config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

    return [$relay, $port];
}

function casualPlayPage(User $user, string $path, int $width, int $height, bool $nip44 = true): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // Every document this context loads, to catch a second navigation (a double redirect reloads the room).
    $page->context()->addInitScript('(() => { const l = JSON.parse(sessionStorage.getItem("__loads") || "[]"); l.push(location.pathname); sessionStorage.setItem("__loads", JSON.stringify(l)); })();');
    $stub = TestSigner::browserStub($user);
    // Without NIP-44: a signer that signs and nothing else (an old extension).
    $page->context()->addInitScript($nip44 ? $stub : $stub.';delete window.nostr.nip44;');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=casual-ready-root]") !== null', 10_000);

    return $page;
}

function casualPlayShot(Page $page, string $name, ?string $element = null, bool $fullPage = true): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot($fullPage, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Waits until nothing on the element animates (the sheet rises for 250 ms). */
function casualPlaySettled(Page $page, string $selector): void
{
    BrowserWait::until($page, '() => { const el = document.querySelector('.json_encode($selector).'); return el !== null && el.getAnimations({ subtree: true }).filter((a) => a.effect?.getTiming().iterations !== Infinity).every((a) => a.playState === "finished"); }', 5_000);
}

/**
 * The geometry that must hold at every width: nothing wider than the
 * window, every button of `$selector` at least 44 px high and inside the
 * box, the box inside the window.
 *
 * @return array<string, mixed>
 */
function casualPlayGeometry(Page $page, string $selector): array
{
    return $page->evaluate('(selector) => {
        const box = document.querySelector(selector).getBoundingClientRect();
        const buttons = [...document.querySelectorAll(selector + " button, " + selector + " a")].filter((b) => b.checkVisibility());
        const small = buttons.filter((b) => b.getBoundingClientRect().height < 44).map((b) => (b.dataset.test || b.innerText.trim() || b.outerHTML.slice(0, 120)) + " " + Math.round(b.getBoundingClientRect().height));
        const outside = buttons.filter((b) => { const r = b.getBoundingClientRect(); return r.left < box.left - 0.5 || r.right > box.right + 0.5; }).map((b) => b.dataset.test || b.innerText.trim());
        const clipped = [...document.querySelectorAll(selector + " *")].filter((el) => el.checkVisibility() && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === "visible" && el.children.length === 0).map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 20));
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            box: { left: Math.round(box.left), right: Math.round(box.right), width: Math.round(box.width), height: Math.round(box.height) },
            inWindow: box.left >= 0 && box.right <= window.innerWidth + 0.5,
            small, outside, clipped,
        };
    }', $selector);
}

function casualPlayControl(Page $page): void
{
    // Positive control: the collector catches a thrown error and a 500 on a fetch; then it starts empty.
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('two players find an opponent on the Rocket League page, both get the ready prompt, both press Ready, and the room runs its second step', function () {
    [$relay] = casualPlayRelay();

    try {
        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);

        $wide = casualPlayPage($anna, '/games/rocket-league', 1440, 900);
        $narrow = casualPlayPage($bert, '/games/rocket-league', 375, 812);
        casualPlayControl($wide);
        casualPlayControl($narrow);

        // The module before anything happens, at both widths.
        $module = [casualPlayGeometry($wide, '[data-test=casual-play]'), casualPlayGeometry($narrow, '[data-test=casual-play]')];
        $tiles = $wide->evaluate('() => [...document.querySelectorAll("[data-test=casual-tiles] > li:not([id])")].map((li) => Math.round(li.getBoundingClientRect().top))');
        casualPlayShot($wide, 'casual-module-1440', '[data-test=casual-play]');
        casualPlayShot($narrow, 'casual-module-375', '[data-test=casual-play]');

        expect($module[0])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($module[1])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            // Four tiles in one row from lg.
            ->and(array_unique($tiles))->toHaveCount(1);

        $wide->locator('[data-test=casual-find]')->click();
        BrowserWait::until($wide, '() => document.querySelector("[data-test=casual-searching]") !== null', 10_000);
        casualPlayShot($wide, 'casual-searching-1440', '[data-test=casual-play]');
        expect(SeriesQueueEntry::query()->where('user_id', $anna->id)->exists())->toBeTrue();

        $narrow->locator('[data-test=casual-find]')->click();

        // Both get the prompt: the second one at once, the first one by push or its poll.
        foreach ([$narrow, $wide] as $page) {
            BrowserWait::until($page, '() => document.querySelector("[data-test=ready-sheet]") !== null', 12_000);
            casualPlaySettled($page, '[data-test=ready-sheet]');
        }

        $match = SeriesMatch::query()->sole();
        $prompt = [casualPlayGeometry($wide, '[data-test=ready-sheet]'), casualPlayGeometry($narrow, '[data-test=ready-sheet]')];
        $sheet = $narrow->evaluate('() => {
            const sheet = document.querySelector("[data-test=ready-sheet]").getBoundingClientRect();
            const button = document.querySelector("[data-test=ready-button]").getBoundingClientRect();
            return { bottom: Math.round(window.innerHeight - sheet.bottom), buttonHeight: Math.round(button.height), focused: document.activeElement?.dataset.test ?? null,
                     clock: document.querySelector("[data-test=ready-clock]").innerText, dialog: document.querySelector("[role=dialog][aria-modal=true]") !== null };
        }');
        casualPlayShot($wide, 'casual-ready-1440', null, false);
        casualPlayShot($narrow, 'casual-ready-375', null, false);

        expect($prompt[0])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($prompt[1])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            // The phone gets a sheet on the bottom edge, the Ready button has the focus and a thumb-sized height.
            ->and($sheet['bottom'])->toBe(0)
            ->and($sheet['buttonHeight'])->toBeGreaterThanOrEqual(56)
            ->and($sheet['focused'])->toBe('ready-button')
            ->and($sheet['dialog'])->toBeTrue()
            ->and($sheet['clock'])->toMatch('/^0:[0-5]\d$|^1:00$/');

        $wide->locator('[data-test=ready-button]')->click();
        BrowserWait::until($wide, '() => document.querySelector("[data-test=ready-done]") !== null', 10_000);
        BrowserWait::until($narrow, '() => document.querySelector("[data-test=ready-seat-them]")?.dataset.ready === "1"', 12_000);
        casualPlayShot($narrow, 'casual-ready-other-375', null, false);

        $narrow->locator('[data-test=ready-button]')->click();

        // Straight into the room, both of them, at the second step with its clock.
        $room = route('matches.room', $match, false);
        foreach ([$narrow, $wide] as $page) {
            BrowserWait::until($page, '() => location.pathname === '.json_encode($room).' && document.querySelector("[data-test=casual-clock]")?.innerText.match(/^\d+:\d\d$/) !== null', 15_000);
        }

        $loads = [];
        foreach ([$narrow, $wide] as $page) {
            BrowserWait::until($page, '() => document.body !== null && (window.__t0 ??= Date.now(), Date.now() - window.__t0 > 1500)', 10_000);
            $loads[] = $page->evaluate('() => JSON.parse(sessionStorage.getItem("__loads") || "[]")');
        }
        // One load of the room per player: a second redirect (push and poll both answered) reloaded it.
        expect($loads)->toBe([['/games/rocket-league', $room], ['/games/rocket-league', $room]]);

        $steps = $wide->evaluate('() => ({
            path: location.pathname,
            current: document.querySelector("[data-test=casual-timeline] [aria-current=step]")?.dataset.test,
            done: [...document.querySelectorAll("[data-test=casual-timeline] li")].map((li) => li.dataset.done),
            kind: document.querySelector("[data-test=casual-deadline]")?.dataset.kind ?? document.body.innerText.slice(0, 600),
        })');
        $roomGeometry = [casualPlayGeometry($wide, '[data-test=casual-steps]'), casualPlayGeometry($narrow, '[data-test=casual-steps]')];
        // Every visible label of the timeline fits its stop (the rail between the stops overhangs on purpose).
        $timeline = $narrow->evaluate('() => [...document.querySelectorAll("[data-test=casual-timeline] li")].map((li) => [...li.children].filter((el) => el.checkVisibility() && getComputedStyle(el).position !== "absolute").every((el) => el.scrollWidth <= el.clientWidth + 1 && el.getBoundingClientRect().height <= 20))');
        $current = $narrow->evaluate('() => document.querySelector("[data-test=casual-current-step]")?.innerText');
        casualPlayShot($wide, 'casual-room-1440');
        casualPlayShot($narrow, 'casual-room-375');
        casualPlayShot($narrow, 'casual-room-steps-375', '[data-test=casual-steps]');

        expect($steps)->toBe(['path' => $room, 'current' => 'casual-step-lobby', 'done' => ['1', '0', '0', '0', '0'], 'kind' => 'lobby'])
            ->and($roomGeometry[0])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($roomGeometry[1])->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($timeline)->toBe([true, true, true, true, true])
            ->and($current)->toBe('Step 2 of 5: Lobby shared')
            // No sticky score bar on the phone before both are in: the steps carry the one action.
            ->and($narrow->evaluate('() => document.querySelector("[data-page-bar]")'))->toBeNull()
            ->and($match->refresh()->start_at)->not->toBeNull();

        // "Share lobby" of the host opens the card composer in the chat.
        [$hostPage] = $match->host_side === casualSideOf($match, $anna) ? [$wide] : [$narrow];
        BrowserWait::until($hostPage, '() => Alpine.$data(document.querySelector("[data-test=room-chat]"))?.status === "live"', 10_000);
        $hostPage->locator('[data-test=casual-share]')->click();
        BrowserWait::until($hostPage, '() => document.querySelector("[data-test=lobby-form]")?.checkVisibility() === true', 5_000);

        foreach ([$wide, $narrow] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});

test('a player switches Looking to play on, the other invites them from the list, and the accept brings the ready prompt to both', function () {
    // The invitee's page learns of the invite by push; its own poll runs only every 30 s, longer than the 15 s wait below.
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');

    [$relay] = casualPlayRelay();

    try {
        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);

        $invitee = casualPlayPage($bert, '/games/rocket-league', 375, 812);
        casualPlayControl($invitee);
        $invitee->locator('[data-test=casual-looking]')->click();
        // The switch flips at once; wait for the server's answer, not the flip.
        BrowserWait::until($invitee, '() => document.querySelector("[data-test=casual-looking-state]")?.innerText === "On" && Alpine.$data(document.querySelector("[data-test=casual-play]")).savedLooking === true', 5_000);
        expect($bert->refresh()->looking_to_play)->toBe('rocket-league/1v1');

        $inviter = casualPlayPage($anna, '/play', 1440, 900);
        casualPlayControl($inviter);
        BrowserWait::until($inviter, '() => document.querySelector("[data-test=casual-player] [data-test=casual-invite]") !== null', 5_000);
        casualPlayShot($inviter, 'casual-play-page-1440', '[data-test=casual-play]');
        $playPage = casualPlayGeometry($inviter, '[data-test=casual-play]');

        $inviter->locator('[data-test=casual-invite]')->click();
        BrowserWait::until($inviter, '() => document.querySelector("[data-test=casual-waiting]") !== null', 10_000);

        // The invitee's page learns by push (or its own look): Accept with a two-minute clock.
        BrowserWait::until($invitee, '() => document.querySelector("[data-test=casual-incoming]") !== null && /^[12]:\d\d$/.test(document.querySelector("[data-test=casual-incoming-clock]").innerText)', 15_000);
        casualPlayShot($invitee, 'casual-invite-375', '[data-test=casual-play]');
        $incoming = casualPlayGeometry($invitee, '[data-test=casual-incoming]');

        $invitee->locator('[data-test=casual-accept]')->click();

        foreach ([$invitee, $inviter] as $page) {
            BrowserWait::until($page, '() => document.querySelector("[data-test=ready-sheet]") !== null', 12_000);
        }

        expect(SeriesInvite::query()->sole()->series_match_id)->toBe(SeriesMatch::query()->sole()->id)
            ->and($playPage)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($incoming)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []]);

        foreach ([$inviter, $invitee] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});

test('without a NIP-44 signer the search is refused in the page, with the way to get one', function () {
    [$relay] = casualPlayRelay();

    try {
        $anna = User::factory()->create();
        TestSigner::forBrowser($anna);

        $page = casualPlayPage($anna, '/games/ea-sports-fc-26', 375, 812, nip44: false);
        $page->locator('[data-test=casual-find]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=casual-gate]")?.checkVisibility() === true', 5_000);
        casualPlayShot($page, 'casual-gate-375', '[data-test=casual-play]');

        expect($page->evaluate('() => document.querySelector("[data-test=casual-gate]").innerText'))->toContain('Your signer cannot encrypt messages (NIP-44)')
            ->and(SeriesQueueEntry::query()->count())->toBe(0)
            ->and($page->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

test('in German, the longest language, the module and the ready prompt still fit a 375 px phone', function () {
    [$relay] = casualPlayRelay();

    try {
        [$anna, $bert] = User::factory()->count(2)->create(['locale' => 'de']);
        TestSigner::forBrowser($anna);
        app(CasualQueue::class)->setLooking($bert, 'ea-sports-fc-27');

        $page = casualPlayPage($anna, '/games/ea-sports-fc-27', 375, 812);
        $module = casualPlayGeometry($page, '[data-test=casual-play]');
        casualPlayShot($page, 'casual-module-de-375', '[data-test=casual-play]');

        app(CasualQueue::class)->join($bert, 'ea-sports-fc-27', Platform::Pc);
        $page->locator('[data-test=casual-find]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=ready-sheet]") !== null', 12_000);
        casualPlaySettled($page, '[data-test=ready-sheet]');
        $prompt = casualPlayGeometry($page, '[data-test=ready-sheet]');
        casualPlayShot($page, 'casual-ready-de-375', null, false);

        expect($page->evaluate('() => document.querySelector("[data-test=casual-play] h2").innerText'))->toBe('1v1 casual spielen')
            ->and($module)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($prompt)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($page->evaluate('() => window.__errors'))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

test('a scheduled 1v1 in its check-in window: step 1 "Checked in", the check-in clock and Check in as the one action, at 375 and 1440', function () {
    [$relay] = casualPlayRelay();

    try {
        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        $at = now()->addDay()->setTime(20, 0)->getTimestamp();
        $match = app(CasualChallenges::class)->challenge($anna, $bert, 'rocket-league', Platform::Pc, true, [$at], now()->addDay()->setTime(12, 0)->getTimestamp(), '');
        $match = app(CasualChallenges::class)->accept($match, $bert, $at, Platform::Pc, true);
        $this->travelTo(now()->setTimestamp($at)->subMinutes(5));

        $page = casualPlayPage($anna, route('matches.room', $match, false), 375, 812);
        casualPlayControl($page);
        // The browser counts with its own clock, not the travelled one of the test: the clock may read a day (h:mm:ss).
        BrowserWait::until($page, '() => document.querySelector("[data-test=casual-clock]")?.innerText.match(/^\\d+:\\d\\d(:\\d\\d)?$/) !== null', 5_000);

        $narrow = casualPlayGeometry($page, '[data-test=casual-steps]');
        $state = $page->evaluate('() => ({
            step: document.querySelector("[data-test=casual-current-step]").innerText,
            kind: document.querySelector("[data-test=casual-deadline]").dataset.kind,
            button: Math.round(document.querySelector("[data-test=casual-checkin]").getBoundingClientRect().height),
        })');
        casualPlayShot($page, 'casual-scheduled-steps-375', '[data-test=casual-steps]');
        $page->setViewportSize(1440, 900);
        $wide = casualPlayGeometry($page, '[data-test=casual-steps]');
        casualPlayShot($page, 'casual-scheduled-steps-1440', '[data-test=casual-steps]');

        $page->locator('[data-test=casual-checkin]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=casual-checkin]") === null && document.querySelector("[data-test=casual-steps]").innerText.includes("1 of 2 checked in")', 10_000);

        expect($state)->toBe(['step' => 'Step 1 of 5: Checked in', 'kind' => 'checkin', 'button' => 56])
            ->and($narrow)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($wide)->toMatchArray(['overflow' => 0, 'inWindow' => true, 'small' => [], 'outside' => [], 'clipped' => []])
            ->and($match->refresh()->readyAt(casualSideOf($match, $anna)))->not->toBeNull()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    } finally {
        $relay->stop(1);
    }
});

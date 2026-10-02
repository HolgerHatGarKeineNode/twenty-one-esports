<?php

use App\Enums\Platform;
use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\NotificationDm;
use App\Support\Series\CasualChallenges;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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
| Upcoming events and the room chat (2026-10-02)
|--------------------------------------------------------------------------
|
| A player with a casual 1v1 in its check-in window, a series in three days
| and a tournament tonight: home's "Your next match" card at the top and the
| match dock's chip, at 375 x 667 and 1440 x 900, in English and (375) in
| German. Measured: both visible, the card's button above whatever floats at
| the bottom, nothing overlapping, no sideways overflow.
|
| The room chat over a real relay (tests/Support/mini-relay.php) with 40
| messages: a fixed box that opens at the newest message, draws older ones
| at the top without moving the text on screen, and whose field the dock,
| the score bar and the tab bar never cover (bounding boxes).
|
| Console, uncaught errors, rejected promises and responses >= 400 are
| collected (BrowserConsole) and each "empty" has a positive control.
|
| UPCOMING_SHOTS=<dir> writes the screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** The fixed things at the bottom of the window that are visible now, as rects. */
const UPCOMING_FLOATERS = <<<'JS'
    () => ['[data-test=tab-bar]', '[data-test=dock-mobile-bar]', '[data-test=dock-bar-tab]', '[data-test=match-dock] > div:last-child', '[data-room-bar]']
        .flatMap((s) => [...document.querySelectorAll(s)])
        .filter((el) => el.checkVisibility() && el.getBoundingClientRect().height > 0)
        .map((el) => { const r = el.getBoundingClientRect(); return { name: el.dataset.test ?? (el.hasAttribute('data-room-bar') ? 'room-bar' : 'dock'), top: r.top, bottom: r.bottom, left: r.left, right: r.right }; })
    JS;

function upcomingPage(User $user, string $to, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && window.Alpine !== undefined', 10_000);

    return $page;
}

function upcomingShot(Page $page, string $name): void
{
    $dir = getenv('UPCOMING_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** The console is clean, and would not be: a thrown error and a broken image both land in the collector. */
function upcomingConsoleClean(Page $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');

    $page->evaluate('() => { setTimeout(() => { throw new Error("upcoming positive control"); }); const img = document.createElement("img"); img.src = "/upcoming-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('upcoming positive control')->toContain('upcoming-missing.png');
}

test('home\'s next-match card and the dock\'s chip show at 375 and 1440, clear of each other, with nothing overflowing', function () {
    $me = User::factory()->create(['name' => 'satoshi.b', 'locale' => 'en']);
    $lineup = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $me->id, 'name' => 'Laser Eyes', 'clantag' => 'LSR'])->id]);
    $later = SeriesMatch::factory()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => Lineup::factory()->ready()->create()->id, 'status' => SeriesStatus::Accepted, 'start_at' => now()->addDays(3)]);

    // A casual 1v1 in five minutes: its check-in window is open.
    $friend = User::factory()->create(['name' => 'hodlqueen']);
    $at = now()->addMinutes(5)->startOfMinute()->addMinute();
    $casual = app(CasualChallenges::class)->challenge($me, $friend, 'rocket-league', Platform::Pc, true, [$at->getTimestamp()], $at->getTimestamp() - 60, '');
    $casual = app(CasualChallenges::class)->accept($casual, $friend, $at->getTimestamp(), Platform::Pc, true);

    $tournament = openTournament(['name' => 'Blitz Night Kempten', 'starts_at' => now()->addDays(2)]);
    $tournament->forceFill(['starts_at' => now()->addHours(5), 'signup_closes_at' => now()->addHours(4)])->save();
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $me->id, 'name' => $me->displayName(), 'members' => [$me->id]]);

    foreach ([['en', 375, 667], ['en', 1440, 900], ['de', 375, 667]] as [$lang, $width, $height]) {
        $label = "{$lang} {$width}";
        $page = upcomingPage($me, route('home', ['lang' => $lang], false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=upcoming-card]") !== null', 10_000);

        $m = $page->evaluate('() => {
            const rect = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right }; };
            const chip = innerWidth >= 1024 ? rect("[data-dock-tab=\"series-'.$casual->number.'\"]") : rect("[data-test=dock-mobile-bar]");
            return {
                card: rect("[data-test=upcoming-card]"), open: rect("[data-test=upcoming-open]"), chip,
                state: document.querySelector("[data-test=upcoming-card] [data-test=upcoming-state]")?.innerText,
                pulse: document.querySelector("[data-test=upcoming-card] [data-test=upcoming-state]")?.classList.contains("animate-live"),
                widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            };
        }');
        $floaters = $page->evaluate(UPCOMING_FLOATERS);
        $floor = min($height, ...array_map(fn (array $f): float => $f['top'], $floaters));

        expect($m['card'])->not->toBeNull($label)
            ->and($m['chip'])->not->toBeNull($label.': dock chip')
            // The most urgent first: the check-in, pulsing, its button above the fold and above whatever floats.
            ->and($m['state'])->toBe($lang === 'de' ? 'Jetzt einchecken' : 'Check in now')
            ->and($m['pulse'])->toBeTrue()
            ->and($m['open']['bottom'])->toBeLessThanOrEqual($floor, $label.': button above the floating bars')
            ->and($m['open']['top'])->toBeGreaterThanOrEqual(0)
            // Card and chip never overlap.
            ->and($m['card']['bottom'] <= $m['chip']['top'] || $m['card']['top'] >= $m['chip']['bottom'] || $m['card']['right'] <= $m['chip']['left'] || $m['card']['left'] >= $m['chip']['right'])->toBeTrue($label.': card vs chip')
            ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow');

        // The countdown runs in the browser.
        $before = $page->evaluate('() => document.querySelector("[data-test=upcoming-card] [data-test=upcoming-countdown]").innerText');
        BrowserWait::until($page, '() => document.querySelector("[data-test=upcoming-card] [data-test=upcoming-countdown]").innerText !== '.json_encode($before), 3_000);

        upcomingShot($page, "upcoming-home-{$lang}-{$width}");

        // "+N more" opens the rest: the series in three days and the tournament tonight.
        $page->locator('[data-test=upcoming-card] [data-test=upcoming-more]')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=upcoming-card] [data-test=upcoming-row]")].filter((r) => r.checkVisibility()).length === 2', 3_000);
        expect($page->evaluate('() => [...document.querySelectorAll("[data-test=upcoming-card] [data-test=upcoming-row]")].map((r) => r.dataset.key)'))
            ->toBe(['tournament-'.$tournament->id, 'series-'.$later->number])
            ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($width);

        upcomingConsoleClean($page, $label);
    }
});

test('the room chat keeps a fixed height, opens at the newest message, loads older ones in place, and its field stays clear of the dock', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        $url = 'ws://127.0.0.1:'.$port;
        config(['esports.chat.relays' => [$url], 'esports.profile_relays' => [$url]]);

        $match = SeriesMatch::factory()->accepted()->create([
            'challenger_lineup_id' => Lineup::factory()->mode('1v1')->ready()->create()->id,
            'challenged_lineup_id' => Lineup::factory()->mode('1v1')->ready()->create()->id,
            'created_at' => now()->subHour(),
        ]);
        $anna = $match->challengerLineup->clan->owner;
        $bert = $match->challengedLineup->clan->owner;
        $bert->forceFill(['locale' => 'en'])->save();
        $annaKey = TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);

        // Another open room of Bert's, so the dock floats on this page too.
        SeriesMatch::factory()->create(['challenger_lineup_id' => $match->challenged_lineup_id, 'challenged_lineup_id' => Lineup::factory()->mode('1v1')->ready()->create()->id, 'status' => SeriesStatus::Accepted, 'start_at' => now()->addDay()]);

        // Forty messages from Anna over the last forty minutes, on the relay before the page opens.
        foreach (range(1, 40) as $n) {
            $wrap = (new NotificationDm($annaKey->secret))->build($bert->pubkey, "message {$n}", $match->number, now()->subMinutes(41 - $n)->getTimestamp())['wrap'];
            app(RelayPublisher::class)->publish(NostrEvent::fromSigned(SignedEvent::fromInput($wrap)), [$url]);
        }

        // Each pass adds one late message, so the second pass opens with one more.
        $total = 40;
        $newest = 'message 40';

        foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
            $label = "chat {$width}";
            $page = upcomingPage($bert, route('matches.room', $match, false), $width, $height);
            BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=room-chat]")).status === "live"', 10_000);
            BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=room-chat]")).messages.length === '.$total, 20_000);
            $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))');

            $box = $page->evaluate('() => {
                const chat = document.querySelector("[data-test=room-chat]");
                const list = chat.querySelector("[data-test=chat-messages]");
                const drawn = [...list.querySelectorAll("li[data-from]")];
                return {
                    height: chat.getBoundingClientRect().height, drawn: drawn.length, last: drawn.at(-1)?.innerText ?? "",
                    gap: list.scrollHeight - list.scrollTop - list.clientHeight, scrolls: list.scrollHeight > list.clientHeight,
                };
            }');

            // Fixed: the box does not grow with forty messages; it is at the bottom, the newest one in view.
            expect($box['height'])->toBeLessThanOrEqual(560.5, $label)
                ->and($box['height'])->toBeGreaterThanOrEqual(320, $label)
                ->and($box['drawn'])->toBe(30, $label.': the newest 30 drawn')
                ->and($box['last'])->toContain($newest)
                ->and($box['scrolls'])->toBeTrue()
                ->and($box['gap'])->toBeLessThanOrEqual(2, $label.': at the bottom');

            // To the top: ten older ones appear above, and the message that was on top stays where it was.
            $kept = $page->evaluate('() => {
                const list = document.querySelector("[data-test=room-chat] [data-test=chat-messages]");
                const first = list.querySelector("li[data-from]");
                list.scrollTop = 0;
                // Where the oldest drawn message sits once the box reached its top, before anything older is drawn.
                const before = first.getBoundingClientRect().top;
                return new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(() => resolve({
                    drawn: list.querySelectorAll("li[data-from]").length,
                    first: list.querySelector("li[data-from]").innerText,
                    moved: Math.abs(first.getBoundingClientRect().top - before),
                    scrolled: list.scrollTop,
                    height: document.querySelector("[data-test=room-chat]").getBoundingClientRect().height,
                }))), 100));
            }');

            expect($kept['drawn'])->toBe($total, $label)
                ->and($kept['first'])->toContain('message 1')
                // The ten older ones were drawn above it: the box scrolled down by as much, so it did not move.
                ->and($kept['moved'])->toBeLessThanOrEqual(1, $label.': no jump')
                ->and($kept['scrolled'])->toBeGreaterThan(100)
                ->and($kept['height'])->toBe($box['height'], $label.': the height did not change');

            // The field, with the whole box in view, against everything that floats at the bottom.
            $field = $page->evaluate('() => {
                document.querySelector("[data-test=room-chat]").scrollIntoView({ block: "end" });
                return new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => { const r = document.querySelector("#roomchat").getBoundingClientRect(); resolve({ top: r.top, bottom: r.bottom, left: r.left, right: r.right }); })));
            }');
            $floaters = $page->evaluate(UPCOMING_FLOATERS);
            $covering = array_values(array_filter($floaters, fn (array $f): bool => $f['top'] < $field['bottom'] && $f['bottom'] > $field['top'] && $f['left'] < $field['right'] && $f['right'] > $field['left']));

            fwrite(STDERR, "\n[upcoming] {$label}: ".json_encode(['box' => $box, 'kept' => $kept, 'field' => $field, 'floaters' => $floaters])."\n");

            expect($floaters)->not->toBeEmpty($label.': something floats (the dock at least)')
                ->and($covering)->toBe([], $label.': nothing covers the field')
                ->and($field['top'])->toBeGreaterThanOrEqual(0);

            // Scrolled up, a new message waits below behind the pill; the pill takes the box down.
            $page->evaluate('() => { const list = document.querySelector("[data-test=room-chat] [data-test=chat-messages]"); list.scrollTop = list.scrollHeight / 2; list.dispatchEvent(new Event("scroll")); }');
            BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=room-chat]")).atBottom === false', 3_000);
            $middle = $page->evaluate('() => document.querySelector("[data-test=room-chat] [data-test=chat-messages]").scrollTop');
            $wrap = (new NotificationDm($annaKey->secret))->build($bert->pubkey, "late message {$width}", $match->number, now()->getTimestamp())['wrap'];
            app(RelayPublisher::class)->publish(NostrEvent::fromSigned(SignedEvent::fromInput($wrap)), [$url]);
            BrowserWait::until($page, '() => document.querySelector("[data-test=chat-new-pill]").checkVisibility()', 10_000);

            expect($page->evaluate('() => document.querySelector("[data-test=chat-new-pill]").innerText'))->toContain('New messages')->toContain('(1)')
                ->and($page->evaluate('() => document.querySelector("[data-test=room-chat] [data-test=chat-messages]").scrollTop'))->toBe($middle);

            $page->locator('[data-test=chat-new-pill]')->click();
            BrowserWait::until($page, '() => { const l = document.querySelector("[data-test=room-chat] [data-test=chat-messages]"); return l.scrollHeight - l.scrollTop - l.clientHeight <= 2 && ! document.querySelector("[data-test=chat-new-pill]").checkVisibility(); }', 3_000);
            expect($page->evaluate('() => [...document.querySelectorAll("[data-test=room-chat] li[data-from]")].at(-1).innerText'))->toContain("late message {$width}");
            $total++;
            $newest = "late message {$width}";

            upcomingShot($page, "upcoming-room-chat-en-{$width}");
            upcomingConsoleClean($page, $label);
        }
    } finally {
        $relay->stop(1);
    }
});

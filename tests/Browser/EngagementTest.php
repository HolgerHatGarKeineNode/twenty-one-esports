<?php

use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Cosmetic;
use App\Models\PlacementReveal;
use App\Models\Rating;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Support\Engagement\Cosmetics;
use App\Support\Engagement\WeeklySlots;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TrustedFacts;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Engagement for the season start (P10)
|--------------------------------------------------------------------------
|
| Every page P10 added or changed, at 375 and 1440 px: the placement reveal
| (once per page load, then gone), weekly events and quests on home, weekly
| events in the chess lobby, the city ranking on the clans page, the invite
| frame on the player page, the live games page for a guest, and the admin
| page for weekly events with one slot added through the form (a Livewire
| roundtrip).
|
| Collected on every page: console.error, uncaught errors, rejected promises,
| every fetch/XHR >= 400 and the resource timing entries >= 400 (the pattern
| of tests/Browser/ClanEditTest.php, with its positive control below).
|
| P10_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const P10_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const P10_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const P10_NO_OVERFLOW = '() => document.documentElement.scrollWidth <= document.documentElement.clientWidth';

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
});

function p10Page(?User $user, string $to, int $width): Page
{
    // Log in on a page without the shell: every shell page claims a waiting placement reveal.
    $page = visit($user === null ? $to : route('testing.login', ['user' => $user, 'to' => route('robots', absolute: false)]))->page();
    $page->context()->addInitScript(P10_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);

    return $page;
}

function p10Shot(Page $page, string $name, bool $fullPage = true): void
{
    $dir = getenv('P10_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot($fullPage, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * Clean console, no response >= 400, nothing wider than the viewport.
 */
function p10Clean(Page $page, string $label): void
{
    $sizes = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    fwrite(STDERR, "\n[p10] {$label}: scrollWidth/clientWidth ".json_encode($sizes)."\n");

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(P10_BAD_RESPONSES))->toBe([])
        ->and($page->evaluate(P10_NO_OVERFLOW))->toBeTrue();
}

test('the P10 pages fit 375 and 1440 px with a clean console, and the placement reveal shows once', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);

    $player = User::factory()->create(['name' => 'lena.k', 'locale' => 'en']);
    $rival = User::factory()->create(['name' => 'pillpusher', 'locale' => 'en']);
    $admin = User::factory()->create(['name' => 'board', 'locale' => 'en']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // Weekly events, one running now.
    $started = now('Europe/Berlin')->subMinutes(30);
    WeeklySlot::factory()->create(['title' => 'Blitz night', 'weekday' => (int) $started->isoWeekday(), 'time' => $started->format('H:i')]);
    WeeklySlot::factory()->create(['title' => 'RL Sunday', 'game' => 'rocket-league', 'mode' => '3v3', 'weekday' => 7, 'time' => '18:00']);
    app(WeeklySlots::class)->schedule();

    // Clans in two meetup cities; a rated game earns their hashrate and a quest.
    $kempten = Clan::factory()->create(['owner_id' => $player->id, 'name' => 'Laser Eyes', 'clantag' => 'LSR', 'meetup_city' => 'Kempten']);
    $munich = Clan::factory()->create(['owner_id' => $rival->id, 'name' => 'Stack Sats', 'clantag' => 'STK', 'meetup_city' => 'München']);
    $rated = ChessGame::factory()->rated()->finished('1-0')->create([
        'white_id' => $player->id, 'black_id' => $rival->id,
        'clans_at_accept' => [$player->pubkey => $kempten->address(), $rival->pubkey => $munich->address()],
    ]);
    app(RatingService::class)->applyChessGame($rated);

    app(Cosmetics::class)->grant($player->id, Cosmetics::INVITE_FRAME, 'invite:1');

    // Live games for the guest.
    ChessGame::factory()->create(['white_id' => $player->id, 'black_id' => $rival->id]);
    ChessGame::factory()->daily()->create();

    // One placement per width, waiting when the width starts: Platinum I at 375 px, Champion III at 1440 px.
    $placements = [375 => ['chess', 'correspondence', 1120, 'platinum-1', 'rgb(94, 234, 212)'], 1440 => ['rocket-league', '1v1', 1310, 'champion-3', 'rgb(232, 121, 249)']];

    foreach ([375, 1440] as $width) {
        [$game, $mode, $value, $tier, $colour] = $placements[$width];
        $rating = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => $game, 'mode' => $mode, 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => $value, 'results' => 5]);
        PlacementReveal::query()->create(['user_id' => $player->id, 'rating_id' => $rating->id, 'game' => $game, 'mode' => $mode, 'rating' => $value, 'tier' => $tier]);

        // Home: the reveal first, then the page under it.
        $page = p10Page($player, route('home', absolute: false), $width);
        BrowserWait::until($page, '() => document.querySelector("[data-test=placement-reveal]") !== null', 10_000);
        // The cube fills with the tier colour once the animation ran.
        BrowserWait::until($page, '() => getComputedStyle(document.querySelector(".pr-shell")).fill === '.json_encode($colour), 5_000);
        $box = $page->evaluate('() => { const r = document.querySelector("[data-test=placement-reveal] .pr-card").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; }');
        expect($box[0])->toBeGreaterThanOrEqual(0)->and($box[1])->toBeLessThanOrEqual($width)
            // Modal: the match dock at the bottom stays under the scrim.
            ->and($page->evaluate('() => document.elementFromPoint(innerWidth - 40, innerHeight - 30)?.closest("[data-test=placement-reveal]") !== null'))->toBeTrue();
        // The dialog is fixed: the viewport is the picture.
        p10Shot($page, "placement-reveal-{$width}", fullPage: false);
        p10Clean($page, "home+reveal {$width}");

        $page->locator('[data-test=placement-close]')->click();
        BrowserWait::until($page, '() => getComputedStyle(document.querySelector("[data-test=placement-reveal]")).display === "none"', 5_000);

        expect($page->evaluate('() => document.querySelectorAll("[data-test=weekly-event]").length'))->toBe(app(WeeklySlots::class)->upcoming(4)->count())
            ->and($page->evaluate('() => document.querySelector("[data-test=quests]") !== null'))->toBeTrue();
        $page->evaluate('() => document.querySelector("[data-test=weekly-events]").scrollIntoView()');
        p10Shot($page, "home-weekly-quests-{$width}");
        p10Clean($page, "home {$width}");

        // The chess lobby, the clans page, the player page: no second reveal on any of them.
        foreach ([
            ['lobby', route('chess.lobby', absolute: false), '[data-test=weekly-events]'],
            ['clans', route('clans.index', absolute: false), '[data-test=city-ranking]'],
            ['player', route('players.show', $player->npub, absolute: false), '[data-test=invite-frame-chip]'],
        ] as [$name, $url, $selector]) {
            $page = p10Page($player, $url, $width);
            BrowserWait::until($page, '() => document.querySelector('.json_encode($selector).') !== null', 10_000);
            expect($page->evaluate('() => document.querySelector("[data-test=placement-reveal]")'))->toBeNull();
            $page->evaluate('() => document.querySelector('.json_encode($selector).').scrollIntoView()');
            p10Shot($page, "{$name}-{$width}");
            p10Clean($page, "{$name} {$width}");
        }

        // A guest watches: the live games page.
        $page = p10Page(null, route('games.index', ['lang' => 'en'], false), $width);
        BrowserWait::until($page, '() => document.querySelectorAll("[data-test=live-game]").length === 2', 10_000);
        expect($page->evaluate('() => document.querySelector("meta[name=presence-user]")'))->toBeNull();
        p10Shot($page, "live-games-{$width}");
        p10Clean($page, "live games {$width}");

        // The admin adds a weekly event through the form.
        $page = p10Page($admin, route('admin.events', absolute: false), $width);
        BrowserWait::until($page, '() => window.Livewire !== undefined && document.querySelector("[data-test=slot-form]") !== null', 10_000);
        $page->locator('[data-test=slot-title]')->fill("Friday Blitz {$width}");
        $page->locator('[data-test=slot-add]')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=slot-row]")].some((row) => row.textContent.includes("Friday Blitz '.$width.'"))', 10_000);
        p10Shot($page, "admin-events-{$width}");
        p10Clean($page, "admin events {$width}");
    }

    // Both reveals were shown once, and the invite frame is still the one grant.
    expect(PlacementReveal::query()->whereNull('shown_at')->count())->toBe(0)
        ->and(Cosmetic::query()->count())->toBe(1);
});

test('the P10 collector sees a thrown error and a missing asset (positive control)', function () {
    $page = p10Page(null, route('games.index', absolute: false), 1440);
    $page->evaluate('() => { const img = new Image(); img.src = "/__p10-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__p10-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(P10_BAD_RESPONSES)))->toMatch('#^404 http://\S+/__p10-missing\.png$#m');
});

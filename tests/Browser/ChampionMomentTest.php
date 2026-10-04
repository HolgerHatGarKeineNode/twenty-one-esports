<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\TournamentPayout;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The champion moment and the same-day appointments (2026-10-03)
|--------------------------------------------------------------------------
|
| A finished tournament opens on its champion: at 375 x 667 and 1440 x 900
| in English and 375 in German, the hero starts at the top, the champion's
| name and "Share the win" are in the first screen, nothing overflows
| sideways, the first view celebrates once (blocks fall) and a reload does
| not, and with reduced motion nothing moves. Home's "Your next event" card
| lists the second event of the same day without a click.
|
| Console, uncaught errors, rejected promises and responses >= 400 are
| collected (BrowserConsole); each "empty" has a positive control.
|
| CHAMPION_SHOTS=<dir> writes the screenshots there.
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

/** The page at `$to`, logged in as `$user` (a guest when null), with the console collector. */
function championPage(?User $user, string $to, int $width, int $height, bool $reducedMotion = false): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($user !== null) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
    }

    if ($reducedMotion) {
        $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);
        iterator_to_array(Client::instance()->execute($guid, 'emulateMedia', ['reducedMotion' => 'reduce']));
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && window.Alpine !== undefined', 10_000);

    return $page;
}

function championShot(Page $page, string $name): void
{
    $dir = getenv('CHAMPION_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** The console is clean, and would not be: a thrown error and a broken image both land in the collector. */
function championConsoleClean(Page $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');

    $page->evaluate('() => { setTimeout(() => { throw new Error("champion positive control"); }); const img = document.createElement("img"); img.src = "/champion-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('champion positive control')->toContain('champion-missing.png');
}

const CHAMPION_RECTS = <<<'JS'
    () => {
        const rect = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right }; };
        return {
            hero: rect('[data-test=champion-hero]'),
            page: rect('[data-test=tournament-show]'),
            name: rect('[data-test=champion-name]'),
            share: rect('[data-test=champion-share-button]') ?? rect('[data-test=share-post]'),
            rail: rect('[data-test=desk-rail]'),
            results: rect('[data-test=champion-results]'),
            podium: rect('[data-test=champion-podium]'),
            nameSize: parseFloat(getComputedStyle(document.querySelector('[data-test=champion-name]')).fontSize),
            heroWidth: document.querySelector('[data-test=champion-hero]').getBoundingClientRect().width,
            widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            celebrating: document.querySelector('[data-test=champion-hero]').classList.contains('is-celebrating'),
            hidden: [...document.querySelectorAll('[data-test=champion-hero] *')].filter((el) => el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === 'hidden' && el.closest('.cm-rain') === null).map((el) => (el.dataset.test ?? el.tagName) + ': ' + el.innerText.slice(0, 40)).slice(0, 5),
        };
    }
    JS;

test('a finished tournament opens on its champion at 375 and 1440, in English and German, in the first screen, celebrating once', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 8);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => 'Friday Blitz Cup', 'published_at' => now()])->save();
    foreach ($tournament->participants()->orderBy('seed')->get() as $i => $entry) {
        $name = ['aHeck13', 'satoshi_nakamura', 'Lia', 'Blockrunner', 'hodlqueen', 'Mempool Max', 'pleb21', 'Laser'][$i];
        $entry->forceFill(['name' => $name])->save();
        User::query()->whereKey($entry->user_id)->update(['name' => $name]);
    }
    $champion = app(TournamentChampion::class)->of($tournament->refresh());
    $winner = User::query()->findOrFail($champion->user_id);
    TournamentPayout::query()->create([
        'tournament_id' => $tournament->id, 'pubkey' => $winner->pubkey, 'name' => $champion->name, 'place' => 1, 'amount_sats' => 21000,
        'idempotency_key' => TournamentPayout::keyFor($tournament->id, $winner->pubkey, 1), 'status' => PayoutStatus::Paid,
    ]);

    foreach ([['en', 375, 667, null], ['en', 1440, 900, null], ['de', 375, 667, null], ['en', 1440, 900, $winner]] as [$lang, $width, $height, $viewer]) {
        $label = "{$lang} {$width}".($viewer ? ' winner' : '');
        $page = championPage($viewer, route('tournaments.show', ['tournament' => $tournament, 'lang' => $lang], false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=champion-hero]") !== null', 10_000);
        $m = $page->evaluate(CHAMPION_RECTS);
        championShot($page, "champion-first-{$lang}-{$width}".($viewer ? '-winner' : ''));

        expect($m['hero'])->not->toBeNull($label)
            // The first thing of the page, full width.
            ->and(abs($m['hero']['top'] - $m['page']['top']))->toBeLessThan(1, $label.': hero at the top')
            // A member of the tournament's desk (the winner) has the desk chat open beside the page from xl (34446af8, 22.5 rem and a 2 rem gap): the hero fills the rest.
            ->and(($m['rail'] !== null))->toBe($viewer !== null, $label.': the desk rail is there for the desk\'s members only')
            ->and($m['heroWidth'])->toBeGreaterThanOrEqual(($m['rail'] !== null && $width >= 1280 ? $m['rail']['left'] - 32 : $width) - 20, $label.': full width')
            // The name in display type and the way to share it in the first screen.
            ->and($m['nameSize'])->toBeGreaterThanOrEqual($width >= 1024 ? 72.0 : 40.0, $label.': name size')
            ->and($m['name']['bottom'])->toBeLessThanOrEqual($height, $label.': name above the fold')
            ->and($m['share'])->not->toBeNull($label.': share')
            ->and($m['share']['bottom'])->toBeLessThanOrEqual($height, $label.': share above the fold')
            ->and($m['hidden'])->toBe([], $label.': clipped text')
            ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow');

        if ($width >= 1024) {
            // Beside the champion on a wide screen, under it on a phone.
            expect($m['podium']['top'])->toBeLessThan($m['share']['bottom'], $label.': podium beside');
        }

        // The first view of this browser context celebrates; a reload does not.
        expect($m['celebrating'])->toBeTrue($label.': celebrates on the first view');
        BrowserWait::until($page, '() => ! document.querySelector("[data-test=champion-hero]").classList.contains("is-celebrating")', 6_000);
        championShot($page, "champion-{$lang}-{$width}".($viewer ? '-winner' : ''));

        if ($width < 1024 && getenv('CHAMPION_SHOTS')) {
            // The phone's second screen: the podium and the path under the champion.
            $page->evaluate('() => document.querySelector("[data-test=champion-podium]").scrollIntoView({ block: "start" })');
            championShot($page, "champion-{$lang}-{$width}-podium");
        }

        $page->reload();
        BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.querySelector("[data-test=champion-hero]")?._x_dataStack !== undefined', 10_000);
        expect($page->evaluate('() => document.querySelector("[data-test=champion-hero]").classList.contains("is-celebrating")'))->toBeFalse($label.': calm after a reload');

        if ($viewer !== null) {
            expect($page->evaluate('() => document.querySelector("[data-test=champion-you]")?.innerText'))->toContain('You won!');
        }

        championConsoleClean($page, $label);
    }

    // Reduced motion: no falling blocks, no sheen, even on the first view.
    $still = championPage(null, route('tournaments.show', $tournament, false), 1440, 900, reducedMotion: true);
    BrowserWait::until($still, '() => document.querySelector("[data-test=champion-hero]")?._x_dataStack !== undefined', 10_000);
    expect($still->evaluate('() => [document.querySelector("[data-test=champion-hero]").classList.contains("is-celebrating"), getComputedStyle(document.querySelector(".cm-rain")).display]'))->toBe([false, 'none']);
});

test('home\'s next-event card shows every event of the first one\'s day without a click; later days behind "+N more"', function () {
    $me = User::factory()->create(['name' => 'satoshi.b', 'locale' => 'en', 'timezone' => 'Europe/Berlin']);
    // Tomorrow in Berlin: the test server runs on the real clock, and tomorrow has its whole evening ahead at any hour.
    $day = now()->setTimezone('Europe/Berlin')->addDay();
    $signUp = function (string $name, $start) use ($me) {
        $tournament = openTournament(['name' => $name, 'starts_at' => now()->addDays(2)]);
        $tournament->forceFill(['starts_at' => $start, 'signup_closes_at' => min($start, now()->addHour())])->save();
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $me->id, 'name' => $me->displayName(), 'members' => [$me->id]]);

        return $tournament;
    };
    $first = $signUp('Blitz Night Kempten', $day->copy()->setTime(18, 0)->utc());
    $second = $signUp('Rapid Cup Leipzig', $day->copy()->setTime(21, 0)->utc());
    $later = $signUp('Sunday Open', $day->copy()->addDays(3)->setTime(15, 0)->utc());

    foreach ([['en', 375, 667], ['en', 1440, 900], ['de', 375, 667]] as [$lang, $width, $height]) {
        $label = "{$lang} {$width}";
        $page = championPage($me, route('home', ['lang' => $lang], false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=upcoming-card]") !== null', 10_000);
        $visible = '() => [...document.querySelectorAll("[data-test=upcoming-card] [data-test=upcoming-row]")].filter((r) => r.checkVisibility()).map((r) => r.dataset.key)';
        $m = $page->evaluate('() => { const r = document.querySelector("[data-test=upcoming-same-day]").getBoundingClientRect(); return { sameDay: { top: r.top, bottom: r.bottom }, widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth], more: document.querySelector("[data-test=upcoming-card] [data-test=upcoming-more]").innerText.trim() }; }');

        expect($page->evaluate($visible))->toBe(['tournament-'.$second->id], $label.': the same day without a click')
            ->and($m['more'])->toBe($lang === 'de' ? '+1 weitere' : '+1 more')
            ->and($m['sameDay']['bottom'])->toBeLessThanOrEqual($height, $label.': same day in the first screen')
            ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow')
            ->and($page->evaluate('() => document.querySelector("[data-test=upcoming-card] [data-key=tournament-'.$first->id.']") !== null'))->toBeTrue();

        championShot($page, "upcoming-same-day-{$lang}-{$width}");

        $page->locator('[data-test=upcoming-card] [data-test=upcoming-more]')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=upcoming-card] [data-test=upcoming-row]")].filter((r) => r.checkVisibility()).length === 2', 3_000);
        expect($page->evaluate($visible))->toBe(['tournament-'.$second->id, 'tournament-'.$later->id], $label.': later behind the toggle');

        championConsoleClean($page, $label);
    }
});

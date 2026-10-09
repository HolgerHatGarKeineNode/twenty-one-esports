<?php

use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongMatches;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A live Proof of Pong match in two browsers over Reverb (plan "Proof of Pong", P2)
|--------------------------------------------------------------------------
|
| Two players, each in their own context, one on a phone held upright (390x844), one on a desktop (1440x900). Each
| page's paddle is played by a bot (`pong-settings` autoplay) and the server's clock runs twelve times as fast
| (`esports.pong.live_speed`), so the match is the one PongGame::bots() plays for the seed and both levels: both
| pages must end with exactly that score, and so must the server's PongMatch. A page that goes away for a few seconds
| pauses the match on the other page and plays on when it is back. The console and the answers stay clean on both
| pages, proved by a positive control. Headless Chromium has no WebGL: this measures the 2D arena.
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

    PongOn::play();
    // Twelve times as fast: the referee's clock and both pages. A page then reports twelve times as often as in
    // play, so the limit per player is raised with it; a player gone counts after 1.5 s instead of 5.
    config(['esports.pong.live_speed' => 12, 'esports.pong.reports_per_minute' => 12 * 600, 'esports.pong.away_seconds' => 1.5]);
});

/**
 * A player's match page, its paddle played by a bot of `$autoplay`.
 */
function pongLivePage(User $user, PongMatch $match, int $width, int $height, int $autoplay): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("pong-settings", '.json_encode((string) json_encode(['autoplay' => $autoplay])).'); } catch (e) {}');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('pong.match', $match, false)));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1" && document.body.dataset.live === "1"', 15_000);

    return $page;
}

/**
 * @return list<string>
 */
function pongLiveErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/** A picture for the report, only when PONG_SHOTS names a directory. */
function pongLiveShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Where the field lies and whether the page overflows. */
const PONG_LIVE_FIT = <<<'JS'
() => {
    const r = document.querySelector('[data-test=pong-field]').getBoundingClientRect();
    return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), width: innerWidth, height: innerHeight,
        scrollY: document.documentElement.scrollHeight - innerHeight, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
}
JS;

/**
 * A match of two new players with seed `$seed`, nobody's page open yet.
 *
 * @return array{PongMatch, User, User}
 */
function pongLiveMatch(int $seed): array
{
    [$left, $right] = User::factory()->count(2)->create();
    $match = app(PongMatches::class)->create($left, $right);
    $match->forceFill(['seed' => $seed])->save();

    return [$match, $left, $right];
}

test('two players play live to the end over Reverb: both pages and the server end with PongGame::bots()\' score, and the console stays clean', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    // Seed 2, levels 3 and 2: 21:12 over 32 rallies, rally 21 a Pizza Day (two balls at once).
    $expected = PongGame::bots(2, [3, 2]);
    expect($expected['score'])->toBe([21, 12])
        ->and($expected['events'])->toBe([[21, 'pizza']]);

    [$match] = pongLiveMatch(2);
    $phone = pongLivePage($match->left, $match, 390, 844, 3);
    $desktop = pongLivePage($match->right, $match, 1440, 900, 2);

    // Both there: the match starts; the phone plays upright, the desktop lying, each field inside its window.
    BrowserWait::until($phone, '() => window.pongLive.state().status === "active"', 10_000);
    BrowserWait::until($desktop, '() => window.pongLive.state().status === "active"', 10_000);
    foreach ([[$phone, 390, 844, true], [$desktop, 1440, 900, false]] as [$page, $width, $height, $portrait]) {
        $fit = $page->evaluate(PONG_LIVE_FIT);
        expect($page->evaluate('() => window.pongLive.state().portrait'))->toBe($portrait)
            ->and($fit['top'])->toBeGreaterThanOrEqual(56)
            ->and($fit['left'])->toBeGreaterThanOrEqual(0)
            ->and($fit['right'])->toBeLessThanOrEqual($width)
            ->and($fit['bottom'])->toBeLessThanOrEqual($height)
            ->and($fit['scrollY'])->toBeLessThanOrEqual(0)
            ->and($fit['overflow'])->toBe(0);
    }
    expect($phone->evaluate('() => window.pongLive.state().me'))->toBe(0)
        ->and($desktop->evaluate('() => window.pongLive.state().me'))->toBe(1);

    BrowserWait::until($phone, '() => window.pongLive.state().rally >= 3', 30_000);
    pongLiveShot($phone, 'pong-live-390-play');
    pongLiveShot($desktop, 'pong-live-1440-play');

    BrowserWait::until($phone, '() => window.pongLive.state().status === "finished"', 150_000);
    BrowserWait::until($desktop, '() => window.pongLive.state().status === "finished"', 15_000);
    pongLiveShot($phone, 'pong-live-390-end');
    pongLiveShot($desktop, 'pong-live-1440-end');
    $match->refresh();

    expect($match->status)->toBe(PongMatchStatus::Finished)
        ->and($match->score())->toBe($expected['score'])
        ->and($phone->evaluate('() => window.pongLive.state().score'))->toBe($expected['score'])
        ->and($desktop->evaluate('() => window.pongLive.state().score'))->toBe($expected['score'])
        // Each page shows its own points first: the phone plays left, the desktop right.
        ->and($phone->evaluate('() => document.querySelector("[data-test=pong-end-score]").textContent'))->toBe('21 : 12')
        ->and($desktop->evaluate('() => document.querySelector("[data-test=pong-end-score]").textContent'))->toBe('12 : 21')
        ->and($phone->evaluate('() => document.body.dataset.result'))->toBe('win')
        ->and($desktop->evaluate('() => document.body.dataset.result'))->toBe('loss')
        // The rating change of a rated match, on the end card.
        ->and($phone->evaluate('() => document.querySelector("[data-test=pong-end-rating]").textContent'))->toContain('1000')
        ->and($match->left_rating_after)->toBeGreaterThan(1000)
        ->and($match->right_rating_after)->toBeLessThan(1000)
        // Every contact was decided by a report; none ran out of time.
        ->and(collect($match->log)->where(6, 'timeout')->count())->toBe(0)
        ->and(pongLiveErrors($phone))->toBe([])
        ->and(pongLiveErrors($desktop))->toBe([]);

    // Positive control: the collector sees a thrown error and a refused answer on this page.
    $phone->evaluate('() => { setTimeout(() => { throw new Error("pong live positive control"); }); fetch("/proof-of-pong/m/01JZZZZZZZZZZZZZZZZZZZZZZZ"); }');
    BrowserWait::until($phone, '() => window.__errors.some((e) => e.includes("pong live positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
});

test('a player whose page goes away for a few seconds pauses the match on the other page, and back within 30 seconds it plays on', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    [$match] = pongLiveMatch(13);
    $phone = pongLivePage($match->left, $match, 390, 844, 3);
    $desktop = pongLivePage($match->right, $match, 1440, 900, 2);
    BrowserWait::until($phone, '() => window.pongLive.state().rally >= 2', 30_000);

    // The desktop's page goes away: no heartbeat, no presence. The phone sees its opponent gone and the match paused.
    $desktop->goto(ComputeUrl::from(BrowserLogin::LANDING));
    BrowserWait::until($phone, '() => window.pongLive.state().away === 1 && !document.querySelector("[data-test=pong-away]").hidden', 10_000);
    $paused = $phone->evaluate('() => window.pongLive.state()');
    pongLiveShot($phone, 'pong-live-390-away');
    usleep(1_000_000);
    expect($phone->evaluate('() => window.pongLive.state().tick'))->toBe($paused['tick'])
        ->and($match->refresh()->status)->toBe(PongMatchStatus::Active);

    // Back after a few seconds: the match goes on from where it stood, on both pages.
    $desktop->goto(ComputeUrl::from(route('pong.match', $match, false)));
    BrowserWait::until($desktop, '() => document.body.dataset.ready === "1" && window.pongLive.state().status === "active"', 15_000);
    BrowserWait::until($phone, '() => window.pongLive.state().away === null && document.querySelector("[data-test=pong-away]").hidden', 10_000);
    BrowserWait::until($phone, '() => window.pongLive.state().rally > '.$paused['rally'], 30_000);

    expect($match->refresh()->status)->toBe(PongMatchStatus::Active)
        ->and($match->end_reason)->toBeNull()
        ->and(pongLiveErrors($phone))->toBe([])
        ->and(pongLiveErrors($desktop))->toBe([]);

    // The match goes on to its end on both pages, with the server's score.
    BrowserWait::until($phone, '() => window.pongLive.state().status === "finished"', 150_000);
    BrowserWait::until($desktop, '() => window.pongLive.state().status === "finished"', 15_000);
    $match->refresh();

    expect($match->status)->toBe(PongMatchStatus::Finished)
        ->and($phone->evaluate('() => window.pongLive.state().score'))->toBe($match->score())
        ->and($desktop->evaluate('() => window.pongLive.state().score'))->toBe($match->score())
        ->and(max($match->score()))->toBeGreaterThanOrEqual(21)
        ->and(pongLiveErrors($phone))->toBe([])
        ->and(pongLiveErrors($desktop))->toBe([]);
});

test('an invite from the online list, accepted, puts both players on the full-screen match page; the lobby stays clean', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $lobby = function (User $user, int $width, int $height): Page {
        $page = visit(BrowserLogin::url($user))->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('pong.index', absolute: false)));
        BrowserWait::until($page, '() => document.readyState === "complete" && !!document.querySelector("[data-test=pong-lobby]") && !!window.Livewire', 10_000);

        return $page;
    };

    // Both in the lobby; Bert looks for a match, and Anna sees him online, tagged, with an Invite. (Anna joins first:
    // the online list takes a member's "looking" from their join and from the switch's push, so a player who joins
    // after the switch was turned on sees it only at the other's next join.)
    $inviter = $lobby($anna, 1440, 900);
    $invitee = $lobby($bert, 390, 844);
    BrowserWait::until($inviter, '() => document.querySelectorAll("[data-test=online-player]").length === 1', 15_000);
    $invitee->locator('[data-test=looking-toggle]')->click();
    BrowserWait::until($invitee, '() => document.querySelector("[data-test=looking-state]").textContent.trim() === "On"', 8_000);
    BrowserWait::until($inviter, '() => !!document.querySelector("[data-test=online-player] [data-test=invite]")', 15_000);
    pongLiveShot($inviter, 'pong-lobby-1440-online');
    $inviter->locator('[data-test=online-player] [data-test=invite]')->click();
    BrowserWait::until($inviter, '() => !!document.querySelector("[data-test=invited]")', 8_000);

    // Bert's lobby hears of it and offers Accept; Accept opens the match in a new tab.
    BrowserWait::until($invitee, '() => !!document.querySelector("[data-test=pong-incoming-invite]")', 8_000);
    pongLiveShot($invitee, 'pong-lobby-390-invite');
    $invitee->locator('[data-test=pong-accept-invite]')->click();

    // Anna's lobby hears that the match started: it opens it (a new tab where the browser lets a push open one,
    // else this one) and shows it as running.
    BrowserWait::until($inviter, '() => location.pathname.startsWith("/proof-of-pong/m/") || !!document.querySelector("[data-test=pong-active-match]")', 10_000);
    $match = PongMatch::query()->sole();
    BrowserWait::until($invitee, '() => !!document.querySelector("[data-test=pong-active-match]")', 8_000);

    $errors = [...pongLiveErrors($inviter), ...pongLiveErrors($invitee)];

    // Both pages of the match are open: it is under way (it starts only once both players' pages are there).
    $onMatch = $inviter->evaluate('() => location.pathname.startsWith("/proof-of-pong/m/")');
    if (! $onMatch) {
        $inviter->goto(ComputeUrl::from(route('pong.match', $match, false)));
    }
    BrowserWait::until($inviter, '() => document.body.dataset.ready === "1" && window.pongLive.state().status === "active"', 15_000);

    expect($errors)->toBe([])
        ->and([$match->left_id, $match->right_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($inviter->evaluate('() => window.pongLive.state().me'))->toBe($match->sideOf($anna))
        ->and(pongLiveErrors($inviter))->toBe([]);
});

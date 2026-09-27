<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Invite a friend by link" at the top of the pages (engagement placement)
|--------------------------------------------------------------------------
|
| /chess at 375 x 667 and 1440 x 900: the module's box lies inside the first
| viewport, next to "Find opponent", and making a link from there lands on
| its share page with the link shown. The series game page (a captain's join
| link) and a finished game (play again) at both widths.
|
| Collected on every page: console.error, uncaught errors, rejected promises,
| every fetch/XHR >= 400 and the resource timing entries >= 400, with its
| positive control below.
|
| INVITE_SHOTS=<dir> additionally writes the English screenshots there.
|
*/

const INVITE_COLLECTOR = <<<'JS'
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

const INVITE_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

const INVITE_BOX = <<<'JS'
    (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), vh: innerHeight, vw: innerWidth }; }
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
});

function invitePage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? $to : route('testing.login', ['user' => $user, 'to' => route('robots', absolute: false)]))->page();
    $page->context()->addInitScript(INVITE_COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined', 10_000);

    return $page;
}

function inviteShot(Page $page, string $name): void
{
    $dir = getenv('INVITE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * The module's box, measured and written down, and asserted inside the first viewport.
 *
 * @return array{top: int, bottom: int, left: int, right: int, vh: int, vw: int}
 */
function inviteInFirstViewport(Page $page, string $label): array
{
    $box = $page->evaluate(INVITE_BOX, '[data-test=invite-module]');
    fwrite(STDERR, "\n[invite] {$label}: ".json_encode($box)."\n");

    expect($box['top'])->toBeGreaterThanOrEqual(0)
        ->and($box['bottom'])->toBeLessThanOrEqual($box['vh'])
        ->and($box['left'])->toBeGreaterThanOrEqual(0)
        ->and($box['right'])->toBeLessThanOrEqual($box['vw']);

    return $box;
}

function inviteClean(Page $page, string $label): void
{
    $sizes = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');
    fwrite(STDERR, "\n[invite] {$label}: scrollWidth/clientWidth ".json_encode($sizes)."\n");

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(INVITE_BAD_RESPONSES))->toBe([])
        ->and($sizes[0])->toBeLessThanOrEqual($sizes[1]);
}

test('the invite sits in the first viewport of /chess at 375 and 1440 px and makes a link from there', function () {
    $player = User::factory()->create(['name' => 'lena.k', 'locale' => 'en']);
    $rival = User::factory()->create(['name' => 'pillpusher', 'locale' => 'en']);
    $captain = Clan::factory()->create(['name' => 'Laser Eyes', 'clantag' => 'LSR'])->owner;
    $captain->update(['locale' => 'en', 'name' => 'satoshi.b']);
    $finished = ChessGame::factory()->finished('1-0')->create(['white_id' => $player->id, 'black_id' => $rival->id]);

    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        $page = invitePage($player, route('chess.lobby', absolute: false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-module]") !== null', 10_000);
        inviteInFirstViewport($page, "chess invite {$width}x{$height}");
        $find = $page->evaluate(INVITE_BOX, '[data-test=find-opponent-button]');
        fwrite(STDERR, "\n[invite] chess find-opponent {$width}x{$height}: ".json_encode($find)."\n");
        expect($find['bottom'])->toBeLessThanOrEqual($height);
        inviteShot($page, "chess-{$width}");

        // The options open inline, and the link is made from the top of the lobby.
        $page->locator('[data-test=invite-options-toggle]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-options]").checkVisibility()', 5_000);
        $page->locator('[data-test=invite-uses-several]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-uses-several]").getAttribute("aria-checked") === "true"', 5_000);
        inviteShot($page, "chess-options-{$width}");
        inviteClean($page, "chess {$width}");

        $page->locator('[data-test=invite-create]')->click();
        BrowserWait::until($page, '() => location.pathname.startsWith("/i/") && document.querySelector("[data-test=invite-url]") !== null', 10_000);
        $link = InviteLink::query()->latest('id')->firstOrFail();
        expect($page->evaluate('() => document.querySelector("[data-test=invite-url]").value'))->toBe($link->url())
            ->and($link->max_uses)->toBeNull()
            ->and($link->inviter_id)->toBe($player->id);
        inviteClean($page, "invite landing {$width}");

        // A series game page: the captain's join link, at the top.
        $page = invitePage($captain, route('games.rocket-league', absolute: false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=invite-module][data-state=clan]") !== null', 10_000);
        inviteInFirstViewport($page, "rocket league invite {$width}x{$height}");
        inviteShot($page, "rocket-league-{$width}");
        inviteClean($page, "rocket league {$width}");

        // A finished game: play again, and the invite under it.
        $page = invitePage($player, route('games.show', $finished, absolute: false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=play-again]") !== null', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=play-again]").scrollIntoView({ block: "start" })');
        inviteShot($page, "game-done-{$width}");
        inviteClean($page, "game done {$width}");
    }

    // A guest sees the login state, not a broken button.
    $page = invitePage(null, route('chess.lobby', ['lang' => 'en'], false), 375, 667);
    BrowserWait::until($page, '() => document.querySelector("[data-test=invite-module][data-state=guest]") !== null', 10_000);
    inviteInFirstViewport($page, 'chess guest 375x667');
    expect($page->evaluate('() => document.querySelector("[data-test=invite-login]").getAttribute("href")'))->toEndWith('/login');
    inviteShot($page, 'chess-guest-375');
    inviteClean($page, 'chess guest 375');
});

test('the invite collector sees a thrown error and a missing asset (positive control)', function () {
    $page = invitePage(null, route('chess.lobby', absolute: false), 1440, 900);
    $page->evaluate('() => { const img = new Image(); img.src = "/__invite-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__invite-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(INVITE_BAD_RESPONSES)))->toMatch('#^404 http://\S+/__invite-missing\.png$#m');
});

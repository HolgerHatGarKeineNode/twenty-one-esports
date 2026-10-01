<?php

use App\Models\InviteLink;
use App\Models\StackerRun;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The invite follows the game the player is in
|--------------------------------------------------------------------------
|
| In Blockfill (its page opened last) "invite" on /me is a "beat my time"
| challenge; "Other game" opens the picker with Blockfill picked; the link
| lands on the Blockfill landing with the time, for the inviter and for a
| friend. In nine men's morris the picker has morris picked and the link
| shows morris, never the chess board. en and de, 375 x 667 and 1440 x 900:
| no horizontal overflow, and nothing in the console or the network
| (BrowserConsole), with its positive control below.
|
| INVITE_CONTEXT_SHOTS=<dir> additionally writes the screenshots there.
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

    BlockfillOn::play();
    NineMensMorrisOn::play();
});

function inviteContextPage(User $user, string $locale, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));

    return $page;
}

function inviteContextGo(Page $page, string $to, string $ready): void
{
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.querySelector('.json_encode($ready).') !== null', 10_000);
}

function inviteContextClean(Page $page, string $label): void
{
    $widths = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "\n[invite-context] {$label}: scrollWidth/clientWidth ".json_encode($widths)."\n");

    expect($page->evaluate('() => window.__errors'))->toBe([], $label)
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label)
        ->and($widths[0])->toBeLessThanOrEqual($widths[1], "{$label}: horizontal overflow");
}

function inviteContextShot(Page $page, string $name): void
{
    $dir = getenv('INVITE_CONTEXT_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the invite follows Blockfill and nine men\'s morris through module, picker and landing, en and de at 375 and 1440', function (string $locale) {
    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        $me = User::factory()->create(['name' => 'satsjaeger'.$width, 'locale' => $locale]);
        $friend = User::factory()->create(['name' => 'lena.k'.$width, 'locale' => $locale]);
        StackerRun::factory()->for($me)->verified(5000)->create();
        $tag = "{$locale}-{$width}";

        // In Blockfill: the module on /me is the "beat my time" challenge.
        $page = inviteContextPage($me, $locale, $width, $height);
        inviteContextGo($page, route('stacker.play', absolute: false), '[data-test=context-bar], [data-test=tab-bar]');
        inviteContextGo($page, route('dashboard', absolute: false), '[data-test=invite-module]');
        expect($page->evaluate('() => document.querySelector("[data-test=invite-module]").dataset.state'))->toBe('score')
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-module]").dataset.game'))->toBe('blockfill');
        $page->evaluate('() => document.querySelector("[data-test=invite-module]").scrollIntoView({ block: "center" })');
        inviteContextShot($page, "me-module-{$tag}");
        inviteContextClean($page, "me {$tag}");

        // "Other game": the picker, Blockfill picked, the time shown, one button.
        $page->locator('[data-test=invite-other-game]')->click();
        BrowserWait::until($page, '() => location.pathname === "/invite" && document.querySelector("[data-test=invite-picker]") !== null', 10_000);
        expect($page->evaluate('() => document.querySelector("[data-test=invite-picker]").dataset.selected'))->toBe('blockfill')
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-best]").textContent'))->toContain('1:23.333');
        inviteContextShot($page, "picker-blockfill-{$tag}");
        inviteContextClean($page, "picker blockfill {$tag}");

        $page->locator('[data-test=invite-create]')->click();
        BrowserWait::until($page, '() => location.pathname.startsWith("/i/") && document.querySelector("[data-test=invite-share]") !== null', 10_000);
        $link = InviteLink::query()->latest('id')->firstOrFail();
        expect($page->evaluate('() => document.querySelector("[data-test=invite-landing]").dataset.kind'))->toBe('score')
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-time]").textContent.trim()'))->toBe('1:23.333')
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-stage] [data-game-cover]")?.dataset.gameCover'))->toBe('blockfill');
        inviteContextShot($page, "landing-blockfill-own-{$tag}");
        inviteContextClean($page, "landing blockfill own {$tag}");

        // The friend: one button into Blockfill.
        $friendPage = inviteContextPage($friend, $locale, $width, $height);
        inviteContextGo($friendPage, route('invites.link', $link, false), '[data-test=invite-play]');
        expect($friendPage->evaluate('() => new URL(document.querySelector("[data-test=invite-play]").href).pathname'))->toBe('/blockfill');
        inviteContextShot($friendPage, "landing-blockfill-friend-{$tag}");
        inviteContextClean($friendPage, "landing blockfill friend {$tag}");

        // In nine men's morris: the picker has morris picked, the link is a morris invite.
        inviteContextGo($page, route('board.lobby', 'nine-mens-morris', false), '[data-test=context-bar], [data-test=tab-bar]');
        inviteContextGo($page, route('invites.create', absolute: false), '[data-test=invite-picker]');
        expect($page->evaluate('() => document.querySelector("[data-test=invite-picker]").dataset.selected'))->toBe('nine-mens-morris');
        inviteContextShot($page, "picker-morris-{$tag}");
        inviteContextClean($page, "picker morris {$tag}");

        $page->locator('[data-test=invite-create]')->click();
        BrowserWait::until($page, '() => location.pathname.startsWith("/i/") && document.querySelector("[data-test=invite-share]") !== null', 10_000);
        $morris = InviteLink::query()->latest('id')->firstOrFail();
        expect($morris->option('game'))->toBe('nine-mens-morris')
            ->and($page->evaluate('() => document.querySelector("[data-test=invite-stage] [data-game-cover]")?.dataset.gameCover'))->toBe('nine-mens-morris');

        inviteContextGo($friendPage, route('invites.link', $morris, false), '[data-test=accept-invite]');
        inviteContextShot($friendPage, "landing-morris-friend-{$tag}");
        inviteContextClean($friendPage, "landing morris friend {$tag}");

        // The link previews (one width is enough: the card is a fixed-size PNG).
        if ($width === 1440) {
            foreach (['blockfill' => $link, 'morris' => $morris] as $name => $card) {
                $friendPage->goto(ComputeUrl::from(route('invites.card', ['code' => $card->code, 'format' => 'wide'], false)));
                inviteContextShot($friendPage, "card-{$name}-{$locale}");
            }
        }
    }
})->with(['en', 'de']);

test('the invite context collector sees a thrown error and a missing asset (positive control)', function () {
    $page = inviteContextPage(User::factory()->create(['locale' => 'en']), 'en', 1440, 900);
    inviteContextGo($page, route('invites.create', absolute: false), '[data-test=invite-picker]');
    $page->evaluate('() => { const img = new Image(); img.src = "/__invite-context-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__invite-context-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toMatch('#^404 http://\S+/__invite-context-missing\.png$#m');
});

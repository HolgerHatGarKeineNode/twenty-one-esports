<?php

use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Clan roster and lineup builder (P4b)
|--------------------------------------------------------------------------
|
| The founder (375 px) invites a player by npub into the roster, the player
| (1440 px) confirms on the invite page, and the founder then builds the 2v2
| from the roster with the per-member seat pickers. Both confirmations go
| through the stubbed window.nostr (TestSigner::browserStub), so the picks
| travel with the Livewire call exactly as a real browser sends them.
| Same session/auth reset as tests/Browser/ChatAndDailyTest.php.
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
});

const P4B_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    JS;

function rosterPage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(P4B_COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

test('the founder invites into the roster, the player confirms, and the founder builds a lineup from the roster', function () {
    [$owner, $player] = User::factory()->count(2)->create();
    $ownerSigner = TestSigner::forBrowser($owner);
    TestSigner::forBrowser($player);
    $service = app(ClanService::class);
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $ownerSigner->signTemplates($service->prepareCreate($owner, $draft)));

    $manage = route('clans.manage', $clan, false);
    $founder = rosterPage($owner, $manage, 375);
    BrowserWait::until($founder, '() => document.getElementById("invite-player") !== null && window.Alpine !== undefined', 10_000);
    // The player picker: a pasted npub is the one suggestion, highlighted; Enter picks it.
    $founder->locator('#invite-player')->type($player->fresh()->npub);
    BrowserWait::until($founder, '() => document.getElementById("invite-player").getAttribute("aria-activedescendant") === "invite-player-opt-0"', 10_000);
    $founder->locator('#invite-player')->press('Enter');
    BrowserWait::until($founder, '() => document.querySelector("[data-test=picker-chip]")?.offsetParent !== null', 5_000);
    $founder->locator('[data-test=send-invite]')->click();
    BrowserWait::until($founder, '() => document.querySelector("[data-test=invite-link]") !== null', 10_000);

    $invite = ClanInvite::query()->sole();
    expect($invite->invitee_id)->toBe($player->id);

    $invitee = rosterPage($player, route('invites.show', $invite, false), 1440);
    BrowserWait::until($invitee, '() => document.querySelector("[data-test=accept-invite]") !== null', 10_000);
    $invitee->locator('[data-test=accept-invite]')->click();
    BrowserWait::until($invitee, '() => location.pathname === '.json_encode(route('clans.show', $clan, false)), 10_000);

    expect($invite->refresh()->status)->toBe(InviteStatus::Accepted)
        ->and($player->fresh()->clanMember?->clan_id)->toBe($clan->id);

    $founder->reload();
    BrowserWait::until($founder, '() => document.querySelector("[data-test=edit-lineup-2v2]") !== null', 10_000);
    $founder->locator('[data-test=edit-lineup-2v2]')->click();
    BrowserWait::until($founder, '() => document.querySelector("[data-test=pick-'.$player->id.']") !== null', 10_000);
    $founder->locator('[data-test=pick-'.$owner->id.']')->selectOption('captain');
    $founder->locator('[data-test=pick-'.$player->id.']')->selectOption('player');
    $founder->locator('[data-test=confirm-lineup]')->click();
    BrowserWait::until($founder, '() => document.querySelector("[data-test=lineup-status-2v2]")?.innerText.includes("ready, 2 of 2")', 10_000);

    expect(Lineup::query()->where(['clan_id' => $clan->id, 'mode' => '2v2'])->sole()->seats()->pluck('role', 'user_id')->all())
        ->toBe([$owner->id => LineupRole::Captain, $player->id => LineupRole::Player])
        ->and($founder->evaluate('() => window.__errors'))->toBe([])
        ->and($invitee->evaluate('() => window.__errors'))->toBe([])
        ->and($founder->evaluate('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
});

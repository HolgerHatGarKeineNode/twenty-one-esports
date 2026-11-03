<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\User;
use App\Support\Payouts\PayoutApproval;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\File;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
| The player's own profile page and the prize hint (user 2026-10-25), measured at 390 and 1440 in German; console,
| uncaught errors and failed responses collected, with a positive control.
*/

/** Screenshots on request (PROFILE_SHOTS=<dir>); the file's own helper, so a shard without TournamentLiveTest still runs it. */
function profileEditorShot(Page $page, string $name): void
{
    $dir = getenv('PROFILE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the profile page and the prize hint stay inside the screen at 390 and 1440 in German; clean console', function () {
    fakeLightningAddresses(fakeWallet());
    $tournament = finishedPoolTournament(fakeWallet(), withoutAddress: [1]);
    $tournament->forceFill(['published_at' => now()])->save();
    $champion = app(TournamentChampion::class)->of($tournament);
    $user = User::query()->findOrFail($champion->user_id);
    $user->forceFill(['lud16' => null, 'locale' => 'de'])->save();

    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $box = '(s) => { const el = document.querySelector(s); if (! el) return null; const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY) }; }';
    $measured = [];

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('settings.profile')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=profile-save]") !== null && ! document.querySelector("[data-test=profile-name]").disabled', 10_000);
        $profile = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], editor: ({$box})('[data-test=profile-editor]'), help: ({$box})('[data-test=profile-help]'), save: ({$box})('[data-test=profile-save]'), text: document.body.innerText })");
        profileEditorShot($page, "profile-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=prize-needs-address]") !== null && document.readyState === "complete"', 10_000);
        $hint = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], hint: ({$box})('[data-test=prize-needs-address]') })");
        $page->evaluate('() => document.querySelector("[data-test=prize-needs-address]").scrollIntoView({ block: "center" })');
        profileEditorShot($page, "prize-hint-{$width}");
        $measured[$width] = ['profile' => array_diff_key($profile, ['text' => 1]), 'hint' => $hint];

        expect($profile['scroll'][0])->toBeLessThanOrEqual($profile['scroll'][1])
            ->and($profile['editor']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['help']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['text'])->toContain('Noch keine Lightning-Adresse?')->toContain('Coinos')
            ->and($hint['scroll'][0])->toBeLessThanOrEqual($hint['scroll'][1])
            ->and($hint['hint']['right'])->toBeLessThanOrEqual($width);
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.length >= 1', 10_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control');

    fwrite(STDERR, "\n[profile] ".json_encode($measured)."\n");
});

test('a shared moment keeps its text readable beside or under the card at 390, 1180, 1440 and 1920 (user 2026-10-25); clean console', function () {
    $tournament = finishedPoolTournament(fakeWallet());
    $champion = app(TournamentChampion::class)->of($tournament);
    $user = User::query()->findOrFail($champion->user_id);
    $user->forceFill(['locale' => 'de'])->save();

    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[390, 844], [1180, 900], [1440, 900], [1920, 1080]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('settings.badges')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=share-moment]") !== null && document.readyState === "complete"', 10_000);
        $m = $page->evaluate('() => { const li = document.querySelector("[data-test=share-moment]"), img = li.querySelector("img"), text = li.querySelector("b").parentElement.parentElement;
            return { scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], li: Math.round(li.getBoundingClientRect().width), img: Math.round(img.getBoundingClientRect().width), text: Math.round(text.getBoundingClientRect().width), beside: img.getBoundingClientRect().top === text.getBoundingClientRect().top }; }');
        profileEditorShot($page, "badges-{$width}");
        $measured[$width] = $m;

        expect($m['scroll'][0])->toBeLessThanOrEqual($m['scroll'][1])
            ->and($m['text'])->toBeGreaterThanOrEqual(200);
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([]);
    fwrite(STDERR, "\n[badges] ".json_encode($measured)."\n");
});

test('every settings page uses the width, fits 390 and squeezes no text at 390 to 1920, as an admin in German (user 2026-10-25); clean console', function () {
    $user = anAdmin();
    $user->forceFill(['locale' => 'de'])->save();
    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach (['settings.account', 'settings.profile', 'gaming.edit', 'settings.chess', 'settings.notifications', 'settings.opponents', 'settings.badges', 'settings.nip05'] as $route) {
        foreach ([[390, 844], [1024, 800], [1280, 800], [1440, 900], [1920, 1080]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto(ComputeUrl::from(route($route)));
            BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("main section, main form") !== null', 10_000);
            $m = $page->evaluate('() => { const main = document.querySelector("main"), cards = [...main.querySelectorAll(":scope section, :scope form")].filter((el) => el.getBoundingClientRect().height > 40);
                const right = Math.max(...cards.map((el) => el.getBoundingClientRect().right)), left = Math.min(...cards.map((el) => el.getBoundingClientRect().left));
                return { scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], left: Math.round(left), right: Math.round(right), mainRight: Math.round(main.getBoundingClientRect().right) }; }');
            $measured["{$route}-{$width}"] = $m;
            // The squeeze detector (user 2026-10-25, chess tab: a ~200 px column broke every line into single words):
            // no visible text of more than 25 characters in a box narrower than 120 px.
            $squeezed = $page->evaluate('() => [...document.querySelectorAll("main p, main span, main dt, main dd, main label, main h2, main h3, main li, main b")]
                .filter((el) => { const r = el.getBoundingClientRect(), own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(" ");
                    return r.width > 0 && r.height > 0 && r.width < 120 && own.length > 25 && getComputedStyle(el).whiteSpace !== "nowrap"; })
                .map((el) => Math.round(el.getBoundingClientRect().width) + "px: " + el.textContent.trim().slice(0, 40))');
            if ($width !== 390) {
                profileEditorShot($page, str_replace('.', '-', $route)."-{$width}");
            }

            expect($m['scroll'][0])->toBeLessThanOrEqual($m['scroll'][1])
                ->and($squeezed)->toBe([], "{$route} at {$width}");
            $webpage->assertNoJavaScriptErrors();
        }
    }

    expect($page->evaluate('() => window.__errors'))->toBe([]);
    fwrite(STDERR, "\n[settings] ".json_encode($measured)."\n");
});

test('the welcome banner for a fresh Google sign-in fits 390 and 1440 and leads to the profile page with its picture field; clean console', function () {
    $fresh = User::factory()->create(['profile_event_at' => null, 'name' => null, 'lud16' => null, 'locale' => 'de']);
    $webpage = visit(BrowserLogin::url($fresh));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $box = '(s) => { const el = document.querySelector(s); if (! el) return null; const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; }';
    $measured = [];

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.index')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=profile-welcome]") !== null && document.readyState === "complete"', 10_000);
        $banner = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], banner: ({$box})('[data-test=profile-welcome]'), link: ({$box})('[data-test=profile-welcome-link]'), header: ({$box})('header') })");
        profileEditorShot($page, "welcome-{$width}");

        $page->goto(ComputeUrl::from(route('settings.profile')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=profile-avatar-file]") !== null && ! document.querySelector("[data-test=profile-name]").disabled', 10_000);
        $profile = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], avatar: ({$box})('[data-test=profile-avatar]'), banner: document.querySelector('[data-test=profile-welcome]') !== null })");
        profileEditorShot($page, "profile-fresh-{$width}");
        $measured[$width] = ['banner' => $banner, 'profile' => $profile];

        expect($banner['scroll'][0])->toBeLessThanOrEqual($banner['scroll'][1])
            ->and($banner['banner']['right'])->toBeLessThanOrEqual($width)
            ->and($banner['banner']['top'])->toBeGreaterThanOrEqual($banner['header']['bottom'] - 1)
            ->and($banner['link']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['scroll'][0])->toBeLessThanOrEqual($profile['scroll'][1])
            ->and($profile['avatar']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['banner'])->toBeFalse();
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    fwrite(STDERR, "\n[welcome] ".json_encode($measured)."\n");
});

test('the four first steps and the prize hint on a tournament fit 390, 1024 and 1440 (user 2026-10-25); clean console', function () {
    $player = User::factory()->create(['profile_event_at' => null, 'lud16' => null, 'locale' => 'de']);
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4), fakeWallet());
    $webpage = visit(BrowserLogin::url($player));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[390, 844], [1024, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('dashboard')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=me-steps]") !== null && document.readyState === "complete"', 10_000);
        $steps = $page->evaluate('() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], steps: [...document.querySelectorAll("[data-test=me-step]")].map((el) => { const r = el.getBoundingClientRect(), t = el.lastElementChild; return { w: Math.round(r.width), h: Math.round(r.height), right: Math.round(r.right), lines: Math.round(t.getBoundingClientRect().height / parseFloat(getComputedStyle(t).lineHeight)) }; }) })');
        profileEditorShot($page, "steps-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=prize-address-ahead]") !== null && document.readyState === "complete"', 10_000);
        $hint = $page->evaluate('() => { const el = document.querySelector("[data-test=prize-address-ahead]"), r = el.getBoundingClientRect(), banner = document.querySelector("[data-test=profile-welcome]").getBoundingClientRect(), main = document.querySelector("main").getBoundingClientRect(); return { scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], left: Math.round(r.left), right: Math.round(r.right), h: Math.round(r.height), bannerBottom: Math.round(banner.bottom), mainTop: Math.round(main.top) }; }');
        $page->evaluate('() => document.querySelector("[data-test=prize-address-ahead]").scrollIntoView({ block: "center" })');
        profileEditorShot($page, "prize-ahead-{$width}");
        $measured[$width] = ['steps' => $steps, 'hint' => $hint];

        expect($steps['scroll'][0])->toBeLessThanOrEqual($steps['scroll'][1])
            ->and(count($steps['steps']))->toBe(4)
            ->and(max(array_column($steps['steps'], 'right')))->toBeLessThanOrEqual($width)
            ->and(max(array_column($steps['steps'], 'lines')))->toBeLessThanOrEqual(3)
            ->and($hint['scroll'][0])->toBeLessThanOrEqual($hint['scroll'][1])
            ->and($hint['right'])->toBeLessThanOrEqual($width)
            // The hero pulls up over main's padding; the banner sits above main, so nothing covers it (design review 2026-10-25).
            ->and($hint['mainTop'])->toBeGreaterThanOrEqual($hint['bannerBottom']);
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    fwrite(STDERR, "\n[reach] ".json_encode($measured)."\n");
});

test('the payout debug details stay open while the payouts page re-renders (user 2026-10-26: the poll snapped them shut); clean console', function () {
    $admin = anAdmin();
    $admin->forceFill(['locale' => 'de', 'profile_event_at' => now()])->save();
    fakeLightningAddresses($league = fakeWallet());
    $tournament = finishedPoolTournament($league, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, $admin);
    $tournament->payouts()->where('place', 1)->sole()->forceFill(['status' => PayoutStatus::Failed, 'reason' => 'invoice_mismatch',
        'debug' => ['lud16' => 'p@wallet.example', 'failed_checks' => ['description_hash != sha256(metadata) (LUD-06)']]])->save();

    $webpage = visit(BrowserLogin::url($admin));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->goto(ComputeUrl::from(route('admin.payouts')));
    BrowserWait::until($page, '() => document.querySelector("[data-test=payout-debug] summary") !== null && window.Livewire !== undefined', 10_000);
    $page->evaluate('() => document.querySelector("[data-test=payout-debug] summary").click()');
    expect($page->evaluate('() => document.querySelector("[data-test=payout-debug]").open'))->toBeTrue();

    // Five re-renders, as five polls would bring them.
    $page->evaluate('async () => { const wire = Livewire.find(document.querySelector("[data-test=payout-debug]").closest("[wire\\\\:id]").getAttribute("wire:id")); for (let i = 0; i < 5; i++) { await wire.$refresh(); } }');

    expect($page->evaluate('() => document.querySelector("[data-test=payout-debug]").open'))->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

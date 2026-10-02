<?php

use App\Models\Admin;
use App\Models\LeagueSettingChange;
use App\Models\User;
use App\Support\Settings\LeagueSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The league settings page (P44): change a value, then go back to the default
|--------------------------------------------------------------------------
|
| At 375 and 1440 px an admin changes the casual 1v1 pause, saves, sees the
| notice, the "changed" mark and the log row, then goes back to the
| default. Measured: no horizontal overflow, every button of the page at
| least 44 px high, no field wider than the window, nothing cut. The
| console, uncaught errors and every answer (the Livewire round-trips
| included) stay clean, with a positive control first.
| LEAGUE_SETTINGS_SHOTS=<dir> writes the screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

function leagueSettingsPage(User $admin, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // wire:confirm asks window.confirm; the admin says yes.
    $page->context()->addInitScript('window.confirm = () => true;');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('admin.settings', absolute: false)));
    BrowserWait::until($page, '() => window.Alpine !== undefined && window.Livewire !== undefined && document.getElementById("setting-esports-casual-lock-minutes") !== null', 10_000);

    return $page;
}

/**
 * @return array<string, mixed>
 */
function leagueSettingsGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const main = document.querySelector("[data-test=admin-settings]");
        const buttons = [...main.querySelectorAll("button")].filter((el) => el.checkVisibility());
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            small: buttons.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height)),
            outside: [...main.querySelectorAll("input, select, [data-test=settings-log-row]")].filter((el) => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth + 0.5; }).map((el) => el.id || el.dataset.test),
            clipped: [...main.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === "visible" && getComputedStyle(el).textOverflow !== "ellipsis").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 30)),
            fields: main.querySelectorAll("[data-test^=setting-esports-], [data-test^=setting-season-]").length,
        };
    }');
}

function leagueSettingsShot(Page $page, string $name): void
{
    $dir = getenv('LEAGUE_SETTINGS_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('an admin changes a league setting and goes back to the default, at 375 and 1440 px, with a clean console', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $clean = ['overflow' => 0, 'small' => [], 'outside' => [], 'clipped' => [], 'fields' => count(LeagueSettings::definitions())];

    foreach ([[375, 812], [1440, 900]] as $round => [$width, $height]) {
        $page = leagueSettingsPage($admin, $width, $height);

        // Positive control: a thrown error and a 500 answer must both be seen, then the slate is cleaned.
        $page->evaluate('() => { setTimeout(() => { throw new Error("settings-probe"); }); return fetch("/__test/server-error"); }');
        BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("settings-probe")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
        $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

        $first = leagueSettingsGeometry($page);
        leagueSettingsShot($page, "league-settings-{$width}");

        $page->evaluate('() => document.getElementById("setting-esports-casual-lock-minutes").scrollIntoView({ block: "center" })');
        $page->locator('#setting-esports-casual-lock-minutes')->fill('45');
        $page->locator('[data-test=settings-save]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=settings-notice]")?.innerText.includes("Saved: 1 value changed.") === true && document.querySelector("[data-test=settings-log-row]") !== null', 10_000);
        $saved = leagueSettingsGeometry($page);
        $row = $page->evaluate('() => document.querySelector("[data-test=settings-log-row]").innerText');
        $changed = $page->evaluate('() => document.querySelector("[data-test=setting-esports-casual-lock-minutes] [data-test=setting-changed]") !== null');
        leagueSettingsShot($page, "league-settings-saved-{$width}");

        $page->evaluate('() => document.querySelector("[data-test=setting-esports-casual-lock-minutes] [data-test=setting-use-default]").scrollIntoView({ block: "center" })');
        $page->locator('[data-test=setting-esports-casual-lock-minutes] [data-test=setting-use-default]')->click();
        BrowserWait::until($page, '() => document.getElementById("setting-esports-casual-lock-minutes").value === "30" && document.querySelectorAll("[data-test=settings-log-row]").length === '.(2 * $round + 2), 10_000);
        $reset = leagueSettingsGeometry($page);

        expect($first)->toBe($clean, "first load at {$width}px")
            ->and($saved)->toBe($clean, "saved at {$width}px")
            ->and($reset)->toBe($clean, "reset at {$width}px")
            ->and($changed)->toBeTrue()
            ->and($row)->toContain('Pause from casual 1v1 (minutes)')->toContain('30 → 45')->toContain('satsjaeger')
            ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}px")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}px")
            ->and(LeagueSettingChange::query()->count())->toBe(2 * $round + 2);
    }
});

test('an admin switches a game\'s automatic casual cups off and on, at 375 and 1440 px, with a clean console', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    config(['esports.casual_cups.enabled' => ['chess', 'checkers']]);
    $clean = ['overflow' => 0, 'small' => [], 'outside' => [], 'clipped' => [], 'fields' => count(LeagueSettings::definitions())];
    $select = '#setting-esports-casual_cups-games-checkers-auto';

    foreach ([[375, 812], [1440, 900]] as $round => [$width, $height]) {
        $page = leagueSettingsPage($admin, $width, $height);
        $before = $page->evaluate('() => document.querySelector("'.$select.'").value');
        $page->evaluate('() => document.querySelector("[data-test=settings-group-casual_cups]").scrollIntoView({ block: "start" })');
        leagueSettingsViewShot($page, "league-settings-auto-cups-{$width}");

        // Off at 375, back on at 1440.
        $page->locator($select)->selectOption($round === 0 ? 'off' : 'on');
        $page->locator('[data-test=settings-save]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=settings-notice]")?.innerText.includes("Saved: 1 value changed.") === true', 10_000);
        $row = $page->evaluate('() => document.querySelector("[data-test=settings-log-row]").innerText');
        LeagueSettings::forget();

        expect($before)->toBe($round === 0 ? 'on' : 'off')
            ->and(leagueSettingsGeometry($page))->toBe($clean, "saved at {$width}px")
            ->and($row)->toContain('Automatic cups: Checkers')->toContain($round === 0 ? 'On → Off' : 'Off → On')
            ->and(LeagueSettings::get('esports.casual_cups.games.checkers.auto'))->toBe($round === 0 ? 'off' : 'on')
            ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$width}px")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$width}px");
    }
});

/** A viewport shot (not the full page) into LEAGUE_SETTINGS_SHOTS, when set. */
function leagueSettingsViewShot(Page $page, string $name): void
{
    $dir = getenv('LEAGUE_SETTINGS_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

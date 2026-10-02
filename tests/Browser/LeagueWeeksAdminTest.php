<?php

use App\Games\Blockfill;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\LeagueWeek;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The league weeks page: an admin sets next week and approves it
|--------------------------------------------------------------------------
|
| Thursday 2026-10-08 13:00 Berlin: week 41 runs (TMNF on A02-Race, Blockfill
| Hard), the drafts of week 42 were just made with its settings. At 1440 and
| 375 px in English and 375 px in German an admin picks A05-Race with 12
| minutes a round for TMNF and saves, then sets the rules of Blockfill week
| 42 (60 blocks, a level every 5, one curve level faster each up to level 9:
| quick chips and selects, the summary sentence following them) and approves it.
| Measured: no horizontal overflow, every button at least 44 px high, no
| field outside the window, no text cut. The console, uncaught errors and
| every answer (the Livewire round-trips included) stay clean, with a
| positive control first. LEAGUE_WEEKS_SHOTS=<dir> writes the screenshots.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    BlockfillOn::play();
    tmnfOn();
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 11:00:00'));
    leagueWeeksApproved(Blockfill::SLUG, ['difficulty' => 'bf1hard'], before: 0, after: 0);
    leagueWeeksApproved(TrackmaniaNationsForever::SLUG, ['track' => 'JwKdDsOUh4L9_eYyRsdiA2o1fW1', 'time_limit_minutes' => 10], before: 0, after: 0);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('tmnf:weeks')->assertSuccessful();
});

function leagueWeeksPage(User $admin, string $locale, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('admin.league-weeks', absolute: false)));
    BrowserWait::until($page, '() => window.Alpine !== undefined && window.Livewire !== undefined && document.querySelectorAll("[data-test=league-week]").length === 2', 10_000);

    return $page;
}

/**
 * @return array<string, mixed>
 */
function leagueWeeksGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const main = document.querySelector("[data-test=admin-league-weeks]");
        const buttons = [...main.querySelectorAll("button")].filter((el) => el.checkVisibility());
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            small: buttons.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height)),
            outside: [...main.querySelectorAll("input, select, label")].filter((el) => el.checkVisibility()).filter((el) => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth + 0.5; }).map((el) => el.id || el.dataset.test || el.tagName),
            clipped: [...main.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === "visible" && getComputedStyle(el).textOverflow !== "ellipsis" && el.tagName !== "SELECT").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 30)),
            weeks: main.querySelectorAll("[data-test=league-week]").length,
        };
    }');
}

/**
 * Does `$action` and waits for the Livewire round-trip it starts to be answered.
 */
function leagueWeeksRoundTrip(Page $page, Closure $action): void
{
    $count = '() => performance.getEntriesByType("resource").filter((e) => e.initiatorType === "fetch" && e.name.includes("/livewire") && e.responseEnd > 0).length';
    $before = (int) $page->evaluate($count);
    $action();
    BrowserWait::until($page, '() => ('.$count.')() > '.$before, 10_000);
    // the answer is morphed in on the next frame
    $page->evaluate('() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)))');
}

function leagueWeeksShot(Page $page, string $name): void
{
    $dir = getenv('LEAGUE_WEEKS_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('an admin sets the next TMNF week and approves the next Blockfill week, measured, with a clean console', function (string $locale, int $width, int $height) {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tmnf = LeagueWeek::query()->where('game', TrackmaniaNationsForever::SLUG)->whereNull('approved_at')->sole();
    $blockfill = LeagueWeek::query()->where('game', Blockfill::SLUG)->whereNull('approved_at')->sole();
    $clean = ['overflow' => 0, 'small' => [], 'outside' => [], 'clipped' => [], 'weeks' => 2];
    $page = leagueWeeksPage($admin, $locale, $width, $height);

    // Positive control: a thrown error and a 500 answer must both be seen, then the slate is cleaned.
    $page->evaluate('() => { setTimeout(() => { throw new Error("league-weeks-probe"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("league-weeks-probe")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');

    $first = leagueWeeksGeometry($page);
    $heading = $page->evaluate('() => document.querySelector("h1").innerText');
    leagueWeeksShot($page, "league-weeks-{$locale}-{$width}");

    $page->locator("#track-{$tmnf->id}")->selectOption('I7rI7jAga6C4tGAe5OTDoyLF2fh');
    $page->locator("#limit-{$tmnf->id}")->fill('12');
    $page->locator("[data-week=\"{$tmnf->id}\"] [data-test=league-week-save]")->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=league-weeks-notice]") !== null', 10_000);
    $saved = $page->evaluate('() => document.querySelector("[data-test=league-weeks-notice]").innerText');

    $card = "[data-week=\"{$blockfill->id}\"]";
    leagueWeeksRoundTrip($page, fn () => $page->locator("{$card} [data-test=rules-goal-60]")->click());
    leagueWeeksRoundTrip($page, fn () => $page->locator("#start-{$blockfill->id}")->selectOption('1'));
    leagueWeeksRoundTrip($page, fn () => $page->locator("{$card} [data-test=rules-every-5]")->click());
    leagueWeeksRoundTrip($page, fn () => $page->locator("#step-{$blockfill->id}")->selectOption('1'));
    leagueWeeksRoundTrip($page, fn () => $page->locator("#cap-{$blockfill->id}")->selectOption('9'));
    $rules = $page->evaluate('() => document.querySelector(\'[data-week="'.$blockfill->id.'"] [data-test=rules-summary]\').innerText');
    $ruled = leagueWeeksGeometry($page);
    leagueWeeksShot($page, "league-weeks-{$locale}-{$width}-rules");
    $page->locator("{$card} [data-test=league-week-approve]")->click();
    BrowserWait::until($page, '() => document.querySelector("[data-week=\"'.$blockfill->id.'\"] [data-test=league-week-state]")?.dataset.state === "approved"', 10_000);
    $approved = $page->evaluate('() => document.querySelector("[data-test=league-weeks-notice]").innerText');
    $after = leagueWeeksGeometry($page);
    leagueWeeksShot($page, "league-weeks-{$locale}-{$width}-approved");

    expect($first)->toBe($clean, "first load at {$locale} {$width}px")
        ->and($ruled)->toBe($clean, "rules set at {$locale} {$width}px")
        ->and($after)->toBe($clean, "after approving at {$locale} {$width}px")
        ->and($rules)->toBe($locale === 'de'
            ? 'Ein Lauf endet nach 60 Blöcken (abgeräumten Reihen). Alle 5 Blöcke steigt das Level und die Steine fallen eine Stufe schneller auf der Guideline-Kurve: von 1 Reihe/s (Level 1) bis höchstens 11 Reihen/s (Level 9). Die Höchstgeschwindigkeit kommt bei Block 40.'
            : 'A run ends after 60 blocks (cleared lines). Every 5 blocks the level goes up and pieces fall one step faster on the guideline curve: from 1 row/s (level 1) to at most 11 rows/s (level 9). The top speed comes at block 40.')
        ->and($heading)->toBe($locale === 'de' ? 'Liga-Wochen' : 'League weeks')
        ->and($saved)->toBe($locale === 'de' ? 'Gespeichert. Gib die Woche frei, damit sie mit diesen Einstellungen beginnt.' : 'Saved. Approve the week to let it start with these settings.')
        ->and($approved)->toContain($locale === 'de' ? 'Blockfill-Woche 42 ist freigegeben.' : 'Blockfill week 42 is approved.')
        ->and($tmnf->refresh()->settings)->toBe(['track' => 'I7rI7jAga6C4tGAe5OTDoyLF2fh', 'time_limit_minutes' => 12])
        ->and($blockfill->refresh()->settings)->toBe(['difficulty' => 't60e5g1s1c9'])
        ->and($blockfill->approved_by_id)->toBe($admin->id)
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console at {$locale} {$width}px")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers at {$locale} {$width}px");
})->with([
    'en 1440' => ['en', 1440, 900],
    'en 375' => ['en', 375, 812],
    'de 375' => ['de', 375, 812],
]);

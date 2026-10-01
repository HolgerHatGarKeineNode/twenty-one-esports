<?php

use App\Enums\StackerRunStatus;
use App\Jobs\VerifyStackerRun;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerSettings;
use App\Support\Stacker\Verifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FakeStackerVerifier;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Sharing a Blockfill moment in the browser
|--------------------------------------------------------------------------
|
| The share button opens the share sheet (components/⚡blockfill-share) on
| the result screen after a verified ranked run (the real Node verifier, as
| in StackerTest) and in the player's own row of scores/blockfill. Measured
| at 375 and 1440 px in English and at 375 px in German: the sheet inside
| the viewport, nothing in it wider than the sheet, no page overflow; the
| Nostr post shows its note before anything is signed. Console, uncaught
| errors and answers >= 400 stay empty, with a positive control.
|
*/

beforeEach(function () {
    // The reference bot plays faster than a human: without this its runs would be held for review (P5 hints).
    config(['esports.blockfill.hints' => ['pps' => 7]]);
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database']);
    Storage::fake('local');

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    BlockfillOn::play();
});

function bfSharePage(User $user, string $locale, int $width, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $width < 640 ? 812 : 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/**
 * The open sheet, measured: inside the viewport, nothing in it wider than
 * the sheet, no page overflow, the card loaded; then the Nostr post's
 * preview, the copy button, closing; the console empty, and a thrown error
 * caught by the same collector (positive control).
 *
 * @return array<string, mixed>
 */
function bfShareMeasure(Page $page, string $label, string $locale): array
{
    // The first open draws the card (GD, two formats' worth of work on a busy machine): a generous wait.
    BrowserWait::until($page, '() => { const s = document.querySelector("[data-test=blockfill-share-sheet]"); const img = s?.querySelector("[data-test=blockfill-share-card]"); return s && s.getBoundingClientRect().height > 0 && img?.complete && img.naturalWidth === 1200; }', 30_000);

    $m = $page->evaluate('() => {
        const sheet = document.querySelector("[data-test=blockfill-share-sheet]").getBoundingClientRect();
        const wider = [...document.querySelectorAll("[data-test=blockfill-share-sheet] *")]
            .filter((el) => el.getClientRects().length > 0 && el.getBoundingClientRect().right > sheet.right + 1)
            .map((el) => (el.dataset.test || el.tagName) + " " + Math.round(el.getBoundingClientRect().right));
        return {
            viewport: [innerWidth, innerHeight],
            sheet: [Math.round(sheet.left), Math.round(sheet.top), Math.round(sheet.width), Math.round(sheet.height), Math.round(sheet.bottom)],
            widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            wider,
            buttons: [...document.querySelectorAll("[data-test=blockfill-share-sheet] button, [data-test=blockfill-share-sheet] a")].filter((el) => el.getClientRects().length > 0)
                .map((el) => (el.dataset.test || el.tagName) + " " + Math.round(el.getBoundingClientRect().width) + "x" + Math.round(el.getBoundingClientRect().height)),
            headline: document.querySelector("[data-test=blockfill-share-headline]").innerText,
            lang: document.documentElement.lang,
        };
    }');
    fwrite(STDERR, "bf-share {$label}: ".json_encode($m, JSON_UNESCAPED_UNICODE).PHP_EOL);
    shellShot($page, "bf-share-{$label}-sheet");

    expect($m['lang'])->toBe($locale)
        ->and($m['sheet'][0])->toBeGreaterThanOrEqual(0)
        ->and($m['sheet'][0] + $m['sheet'][2])->toBeLessThanOrEqual($m['viewport'][0])
        ->and($m['sheet'][4])->toBeLessThanOrEqual($m['viewport'][1])
        ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1])
        ->and($m['wider'])->toBe([]);

    // The Nostr post: its exact note before anything is signed, the moment's page last.
    $page->locator('[data-test=blockfill-share-sheet] [data-test=share-post]')->click();
    BrowserWait::until($page, '() => (document.querySelector("[data-test=share-preview-text]")?.innerText ?? "").includes("/scores/blockfill/moment/")', 8_000);
    $note = $page->evaluate('() => document.querySelector("[data-test=share-preview-text]").innerText');
    $previewWider = $page->evaluate('() => { const sheet = document.querySelector("[data-test=blockfill-share-sheet]").getBoundingClientRect(); return [...document.querySelectorAll("[data-test=share-preview] *")].filter((el) => el.getClientRects().length > 0 && el.getBoundingClientRect().right > sheet.right + 1).map((el) => el.tagName); }');
    fwrite(STDERR, "bf-share {$label} note: ".json_encode($note, JSON_UNESCAPED_UNICODE).PHP_EOL);
    shellShot($page, "bf-share-{$label}-note");

    expect($note)->not->toContain('#')
        ->and(preg_match('~/scores/blockfill/moment/\d+$~', trim($note)))->toBe(1)
        ->and($previewWider)->toBe([])
        ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($page->evaluate(BrowserConsole::WIDTHS)[1]);

    // Copy link: copied, or the hint where the clipboard is not allowed; never an error.
    $page->locator('[data-test=blockfill-share-copy]')->click();
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 300))');

    $page->locator('[data-test=blockfill-share-close]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=blockfill-share-sheet]") === null', 5_000);

    $errors = $page->evaluate('() => window.__errors');
    $bad = $page->evaluate(BrowserConsole::BAD_RESPONSES);
    expect($errors)->toBe([])->and($bad)->toBe([]);

    // Positive control: the collector sees a thrown error on this very page.
    $page->evaluate('() => new Promise((resolve) => { setTimeout(() => { throw new Error("bf-share positive control"); }); setTimeout(resolve, 100); })');
    expect(implode(' ', $page->evaluate('() => window.__errors')))->toContain('bf-share positive control');

    return $m;
}

test('the result screen of a verified ranked run opens the share sheet', function (string $locale, int $width) {
    $this->freezeTime();
    $forty = json_decode((string) file_get_contents(base_path('tests/Fixtures/stacker/forty-lines.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    $user = User::factory()->create(['name' => 'Satoshi Nakamoto', 'stacker_settings' => $forty['settings'] + ['keys' => StackerSettings::DEFAULT_KEYS]]);

    $page = bfSharePage($user, $locale, $width, route('stacker.play', [], false));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);
    $page->locator('[data-test=start-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown" && window.__stacker.state().kind === "ranked" && document.querySelector("[data-test=countdown]") !== null', 5_000);
    expect($page->evaluate('(inputs) => window.__stacker.feed(inputs, { hold: true })', $forty['inputs']))->toBe('queued');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "held"', 8_000);
    $this->travel(17)->seconds();
    $page->evaluate('() => window.__stacker.release()');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "verified" && !!window.__stacker.state().result?.moment', 15_000);

    $run = StackerRun::query()->sole();
    expect($page->evaluate('() => window.__stacker.state().result.moment'))->toBe((string) $run->id);

    BrowserWait::until($page, '() => document.querySelector("[data-test=result-share-open]")?.getClientRects().length > 0', 5_000);
    shellShot($page, "bf-share-result-{$locale}-{$width}");
    $page->locator('[data-test=result-share-open]')->click();

    bfShareMeasure($page, "result-{$locale}-{$width}", $locale);
})->with([
    'en 375' => ['en', 375],
    'en 1440' => ['en', 1440],
    'de 375' => ['de', 375],
]);

test('the player\'s own row on scores/blockfill opens the share sheet', function (string $locale, int $width) {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    $others = ['HalvingHodler21' => 2800, 'Lightning Larry' => 3300];

    foreach ([...$others, 'Satoshi Nakamoto' => 2950] as $name => $ticks) {
        $user = User::factory()->create(['name' => $name]);
        $at = CarbonImmutable::now()->subHours(3);
        $run = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
            'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA']);
        VerifyStackerRun::dispatchSync($run->id);
    }

    $page = bfSharePage($user, $locale, $width, route('scores.show', 'blockfill', false));
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=score-row-share]").length === 1', 8_000);
    $row = $page->evaluate('() => { const b = document.querySelector("[data-test=score-row-share]"); const row = b.closest("[data-test=score-row]").getBoundingClientRect(); const r = b.getBoundingClientRect(); return { place: b.closest("[data-test=score-row]").dataset.place, button: [Math.round(r.width), Math.round(r.height)], inside: r.right <= row.right + 1 && r.left >= row.left - 1 }; }');
    fwrite(STDERR, "bf-share row {$locale}-{$width}: ".json_encode($row).PHP_EOL);
    expect($row)->toBe(['place' => '2', 'button' => [44, 44], 'inside' => true]);

    $page->locator('[data-test=score-row-share]')->click();
    bfShareMeasure($page, "scores-{$locale}-{$width}", $locale);
})->with([
    'en 375' => ['en', 375],
    'en 1440' => ['en', 1440],
    'de 375' => ['de', 375],
]);

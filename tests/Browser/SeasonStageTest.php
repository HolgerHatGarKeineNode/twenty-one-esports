<?php

use App\Models\Season;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| The Block 0 stage in the browser (pages/admin/⚡stage): the release of a season as one big button on a projected
| screen. The release cannot be undone, so this file counts requests: nothing but a press held for two seconds on
| the confirm's own control calls launch(), and that calls it once. A rehearsal runs the same show, says so in
| every act and leaves no season behind.
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

    $this->board = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($this->board);
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->refresh()->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
    saveChainDraft(['message' => 'Pre-Season: every fair win is a block']);
});

// Every Livewire roundtrip of the page, and the ones that call launch().
const STAGE_COUNTER = <<<'JS'
    window.__updates = 0;
    window.__launches = 0;
    // Presses that really landed on the hold control: a press that missed it would prove nothing.
    window.__presses = 0;
    document.addEventListener('pointerdown', (event) => { if (event.target.closest?.('[data-test=stage-hold]')) window.__presses++; }, true);
    // Livewire sends one request per component at a time, so a second launch() would not show on the network
    // while the first is on its way: the fill starting again under a sent hold is what gives a missing lock away.
    window.__heldWhileSent = false;
    setInterval(() => {
        const hold = document.querySelector('[data-test=stage-hold]');
        if (hold?.classList.contains('is-holding') && hold.classList.contains('is-sent')) window.__heldWhileSent = true;
    }, 100);
    const countedFetch = window.fetch;
    window.fetch = (input, init) => {
        if (String(input?.url ?? input).includes('/update')) {
            window.__updates++;
            if (String(init?.body ?? '').includes('"method":"launch"')) {
                window.__launches++;

                // The release takes its time, so a second press can meet the request on its way.
                return new Promise((resolve) => setTimeout(resolve, window.__launchDelay ?? 0)).then(() => countedFetch(input, init));
            }
        }
        return countedFetch(input, init);
    };
    JS;

const STAGE_STATE = <<<'JS'
    () => {
        const box = (selector) => { const el = document.querySelector(selector); if (! el || ! el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)]; };
        return {
            scene: document.querySelector('[data-test=stage]').dataset.scene,
            confirm: document.querySelector('[data-test=stage-confirm]').open,
            focus: document.activeElement?.dataset.test ?? null,
            updates: window.__updates,
            launches: window.__launches,
            presses: window.__presses,
            heldWhileSent: window.__heldWhileSent,
            button: box('[data-test=stage-button]'),
            hold: box('[data-test=stage-hold]'),
            modal: box('[data-test=stage-confirm]'),
            view: [innerWidth, innerHeight],
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors,
        };
    }
    JS;

/** The stage page as the board member at 1280 × 720, armed and with the stage open. */
function armedStage(User $board, bool $real): Page
{
    $page = visit(BrowserLogin::url($board))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(STAGE_COUNTER);
    $page->context()->addInitScript(TestSigner::browserStub($board));
    $page->setViewportSize(1280, 720);
    $page->goto(ComputeUrl::from(route('admin.season.stage')));

    if ($real) {
        $page->locator('[data-test=mode-real]')->click();
    }

    $page->locator('[data-test=stage-supply]')->fill('2 100 000');
    $page->locator('[data-test=stage-arm-button]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=armed-signature]")?.dataset.signed === "yes"', 10_000);
    $page->locator('[data-test=stage-open]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage-button]")?.checkVisibility() === true');

    return $page;
}

/** @return array<string, mixed> */
function stageState(Page $page): array
{
    return $page->evaluate(STAGE_STATE);
}

function openConfirm(Page $page): void
{
    $page->locator('[data-test=stage-button]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage-confirm]").open');
}

test('nothing but a completed hold releases Block 0 from the stage, and the hold releases it once', function () {
    $page = armedStage($this->board, real: true);
    $armed = stageState($page);
    $button = $armed['button'];

    // The button is on the screen, whole.
    expect($button[0])->toBeGreaterThanOrEqual(0)
        ->and($button[1])->toBeGreaterThanOrEqual(0)
        ->and($button[2])->toBeLessThanOrEqual($armed['view'][0])
        ->and($button[3])->toBeLessThanOrEqual($armed['view'][1])
        ->and($armed['overflow'])->toBe(0)
        ->and($armed['focus'])->toBe('stage');

    // A double click opens the confirm and nothing else.
    $page->locator('[data-test=stage-button]')->dblclick();
    usleep(2_600_000);
    $state = stageState($page);
    expect($state['confirm'])->toBeTrue()
        ->and($state['updates'])->toBe($armed['updates']);

    // The confirm's control stands beside the button's place, inside the screen.
    expect($state['hold'][0] >= $button[2] || $state['hold'][2] <= $button[0] || $state['hold'][1] >= $button[3] || $state['hold'][3] <= $button[1])->toBeTrue()
        ->and($state['modal'][2])->toBeLessThanOrEqual($state['view'][0])
        ->and($state['modal'][3])->toBeLessThanOrEqual($state['view'][1]);

    // Enter and Space right after it opened: the focus is on Cancel, never on the hold.
    $page->locator(':focus')->press('Escape');
    openConfirm($page);
    expect(stageState($page)['focus'])->toBe('stage-cancel');
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "idle"');
    $page->locator(':focus')->press('Space');
    usleep(2_300_000);
    // Enter chose Cancel; Space met the stage itself, where no key does anything.
    $state = stageState($page);
    expect($state['updates'])->toBe($armed['updates'])
        ->and($state['confirm'])->toBeFalse()
        ->and($state['scene'])->toBe('idle')
        ->and($state['focus'])->toBe('stage');

    // A press in the first second, and a hold let go after a second and a half.
    openConfirm($page);
    $presses = stageState($page)['presses'];
    usleep(400_000);
    // Begun in the first second and held past it for longer than a hold takes: a press that starts cold never counts.
    // Forced: the control says aria-disabled in its first second, and the runner would wait that second out.
    $page->locator('[data-test=stage-hold]')->click(['delay' => 2_600, 'force' => true]);
    usleep(300_000);
    $state = stageState($page);
    expect($state['presses'])->toBe($presses + 1)
        ->and($state['scene'])->toBe('confirm')
        ->and($state['updates'])->toBe($armed['updates']);
    BrowserWait::until($page, '() => ! document.querySelector("[data-test=stage-hold]").classList.contains("is-cold")');
    $page->locator('[data-test=stage-hold]')->click(['delay' => 1_500]);
    usleep(800_000);
    $state = stageState($page);
    expect($state['presses'])->toBe($presses + 2)
        ->and($state['scene'])->toBe('confirm')
        ->and($state['updates'])->toBe($armed['updates']);

    // Escape, Cancel and a click outside close it without a request.
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "idle"');
    expect(stageState($page)['confirm'])->toBeFalse();
    openConfirm($page);
    $page->locator('[data-test=stage-cancel]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "idle"');
    expect(stageState($page)['confirm'])->toBeFalse();
    openConfirm($page);
    usleep(600_000);
    // The button's place is under the confirm's backdrop now.
    $page->locator('[data-test=stage-button]')->click(['force' => true]);
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "idle"');
    $state = stageState($page);
    expect($state['scene'])->toBe('idle')
        ->and($state['updates'])->toBe($armed['updates'])
        ->and($state['launches'])->toBe(0)
        ->and(Season::query()->count())->toBe(0);

    // The completed hold, and a second press while the request is on its way.
    openConfirm($page);
    BrowserWait::until($page, '() => ! document.querySelector("[data-test=stage-hold]").classList.contains("is-cold")');
    $page->evaluate('() => { window.__launchDelay = 8000; }');
    $page->locator('[data-test=stage-hold]')->click(['delay' => 2_300]);
    expect($page->evaluate('() => [document.querySelector("[data-test=hold-release]").checkVisibility(), document.querySelector("[data-test=stage-cancel]").disabled]'))->toBe([true, true]);
    $presses = stageState($page)['presses'];
    $page->locator('[data-test=stage-hold]')->click(['delay' => 2_300, 'force' => true]);
    expect(stageState($page)['presses'])->toBe($presses + 1);
    // Escape, twice: the browser asks the page only the first time; the confirm stays, or comes back.
    $page->locator('[data-test=stage-confirm]')->press('Escape');
    $page->locator('[data-test=stage-confirm]')->press('Escape');
    usleep(300_000);
    $state = stageState($page);
    expect($state['confirm'])->toBeTrue()
        ->and($state['scene'])->toBe('confirm')
        ->and($state['heldWhileSent'])->toBeFalse()
        ->and($state['launches'])->toBe(1);
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "done"', 20_000);

    $state = stageState($page);
    expect($state['launches'])->toBe(1)
        ->and(Season::query()->count())->toBe(1)
        ->and($state['errors'])->toBe([]);

    // The celebration waits for a click: no link before it, the live page after it.
    usleep(3_000_000);
    expect($page->evaluate('() => [document.querySelector("[data-test=stage]").dataset.scene, document.querySelector("[data-test=done-home]").checkVisibility(), window.__launches]'))->toBe(['done', false, 1]);
    $page->locator('[data-test=stage-done]')->click(['position' => ['x' => 600, 'y' => 40]]);
    BrowserWait::until($page, '() => document.querySelector("[data-test=done-home]").checkVisibility()');
    expect(stageState($page)['errors'])->toBe([]);

    // With the season live the page offers the live page and no button.
    $page->goto(ComputeUrl::from(route('admin.season.stage')));
    expect($page->evaluate('() => [document.querySelector("[data-test=season-stage]").dataset.state, document.querySelector("[data-test=stage-button]"), document.querySelector("[data-test=stage-live-link]") !== null]'))->toBe(['live', null, true])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: the collector sees a thrown error and a missing file.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }, 0); return fetch("/__test/missing-on-purpose").then(() => true); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2');
    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('positive control')->toContain('404');
});

test('a rehearsal runs the whole show under its marker and leaves no season', function () {
    $page = armedStage($this->board, real: false);
    $marker = '() => ["stage-rehearsal", "confirm-rehearsal", "done-rehearsal"].map((name) => document.querySelector("[data-test=" + name + "]").checkVisibility())';

    expect($page->evaluate($marker))->toBe([true, false, false]);

    openConfirm($page);
    expect($page->evaluate($marker))->toBe([true, true, false]);

    BrowserWait::until($page, '() => ! document.querySelector("[data-test=stage-hold]").classList.contains("is-cold")');
    $page->locator('[data-test=stage-hold]')->click(['delay' => 2_300]);
    BrowserWait::until($page, '() => document.querySelector("[data-test=stage]").dataset.scene === "done"', 20_000);

    expect($page->evaluate($marker))->toBe([true, false, true])
        ->and(Season::query()->count())->toBe(0);

    // The way out of a rehearsal is back to arming, never to a release.
    $page->locator('[data-test=stage-done]')->click(['position' => ['x' => 600, 'y' => 40]]);
    BrowserWait::until($page, '() => document.querySelector("[data-test=done-back]").checkVisibility()');
    expect($page->evaluate('() => document.querySelector("[data-test=done-home]").checkVisibility()'))->toBeFalse()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

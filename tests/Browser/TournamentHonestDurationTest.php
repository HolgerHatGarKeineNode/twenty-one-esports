<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The honest range in the chooser (P18, user decision 2026-09-27)
|--------------------------------------------------------------------------
|
| /admin/tournaments/create at 375 and 1440 px: EA Sports FC 26 1v1 with 16
| players in Two Stage shows "Start 19:00 · expected end about 22:55 · at
| the latest about 1:34", and the line follows a format change and a player
| count change (island requests). The box stays inside the viewport.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400. The collector is proven at the end with a thrown error and
| a 404 fetch (positive control).
|
*/

const HONEST_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const HONEST_STATE = <<<'JS'
    () => {
        const box = document.querySelector('[data-test=duration-range]');
        const rect = box?.getBoundingClientRect();
        return {
            line: document.querySelector('[data-test=duration-range-line]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
            format: document.querySelector('[data-test=tournament-chooser]')?.dataset.format ?? null,
            box: rect ? { left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width), height: Math.round(rect.height) } : null,
            viewport: document.documentElement.clientWidth,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors,
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    // The reference case of EstimatorRangeTest and the Feature test: the round clock's deadlines pinned, not the league's current ones (noshow_minutes 20 since,
    // which moves the worst case of 16 players by 30 minutes).
    config(['session.driver' => 'database', 'esports.tournaments.round_clock' => ['noshow_minutes' => 15, 'grace_minutes' => 5, 'response_minutes' => 10]]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function honestLine(Page $page, string $expected): array
{
    try {
        BrowserWait::until($page, '() => document.querySelector("[data-test=duration-range-line]")?.textContent.replace(/\s+/g, " ").trim() === '.json_encode($expected), 8_000);
    } catch (RuntimeException $timeout) {
        // Say what the line read when the wait ran out: the expected text alone does not show which part moved.
        throw new RuntimeException($timeout->getMessage().' [the line read: '.json_encode($page->evaluate('() => document.querySelector("[data-test=duration-range-line]")?.textContent.replace(/\\s+/g, " ").trim() ?? null')).']', 0, $timeout);
    }

    return $page->evaluate(HONEST_STATE);
}

test('the chooser shows the honest range and follows the format and the player count at 375 and 1440 px', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(HONEST_COLLECTOR);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.tournaments.create')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=duration-range-line]") !== null', 8_000);

        $page->locator('[data-test=game-ea-sports-fc-26] button:has-text("1v1")')->click();
        // A series game offers the final's length; blitz does not.
        BrowserWait::until($page, '() => document.querySelector("#fbo-l") !== null', 8_000);
        $page->locator('#n-in')->fill('16');
        BrowserWait::until($page, '() => document.querySelector("#n-in")?.value === "16"', 8_000);
        $page->locator('[data-test=row-two-stage]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-chooser]")?.dataset.format === "two-stage"', 8_000);

        // 175 min planned, 235 typical, 394 at worst (EstimatorRangeTest, the reference case).
        $twoStage = honestLine($page, 'Start 19:00 · expected end about 22:55 · at the latest about 1:34 if every deadline runs out');

        // Single Elimination of 16: 3 rounds of 30 and a Bo3 final of 60, 3 breaks; at worst 3 × 55 + 94 + 15.
        $page->locator('[data-test=row-single-elimination]')->click();
        $single = honestLine($page, 'Start 19:00 · expected end about 21:45 · at the latest about 23:34 if every deadline runs out');

        // 8 players: one round less.
        $page->locator('#n-in')->fill('8');
        $eight = honestLine($page, 'Start 19:00 · expected end about 21:10 · at the latest about 22:34 if every deadline runs out');

        $measured[$width] = ['box' => $eight['box'], 'overflow' => $eight['overflow']];

        foreach ([$twoStage, $single, $eight] as $state) {
            expect($state['box'])->not->toBeNull()
                ->and($state['box']['left'])->toBeGreaterThanOrEqual(0)
                ->and($state['box']['right'])->toBeLessThanOrEqual($state['viewport'])
                ->and($state['overflow'])->toBeLessThanOrEqual(0)
                ->and($state['errors'])->toBe([]);
        }
    }

    // Positive control: the collector sees a thrown error and a 404 response.
    $page->evaluate('() => { setTimeout(() => { throw new Error("honest-probe"); }); fetch("/honest-probe-404"); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 8_000);
    $control = $page->evaluate('() => window.__errors');

    expect(collect($control)->contains(fn (string $entry) => str_contains($entry, 'honest-probe') && str_starts_with($entry, 'error')))->toBeTrue()
        ->and(collect($control)->contains(fn (string $entry) => str_starts_with($entry, '404 ')))->toBeTrue();

    fwrite(STDERR, "\n[honest-range] ".json_encode($measured)."\n");
});

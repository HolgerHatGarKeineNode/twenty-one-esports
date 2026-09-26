<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The format chooser in the browser (P8a)
|--------------------------------------------------------------------------
|
| /admin/tournaments/create at 375 and 1440 px, one page load each: 8 players
| recommend Round Robin, 12 players Swiss; Rocket League 3v3 recommends a
| format with a final and shows Free for All disabled with its reason. Every
| change travels as an island request: the page outside the island is never
| morphed (a marker on the name field survives) and every Livewire response
| carries island fragments, never the whole component.
|
| Collected: console.error/warn, uncaught errors, rejected promises, fetch
| and XHR >= 400, and horizontal overflow. P8A_SHOTS=<dir> writes the
| English screenshots there.
|
*/

const CHOOSER_COLLECTOR = <<<'JS'
    window.__errors = [];
    window.__livewire = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || 'unknown')));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => {
        if (r.status >= 400) push(r.status + ' ' + r.url);
        if (String(r.url).includes('/livewire')) {
            r.clone().json().then((body) => (body.components || []).forEach((c) => window.__livewire.push({
                html: typeof c.effects?.html === 'string', islands: (c.effects?.islandFragments || []).length,
            }))).catch(() => {});
        }
        return r;
    });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const CHOOSER_STATE = <<<'JS'
    () => {
        const chooser = document.querySelector('[data-test=tournament-chooser]');
        const ffa = document.querySelector('[data-test=row-free-for-all]');
        return {
            recommended: chooser?.dataset.recommended ?? null,
            name: document.querySelector('[data-test=recommendation-name]')?.textContent.trim() ?? null,
            why: document.querySelector('[data-test=recommendation-why]')?.textContent.trim() ?? null,
            ffaDisabled: ffa?.dataset.disabled === 'true' && ffa.tagName !== 'BUTTON',
            ffaReason: ffa?.querySelector('[data-test=row-reason]')?.textContent.trim() ?? null,
            ffaVisible: ffa ? ffa.getBoundingClientRect().height > 0 : false,
            marker: document.querySelector('[data-test=tournament-name]')?.dataset.probe ?? null,
            summary: document.querySelector('[data-test=tournament-summary]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors,
            livewire: window.__livewire,
        };
    }
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

function chooserShot(Page $page, string $name): void
{
    $dir = getenv('P8A_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function chooserWait(Page $page, string $recommended): void
{
    BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-chooser]")?.dataset.recommended === "'.$recommended.'"', 8_000);
}

test('the chooser recommends live at 375 and 1440 px, through island requests only', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $url = route('admin.tournaments.create');

    // Logged in on a static file: the page under test is loaded once per viewport, with the collector armed.
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(CHOOSER_COLLECTOR);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        chooserWait($page, 'swiss');
        $page->evaluate('() => { document.querySelector("[data-test=tournament-name]").dataset.probe = "kept"; }');

        // Enter the player count: 8 → Round Robin, 12 → Swiss (the layperson case).
        $page->locator('#n-in')->fill('8');
        chooserWait($page, 'round-robin');
        $page->locator('#n-in')->fill('12');
        chooserWait($page, 'swiss');
        $blitz = $page->evaluate(CHOOSER_STATE);
        chooserShot($page, "p8a-chooser-blitz-12-{$width}");

        // Rocket League 3v3: a format that ends with a final.
        $page->locator('[data-test=game-rl] button:has-text("3v3")')->click();
        chooserWait($page, 'single-elimination');
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-summary]")?.textContent.includes("teams")', 8_000);
        $rl = $page->evaluate(CHOOSER_STATE);
        chooserShot($page, "p8a-chooser-rl3-12-{$width}");

        $measured[$width] = ['blitz' => $blitz['name'], 'rl' => $rl['name'], 'overflow' => $rl['overflow'], 'requests' => count($rl['livewire'])];

        expect($blitz['name'])->toBe('Swiss, 6 rounds')
            ->and($rl['recommended'])->toBe('single-elimination')
            ->and($rl['why'])->toStartWith('Ends with a final; every team plays at least 1 game')
            ->and($rl['name'])->toBe('Single Elimination')
            ->and($rl['ffaDisabled'])->toBeTrue()
            ->and($rl['ffaVisible'])->toBeTrue()
            ->and($rl['ffaReason'])->toBe('Not available for this game. Needs 3 or more players in one match. Rocket League is always one team against one.')
            ->and($rl['summary'])->toContain('Single Elimination, 12 teams')
            // Nothing outside the islands was re-rendered, and no response carried the whole component.
            ->and($rl['marker'])->toBe('kept')
            ->and($rl['livewire'])->not->toBeEmpty()
            ->and(collect($rl['livewire'])->where('html', true)->count())->toBe(0)
            ->and(collect($rl['livewire'])->every(fn (array $response) => $response['islands'] >= 1))->toBeTrue()
            ->and($blitz['overflow'])->toBeLessThanOrEqual(0)
            ->and($rl['overflow'])->toBeLessThanOrEqual(0)
            ->and($rl['errors'])->toBe([]);
    }

    // Still at 1440: the split option says which seeds start in the lower bracket (12 teams: 9 to 12).
    $page->locator('[data-test=row-double-elimination]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-chooser]")?.dataset.format === "double-elimination"', 8_000);
    $page->locator('[data-test=split-toggle]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=guaranteed]")?.textContent.includes("only 1 match")', 8_000);

    expect($page->evaluate('() => document.querySelector("[data-test=split-help]").textContent.trim()'))
        ->toBe('The lowest seeds start in the lower bracket, with one life: here seeds 9 to 12. Shorter, but less fair.');

    // Every width in between, without a reload: no horizontal overflow anywhere.
    $overflow = [];

    foreach ([320, 390, 768, 1024, 1280, 1920] as $width) {
        $page->setViewportSize($width, 900);
        $overflow[$width] = $page->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth');
    }

    expect(array_filter($overflow))->toBe([]);
    $measured['overflow by width'] = $overflow;
    $page->setViewportSize(1440, 900);

    // Positive control: a request outside the islands (create without a name)
    // re-renders the component, and the probes see it — the marker goes, the
    // response carries the component's html. The page stays clean.
    $page->locator('[data-test=tournament-create-button]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-create] [role=alert]") !== null', 8_000);
    $control = $page->evaluate(CHOOSER_STATE);

    expect($control['marker'])->toBeNull()
        ->and(collect($control['livewire'])->where('html', true)->count())->toBe(1)
        ->and($control['errors'])->toBe([]);

    fwrite(STDERR, "\n[p8a-chooser] ".json_encode($measured)."\n");
});

<?php

use App\Enums\ChessEndReason;
use App\Enums\InviteStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\DisputeEvidence;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BrowserWait;

pest()->group('browser');

beforeEach(function () {
    // A fresh authenticated user is always "stale" (member_checked_at is
    // null), so App\Http\Middleware\RefreshStaleMembership defers a real
    // call to the Verein API on the first authenticated request of the
    // sweep. Fake it — a browser test must never depend on the network.
    Http::fake(fn () => Http::response([]));
});

/*
|--------------------------------------------------------------------------
| Route discovery
|--------------------------------------------------------------------------
|
| Every GET route this app should render as a page, taken from the router
| itself rather than a hand list, so a new placeholder in routes/web.php is
| swept automatically the moment it exists.
|
*/

/**
 * URI prefixes that are vendor/asset/infra endpoints, not pages: Flux's
 * asset manager, Livewire's asset + file-preview routes (the hash prefix is
 * config-driven, hence the "starts with" check, not an exact route name),
 * the storage disk, the broadcasting auth endpoint (JSON, not a page), and
 * this app's own testing-only fixtures below (the positive-control tests
 * visit those directly; sweeping them would always fail on purpose). Horizon
 * is a vendor dashboard; its admin gate is covered in tests/Feature/AdminTest.
 *
 * @var list<string>
 */
const SWEEP_VENDOR_PREFIXES = ['flux/', 'livewire-', 'storage/', 'broadcasting/', '__test/', 'horizon'];

/**
 * @param  array<string, string>  $bound  route key per bound parameter, from sweepFixtures()
 * @return list<array{name: string, url: string}>
 */
function sweepRoutes(array $bound = []): array
{
    return collect(Route::getRoutes())
        ->reject(fn (RoutingRoute $route) => $route->isFallback)
        ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
        ->reject(fn (RoutingRoute $route) => $route->uri() === 'up')
        ->reject(function (RoutingRoute $route) {
            foreach (SWEEP_VENDOR_PREFIXES as $prefix) {
                if (str_starts_with($route->uri(), $prefix)) {
                    return true;
                }
            }

            return false;
        })
        ->map(fn (RoutingRoute $route) => [
            'name' => $route->getName() ?? $route->uri(),
            'url' => fillRouteParameters($route, $bound),
        ])
        ->unique('url')
        ->values()
        ->all();
}

/*
|--------------------------------------------------------------------------
| Fixtures for route-model-bound parameters
|--------------------------------------------------------------------------
|
| A bound parameter (`clans/{clan}`) 404s on a made-up value, so each one
| gets a real row, built for the swept user. Register a new bound parameter
| here: parameter name => closure(?User $user, array $made): Model. `$user`
| is the logged-in user of the sweep (null for the guest sweep), `$made` the
| models of the entries above it, so later fixtures can hang off earlier
| ones. The URL gets the model's route key (slug, ulid, ...). An unbound
| parameter keeps the plain `sweep-fixture` value.
|
*/

/**
 * @return array<string, Closure(?User, array<string, Model>): Model>
 */
function sweepFixtures(): array
{
    return [
        // Owned by the swept user, so the member sweep can open the captain-only manage page.
        'clan' => fn (?User $user, array $made): Model => Clan::factory()->create($user === null ? [] : ['owner_id' => $user->id]),

        // An open invite into that clan's roster; the clan owner may view it.
        'invite' => fn (?User $user, array $made): Model => ClanInvite::query()->create([
            'clan_id' => $made['clan']->getKey(),
            'inviter_id' => $made['clan']->getAttribute('owner_id'),
            'invitee_id' => User::factory()->create()->id,
            'status' => InviteStatus::Pending,
        ]),

        // A live blitz game; the member sweep plays White in it, so the board is playable.
        'game' => fn (?User $user, array $made): Model => ChessGame::factory()->create($user === null ? [] : ['white_id' => $user->id]),

        // A running Rocket League series (P6a). The swept member captains the
        // challenger lineup (a 2v2 of the `clan` fixture), so the match room
        // opens with the lobby, the score sheet and the chat; the guest gets
        // the public page and the login redirect.
        'match' => fn (?User $user, array $made): Model => SeriesMatch::factory()->accepted()->create($user === null ? [] : [
            'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => $made['clan']->getKey()])->id,
            'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
            'lobby_name' => 'sweep-lobby',
            // One game entered, so the match page draws its flow and the room its score.
            'live_games' => [['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger']],
        ]),

        // A dispute screenshot of that series, served to admins only.
        'evidence' => function (?User $user, array $made): Model {
            Storage::disk('local')->put('dispute-evidence/sweep.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

            return DisputeEvidence::query()->create(['series_match_id' => $made['match']->getKey(), 'path' => 'dispute-evidence/sweep.png', 'name' => 'sweep.png']);
        },
    ];
}

/**
 * Pages one route renders in more than one way: `games/{game}` is a live
 * blitz board with the `game` fixture above, and a daily game (P5b) or a
 * daily game lost on time with these. Each is swept like a route.
 *
 * @return list<array{name: string, url: string}>
 */
function sweepExtraPages(?User $user): array
{
    $daily = ChessGame::factory()->daily()->create($user === null ? [] : ['black_id' => $user->id]);
    $lost = ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Timeout)->create($user === null ? [] : ['black_id' => $user->id]);

    if ($user !== null) {
        app(DailyChallenges::class)->challenge(User::factory()->create(), $user, 'white', 'gl hf');
    }

    return [
        ['name' => 'games.show (daily)', 'url' => route('games.show', $daily, false)],
        ['name' => 'games.show (daily, lost on time)', 'url' => route('games.show', $lost, false)],
    ];
}

/**
 * Build every registered fixture and return its route key per parameter name.
 *
 * @return array<string, string>
 */
function buildSweepFixtures(?User $user): array
{
    $made = [];

    foreach (sweepFixtures() as $name => $build) {
        $made[$name] = $build($user, $made);
    }

    return array_map(fn (Model $model): string => (string) $model->getRouteKey(), $made);
}

/**
 * Bound parameters take their fixture's route key; `locale` must satisfy its
 * `->whereIn()` constraint; every other parameter belongs to a route that
 * does not read it (placeholders, redirects), so any value is enough.
 *
 * @param  array<string, string>  $bound
 */
function fillRouteParameters(RoutingRoute $route, array $bound = []): string
{
    $values = [
        'locale' => config('app.supported_locales')[0] ?? 'en',
        ...$bound,
    ];

    $uri = $route->uri();

    foreach ($route->parameterNames() as $name) {
        $uri = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', $values[$name] ?? 'sweep-fixture', $uri, 1);
    }

    return '/'.ltrim($uri, '/');
}

/*
|--------------------------------------------------------------------------
| Error/overflow collector
|--------------------------------------------------------------------------
|
| Pest's own javaScriptErrors()/consoleLogs() only override console.log and
| listen for window "error" — they miss console.error/warn, unhandled
| promise rejections and >=400 fetch responses entirely (verified by hand
| against vendor/pestphp/pest-plugin-browser/src/Playwright/InitScript.php).
| This is a wider collector registered the same way (Context::addInitScript),
| so it is active before any of the page's own scripts run.
|
*/

const SWEEP_COLLECTOR_SCRIPT = <<<'JS'
    window.__sweep = { console: [], errors: [], responses: [] };

    const wrap = (level) => {
        const original = console[level];
        console[level] = function (...args) {
            window.__sweep.console.push({ level, message: args.map(String).join(' ') });
            original.apply(console, args);
        };
    };
    wrap('error');
    wrap('warn');

    window.addEventListener('error', (event) => {
        window.__sweep.errors.push({ type: 'error', message: event.message });
    });
    window.addEventListener('unhandledrejection', (event) => {
        window.__sweep.errors.push({ type: 'unhandledrejection', message: String(event.reason) });
    });

    const originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (...args) {
            return originalFetch.apply(window, args).then((response) => {
                if (response.status >= 400) {
                    window.__sweep.responses.push({ url: response.url, status: response.status });
                }
                return response;
            });
        };
    }
    JS;

const SWEEP_READ_SCRIPT = <<<'JS'
    () => {
        const nav = performance.getEntriesByType('navigation')[0] || {};
        const sweep = window.__sweep || { console: [], errors: [], responses: [] };
        return {
            console: sweep.console,
            errors: sweep.errors,
            responses: sweep.responses,
            navStatus: nav.responseStatus ?? null,
            overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        };
    }
    JS;

/**
 * A fresh browser context with the collector active from the very first
 * navigation, on the given URL.
 */
function freshSweepPage(string $url): Page
{
    $page = visit($url)->page();
    $page->context()->addInitScript(SWEEP_COLLECTOR_SCRIPT);
    // The context's init script only applies to navigations after it was
    // registered, and the visit() call above already navigated once without
    // it — re-navigate so the very first page is measured too.
    $page->goto(ComputeUrl::from($url));

    return $page;
}

/**
 * @param  list<string>  &$violations
 */
function recordSweepViolations(array $data, string $label, array &$violations): void
{
    if (($data['navStatus'] ?? null) !== null && $data['navStatus'] >= 400) {
        $violations[] = "{$label}: navigation responded {$data['navStatus']}";
    }

    foreach ($data['responses'] as $response) {
        $violations[] = "{$label}: {$response['status']} on {$response['url']}";
    }

    foreach ($data['errors'] as $error) {
        $violations[] = "{$label}: JS {$error['type']}: {$error['message']}";
    }

    foreach ($data['console'] as $entry) {
        $violations[] = "{$label}: console.{$entry['level']}: {$entry['message']}";
    }
}

/**
 * @param  list<string>  &$violations
 */
function recordOverflowViolation(array $data, string $label, array &$violations): void
{
    if ($data['overflow']) {
        $violations[] = "{$label}: horizontal overflow ({$data['scrollWidth']}px content, {$data['clientWidth']}px viewport)";
    }
}

/*
|--------------------------------------------------------------------------
| Top spacing under the header
|--------------------------------------------------------------------------
|
| Every page starts its content at least one page-top step below the
| header's bottom edge: 20px at 375 (MobileChessLobby/MobileLadder
| `padding-top: 20px`) and 32px at 1440 (Clans/ClanShow/Dashboard
| `padding: 32px 48px`). The value lives once, as --spacing-page-top*
| in resources/css/app.css, applied to <main> in layouts/app.blade.php.
|
| "First content" is the topmost visible element inside <main> that paints
| something: its own text, a replaced element (img/svg/canvas/form control),
| a background, a border or a box-shadow. Transparent wrappers do not count,
| because their padding is exactly the gap being measured. Screen-reader-only
| boxes (1px) and fixed overlays are skipped.
|
*/

const SWEEP_PAGE_TOP = [375 => 20, 1440 => 32];

/** Minimum distance of any non-full-bleed element from the viewport edges (px-4 on phones). */
const SWEEP_PAGE_SIDE = [375 => 16, 1440 => 16];

/**
 * Pages whose design starts flush under the header on purpose, by the path
 * the browser lands on (a redirect such as locale/{locale} is judged by its
 * target): only the pre-launch home, which opens with its full-bleed Block 0
 * bar (MainPrelaunch.dc.html, MobileHomePrelaunch.dc.html). The layout opt-out
 * is `<x-layouts::app flush>`. The NIP-05 JSON endpoint has no header at all
 * and is not a page.
 *
 * @var list<string>
 */
const SWEEP_FLUSH_PATHS = ['/'];

/** @var list<string> Routes that answer without the app shell (JSON, images). */
const SWEEP_NO_HEADER_ROUTES = ['nostr.nip05', 'admin.disputes.evidence'];

const SWEEP_GAP_SCRIPT = <<<'JS'
    async () => {
        window.scrollTo(0, 0);
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const header = document.querySelector('body > header');
        const main = document.getElementById('content');
        if (!header || !main) {
            return { gap: null, path: location.pathname, first: !header ? 'no <header>' : 'no <main id="content">' };
        }

        const paints = (el, style) => {
            if ([...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '')) return true;
            if (['IMG', 'svg', 'CANVAS', 'VIDEO', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA'].includes(el.tagName)) return true;
            if (style.backgroundColor !== 'rgba(0, 0, 0, 0)' || style.backgroundImage !== 'none') return true;
            if (style.boxShadow !== 'none') return true;
            return ['Top', 'Right', 'Bottom', 'Left'].some((side) =>
                parseFloat(style[`border${side}Width`]) > 0 && style[`border${side}Style`] !== 'none');
        };

        const headerBottom = header.getBoundingClientRect().bottom;
        const viewport = document.documentElement.clientWidth;
        let top = Infinity;
        let first = null;
        let side = Infinity;
        let sideFirst = null;

        for (const el of main.querySelectorAll('*')) {
            if (el.closest('svg') && el.tagName !== 'svg') continue;
            if (!el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
            const rect = el.getBoundingClientRect();
            if (rect.width <= 1 || rect.height <= 1) continue;
            const style = getComputedStyle(el);
            if (style.position === 'fixed') continue;
            if (!paints(el, style)) continue;
            if (rect.top < top) {
                top = rect.top;
                first = el;
            }
            // Full-bleed bands (a tab bar's hairline, a hero, a phone chess
            // board marked data-bleed) may touch the edges; anything narrower
            // than the viewport needs a gutter. Measure what is actually
            // visible: clip against every clipping ancestor (sr-only, scrollers,
            // tickers), so hidden or scrolled-away parts don't count.
            if (rect.width >= viewport - 1 || el.parentElement.closest('[data-bleed]')) continue;
            let left = rect.left;
            let right = rect.right;
            let scrolledAway = false;
            for (let a = el.parentElement; a && a !== main; a = a.parentElement) {
                const as = getComputedStyle(a);
                if (as.overflowX === 'visible' && as.clip === 'auto' && as.clipPath === 'none') continue;
                const ar = a.getBoundingClientRect();
                // Inside a horizontal scroller that actually overflows (a tab
                // bar on phones): its edge is the scroll affordance.
                if (['auto', 'scroll'].includes(as.overflowX) && a.scrollWidth > a.clientWidth) scrolledAway = true;
                left = Math.max(left, ar.left);
                right = Math.min(right, ar.right);
            }
            if (scrolledAway || right - left <= 1) continue;
            const edge = Math.min(left, viewport - right);
            if (edge < side) {
                side = edge;
                sideFirst = el;
            }
        }

        if (first === null) {
            return { gap: null, path: location.pathname, first: 'nothing painted inside <main>' };
        }

        const name = first.tagName.toLowerCase() + (first.dataset.test ? `[data-test=${first.dataset.test}]` : '')
            + ' "' + (first.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';

        const describe = (el) => el === null ? 'nothing' : el.tagName.toLowerCase() + (el.dataset.test ? `[data-test=${el.dataset.test}]` : '')
            + ' "' + (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';

        return {
            gap: Math.round((top - headerBottom) * 100) / 100,
            side: sideFirst === null ? null : Math.round(side * 100) / 100,
            path: location.pathname,
            first: name,
            sideFirst: describe(sideFirst),
        };
    }
    JS;

/**
 * @param  list<string>  &$violations
 */
function recordGapViolation(Page $page, string $routeName, string $label, int $width, array &$violations): void
{
    if (in_array($routeName, SWEEP_NO_HEADER_ROUTES, true)) {
        return;
    }

    // A navigation after load tears the probe's execution context down
    // mid-frame. Seen once in seven guest sweeps (the page was not
    // identified); retry on the page it lands on, rethrow anything else.
    $data = null;

    for ($attempt = 1; $data === null; $attempt++) {
        try {
            $data = $page->evaluate(SWEEP_GAP_SCRIPT);
        } catch (Throwable $e) {
            if ($attempt === 3 || ! str_contains($e->getMessage(), 'Execution context was destroyed')) {
                throw $e;
            }

            usleep(300_000);
        }
    }

    if ($data['gap'] === null) {
        $violations[] = "{$label} at {$width}px: cannot measure the top gap ({$data['first']})";

        return;
    }

    if (in_array($data['path'], SWEEP_FLUSH_PATHS, true)) {
        return;
    }

    if ($data['gap'] < SWEEP_PAGE_TOP[$width]) {
        $violations[] = "{$label} at {$width}px: content starts {$data['gap']}px under the header, needs >= ".SWEEP_PAGE_TOP[$width]."px (first: {$data['first']})";
    }

    if ($data['side'] !== null && $data['side'] < SWEEP_PAGE_SIDE[$width]) {
        $violations[] = "{$label} at {$width}px: content sits {$data['side']}px from the viewport edge, needs >= ".SWEEP_PAGE_SIDE[$width]."px (element: {$data['sideFirst']})";
    }
}

/*
|--------------------------------------------------------------------------
| The sweep
|--------------------------------------------------------------------------
|
| One context per (auth state), reused across every route: the collector is
| registered once, each goto() re-arms it fresh (Context::addInitScript runs
| on every subsequent document). Overflow is also checked at 1440px without
| an extra navigation, by resizing in place.
|
*/

test('every route renders without console errors, page errors, bad responses or overflow', function (bool $authenticated) {
    $user = null;

    if ($authenticated) {
        $user = User::factory()->create();
        Admin::query()->create(['pubkey' => $user->pubkey]);
        test()->actingAs($user);
    }

    $routes = [...sweepRoutes(buildSweepFixtures($user)), ...sweepExtraPages($user)];
    expect($routes)->not->toBeEmpty();

    $page = freshSweepPage('/');
    $page->setViewportSize(375, 800);

    $violations = [];

    foreach ($routes as $route) {
        $label = "{$route['name']} ({$route['url']})";

        $page->goto(ComputeUrl::from($route['url']));
        $mobile = $page->evaluate(SWEEP_READ_SCRIPT);

        recordSweepViolations($mobile, $label, $violations);
        recordOverflowViolation($mobile, "{$label} at 375px", $violations);
        recordGapViolation($page, $route['name'], $label, 375, $violations);

        $page->setViewportSize(1440, 900);
        $desktop = $page->evaluate(SWEEP_READ_SCRIPT);
        recordOverflowViolation($desktop, "{$label} at 1440px", $violations);
        recordGapViolation($page, $route['name'], $label, 1440, $violations);
        $page->setViewportSize(375, 800);
    }

    expect($violations)->toBe([]);
})->with([
    'guest' => [false],
    'member' => [true],
]);

/*
|--------------------------------------------------------------------------
| Positive controls
|--------------------------------------------------------------------------
|
| Prove the collector actually catches something, using the exact assertion
| the sweep above makes (expect($violations)->toBe([])) — if this assertion
| never fires red, the sweep is decorative.
|
*/

test('positive control: the sweep fails on an injected JS error', function () {
    $page = freshSweepPage(route('testing.js-throw'));

    $violations = [];
    recordSweepViolations($page->evaluate(SWEEP_READ_SCRIPT), 'js-throw fixture', $violations);

    expect($violations)->not->toBeEmpty();
    expect(collect($violations)->contains(fn (string $v) => str_contains($v, 'injected JS error')))->toBeTrue();

    expect(fn () => expect($violations)->toBe([]))->toThrow(ExpectationFailedException::class);
});

test('positive control: the top-gap probe measures a page that starts flush', function () {
    // The pre-launch home is the one opt-out: its Block 0 bar sits directly
    // under the header. The probe must report that as ~0px, or a page that
    // loses its spacing would pass unnoticed.
    $page = freshSweepPage(route('home'));
    $page->setViewportSize(375, 800);

    $data = $page->evaluate(SWEEP_GAP_SCRIPT);

    expect($data['gap'])->toBeLessThan(SWEEP_PAGE_TOP[375])
        ->and($data['first'])->toContain('prelaunch-bar');
});

test('positive control: the sweep fails on an injected 500', function () {
    $page = freshSweepPage(route('testing.server-error'));

    $data = $page->evaluate(SWEEP_READ_SCRIPT);
    $violations = [];
    recordSweepViolations($data, 'server-error fixture', $violations);

    expect($data['navStatus'])->toBe(500);
    expect($violations)->not->toBeEmpty();

    expect(fn () => expect($violations)->toBe([]))->toThrow(ExpectationFailedException::class);
});

/*
|--------------------------------------------------------------------------
| Livewire roundtrip
|--------------------------------------------------------------------------
*/

test('a Livewire roundtrip stays clean: submitting the admin form without a key', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    test()->actingAs($admin);

    $page = freshSweepPage(route('admin.admins'));

    $page->getByRole('button', ['name' => __('Add admin')])->click();
    BrowserWait::until($page, '() => document.body.innerText.toLowerCase().includes("required")', 10_000);

    $violations = [];
    recordSweepViolations($page->evaluate(SWEEP_READ_SCRIPT), 'admin.admins Livewire roundtrip', $violations);

    expect($violations)->toBe([])
        // The roundtrip failed validation and did not write anything: only
        // the admin created in this test's setup exists.
        ->and(Admin::query()->count())->toBe(1);
});

<?php

use App\Enums\ChessEndReason;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Chess\DailyChallenges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
 * visit those directly; sweeping them would always fail on purpose).
 *
 * @var list<string>
 */
const SWEEP_VENDOR_PREFIXES = ['flux/', 'livewire-', 'storage/', 'broadcasting/', '__test/'];

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

        // An open invite into that clan's 3v3 lineup; the clan owner may view it.
        'invite' => fn (?User $user, array $made): Model => ClanInvite::query()->create([
            'clan_id' => $made['clan']->getKey(),
            'lineup_id' => Lineup::query()->create(['clan_id' => $made['clan']->getKey(), 'game' => 'rocket-league', 'mode' => '3v3'])->id,
            'inviter_id' => $made['clan']->getAttribute('owner_id'),
            'invitee_id' => User::factory()->create()->id,
            'role' => LineupRole::Substitute,
            'status' => InviteStatus::Pending,
        ]),

        // A live blitz game; the member sweep plays White in it, so the board is playable.
        'game' => fn (?User $user, array $made): Model => ChessGame::factory()->create($user === null ? [] : ['white_id' => $user->id]),
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

        $page->setViewportSize(1440, 900);
        $desktop = $page->evaluate(SWEEP_READ_SCRIPT);
        recordOverflowViolation($desktop, "{$label} at 1440px", $violations);
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

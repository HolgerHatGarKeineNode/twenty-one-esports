<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Every edge of the Hyperbitcoinization map can be reached by a swipe
|--------------------------------------------------------------------------
|
| A phone shows the map cut to its height (`slice`), so most of its width lies off screen; a swipe to the
| far left, right, top and bottom must bring the outermost territory fully into the free area between the
| top bar and roster above and the treasury and buttons below. Measured on the page with real drags.
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

    HyperOn::play();
});

function panPage(User $user, string $path, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20])).'); } catch (e) {}');
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1" && document.body.dataset.live === "1"', 15_000);

    return $page;
}

/** Drags the map by (dx, dy) a few times, as far as it goes, then measures the free area and the outermost pins. */
const HYPER_SWIPE = <<<'JS'
async ([dx, dy]) => {
    const map = document.querySelector('#map');
    const cx = innerWidth / 2; const cy = innerHeight / 2;
    for (let i = 0; i < 6; i++) {
        map.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: cx, clientY: cy, button: 0, view: window }));
        for (let s = 1; s <= 4; s++) window.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: cx + dx * s / 4, clientY: cy + dy * s / 4, view: window }));
        window.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: cx + dx, clientY: cy + dy, view: window }));
    }
    await new Promise((r) => setTimeout(r, 200));
    const shown = (sel) => [...document.querySelectorAll(sel)].filter((el) => !el.hidden && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden').map((el) => el.getBoundingClientRect()).filter((r) => r.width && r.height);
    // The bars only lie over the map's top and bottom on a phone's layout; wider, the whole screen counts.
    const phone = innerWidth < 820;
    const top = phone ? Math.max(0, ...shown('#topbar, #roster').map((r) => r.bottom)) : 0;
    const bottom = phone ? Math.min(innerHeight, ...shown('#cta, #treasury').map((r) => r.top)) : innerHeight;
    const pins = [...document.querySelectorAll('.pin .disc')].map((el) => el.getBoundingClientRect());
    return {
        top: Math.round(top), bottom: Math.round(bottom), width: innerWidth,
        left: Math.round(Math.min(...pins.map((r) => r.left))), right: Math.round(Math.max(...pins.map((r) => r.right))),
        high: Math.round(Math.min(...pins.map((r) => r.top))), low: Math.round(Math.max(...pins.map((r) => r.bottom))),
    };
}
JS;

test('a swipe reaches every edge of the map on a phone, a landscape phone and a desktop', function () {
    $anna = User::factory()->create();
    $match = HyperOn::versus($anna, User::factory()->create());
    $path = route('hyper.match', $match, false);
    $seen = [];

    foreach ([[390, 844], [360, 740], [844, 390], [1440, 900]] as [$width, $height]) {
        $page = panPage($anna, $path, $width, $height);
        $size = "{$width}x{$height}";
        $seen[$size] = [
            'right' => $page->evaluate(HYPER_SWIPE, [4000, 0]),
            'left' => $page->evaluate(HYPER_SWIPE, [-4000, 0]),
            'down' => $page->evaluate(HYPER_SWIPE, [0, 4000]),
            'up' => $page->evaluate(HYPER_SWIPE, [0, -4000]),
        ];

        if ($width === 390) {
            // Zoomed in, the map still pans to its western edge.
            $page->evaluate('() => document.querySelector("#zoom-in").click()');
            $page->evaluate('() => new Promise((r) => setTimeout(r, 500))');
            $seen[$size]['zoomed'] = $page->evaluate(HYPER_SWIPE, [4000, 0]);
        }

        $seen[$size]['errors'] = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    }

    file_put_contents(getenv('HYPER_PAN_OUT') ?: '/dev/null', json_encode($seen, JSON_PRETTY_PRINT));

    foreach ($seen as $size => $at) {
        expect($at['right']['left'])->toBeGreaterThanOrEqual(0, "{$size}: westmost territory off screen")
            ->and($at['left']['right'])->toBeLessThanOrEqual($at['left']['width'], "{$size}: eastmost territory off screen")
            ->and($at['down']['high'])->toBeGreaterThanOrEqual($at['down']['top'], "{$size}: northmost territory under the top bar")
            ->and($at['up']['low'])->toBeLessThanOrEqual($at['up']['bottom'], "{$size}: southmost territory under the buttons")
            ->and($at['zoomed']['left'] ?? 0)->toBeGreaterThanOrEqual(0, "{$size}: zoomed in, westmost territory off screen")
            ->and($at['errors'])->toBe([]);
    }
});

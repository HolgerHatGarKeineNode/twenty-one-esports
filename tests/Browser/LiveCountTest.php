<?php

use App\Models\Tournament;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\LiveStreamFixture;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The live count, live (P20b)
|--------------------------------------------------------------------------
|
| One poller per page (resources/js/liveFeed.js, GET /stream/status) keeps
| the header badge, the floating player, the More sheet and /live current.
| The interval is 1 s here (15 s in production). A new count reaches every
| place within one poll without a reload, and no box moves (rects before and
| after, and no layout-shift entry). The badge and the tab flip on air and
| off air while the page is open, and /live starts playing when the stream
| comes on. A hidden page asks nothing; failed requests space out. The row-1
| count shows from 1280 px with a 90 px reserve left. Console and responses
| stay clean, with a thrown error and a 404 as positive control.
|
*/

const COUNT_COLLECTOR = <<<'JS'
    window.__errors = [];
    window.__shifts = [];
    performance.setResourceTimingBufferSize(20000);
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then(async (r) => {
        if (r.status >= 400) push(r.status + ' ' + r.url);
        // Holds the status answer back by window.__holdStatus ms: a page hidden while a request is out.
        if (String(args[0]).includes('/stream/status') && window.__holdStatus) await new Promise((resolve) => setTimeout(resolve, window.__holdStatus));
        return r;
    });
    new PerformanceObserver((list) => list.getEntries().forEach((e) => { if (!e.hadRecentInput) window.__shifts.push({ value: e.value, at: e.startTime, nodes: e.sources.map((s) => s.node?.dataset?.test ?? s.node?.nodeName ?? '?') }); })).observe({ type: 'layout-shift', buffered: true });
    // A page the test can hide: document.visibilityState follows window.__hidden.
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (window.__hidden ? 'hidden' : 'visible') });
    JS;

/** Requests to the status endpoint so far. */
const COUNT_POLLS = '() => performance.getEntriesByType("resource").filter((e) => e.name.includes("/stream/status")).length';

/** The visible text and box of each live count on the page. */
const COUNT_PROBE = <<<'JS'
    () => {
        const pick = (sel) => [...document.querySelectorAll(sel)].find((el) => el.checkVisibility()) ?? null;
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; };
        const read = (sel) => { const el = pick(sel); return el ? { text: el.textContent.trim(), box: box(el) } : null; };
        return {
            badge: box(pick('[data-test=live-badge]')),
            badgeCount: read('[data-test=live-badge-viewers]'),
            navLive: !!pick('[data-test=nav-live]'),
            tab: box(pick('[data-test=live-tab]')),
            tabLine: read('[data-test=live-tab-viewers]'),
            mini: read('[data-test=live-mini-viewers]') ?? read('[data-test=live-mini-count]'),
            page: read('[data-test=live-viewers] b'),
            store: window.Alpine?.store('live') ? { live: Alpine.store('live').live, viewers: Alpine.store('live').viewers } : null,
            spare: (() => { const nav = document.querySelector('[data-test=game-tabs]'); const grow = nav && [...nav.children].find((el) => el.classList.contains('grow')); return grow && grow.checkVisibility() ? Math.round(grow.getBoundingClientRect().width) : null; })(),
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    // The pages poll 15 times faster than in production and every page of a test stays open: the 240 per minute and IP of the status route (production: a poll
    // every 15 s) were crossed under load, when a test lasts longer, and a 429 is an error of the page. Refusal is tested on purpose below, with 0.
    config(['session.driver' => 'database', 'esports.live.poll_seconds' => 1, 'esports.live.status_per_minute' => 100_000]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->hls = sys_get_temp_dir().'/esports-live-count-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->hls);
    config(['twentyone.stream.hls_dir' => $this->hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    Cache::forever(LiveStreamFixture::START_KEY, microtime(true));
});

afterEach(function () {
    File::deleteDirectory($this->hls);
});

/** The stream on air now (or off), with this count; the status cache starts over, as after its 5 s. */
function countAir(bool $live, ?int $viewers = null): void
{
    $playlist = test()->hls.'/stream.m3u8';

    if ($live) {
        file_put_contents($playlist, "#EXTM3U\n");
        touch($playlist);
    } else {
        File::delete($playlist);
    }

    Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => $viewers], 3600);
    Cache::forget(LiveStatus::CACHE_KEY);
}

function countPage(int $width, string $to = '/rules'): Page
{
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(COUNT_COLLECTOR);
    $page->setViewportSize($width, $width < 640 ? 667 : 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => !!window.Alpine && !!window.__liveFeed && document.fonts.status === "loaded"', 10_000);
    Execution::instance()->wait(0.5);

    return $page;
}

/** P20_SHOTS=<dir>: the viewport as it runs (the tally ring included). */
function countShot(Page $page, string $name): void
{
    $dir = getenv('P20_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);

    foreach (Client::instance()->execute($guid, 'screenshot', ['type' => 'png', 'fullPage' => false, 'caret' => 'hide', 'animations' => 'allow', 'scale' => 'css']) as $message) {
        if (isset($message['result']['binary'])) {
            File::ensureDirectoryExists($dir);
            File::put($dir.'/'.$name.'.png', base64_decode((string) $message['result']['binary']));
        }
    }
}

function countProblems(Page $page): array
{
    return [...$page->evaluate('() => window.__errors'), ...$page->evaluate('() => performance.getEntries().filter((e) => typeof e.responseStatus === "number" && e.responseStatus >= 400).map((e) => e.responseStatus + " " + e.name)')];
}

test('a new count reaches the badge, the tab, the mini player and /live within one poll, and no box moves', function (int $width) {
    countAir(true, 5);
    $page = countPage($width);
    $before = $page->evaluate(COUNT_PROBE);
    // Shifts are taken from this moment on: the page's own load (fonts, x-cloak) is not the count's doing.
    $mark = $page->evaluate('() => performance.now()');

    countAir(true, 128);
    BrowserWait::until($page, '() => Alpine.store("live").viewers === 128', 2_500);
    Execution::instance()->wait(0.4);
    $after = $page->evaluate(COUNT_PROBE);
    countShot($page, "count-128-{$width}");
    fwrite(STDERR, "\n[live count {$width}] before ".json_encode($before).' after '.json_encode($after)."\n");

    expect($after['tabLine']['text'])->toBe('128 watching')
        ->and($after['tab'])->toBe($before['tab'])
        ->and($after['badge'])->toBe($before['badge'])
        ->and($page->evaluate('(mark) => window.__shifts.filter((s) => s.at > mark)', $mark))->toBe([]);

    if ($width >= 1440) {
        expect($before['badgeCount']['text'])->toBe('5')->and($after['badgeCount']['text'])->toBe('128')
            ->and($after['badgeCount']['box'])->toBe($before['badgeCount']['box']);
    } else {
        expect($after['badgeCount'])->toBeNull();
    }

    // The mini player shows the count too, and follows it.
    $page->locator('[data-test=live-tab]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=live-mini]").checkVisibility()', 5_000);
    countAir(true, 7);
    BrowserWait::until($page, '() => Alpine.store("live").viewers === 7', 2_500);
    Execution::instance()->wait(0.4);
    $mini = $page->evaluate(COUNT_PROBE)['mini'];
    countShot($page, "count-mini-7-{$width}");
    expect($mini['text'])->toBe($width >= 640 ? '7 watching' : '7');
    $page->locator('[data-test=live-close]')->click();

    // /live: the big number follows as well.
    $page->goto(ComputeUrl::from('/live'));
    BrowserWait::until($page, '() => !!window.__liveFeed && document.fonts.status === "loaded" && document.querySelector("[data-test=live-viewers] b")?.textContent.trim() === "7"', 5_000);
    // The page's own load settles first (the font swap shifts it once, before the count changes anything).
    Execution::instance()->wait(0.8);
    $pageBefore = $page->evaluate(COUNT_PROBE)['page'];
    // Shifts are taken from this moment on: the page's own load (fonts, x-cloak) is not the count's doing.
    $mark = $page->evaluate('() => performance.now()');
    countAir(true, 21);
    BrowserWait::until($page, '() => document.querySelector("[data-test=live-viewers] b").textContent.trim() === "21"', 2_500);
    // Past the 0.24 s tick: a transform in flight is no shift, but it moves the measured box.
    Execution::instance()->wait(0.4);
    $pageAfter = $page->evaluate(COUNT_PROBE)['page'];
    expect($pageAfter['box'])->toBe($pageBefore['box'])
        ->and($page->evaluate('(mark) => window.__shifts.filter((s) => s.at > mark)', $mark))->toBe([])
        ->and(countProblems($page))->toBe([]);
})->with([375, 1440]);

test('the badge, the Live link and the tab flip on air and off air while the page is open, and /live starts playing', function () {
    if (! LiveStreamFixture::available()) {
        $this->markTestSkipped('ffmpeg is needed for the local live stream.');
    }
    LiveStreamFixture::dir();

    countAir(false);
    $page = countPage(1440);
    $off = $page->evaluate(COUNT_PROBE);
    expect($off['badge'])->toBeNull()->and($off['navLive'])->toBeTrue()->and($off['tab'])->toBeNull();

    countAir(true, 3);
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=live-badge]")?.checkVisibility() && !!document.querySelector("[data-test=live-tab]")?.checkVisibility()', 2_500);
    $on = $page->evaluate(COUNT_PROBE);
    expect($on['navLive'])->toBeFalse()->and($on['badgeCount']['text'])->toBe('3')->and($on['tabLine']['text'])->toBe('3 watching');

    countAir(false);
    BrowserWait::until($page, '() => !document.querySelector("[data-test=live-badge]").checkVisibility() && !document.querySelector("[data-test=live-tab]").checkVisibility() && document.querySelector("[data-test=nav-live]").checkVisibility()', 2_500);

    // /live opened off air: the stream comes on and the stage plays, without a reload.
    $page->goto(ComputeUrl::from('/live'));
    BrowserWait::until($page, '() => !!window.__liveFeed && document.querySelector("[data-test=live-offline]").checkVisibility()', 5_000);
    countAir(true, 3);
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-stage-video]"); return v.checkVisibility() && !v.paused && v.currentTime > 0.5 && !document.querySelector("[data-test=live-offline]").checkVisibility(); }', 20_000);
    expect($page->evaluate('() => location.pathname'))->toBe('/live')
        ->and(countProblems($page))->toBe([]);
});

test('a hidden page asks nothing, a visible one asks at once, and failed requests space out', function () {
    countAir(true, 2);
    $page = countPage(1440);

    // Visible: about one request per second.
    $start = $page->evaluate(COUNT_POLLS);
    Execution::instance()->wait(3.2);
    $visible = $page->evaluate(COUNT_POLLS) - $start;
    expect($visible)->toBeGreaterThanOrEqual(2);

    // Hidden: none.
    $page->evaluate('() => { window.__hidden = true; document.dispatchEvent(new Event("visibilitychange")); }');
    Execution::instance()->wait(1.2);
    $atHide = $page->evaluate(COUNT_POLLS);
    Execution::instance()->wait(3.2);
    expect($page->evaluate(COUNT_POLLS) - $atHide)->toBe(0);

    // Hidden while a request is out: its answer lands, and nothing is asked after it.
    $page->evaluate('() => { window.__hidden = false; window.__holdStatus = 1500; document.dispatchEvent(new Event("visibilitychange")); }');
    Execution::instance()->wait(0.3);
    // Only that one answer is held: anything asked after it would come back at once.
    $page->evaluate('() => { window.__hidden = true; document.dispatchEvent(new Event("visibilitychange")); window.__holdStatus = 0; }');
    $atHideInFlight = $page->evaluate(COUNT_POLLS);
    Execution::instance()->wait(4.5);
    expect($page->evaluate(COUNT_POLLS) - $atHideInFlight)->toBeLessThanOrEqual(1);

    // Back: asked at once, and the news arrives.
    countAir(true, 9);
    $page->evaluate('() => { window.__hidden = false; document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => Alpine.store("live").viewers === 9', 800);

    // Refused (429): 1, 2, 4 s apart instead of every second.
    config(['esports.live.status_per_minute' => 0]);
    $atRefuse = $page->evaluate(COUNT_POLLS);
    Execution::instance()->wait(6.5);
    $refused = $page->evaluate(COUNT_POLLS) - $atRefuse;
    fwrite(STDERR, "\n[live poll] visible 3.2 s: {$visible}, refused 6.5 s: {$refused}, failures ".json_encode($page->evaluate('() => window.__liveFeed.failures()'))."\n");
    expect($refused)->toBeLessThanOrEqual(4)->and($refused)->toBeGreaterThanOrEqual(2)
        ->and($page->evaluate('() => window.__liveFeed.failures()'))->toBeGreaterThanOrEqual(2);

    // The only bad answers are those 429s.
    expect(array_values(array_filter(countProblems($page), fn (string $p): bool => ! str_contains($p, '429'))))->toBe([]);
});

test('row 1 carries the count from 1440 px with room to spare, and not at 1280, in English and German, with Tournaments open', function () {
    Tournament::factory()->signup()->count(2)->create(['signup_closes_at' => now()->addDays(2)]);
    $users = ['guest' => null, 'player' => shellPlayer(), 'admin' => shellAdmin()];
    $failures = [];
    $problems = [];
    $seen = [];

    foreach ($users as $role => $user) {
        foreach (['en', 'de'] as $locale) {
            foreach ([1280 => 800, 1440 => 900] as $width => $height) {
                countAir(true, 888);
                $page = shellPage($user, $width, $height);
                $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
                shellOpen($page, '/rules', $problems);
                $m = $page->evaluate(SHELL_MEASURE);
                $probe = $page->evaluate(COUNT_PROBE);
                $seen["{$role} {$locale} @{$width}"] = ['count' => $probe['badgeCount'], 'spare' => $probe['spare'], 'squeezed' => $m['squeezed']];

                // 1440: the count shows and row 1 keeps at least 48 px (measured: 72 to 180). 1280: it would leave 5 to 26 px, so it waits.
                $countOk = $width >= 1440 ? ($probe['badgeCount']['text'] ?? null) === '888' && ($probe['spare'] ?? 0) >= 48 : $probe['badgeCount'] === null;
                if (! $countOk || $probe['spare'] === null || $m['squeezed'] !== [] || $m['scroll'] > $m['client']) {
                    $failures[] = "{$role} {$locale} @{$width}: ".json_encode($seen["{$role} {$locale} @{$width}"]);
                }
            }
        }
    }

    fwrite(STDERR, "\n[live count row 1] ".json_encode($seen)."\n");
    expect($failures)->toBe([])->and($problems)->toBe([]);
});

test('positive control: the collector sees a thrown error and a 404, and the shift probe a moved page', function () {
    countAir(false);
    $page = countPage(1440);
    expect(countProblems($page))->toBe([]);

    $mark = $page->evaluate('() => performance.now()');
    $page->evaluate('() => { const d = document.createElement("div"); d.style.height = "80px"; document.querySelector("main").prepend(d); }');
    Execution::instance()->wait(0.5);
    expect($page->evaluate('(mark) => window.__shifts.filter((s) => s.at > mark).length', $mark))->toBeGreaterThan(0);

    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); fetch("/stream/status-missing"); }');
    Execution::instance()->wait(0.8);
    $caught = countProblems($page);
    expect(collect($caught)->contains(fn ($e) => str_contains($e, 'positive control')))->toBeTrue(json_encode($caught))
        ->and(collect($caught)->contains(fn ($e) => str_contains($e, '404')))->toBeTrue(json_encode($caught));
});

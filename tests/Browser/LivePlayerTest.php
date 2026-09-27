<?php

use App\Models\ChessGame;
use App\Models\User;
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
| The live stream around the site (P20), in the browser
|--------------------------------------------------------------------------
|
| A local HLS stream (Tests\Support\LiveStreamFixture: ffmpeg-made fMP4
| segments behind a moving live window at /__test/live/) stands in for the
| real one. The "Watch live" tab opens a muted mini player that plays
| (currentTime runs), keeps the same <video> and its time across a
| wire:navigate, stops every request to the stream when closed (measured in
| the resource timing log against the same window while it plays), and
| stays closed after a reload. At 375 and 1440 px, with a player's match
| dock and a toast on screen, the tab, the mini player, the header badge,
| the dock, the toast and the header never overlap, and nothing scrolls
| sideways. The console and the responses stay clean, with a thrown error
| and a 404 as positive control. Off air there is no badge and no tab.
|
| P20_SHOTS=<dir> writes English screenshots.
|
*/

const LIVE_COLLECTOR = <<<'JS'
    window.__errors = [];
    performance.setResourceTimingBufferSize(20000);
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

/** Requests to the stream so far (playlist, init, segments), from the resource timing log. */
const LIVE_REQUESTS = '() => performance.getEntriesByType("resource").filter((e) => e.name.includes("/__test/live/")).length';

const LIVE_OVERFLOW = '() => document.documentElement.scrollWidth - document.documentElement.clientWidth';

const LIVE_BAD = '() => performance.getEntries().filter((e) => typeof e.responseStatus === "number" && e.responseStatus >= 400).map((e) => e.responseStatus + " " + e.name)';

const LIVE_VIDEO = <<<'JS'
    () => {
        const v = document.querySelector('[data-test=live-mini-video]');
        return v ? { time: v.currentTime, paused: v.paused, muted: v.muted, engine: v.dataset.engine ?? null, same: v === window.__video, src: v.currentSrc } : null;
    }
    JS;

/**
 * Rects of everything that shares the screen edges, and whether any two of
 * them meet: the tab or the mini player, the header, the badge, every visible
 * dock bar, every toast. Plus the document's sideways overflow.
 */
const LIVE_LAYOUT = <<<'JS'
    () => {
        const box = (el) => { if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 ? { l: r.left, t: r.top, r: r.right, b: r.bottom } : null; };
        const named = [];
        const add = (name, el) => { const r = box(el); if (r) named.push([name, r]); };
        add('player', document.querySelector('[data-test=live-mini]')?.checkVisibility() ? document.querySelector('[data-test=live-mini]') : document.querySelector('[data-test=live-tab]'));
        add('header', document.querySelector('body > header'));
        document.querySelectorAll('[data-live-floor]').forEach((el) => add(el.dataset.test || 'floor', el));
        document.querySelectorAll('[aria-live=polite] > div').forEach((el, i) => add('toast' + i, el));
        const meets = (a, b) => a.l < b.r && a.r > b.l && a.t < b.b && a.b > b.t;
        const overlaps = [];
        const player = named.find(([n]) => n === 'player');
        if (player) named.forEach(([n, r]) => { if (n !== 'player' && meets(player[1], r)) overlaps.push(n); });
        const badge = box(document.querySelector('[data-test=live-badge]'));
        const header = box(document.querySelector('header > div'));
        const round = (r) => r && [Math.round(r.l), Math.round(r.t), Math.round(r.r), Math.round(r.b)];
        return {
            rects: Object.fromEntries(named.map(([n, r]) => [n, round(r)])),
            overlaps,
            badge: round(badge),
            badgeInHeader: !!badge && !!header && badge.l >= header.l && badge.r <= header.r + 0.5 && badge.t >= header.t && badge.b <= header.b + 0.5,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            viewport: [window.innerWidth, window.innerHeight],
        };
    }
    JS;

beforeEach(function () {
    if (! LiveStreamFixture::available()) {
        $this->markTestSkipped('ffmpeg is needed for the local live stream.');
    }

    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    LiveStreamFixture::dir();
    $this->hls = sys_get_temp_dir().'/esports-live-browser-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->hls);
    config([
        'twentyone.stream.hls_dir' => $this->hls,
        'twentyone.stream.public_url' => '/__test/live/stream.m3u8',
    ]);
    Cache::forever(LiveStreamFixture::START_KEY, microtime(true));
    liveOnAir();
});

afterEach(function () {
    File::deleteDirectory($this->hls);
});

/** The playlist moved just now (the tests run longer than the 18 s it stays fresh). */
function liveOnAir(): void
{
    file_put_contents(test()->hls.'/stream.m3u8', "#EXTM3U\n");
    touch(test()->hls.'/stream.m3u8');
    Cache::forget(LiveStatus::CACHE_KEY);
}

function liveOffAir(): void
{
    File::delete(test()->hls.'/stream.m3u8');
    Cache::forget(LiveStatus::CACHE_KEY);
}

function livePage(int $width, ?User $user = null, string $to = '/'): Page
{
    $page = visit($user ? route('testing.login', ['user' => $user, 'to' => '/robots.txt']) : '/robots.txt')->page();
    $page->context()->addInitScript(LIVE_COLLECTOR);
    $page->setViewportSize($width, $width < 640 ? 667 : 900);
    liveGoto($page, $to);

    return $page;
}

function liveGoto(Page $page, string $to): void
{
    liveOnAir();
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => window.Alpine !== undefined && window.Livewire !== undefined && document.fonts.status === "loaded"', 10_000);
    Execution::instance()->wait(0.3);
}

function liveShot(Page $page, string $name): void
{
    $dir = getenv('P20_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    // Page::screenshot() freezes animations on their first frame; the tally ring should show as it runs.
    $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);

    foreach (Client::instance()->execute($guid, 'screenshot', ['type' => 'png', 'fullPage' => false, 'caret' => 'hide', 'animations' => 'allow', 'scale' => 'css']) as $message) {
        if (isset($message['result']['binary'])) {
            File::ensureDirectoryExists($dir);
            File::put($dir.'/'.$name.'.png', base64_decode((string) $message['result']['binary']));
        }
    }
}

/** Open the mini player and wait until it has played for a while. */
function livePlay(Page $page): array
{
    $page->locator('[data-test=live-tab]')->click();
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-mini-video]"); return !!v && !v.paused && v.currentTime > 1.5; }', 20_000);
    $page->evaluate('() => { window.__video = document.querySelector("[data-test=live-mini-video]"); }');

    return $page->evaluate(LIVE_VIDEO);
}

function liveErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(LIVE_BAD)];
}

test('the mini player plays muted, survives wire:navigate, stops loading when closed and stays closed', function () {
    $page = livePage(1440);
    $codecs = $page->evaluate('() => [window.MediaSource?.isTypeSupported(\'video/mp4; codecs="avc1.42c01e,mp4a.40.2"\') ?? false, document.createElement("video").canPlayType("application/vnd.apple.mpegurl")]');
    expect($codecs[0])->toBeTrue('This Chromium cannot decode H.264/AAC in MSE, so it cannot play the stream.');

    // Before anyone asks for it, nothing of the stream loads.
    expect($page->evaluate(LIVE_REQUESTS))->toBe(0)
        ->and($page->evaluate('() => document.querySelector("[data-test=live-tab]")?.checkVisibility()'))->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=live-badge]")?.checkVisibility()'))->toBeTrue();

    $playing = livePlay($page);
    fwrite(STDERR, "\n[live engine] canPlayType(HLS) ".json_encode($codecs[1]).', MSE H.264/AAC '.json_encode($codecs[0]).', playing with '.$playing['engine']."\n");
    expect($playing['muted'])->toBeTrue()
        ->and($playing['paused'])->toBeFalse()
        ->and($playing['time'])->toBeGreaterThan(1.5)
        ->and($playing['engine'])->toBe($codecs[1] === '' ? 'hls.js' : 'native');

    // It keeps playing: the live window moves and the player follows it.
    Execution::instance()->wait(2.5);
    $later = $page->evaluate(LIVE_VIDEO);
    expect($later['time'])->toBeGreaterThan($playing['time'] + 1.5);
    liveShot($page, 'player-open-1440');

    // wire:navigate (Livewire.navigate is what a wire:navigate link calls): same element, time not reset.
    liveOnAir();
    $page->evaluate('() => { window.__navigated = false; document.addEventListener("livewire:navigated", () => { window.__navigated = true; }, { once: true }); window.Livewire.navigate("/clans"); }');
    BrowserWait::until($page, '() => window.__navigated === true && location.pathname === "/clans"', 10_000);
    Execution::instance()->wait(1);
    $after = $page->evaluate(LIVE_VIDEO);
    expect($after['same'])->toBeTrue()
        ->and($after['paused'])->toBeFalse()
        ->and($after['time'])->toBeGreaterThanOrEqual($later['time'])
        ->and($page->evaluate('() => document.querySelector("h1")?.textContent.trim()'))->not->toBe('');

    // Negative control for the measurement below: while it plays, the player keeps asking for the stream.
    $before = $page->evaluate(LIVE_REQUESTS);
    Execution::instance()->wait(5);
    $whilePlaying = $page->evaluate(LIVE_REQUESTS) - $before;
    expect($whilePlaying)->toBeGreaterThanOrEqual(2);

    // Closed: not one more request to the stream in the same window.
    $page->locator('[data-test=live-close]')->click();
    Execution::instance()->wait(0.5);
    $atClose = $page->evaluate(LIVE_REQUESTS);
    Execution::instance()->wait(5);
    expect($page->evaluate(LIVE_REQUESTS) - $atClose)->toBe(0)
        ->and($page->evaluate('() => document.querySelector("[data-test=live-tab]").checkVisibility() || document.querySelector("[data-test=live-mini]").checkVisibility()'))->toBeFalse()
        ->and($page->evaluate('() => { const v = document.querySelector("[data-test=live-mini-video]"); return v.getAttribute("src") === null && v.paused; }'))->toBeTrue();

    // The choice outlives a reload: no tab, no request.
    liveOnAir();
    $page->reload();
    BrowserWait::until($page, '() => window.Alpine !== undefined && document.querySelector("[data-test=live-player]") !== null', 10_000);
    Execution::instance()->wait(1.5);
    expect($page->evaluate('() => document.querySelector("[data-test=live-tab]").checkVisibility()'))->toBeFalse()
        ->and($page->evaluate(LIVE_REQUESTS))->toBe(0)
        ->and($page->evaluate('() => localStorage.getItem("twentyone.live-player")'))->toBe('closed');

    // /live switches it back on; an open player comes back open (muted, playing) after a reload.
    liveGoto($page, '/live');
    $page->locator('[data-test=live-mini-off] button')->click();
    liveGoto($page, '/');
    livePlay($page);
    liveOnAir();
    $page->reload();
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-mini-video]"); return !!v && !v.paused && v.currentTime > 0.5; }', 20_000);
    expect($page->evaluate(LIVE_VIDEO)['muted'])->toBeTrue();

    // Minimise with the sound off: back to the tab, and the stream stops loading.
    $page->locator('[data-test=live-minimise]')->click();
    Execution::instance()->wait(0.5);
    $atMinimise = $page->evaluate(LIVE_REQUESTS);
    Execution::instance()->wait(4);
    expect($page->evaluate(LIVE_REQUESTS) - $atMinimise)->toBe(0)
        ->and($page->evaluate('() => document.querySelector("[data-test=live-tab]").checkVisibility()'))->toBeTrue()
        ->and($page->evaluate('() => localStorage.getItem("twentyone.live-player")'))->toBe('tab');

    expect(liveErrors($page))->toBe([]);
});

test('without native HLS it plays with hls.js, says so while the stream is gone, comes back on its own, and stops when closed', function () {
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(LIVE_COLLECTOR);
    // Firefox, and Chromium before its own HLS: no native playback, so the player loads hls.js.
    $page->context()->addInitScript('(() => { const original = HTMLMediaElement.prototype.canPlayType; HTMLMediaElement.prototype.canPlayType = function (type) { return /mpegurl/i.test(type) ? "" : original.call(this, type); }; })();');
    $page->setViewportSize(1440, 900);
    liveGoto($page, '/');

    $playing = livePlay($page);
    expect($playing['engine'])->toBe('hls.js')
        ->and($playing['muted'])->toBeTrue()
        ->and($page->evaluate('() => performance.getEntriesByType("resource").some((e) => /hls\.light-[\w-]+\.js$/.test(e.name))'))->toBeTrue();

    // The playlist answers 404: the player says what happens and keeps trying ...
    Cache::forever(LiveStreamFixture::DOWN_KEY, true);
    BrowserWait::until($page, '() => { const s = document.querySelector("[data-test=live-mini-status]"); return !!s && s.checkVisibility() && s.textContent.includes("Reconnecting"); }', 30_000);
    liveShot($page, 'player-reconnecting-1440');

    // ... and plays again once the stream is back, without a click.
    Cache::forget(LiveStreamFixture::DOWN_KEY);
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-mini-video]"); const s = document.querySelector("[data-test=live-mini-status]"); return !v.paused && v.readyState >= 3 && !s.checkVisibility(); }', 40_000);
    $before = $page->evaluate(LIVE_VIDEO)['time'];
    Execution::instance()->wait(2);
    expect($page->evaluate(LIVE_VIDEO)['time'])->toBeGreaterThan($before + 1);

    $page->locator('[data-test=live-close]')->click();
    Execution::instance()->wait(0.5);
    $atClose = $page->evaluate(LIVE_REQUESTS);
    Execution::instance()->wait(5);
    expect($page->evaluate(LIVE_REQUESTS) - $atClose)->toBe(0);

    // The only bad answers are the playlist's 404s while it was gone.
    $unexpected = array_values(array_filter(liveErrors($page), fn (string $entry): bool => ! str_contains($entry, '404') || ! str_contains($entry, '/__test/live/stream.m3u8')));
    expect($unexpected)->toBe([]);
});

test('tab, mini player and badge share the screen with the header, the match dock and a toast at 375 and 1440', function (int $width) {
    $player = User::factory()->member()->create(['name' => 'Pia Player']);
    // A daily game on her move: the dock shows at the bottom on every page.
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);

    $page = livePage($width, $player, '/clans');
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-live-floor]")].some((el) => el.checkVisibility())', 10_000);
    $page->evaluate('() => window.dispatchEvent(new CustomEvent("toast", { detail: { tone: "confirmed", title: "Result confirmed", text: "Pia vs Ben, 1-0" } }))');
    Execution::instance()->wait(1.2);

    $tab = $page->evaluate(LIVE_LAYOUT);
    liveShot($page, 'badge-and-tab-with-dock-'.$width);

    livePlay($page);
    Execution::instance()->wait(1.2);
    $open = $page->evaluate(LIVE_LAYOUT);
    liveShot($page, 'player-open-with-dock-'.$width);

    fwrite(STDERR, "\n[live layout {$width}] tab ".json_encode($tab)."\n[live layout {$width}] open ".json_encode($open)."\n");

    // Phones: the tab bar and the dock above it are both on screen, the player above both; the badge is in More.
    $expected = $width < 1024 ? ['player', 'header', 'toast0', 'dock-mobile-bar', 'tab-bar'] : ['player', 'header', 'toast0', 'match-dock'];

    foreach (['tab' => $tab, 'open' => $open] as $state => $layout) {
        $dump = "{$state} at {$width}: ".json_encode($layout);
        expect(array_diff($expected, array_keys($layout['rects'])))->toBe([], $dump)
            ->and($layout['overlaps'])->toBe([], $dump)
            ->and($layout['overflow'])->toBeLessThanOrEqual(0, $dump)
            // Inside the screen, at least 16 px from its left edge and above the bottom edge.
            ->and($layout['rects']['player'][0])->toBeGreaterThanOrEqual(16, $dump)
            ->and($layout['rects']['player'][3])->toBeLessThanOrEqual($layout['viewport'][1] - 8, $dump);

        if ($width < 1024) {
            // Above the highest bar: the dock, which sits above the tab bar.
            expect($layout['rects']['player'][3])->toBeLessThanOrEqual($layout['rects']['dock-mobile-bar'][1] - 8, $dump)
                ->and($layout['rects']['dock-mobile-bar'][3])->toBeLessThanOrEqual($layout['rects']['tab-bar'][1], $dump);
        } else {
            expect($layout['badgeInHeader'])->toBeTrue($dump);
        }
    }

    expect(liveErrors($page))->toBe([]);
})->with([375, 1440]);

test('on air, the badge fits the shell at 320 to 1440 px in English and German, for guest, player and admin', function () {
    $users = ['guest' => null, 'player' => shellPlayer(), 'admin' => shellAdmin()];
    $problems = [];
    $failures = [];
    $offAirSqueezed = [];
    $sizes = [1440 => 900, 1280 => 800, 1024 => 768, 768 => 1024, 390 => 844, 375 => 667, 360 => 740, 320 => 568];

    foreach ($users as $role => $user) {
        foreach (['en', 'de'] as $locale) {
            foreach ($sizes as $width => $height) {
                liveOnAir();
                Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 128], 600);
                $page = shellPage($user, $width, $height);
                // Both ways: a logged-in player keeps the language on the account.
                $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
                shellOpen($page, '/rules', $problems);
                $m = $page->evaluate(SHELL_MEASURE);
                $badge = $page->evaluate(<<<'JS'
                    () => {
                        // Two in the header (row 1 from lg, the top bar from md); at most one shows.
                        const shown = [...document.querySelectorAll('[data-test=live-badge]')].filter((el) => el.checkVisibility());
                        if (shown.length > 1) return { shown: shown.length };
                        const a = shown[0];
                        if (!a) return null;
                        const r = a.getBoundingClientRect(), row = document.querySelector('body > header > div').getBoundingClientRect();
                        const chips = document.getElementById('game-chips');
                        const c = chips && chips.checkVisibility() ? chips.getBoundingClientRect() : null;
                        const search = document.querySelector('[data-test=site-search-form]');
                        const s = search && search.checkVisibility() ? search.getBoundingClientRect() : null;
                        return { rect: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)], inRow: r.left >= row.left && r.right <= row.right + 0.5 && r.top >= row.top && r.bottom <= row.bottom + 0.5,
                            clearOfChips: !c || r.left >= c.right - 0.5, clearOfSearch: !s || r.right <= s.left + 0.5, chipsWidth: c ? Math.round(c.width) : null };
                    }
                    JS);
                fwrite(STDERR, "\n[live shell] {$role} {$locale} @{$width}: badge ".json_encode($badge).' shell '.json_encode(['scroll' => $m['scroll'], 'client' => $m['client'], 'squeezed' => $m['squeezed'], 'small' => $m['small']]));

                $more = $page->evaluate('() => !!document.querySelector("#more-sheet [data-test=mobile-live-on-air]")');

                if ($width < 768) {
                    // Phones: no room in the top bar (the game chips); the badge's dot is in More.
                    if ($badge !== null || ! $more) {
                        $failures[] = "{$role} {$locale} @{$width}: badge ".json_encode($badge).', in More: '.json_encode($more);
                    }
                } elseif ($badge === null || ! $badge['inRow'] || ! $badge['clearOfChips'] || ! $badge['clearOfSearch'] || $badge['rect'][3] < 44) {
                    $failures[] = "{$role} {$locale} @{$width}: badge ".json_encode($badge);
                }
                $squeezed = $m['squeezed'];

                if ($squeezed !== []) {
                    // Only what the stream adds counts here: the same page off air is the baseline.
                    liveOffAir();
                    shellOpen($page, '/rules', $problems);
                    $baseline = $page->evaluate(SHELL_MEASURE)['squeezed'];
                    $squeezed = array_values(array_diff($squeezed, $baseline));
                    fwrite(STDERR, "\n[live shell] {$role} {$locale} @{$width}: off air already squeezed ".json_encode($baseline));
                    $offAirSqueezed[] = "{$role} {$locale} @{$width}: ".json_encode($baseline);
                }

                if ($m['scroll'] > $m['client'] || $squeezed !== [] || $m['small'] !== [] || $m['lang'] !== $locale) {
                    $failures[] = "{$role} {$locale} @{$width}: ".json_encode($m);
                }
                if ($locale === 'en' && $role === 'guest' && in_array($width, [768, 1024, 1440], true)) {
                    liveShot($page, "header-badge-{$width}");
                }
                if ($locale === 'en' && $role === 'player' && $width === 375) {
                    $page->locator('[data-test=tab-more]')->click();
                    $page->evaluate(SHELL_SETTLE);
                    liveShot($page, 'more-sheet-live-375');
                }
            }
        }
    }

    fwrite(STDERR, "\n[live shell] squeezed off air too (not the badge): ".json_encode($offAirSqueezed)."\n");

    expect($failures)->toBe([])->and($problems)->toBe([]);
});

test('off air there is no badge and no tab, and /live says so; the collector catches a thrown error and a 404', function (int $width) {
    liveOffAir();
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(LIVE_COLLECTOR);
    $page->setViewportSize($width, $width < 640 ? 667 : 900);
    $page->goto(ComputeUrl::from('/'));
    BrowserWait::until($page, '() => window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);

    // In the page for the live feed (P20b), but nothing of it shows off air.
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=live-badge], [data-test=live-tab], [data-test=live-mini]")].filter((el) => el.checkVisibility()).length'))->toBe(0)
        ->and($page->evaluate(LIVE_REQUESTS))->toBe(0);

    $page->goto(ComputeUrl::from('/live'));
    BrowserWait::until($page, '() => window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);
    Execution::instance()->wait(0.3);
    expect($page->evaluate('() => document.querySelector("[data-test=live-offline]")?.checkVisibility()'))->toBeTrue()
        ->and($page->evaluate(LIVE_OVERFLOW))->toBeLessThanOrEqual(0);
    liveShot($page, 'live-page-offline-'.$width);
    expect(liveErrors($page))->toBe([]);

    // Positive control: the same collector sees a thrown error and a 404.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); fetch("/__test/live/seg-999.m4s"); }');
    Execution::instance()->wait(0.8);
    $caught = liveErrors($page);
    expect(collect($caught)->contains(fn ($e) => str_contains($e, 'positive control')))->toBeTrue(json_encode($caught))
        ->and(collect($caught)->contains(fn ($e) => str_contains($e, '404')))->toBeTrue(json_encode($caught));
})->with([375, 1440]);

test('/live on air: the big player plays, the floating one is not there, nothing overflows', function (int $width) {
    ChessGame::factory()->create();
    $page = livePage($width, null, '/live');

    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-stage-video]"); return !!v && !v.paused && v.currentTime > 1; }', 20_000);
    expect($page->evaluate('() => document.querySelector("[data-test=live-player]")'))->toBeNull()
        ->and($page->evaluate('() => document.querySelector("[data-test=live-stage-video]").muted'))->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=live-badge]")?.getAttribute("aria-current")'))->toBe('page')
        ->and($page->evaluate(LIVE_OVERFLOW))->toBeLessThanOrEqual(0);

    // The stage fills its column at 16:9.
    $stage = $page->evaluate('() => { const r = document.querySelector("[data-test=live-stage-video]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }');
    expect(abs($stage[0] / 16 * 9 - $stage[1]))->toBeLessThanOrEqual(1);
    fwrite(STDERR, "\n[live page {$width}] stage ".json_encode($stage)."\n");

    liveShot($page, 'live-page-'.$width);
    expect(liveErrors($page))->toBe([]);
})->with([375, 1440]);

/**
 * Autoplay refused for every medium, muted included (LibreWolf, Firefox with
 * "block audio and video", Safari's "never auto-play"): play() rejects with a
 * NotAllowedError unless a click came within the last second, as those
 * browsers allow a play() inside a user gesture.
 * Layout shifts after a mark are recorded to check the button moves nothing.
 */
const LIVE_NO_AUTOPLAY = <<<'JS'
    window.__refusals = 0;
    window.__shifts = [];
    const nativePlay = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function () {
        // A click in the last second is the gesture (Playwright's evaluate() counts as user activation, so that flag cannot tell).
        if (!(performance.now() - (window.__gestureAt ?? -Infinity) < 1000)) {
            window.__refusals += 1;
            return Promise.reject(new DOMException('Autoplay is only allowed when approved by the user, the site is activated by the user, or media is muted.', 'NotAllowedError'));
        }
        return nativePlay.call(this);
    };
    window.addEventListener('pointerdown', () => { window.__gestureAt = performance.now(); }, true);
    new PerformanceObserver((list) => list.getEntries().forEach((e) => { if (!e.hadRecentInput) window.__shifts.push({ value: e.value, at: e.startTime }); })).observe({ type: 'layout-shift', buffered: true });
    JS;

/** The play button over a refused stage: its box, the stage's box, the hint text and whether it fits. */
const LIVE_BLOCKED = <<<'JS'
    (which) => {
        const button = document.querySelector(`[data-test=live-${which}-play]`);
        const status = document.querySelector(`[data-test=live-${which}-status]`);
        const stage = status?.parentElement;
        const box = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; };
        if (!button || !button.checkVisibility()) return null;
        const b = button.getBoundingClientRect(), s = stage.getBoundingClientRect();
        return {
            button: box(button), stage: box(stage),
            inside: b.left >= s.left && b.right <= s.right && b.top >= s.top && b.bottom <= s.bottom,
            // What shows: innerText skips the hidden "Try again" of the ended state.
            text: status.innerText.replace(/\s+/g, ' ').trim(),
            fits: status.scrollHeight <= status.clientHeight + 1 && status.scrollWidth <= status.clientWidth + 1,
            hit: document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2)?.closest('button') === button,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

function liveNoAutoplayPage(int $width, string $to): Page
{
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(LIVE_COLLECTOR);
    $page->context()->addInitScript(LIVE_NO_AUTOPLAY);
    $page->setViewportSize($width, $width < 640 ? 667 : 900);
    liveGoto($page, $to);

    return $page;
}

test('autoplay refused even muted: /live and the mini player show a play button within 2 s, and a click plays', function (int $width) {
    $page = liveNoAutoplayPage($width, '/live');

    // Positive control: without a gesture the stub really refuses, with the name browsers use.
    $control = $page->evaluate('() => Promise.race([document.createElement("video").play().then(() => "played", (e) => e.name), new Promise((resolve) => setTimeout(() => resolve("pending, activation " + navigator.userActivation?.isActive + ", stub " + (window.__refusals !== undefined)), 1000))])');
    expect($control)->toBe('NotAllowedError');

    BrowserWait::until($page, '() => !!document.querySelector("[data-test=live-stage-play]")?.checkVisibility()', 2_000);
    $mark = $page->evaluate('() => performance.now()');
    Execution::instance()->wait(0.5);
    $stage = $page->evaluate(LIVE_BLOCKED, 'stage');
    liveShot($page, "autoplay-blocked-live-{$width}");
    fwrite(STDERR, "\n[autoplay {$width}] /live ".json_encode($stage)."\n");

    expect($stage['text'])->toBe('Your browser blocks autoplay. Press play, or allow autoplay for this site in the address bar.')
        ->and($stage['button'][2])->toBeGreaterThanOrEqual(44)->and($stage['button'][3])->toBeGreaterThanOrEqual(44)
        ->and($stage['inside'])->toBeTrue()->and($stage['fits'])->toBeTrue()->and($stage['hit'])->toBeTrue()
        ->and($stage['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__refusals'))->toBeGreaterThanOrEqual(1);

    $page->locator('[data-test=live-stage-play]')->click();
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-stage-video]"); return !v.paused && v.currentTime > 0.5 && !document.querySelector("[data-test=live-stage-status]").checkVisibility(); }', 20_000);
    expect($page->evaluate('(mark) => window.__shifts.filter((s) => s.at > mark)', $mark))->toBe([]);

    // The mini player, opened again after a reload (no gesture): the same button, sized for its 16:9 box.
    $page->evaluate('() => localStorage.setItem("twentyone.live-player", "open")');
    liveGoto($page, '/rules');
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=live-mini-play]")?.checkVisibility()', 2_000);
    Execution::instance()->wait(0.5);
    $mini = $page->evaluate(LIVE_BLOCKED, 'mini');
    liveShot($page, "autoplay-blocked-mini-{$width}");
    fwrite(STDERR, "\n[autoplay {$width}] mini ".json_encode($mini)."\n");

    expect($mini['text'])->toBe($stage['text'])
        ->and($mini['button'][2])->toBeGreaterThanOrEqual(44)->and($mini['button'][3])->toBeGreaterThanOrEqual(44)
        ->and($mini['inside'])->toBeTrue()->and($mini['fits'])->toBeTrue()->and($mini['hit'])->toBeTrue()
        ->and($mini['overflow'])->toBeLessThanOrEqual(0)
        // No hint on the tab: its line still says what is on.
        ->and($page->evaluate('() => document.querySelector("[data-test=live-tab]").textContent'))->not->toContain('autoplay');

    $page->locator('[data-test=live-mini-play]')->click();
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-mini-video]"); return !v.paused && v.currentTime > 0.5 && !document.querySelector("[data-test=live-mini-status]").checkVisibility(); }', 20_000);

    expect(liveErrors($page))->toBe([]);
    $page->locator('[data-test=live-close]')->click();
})->with([375, 1440]);

test('autoplay refused with hls.js (Firefox, LibreWolf): the play button shows and a click plays', function () {
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(LIVE_COLLECTOR);
    $page->context()->addInitScript(LIVE_NO_AUTOPLAY);
    // No native HLS, as in Firefox: the player takes hls.js.
    $page->context()->addInitScript('(() => { const original = HTMLMediaElement.prototype.canPlayType; HTMLMediaElement.prototype.canPlayType = function (type) { return /mpegurl/i.test(type) ? "" : original.call(this, type); }; })();');
    $page->setViewportSize(1440, 900);
    liveGoto($page, '/live');

    BrowserWait::until($page, '() => !!document.querySelector("[data-test=live-stage-play]")?.checkVisibility()', 2_000);
    expect($page->evaluate('() => document.querySelector("[data-test=live-stage-video]").dataset.engine'))->toBe('hls.js');

    $page->locator('[data-test=live-stage-play]')->click();
    BrowserWait::until($page, '() => { const v = document.querySelector("[data-test=live-stage-video]"); return !v.paused && v.currentTime > 0.5 && !document.querySelector("[data-test=live-stage-status]").checkVisibility(); }', 20_000);
    expect(liveErrors($page))->toBe([]);
});

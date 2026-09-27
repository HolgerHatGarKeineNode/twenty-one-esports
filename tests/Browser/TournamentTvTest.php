<?php

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament TV in the browser (P19)
|--------------------------------------------------------------------------
|
| A guest opens the TV of a running 8-player bracket at 1920×1080. The
| scenes rotate on their own; three results entered through the service
| layer reach the open page over Reverb without a reload (the DOM updates
| well inside the 20-second poll), each one slams in and lights its winner
| line. Every scene is then measured at 1920×1080 and 3840×2160: the stage
| fills the viewport, the type doubles, nothing is smaller than 24 px at
| 1080p and nothing leaves its scene. Console (errors and warnings, uncaught
| errors, rejected promises) and responses (fetch, XHR, loaded resources)
| stay clean, with a thrown error and a missing image as positive control.
| With reduced motion the TV stays live without slam or confetti.
|
| TV_SHOTS=<dir> writes the English screenshots of every scene at both
| sizes. TV_SOAK=<minutes> runs the long test: results keep arriving every
| few seconds while the scenes rotate fast, heap and DOM nodes are sampled.
|
*/

const TV_COLLECTOR = <<<'JS'
    window.__errors = [];
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
    // Which boxes got the result moment, and how many server round trips the TV made.
    window.__slams = [];
    window.__news = [];
    new MutationObserver((records) => records.forEach((r) => {
        if (r.attributeName === 'class' && r.target.classList?.contains('is-slam')) window.__slams.push(r.target.dataset.key);
        if (r.attributeName === 'class' && r.target.classList?.contains('is-new')) window.__news.push(r.target.dataset.key);
    })).observe(document, { subtree: true, attributes: true, attributeFilter: ['class'] });
    JS;

const TV_STATE = <<<'JS'
    () => {
        const stage = document.querySelector('.tv-stage');
        const scene = document.querySelector(`[data-scene-id="${stage?.dataset.scene}"]`);
        const s = stage?.getBoundingClientRect();
        const sr = scene?.getBoundingClientRect();
        // Every visible text of the stage: the smallest font, and anything that leaves the stage or its scene.
        let minFont = Infinity, minFontText = null;
        const escapes = [];
        const walker = document.createTreeWalker(stage, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const el = node.parentElement;
            if (!node.textContent.trim() || !el.checkVisibility({ visibilityProperty: true, opacityProperty: true }) || el.closest('.tv-hint, .tv-ticker-track, svg, .sr-only')) continue;
            if (el.closest('.tv-scene') && el.closest('.tv-scene') !== scene) continue;
            const size = parseFloat(getComputedStyle(el).fontSize);
            if (size < minFont) { minFont = size; minFontText = node.textContent.trim().slice(0, 40); }
            const range = document.createRange(); range.selectNodeContents(node);
            const r = range.getBoundingClientRect();
            const box = el.closest('.tv-scene') ? sr : s;
            if (r.width > 0 && (r.left < box.left - 1 || r.right > box.right + 1 || r.top < box.top - 1 || r.bottom > box.bottom + 1)) escapes.push(node.textContent.trim().slice(0, 30));
        }
        return {
            scene: stage?.dataset.scene ?? null,
            scenes: [...document.querySelectorAll('[data-scene-id]')].map((el) => el.dataset.sceneId),
            stage: s ? [Math.round(s.width), Math.round(s.height), Math.round(s.left), Math.round(s.top)] : null,
            name: parseFloat(getComputedStyle(document.querySelector('.tv-name')).fontSize),
            minFont: Math.round(minFont * 10) / 10, minFontText, escapes,
            // Names cut by their ellipsis (the scene's own names and the header).
            clipped: [...document.querySelectorAll('.tv-name'), ...(scene?.querySelectorAll('.tv-side-name, .tv-duel-name, .tv-champion-name, .tv-row-name, .tv-pot-contenders span, .tv-lobby-faces span') ?? [])]
                .filter((el) => el.checkVisibility() && el.scrollWidth > el.clientWidth + 1).map((el) => el.textContent.trim()),
            boxes: document.querySelectorAll('[data-scene-id="bracket"] [data-test=tv-box]').length,
            done: document.querySelectorAll('[data-scene-id="bracket"] [data-test=tv-box][data-status=done]').length,
            lit: document.querySelectorAll('[data-scene-id="bracket"] path.tv-line.is-lit').length,
            lines: document.querySelectorAll('[data-scene-id="bracket"] path.tv-line').length,
            ticks: document.querySelectorAll('[data-test=tv-tick]').length,
            marker: window.__marker ?? null,
            slams: window.__slams ?? [], news: window.__news ?? [],
            errors: window.__errors ?? ['collector missing'],
            bad: performance.getEntries().filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400).map((e) => e.responseStatus + ' ' + e.name),
            overflow: [document.documentElement.scrollWidth - document.documentElement.clientWidth, document.documentElement.scrollHeight - document.documentElement.clientHeight],
            socket: window.Echo?.connector?.pusher?.connection?.state ?? 'none',
            nodes: document.getElementsByTagName('*').length,
            heap: performance.memory ? performance.memory.usedJSHeapSize : null,
            lang: document.documentElement.lang,
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

    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

const TV_NAMES = ['Wels', 'markusturm', 'hodlqueen', 'satsjaeger', 'rbf_rita', 'nakamoto_nick', 'blockbauer', 'zapzap'];

/** A running, published chess tournament whose entries carry real-looking names. */
function tvTournament(TournamentFormat $format, int $n, string $name, array $options = []): Tournament
{
    $tournament = runningChess($format, $n, options: $options);
    $tournament->forceFill(['name' => $name, 'published_at' => now()->subHour(), 'draw_height' => 915000, 'draw_hash' => str_repeat('ab', 32)])->save();

    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $label = TV_NAMES[$index % count(TV_NAMES)].($index >= count(TV_NAMES) ? '_'.$index : '');
        $participant->forceFill(['name' => $label])->save();
        User::query()->whereKey($participant->user_id)->update(['name' => $label]);
    }

    return $tournament->refresh();
}

/** Enter `$count` results of the open round, the better seed winning. */
function tvResults(Tournament $tournament, int $count, bool $upset = false): array
{
    $runner = app(TournamentRunner::class);
    $keys = [];

    foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->where('tournament_round_id', TournamentRunner::currentRound($tournament)?->id)->where('status', 'ready')->where('bracket', '!=', 'bye')->with('slots.participant')->orderBy('id')->limit($count)->get() as $match) {
        $better = $match->slots[0]->participant->seed < $match->slots[1]->participant->seed ? 0 : 1;
        $winner = $upset ? 1 - $better : $better;
        $runner->enterResult($match, $tournament->creator, ['result' => $winner === 0 ? '1-0' : '0-1']);
        $keys[] = $match->key;
    }

    return $keys;
}

function tvPage(int $width = 1920, int $height = 1080): Page
{
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(TV_COLLECTOR);
    $page->setViewportSize($width, $height);

    return $page;
}

function tvOpen(Page $page, Tournament $tournament): array
{
    $page->goto(ComputeUrl::from(route('tournaments.tv', $tournament)));
    BrowserWait::until($page, '() => window.Alpine !== undefined && document.querySelector(".tv-stage") !== null && document.fonts.status === "loaded"', 10_000);

    return $page->evaluate(TV_STATE);
}

/**
 * The full-screen hint while it shows (on load and whenever someone moves the mouse): it must be
 * visible, and neither the active scene nor anything inside it may reach under it.
 */
const TV_HINT = <<<'JS'
    () => {
        const hint = document.querySelector('[data-test=tv-hint]');
        const h = hint.getBoundingClientRect();
        const scene = document.querySelector(`[data-scene-id="${document.querySelector('.tv-stage').dataset.scene}"]`);
        const meets = (r) => r.width > 0 && r.height > 0 && r.left < h.right && r.right > h.left && r.top < h.bottom && r.bottom > h.top;
        const hits = [scene, ...scene.querySelectorAll('*')]
            .filter((el) => !el.closest('.tv-confetti') && meets(el.getBoundingClientRect()))
            .map((el) => (el.getAttribute('class') ?? el.tagName).split(' ')[0] + ':' + (el.textContent ?? '').trim().slice(0, 20));
        return { visible: hint.checkVisibility({ visibilityProperty: true, opacityProperty: true }), rect: [Math.round(h.left), Math.round(h.top), Math.round(h.right), Math.round(h.bottom)], hits };
    }
    JS;

/**
 * Show a scene by hand (as the arrow keys do), with someone at the screen: the hint is up while the
 * scene is measured (`hint`), then it is let go for the screenshot.
 */
function tvShow(Page $page, string $scene): array
{
    $page->evaluate('(scene) => { const root = document.querySelector(".tv"); const tv = window.Alpine.$data(root); tv.show(scene, true); tv.wake(); root.querySelectorAll("[data-scene-id]").forEach((el) => el.dataset.dwell = "600"); }', $scene);
    Execution::instance()->wait(1.2);
    $hint = $page->evaluate(TV_HINT);
    $page->evaluate('() => document.querySelector(".tv-stage").setAttribute("data-idle", "")');
    Execution::instance()->wait(0.4);

    return $page->evaluate(TV_STATE) + ['hint' => $hint];
}

function tvShot(Page $page, string $name): void
{
    $dir = getenv('TV_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    // Page::screenshot() sends `animations: disabled`, which resets every infinite animation (the confetti,
    // the live dots) to its first frame: the picture would not show the TV as it runs. Ask with `allow`.
    $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);

    foreach (Client::instance()->execute($guid, 'screenshot', ['type' => 'png', 'fullPage' => false, 'caret' => 'hide', 'animations' => 'allow', 'scale' => 'css']) as $message) {
        if (isset($message['result']['binary'])) {
            File::ensureDirectoryExists($dir);
            File::put($dir.'/'.$name.'.png', base64_decode((string) $message['result']['binary']));
        }
    }
}

function tvReducedMotion(Page $page): void
{
    $guid = (new ReflectionProperty(Page::class, 'guid'))->getValue($page);
    iterator_to_array(Client::instance()->execute($guid, 'emulateMedia', ['reducedMotion' => 'reduce']));
}

function tvFakePool(): void
{
    // A pool as P9 will hand it over; the TV only draws what it gets.
    app()->instance(TournamentPrizePool::class, new class implements TournamentPrizePool
    {
        public function for(Tournament $tournament): ?array
        {
            return ['sats' => 2_100_000, 'split' => [['place' => 1, 'percent' => 50, 'sats' => 1_050_000], ['place' => 2, 'percent' => 30, 'sats' => 630_000], ['place' => 3, 'percent' => 20, 'sats' => 420_000]], 'sponsors' => []];
        }
    });
}

test('the TV rotates its scenes and shows three new results live, without a reload, at 1920×1080 and 3840×2160', function () {
    $tournament = tvTournament(TournamentFormat::SingleElimination, 8, 'Blitz Night Munich');
    $page = tvPage();
    $first = tvOpen($page, $tournament);

    expect($first['scene'])->toBe('bracket')
        ->and($first['scenes'])->toBe(['bracket', 'spotlight'])
        ->and($first['boxes'])->toBe(7)
        ->and($first['done'])->toBe(0)
        ->and($first['lang'])->toBe('en')
        ->and($first['stage'])->toBe([1920, 1080, 0, 0]);

    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"', 10_000);

    // Rotation on its own: shorten the dwell and watch the scenes change.
    $page->evaluate('() => document.querySelectorAll("[data-scene-id]").forEach((el) => el.dataset.dwell = "2")');
    BrowserWait::until($page, '() => document.querySelector(".tv-stage").dataset.scene === "spotlight"', 6_000);
    BrowserWait::until($page, '() => document.querySelector(".tv-stage").dataset.scene === "bracket"', 6_000);

    // Three results through the service layer; the page must not reload.
    $page->evaluate('() => { window.__marker = "same-document"; document.querySelectorAll("[data-scene-id]").forEach((el) => el.dataset.dwell = "600"); }');
    $started = microtime(true);
    $keys = tvResults($tournament, 3);
    BrowserWait::until($page, '() => document.querySelectorAll(\'[data-scene-id="bracket"] [data-test=tv-box][data-status=done]\').length === 3', 10_000);
    $latency = microtime(true) - $started;
    $page->evaluate('() => document.querySelector(".tv-stage").setAttribute("data-idle", "")');
    Execution::instance()->wait(0.8);
    tvShot($page, 'tv-1920-result-moment');
    Execution::instance()->wait(0.7);
    $live = $page->evaluate(TV_STATE);

    expect($live['marker'])->toBe('same-document')
        ->and($live['done'])->toBe(3)
        ->and($latency)->toBeLessThan(8.0)
        ->and(array_values(array_unique($live['slams'])))->toEqualCanonicalizing($keys)
        ->and($live['scene'])->toBe('bracket')
        ->and($live['lit'])->toBe(3)
        ->and($live['lines'])->toBe(6)
        ->and($live['ticks'])->toBe(3);

    // Every scene at both sizes: the stage fills the screen, the type scales with it, nothing is tiny or cut.
    tvFakePool();
    $page->goto(ComputeUrl::from(route('tournaments.tv', $tournament)));
    BrowserWait::until($page, '() => document.querySelector("[data-scene-id=pot]") !== null && window.Alpine !== undefined', 10_000);
    $measured = [];

    foreach ([[1920, 1080], [3840, 2160]] as [$width, $height]) {
        $page->setViewportSize($width, $height);

        foreach (['bracket', 'spotlight', 'pot'] as $scene) {
            $measured[$width][$scene] = tvShow($page, $scene);
            tvShot($page, "tv-{$width}-{$scene}");
        }
    }

    foreach ($measured as $width => $scenes) {
        foreach ($scenes as $scene => $state) {
            expect([$width, $scene, $state['hint']['visible'], $state['hint']['hits']])->toBe([$width, $scene, true, []]);
            expect([$width, $scene, $state['scene'], $state['escapes'], $state['clipped'], $state['overflow'], $state['errors'], $state['bad']])->toBe([$width, $scene, $scene, [], [], [0, 0], [], []])
                ->and($state['minFont'])->toBeGreaterThanOrEqual(24 * $width / 1920 - 0.5);
        }
    }

    expect($measured[1920]['bracket']['stage'])->toBe([1920, 1080, 0, 0])
        ->and($measured[3840]['bracket']['stage'])->toBe([3840, 2160, 0, 0])
        ->and($measured[3840]['bracket']['name'] / $measured[1920]['bracket']['name'])->toEqualWithDelta(2.0, 0.01);

    // Positive control: a thrown error and a missing image reach the collectors.
    $page->evaluate('() => { const img = new Image(); img.src = "/images/games/not-there.webp"; document.body.append(img); setTimeout(() => { throw new Error("probe"); }, 0); }');
    BrowserWait::until($page, '() => window.__errors.length > 0 && performance.getEntries().some((e) => e.name.includes("not-there"))', 5_000);
    $control = $page->evaluate(TV_STATE);

    expect(implode(' | ', $control['errors']))->toContain('probe')
        ->and(implode(' | ', $control['bad']))->toContain('404');

    fwrite(STDERR, "\n[tv] latency ".round($latency, 2).'s '.json_encode(['live' => array_diff_key($live, ['errors' => 0]), 'measured' => array_map(fn (array $scenes): array => array_map(fn (array $s): array => array_intersect_key($s, array_flip(['stage', 'name', 'minFont', 'minFontText', 'nodes'])), $scenes), $measured)])."\n");
});

test('the champion, the tables and the lobby, and reduced motion: still live, nothing flies', function () {
    $finished = tvTournament(TournamentFormat::SingleElimination, 4, 'Genesis Open');
    playOutAsDirector($finished);
    $groups = tvTournament(TournamentFormat::TwoStage, 8, 'Halving Cup', ['groupSize' => 4, 'advance' => 2]);
    tvResults($groups, 4);
    $open = openTournament(['name' => 'Einundzwanzig Fifa 2026']);

    foreach (array_slice(TV_NAMES, 0, 5) as $name) {
        [$user, $signer] = keyedPlayer();
        $user->forceFill(['name' => $name])->save();
        soloSignup($open, $user, $signer);
    }

    $page = tvPage();
    $measured = [];

    foreach ([[1920, 1080], [3840, 2160]] as [$width, $height]) {
        $page->setViewportSize($width, $height);

        foreach ([[$finished, 'champion'], [$groups, 'standings'], [$open, 'lobby']] as [$tournament, $scene]) {
            tvOpen($page, $tournament);

            if ($scene === 'champion') {
                // Fresh load, nobody touched anything: the champion is the first scene and the hint is up.
                Execution::instance()->wait(0.8);
                $fresh = $page->evaluate(TV_HINT);
                expect([$width, $fresh['visible'], $fresh['hits']])->toBe([$width, true, []]);
            }
            $measured[$width][$scene] = tvShow($page, $scene);
            tvShot($page, "tv-{$width}-{$scene}");

            if ($scene === 'champion') {
                // With motion allowed the confetti falls: the positive control of the reduced-motion check below.
                $confettiRunning = $page->evaluate('() => document.getAnimations().filter((a) => a.animationName === "tv-fall" && a.playState === "running").length');
                expect($confettiRunning)->toBe(36);
            }

            expect([$width, $scene, $measured[$width][$scene]['hint']['visible'], $measured[$width][$scene]['hint']['hits']])->toBe([$width, $scene, true, []]);
            expect([$width, $scene, $measured[$width][$scene]['scene'], $measured[$width][$scene]['escapes'], $measured[$width][$scene]['clipped'], $measured[$width][$scene]['errors'], $measured[$width][$scene]['bad']])->toBe([$width, $scene, $scene, [], [], [], []])
                ->and($measured[$width][$scene]['minFont'])->toBeGreaterThanOrEqual(24 * $width / 1920 - 0.5);
        }
    }

    // The reviewer's other two sizes (a laptop and a phone held sideways is not a TV, but the page still opens there).
    foreach ([[1440, 900], [390, 844]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        tvOpen($page, $finished);
        Execution::instance()->wait(0.8);
        $fresh = $page->evaluate(TV_HINT);
        expect([$width, $fresh['visible'], $fresh['hits']])->toBe([$width, true, []]);
    }

    // Reduced motion: no confetti, no slam; a new result still arrives and is marked.
    $running = tvTournament(TournamentFormat::SingleElimination, 4, 'Calm Cup');
    $page->setViewportSize(1920, 1080);
    tvReducedMotion($page);
    tvOpen($page, $finished);
    $confetti = $page->evaluate('() => getComputedStyle(document.querySelector(".tv-confetti")).display');
    $state = tvOpen($page, $running);
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"', 10_000);
    $page->evaluate('() => window.__marker = "same-document"');
    $keys = tvResults($running, 1);
    BrowserWait::until($page, '() => document.querySelectorAll(\'[data-scene-id="bracket"] [data-test=tv-box][data-status=done]\').length === 1', 10_000);
    $calm = $page->evaluate(TV_STATE);
    $moving = $page->evaluate('() => document.getAnimations().filter((a) => a.playState === "running" && !a.effect?.target?.closest?.(".tv-hint")).map((a) => (a.animationName ?? a.transitionProperty) + " on " + (a.effect?.target?.className?.baseVal ?? a.effect?.target?.className ?? "?"))');
    tvShot($page, 'tv-1920-reduced-motion');

    expect($confetti)->toBe('none')
        ->and($calm['marker'])->toBe('same-document')
        ->and(array_values(array_unique($calm['news'])))->toBe($keys)
        ->and($calm['slams'])->toBe([])
        ->and($moving)->toBe([])
        ->and($calm['errors'])->toBe([])
        ->and($state['scene'])->toBe('bracket');

    fwrite(STDERR, "\n[tv-scenes] ".json_encode(array_map(fn (array $scenes): array => array_map(fn (array $s): array => array_intersect_key($s, array_flip(['stage', 'minFont', 'minFontText'])), $scenes), $measured))."\n");
});

test('soak: hours of results do not grow the DOM, and the heap stays bounded by the renewal', function () {
    $minutes = (float) getenv('TV_SOAK');
    // The TV renews itself after `data-renew-after` renders (400 in production); here after 100, so ten
    // minutes of results at the accelerated pace (a render every 2.5 s) cross it twice.
    $renewAfter = 100;
    $tournament = tvTournament(TournamentFormat::SingleElimination, 16, 'Soak Cup');
    $page = tvPage();
    // Re-armed on every load: fast rotation (every scene comes and goes many times), the lower renewal
    // count, and a load counter that survives the renewal.
    $page->context()->addInitScript('sessionStorage.setItem("tvLoads", String(Number(sessionStorage.getItem("tvLoads") ?? 0) + 1));
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelector(".tv-stage")?.setAttribute("data-renew-after", "'.$renewAfter.'");
            setInterval(() => document.querySelectorAll("[data-scene-id]").forEach((el) => el.dataset.dwell = "3"), 1000);
        });');
    tvOpen($page, $tournament);
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"', 10_000);

    $runner = app(TournamentRunner::class);
    $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->orderBy('id')->get();
    $samples = [];
    $errors = [];
    $bad = [];
    $updates = 0;
    $started = microtime(true);
    $deadline = $started + $minutes * 60;

    // Results keep coming: the directors correct the open round back and forth (each correction moves
    // the winner into round 2 again), one every 2.5 s, so the TV slams, redraws lines and re-renders.
    while (microtime(true) < $deadline) {
        $match = $matches[$updates % count($matches)];
        $runner->enterResult($match->refresh(), $tournament->creator, ['result' => intdiv($updates, count($matches)) % 2 === 0 ? '1-0' : '0-1']);
        $updates++;
        Execution::instance()->wait(2.5);

        // Every ~10 s, right after a full collection when Chromium runs with
        // CHROMIUM_EXTRA_FLAGS="--js-flags=--expose-gc --enable-precise-memory-info" (without it the
        // heap swings with the collector and only window floors mean anything).
        if ($updates % 4 === 0) {
            try {
                $page->evaluate('() => { if (typeof window.gc === "function") { window.gc(); } }');
                $state = $page->evaluate(TV_STATE);
            } catch (Throwable) {
                continue; // the page was renewing itself in this very moment
            }
            $loads = (int) $page->evaluate('() => Number(sessionStorage.getItem("tvLoads"))');
            $samples[] = ['t' => (int) round(microtime(true) - $started), 'heap' => $state['heap'], 'nodes' => $state['nodes'], 'loads' => $loads];
            array_push($errors, ...$state['errors']);
            array_push($bad, ...$state['bad']);
            // The slam log is the test's, not the TV's: drop it so it does not count as growth.
            $page->evaluate('() => { window.__slams.length = 0; window.__news.length = 0; window.__errors.length = 0; }');
        }
    }

    $end = $page->evaluate(TV_STATE);
    array_push($errors, ...$end['errors']);
    $total = microtime(true) - $started;
    $forcedGc = $page->evaluate('() => typeof window.gc === "function"');
    $loads = (int) $page->evaluate('() => Number(sessionStorage.getItem("tvLoads"))');
    // Warm-up is the first minute (the ticker fills to its eight results).
    $window = fn (float $from, float $to): array => array_column(array_filter($samples, fn (array $s): bool => $s['t'] >= $from && $s['t'] < $to), 'heap');
    $heapStart = min($window(60, 180) ?: [PHP_INT_MAX]);
    $heapMax = max($window(60, $total + 1) ?: [0]);
    $heapEnd = min($window($total - 120, $total + 1) ?: [0]);
    $afterWarmup = array_values(array_filter($samples, fn (array $s): bool => $s['t'] >= 60));
    $perMinute = [];

    foreach ($samples as $sample) {
        $minute = intdiv($sample['t'], 60);
        $perMinute[$minute] = min($perMinute[$minute] ?? PHP_INT_MAX, $sample['heap']);
    }

    fwrite(STDERR, "\n[tv-soak] ".json_encode(['forcedGc' => $forcedGc, 'minutes' => $minutes, 'updates' => $updates, 'loads' => $loads,
        'heapStart' => $heapStart, 'heapMax' => $heapMax, 'heapEnd' => $heapEnd, 'floorPerMinute' => $perMinute,
        'nodesFirst' => $samples[0]['nodes'], 'nodesAfterWarmup' => array_values(array_unique(array_column($afterWarmup, 'nodes'))), 'nodesEnd' => $end['nodes'],
        'errors' => $errors, 'bad' => array_values(array_unique($bad))])."\n");

    expect($forcedGc)->toBeTrue()
        ->and($errors)->toBe([])
        ->and($bad)->toBe([])
        // The DOM keeps its size over every render and renewal: it only moves with the state (a "Live"
        // chip is two nodes, and the corrections keep changing which round-2 boxes are up now).
        ->and(max(array_column($afterWarmup, 'nodes')) - min(array_column($afterWarmup, 'nodes')))->toBeLessThanOrEqual(8)
        ->and($end['nodes'])->toBeLessThanOrEqual(max(array_column($afterWarmup, 'nodes')))
        // Each renewal was a real one, and the heap never climbs past what 100 renders cost.
        // (a result that lands while the page reloads arrives with the fresh page, not as a counted render)
        ->and($loads)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(1 + (int) ceil($updates / $renewAfter))
        // (per-minute floors: a single sample can still hold the render in flight)
        ->and(max(array_slice($perMinute, 1)))->toBeLessThan($heapStart + $renewAfter * 25_000)
        ->and($heapEnd)->toBeLessThan($heapStart * 1.25);
})->skip(fn (): bool => ! is_numeric(getenv('TV_SOAK')), 'set TV_SOAK=<minutes> to run the long test');

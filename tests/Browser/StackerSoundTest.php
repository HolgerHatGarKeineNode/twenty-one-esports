<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockfill sound in the browser (plan "Blockfill", P8)
|--------------------------------------------------------------------------
|
| The sound control in the game page's header and the Web Audio side
| behind it (resources/js/stacker/sound.js):
|
| 1. The control fits at 375 and 1440, in English and German: two switches
|    and two volumes, 44 px targets, inside the viewport, no sideways scroll,
|    and no AudioContext before anyone touched the page.
| 2. Switches and volumes survive a reload: a guest's in localStorage, a
|    player's on the server.
| 3. With music on, the AudioContext is made only by a click; the music then
|    plays on the audio clock (the scheduler's own timing is measured for
|    30 s and reported), a hidden tab suspends it, a shown tab resumes it.
|    Measured on the chiptune loops: the MIDI manifest is answered empty, so
|    the fallback is what plays.
| 4. With tracks in public/music/midi/manifest.json the MIDI playlist is the
|    music: a click starts it (window.__midi counts the notes it schedules)
|    and its credit line shows title, author and license, linked to the
|    source, inside the viewport at 375 and 1440; the music switch and a
|    hidden tab stop it, switching on starts it again.
|
| Every AudioContext the page makes is counted by an init script, and the
| console, uncaught errors and answers >= 400 stay empty, with a positive
| control in each test.
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

    BlockfillOn::play();
});

/** Counts every AudioContext the page makes, from any script. */
const STACKER_AUDIO_COUNTER = <<<'JS'
    window.__audioContexts = 0;
    if (window.AudioContext) {
        const Native = window.AudioContext;
        window.AudioContext = class extends Native {
            constructor(...args) {
                super(...args);
                window.__audioContexts++;
            }
        };
    }
    JS;

/** Answers the MIDI manifest with no tracks: the page plays its chiptune loops. */
const STACKER_NO_MIDI_TRACKS = <<<'JS'
    const fetchBeforeMidi = window.fetch;
    window.fetch = (input, ...rest) => String(input && input.url ? input.url : input).includes('/music/midi/manifest.json')
        ? Promise.resolve(new Response('{"tracks":[]}', { headers: { 'Content-Type': 'application/json' } }))
        : fetchBeforeMidi(input, ...rest);
    JS;

function soundPage(?User $user, int $width, int $height, string $locale = 'en'): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(STACKER_AUDIO_COUNTER);
    $page->setViewportSize($width, $height);
    if ($locale !== 'en') {
        $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    }
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined && window.__stacker.sound() !== null', 10_000);

    return $page;
}

function soundPositiveControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("sound positive control"); }); fetch("/stacker/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("sound positive control")) && window.__errors.some((e) => e.startsWith("404 ") || e.startsWith("405 "))', 5_000);
}

const STACKER_SOUND_BOXES = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) }; };
        // the control is in the page twice (title row from lg, below the well under it): the one on screen
        const shown = [...document.querySelectorAll('[data-test=sound]')].filter((el) => el.offsetParent !== null);
        const control = shown[0];
        const q = (s) => control.querySelector(s) ?? document.querySelector(s);
        return {
            shown: shown.length,
            control: box(control),
            h1: box(q('[data-test=stacker] h1')),
            well: box(q('[data-test=well]')),
            chain: box(q('[data-test=chain]')),
            parts: ['effects-toggle', 'effects-volume', 'music-toggle', 'music-volume'].map((name) => ({ name, ...box(q(`[data-test=sound-${name}]`)) })),
            labels: [...control.querySelectorAll('button > span:last-child')].map((s) => ({ text: s.innerText.trim(), width: Math.round(s.getBoundingClientRect().width) })),
            pressed: ['effects', 'music'].map((c) => q(`[data-test=sound-${c}-toggle]`).getAttribute('aria-pressed')),
            names: ['effects', 'music'].map((c) => q(`[data-test=sound-${c}-volume]`).getAttribute('aria-label')),
        };
    }
    JS;

test('the sound control fits at 375 and 1440 in English and German, and nothing makes a sound before a gesture', function (int $width, int $height, string $locale) {
    $page = soundPage(null, $width, $height, $locale);
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 500))');

    $boxes = $page->evaluate(STACKER_SOUND_BOXES);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "sound control {$width} {$locale}: ".json_encode($boxes).PHP_EOL);

    $labels = $locale === 'de' ? ['Effekte', 'Musik'] : ['Effects', 'Music'];
    expect($boxes['shown'])->toBe(1)
        ->and($boxes['control']['left'])->toBeGreaterThanOrEqual(0)
        ->and($boxes['control']['right'])->toBeLessThanOrEqual($width)
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        // defaults: effects on, music off
        ->and($boxes['pressed'])->toBe(['true', 'false'])
        ->and($boxes['names'])->toBe($locale === 'de' ? ['Lautstärke: Effekte', 'Lautstärke: Musik'] : ['Volume: Effects', 'Volume: Music'])
        ->and(array_column($boxes['labels'], 'text'))->toBe($labels);

    foreach ($boxes['parts'] as $part) {
        expect($part['height'])->toBeGreaterThanOrEqual(44, $part['name'])
            ->and($part['width'])->toBeGreaterThanOrEqual(str_ends_with($part['name'], 'toggle') ? 44 : 80, $part['name'])
            ->and($part['left'])->toBeGreaterThanOrEqual(0, $part['name'])
            ->and($part['right'])->toBeLessThanOrEqual($width, $part['name'])
            // all four on one line
            ->and($part['top'])->toBe($boxes['parts'][0]['top'], $part['name']);
    }

    if ($width < 640) {
        // a phone: the switches are icons (their names stay for screen readers), the row sits below the
        // well and its chain, so the title stays one line and the well keeps its room above the touch panel
        expect(array_column($boxes['labels'], 'width'))->each->toBeLessThanOrEqual(1)
            ->and($boxes['control']['top'])->toBeGreaterThanOrEqual($boxes['chain']['top'] + $boxes['chain']['height']);
    } else {
        // a desktop: named switches, on the title's line
        expect(array_column($boxes['labels'], 'width'))->each->toBeGreaterThan(20)
            ->and(abs(($boxes['control']['top'] + $boxes['control']['height'] / 2) - ($boxes['h1']['top'] + $boxes['h1']['height'] / 2)))->toBeLessThan(16);
    }

    // nothing sounds before a gesture: no context, from this page or any other script
    expect($page->evaluate('() => window.__audioContexts'))->toBe(0)
        ->and($page->evaluate('() => window.__stacker.sound().context'))->toBeFalse()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "stacker-sound-{$width}-{$locale}");
    soundPositiveControl($page);
})->with([
    'phone 375 en' => [375, 812, 'en'],
    'desktop 1440 en' => [1440, 900, 'en'],
    'phone 375 de' => [375, 812, 'de'],
    'desktop 1440 de' => [1440, 900, 'de'],
]);

test('switches and volumes survive a reload: a guest\'s in this browser, a player\'s on the server', function (bool $signedIn) {
    $user = $signedIn ? User::factory()->create() : null;
    $page = soundPage($user, 1440, 900);

    // real clicks on both switches: music on, effects off
    $page->locator('[data-test=sound]:visible [data-test=sound-music-toggle]')->click();
    $page->locator('[data-test=sound]:visible [data-test=sound-effects-toggle]')->click();
    // and the volumes moved (fill() sets the range and sends input and change)
    $page->locator('[data-test=sound]:visible [data-test=sound-music-volume]')->fill('30');
    $page->locator('[data-test=sound]:visible [data-test=sound-effects-volume]')->fill('80');
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 100))');

    $before = $page->evaluate('() => Alpine.$data(document.querySelector("[data-test=stacker]")).sound');
    // moving a volume up switches its channel on again: effects back on at 80
    expect($before)->toBe(['effects' => 80, 'music' => 30, 'effectsOn' => true, 'musicOn' => true]);

    // effects off by its switch once more, so the reload has to bring back an "off"
    $page->locator('[data-test=sound]:visible [data-test=sound-effects-toggle]')->click();
    $expected = ['effects' => 80, 'music' => 30, 'effectsOn' => false, 'musicOn' => true];

    if ($signedIn) {
        $deadline = microtime(true) + 5;
        do {
            $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 200))');
            $saved = $user->refresh()->stacker_sound;
        } while ($saved !== $expected && microtime(true) < $deadline);
        expect($saved)->toBe($expected);
    } else {
        expect($page->evaluate('() => JSON.parse(localStorage.getItem("blockfill.sound"))'))->toBe($expected);
    }

    $page->reload();
    BrowserWait::until($page, '() => window.__stacker !== undefined && window.__stacker.sound() !== null', 10_000);
    $after = $page->evaluate(<<<'JS'
        () => ({
            sound: Alpine.$data(document.querySelector("[data-test=stacker]")).sound,
            pressed: ["effects", "music"].map((c) => document.querySelector(`[data-test=sound-${c}-toggle]`).getAttribute("aria-pressed")),
            volumes: ["effects", "music"].map((c) => document.querySelector(`[data-test=sound-${c}-volume]`).value),
            off: ["effects", "music"].map((c) => document.querySelector(`[data-test=sound-${c}-volume]`).hasAttribute("data-off")),
            runtime: window.__stacker.sound(),
        })
        JS);

    expect($after['sound'])->toBe($expected)
        ->and($after['pressed'])->toBe(['false', 'true'])
        ->and($after['volumes'])->toBe(['80', '30'])
        ->and($after['off'])->toBe([true, false])
        // the reload is not a gesture: still no context
        ->and($after['runtime']['context'])->toBeFalse()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    soundPositiveControl($page);
})->with(['guest' => false, 'player' => true]);

test('with music on, only a click makes the AudioContext; the music runs on the audio clock for 30 s at low cost and stops in a hidden tab', function () {
    $page = soundPage(null, 1440, 900);
    $page->context()->addInitScript(STACKER_NO_MIDI_TRACKS);
    $page->evaluate('() => localStorage.setItem("blockfill.sound", JSON.stringify({ effects: 70, music: 60, effectsOn: true, musicOn: true }))');
    $page->reload();
    BrowserWait::until($page, '() => window.__stacker !== undefined && window.__stacker.sound() !== null', 10_000);
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1000))');

    // music on, but nobody touched the page: nothing
    expect($page->evaluate('() => window.__audioContexts'))->toBe(0)
        ->and($page->evaluate('() => window.__stacker.sound()'))->toMatchArray(['context' => false, 'unlocked' => false, 'scheduling' => false]);

    // one click on the page (the title, nothing that does anything): the context, running, the menu piece
    $page->locator('[data-test=stacker] h1')->click();
    BrowserWait::until($page, '() => window.__stacker.sound().state === "running" && window.__stacker.sound().scheduling', 5_000);
    $unlocked = $page->evaluate('() => window.__stacker.sound()');
    expect($page->evaluate('() => window.__audioContexts'))->toBeGreaterThanOrEqual(1)
        // no MIDI tracks: the loops are the fallback, and the MIDI player scheduled nothing
        ->and($unlocked['playlist'])->toBe('none')
        ->and($page->evaluate('() => window.__midi.scheduled'))->toBe(0)
        ->and($unlocked['piece'])->toBe('stay-humble')
        ->and($unlocked['stats']['contexts'])->toBe(1);

    // a practice run: the calm piece takes over at the next bar
    $page->locator('[data-test=start-practice]')->click();
    BrowserWait::until($page, '() => window.__stacker.sound().piece === "tick-tock"', 8_000);

    // 30 s of music, measured: the scheduler's own time per call, from its performance.now() timing
    $start = $page->evaluate('() => ({ stats: window.__stacker.sound().stats, at: performance.now() })');
    foreach (range(1, 6) as $i) {
        $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 5000))');
    }
    $end = $page->evaluate('() => ({ stats: window.__stacker.sound().stats, at: performance.now(), sound: window.__stacker.sound() })');
    $seconds = ($end['at'] - $start['at']) / 1000;
    $calls = $end['stats']['calls'] - $start['stats']['calls'];
    $spent = $end['stats']['totalMs'] - $start['stats']['totalMs'];
    $notes = $end['stats']['notes'] - $start['stats']['notes'];
    $share = $spent / ($seconds * 1000) * 100;
    fwrite(STDERR, sprintf(
        'sound cpu 1440: %.1f s, %d scheduler calls (%.1f/s), %.2f ms in total, %.3f ms per call, max %.3f ms, %.4f %% of the main thread, %d notes queued, piece %s, mode %s'.PHP_EOL,
        $seconds, $calls, $calls / $seconds, $spent, $spent / max(1, $calls), $end['stats']['maxMs'], $share, $notes, $end['sound']['piece'], $page->evaluate('() => window.__stacker.state().mode'),
    ));

    expect($seconds)->toBeGreaterThanOrEqual(30.0)
        // a timer about every 60 ms, not every frame (a 60 Hz frame loop would be ~1800 calls)
        ->and($calls)->toBeGreaterThan(300)->toBeLessThan(700)
        // the calm practice piece: 33 notes and hits per 4-bar loop of 10.4 s, ~95 in 30 s
        ->and($notes)->toBeGreaterThan(60)
        ->and($spent / max(1, $calls))->toBeLessThan(2.0)
        ->and($share)->toBeLessThan(1.0)
        ->and($end['sound']['state'])->toBe('running');

    // a hidden tab: suspended and no timer; shown again: running and playing
    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => window.__stacker.sound().state === "suspended" && !window.__stacker.sound().scheduling', 3_000);
    $hiddenNotes = $page->evaluate('() => window.__stacker.sound().stats.notes');
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1000))');
    expect($page->evaluate('() => window.__stacker.sound().stats.notes'))->toBe($hiddenNotes);

    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => false }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => window.__stacker.sound().state === "running" && window.__stacker.sound().scheduling', 3_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    soundPositiveControl($page);
});

test('with MIDI tracks in the manifest, a click starts the shuffled playlist with its credit line; the music switch and a hidden tab stop it', function (int $width, int $height) {
    $page = soundPage(null, $width, $height);
    $page->evaluate('() => localStorage.setItem("blockfill.sound", JSON.stringify({ effects: 70, music: 60, effectsOn: true, musicOn: true }))');
    $page->reload();
    BrowserWait::until($page, '() => window.__stacker !== undefined && window.__stacker.sound() !== null', 10_000);

    // nothing before a gesture: no context, so no MIDI player either
    expect($page->evaluate('() => window.__audioContexts'))->toBe(0)
        ->and($page->evaluate('() => window.__midi === undefined'))->toBeTrue();

    $page->locator('[data-test=stacker] h1')->click();
    BrowserWait::until($page, '() => window.__midi.playing && window.__midi.scheduled > 20', 8_000);
    $playing = $page->evaluate('() => ({ sound: window.__stacker.sound(), track: window.__midi.track, tracks: window.__midi.tracks })');
    fwrite(STDERR, 'blockfill midi: '.json_encode($playing['sound']['midi']).PHP_EOL);

    expect($playing['sound']['playlist'])->toBe('playing')
        // the chiptune loops are not running beside it
        ->and($playing['sound']['scheduling'])->toBeFalse()
        ->and($playing['tracks'])->toBe(10)
        ->and($playing['track'])->toBeIn(['Airport Attack', 'Chase!', 'Elegy for the Summer of 4096', 'Fun is Infinite at AGM', 'Neon Flames', 'Punch Your Way Through', 'The Journey Continues', 'Gods of Trance', 'Happy Sunset', 'Quantum 2'])
        // a read-only view: a page script cannot set the counter
        ->and($page->evaluate('() => { try { window.__midi.scheduled = -1; } catch (e) {} return window.__midi.scheduled; }'))->toBeGreaterThan(20);

    // the credit of the track that plays: shown once, inside the viewport, title linked to its source
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=now-playing]")].some((el) => el.offsetParent !== null)', 3_000);
    $credit = $page->evaluate(<<<'JS'
        () => {
            const shown = [...document.querySelectorAll('[data-test=now-playing]')].filter((el) => el.offsetParent !== null);
            const r = shown[0].getBoundingClientRect();
            const link = shown[0].querySelector('[data-test=now-playing-title]');
            return { shown: shown.length, left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), text: shown[0].innerText.trim(), title: link.textContent, href: link.getAttribute('href') };
        }
        JS);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "blockfill credit {$width}: ".json_encode($credit).PHP_EOL);
    expect($credit['shown'])->toBe(1)
        ->and($credit['title'])->toBe($playing['track'])
        ->and($credit['href'])->toStartWith('https://opengameart.org/content/')
        ->and($credit['text'])->toStartWith('Playing now: '.$playing['track'].' – ')
        ->and($credit['left'])->toBeGreaterThanOrEqual(0)
        ->and($credit['right'])->toBeLessThanOrEqual($width)
        ->and($credit['height'])->toBeLessThanOrEqual(20)
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth);
    shellShot($page, "stacker-now-playing-{$width}");

    // the music switch off: the playlist stops, nothing more is scheduled, the credit goes
    $page->locator('[data-test=sound]:visible [data-test=sound-music-toggle]')->click();
    BrowserWait::until($page, '() => !window.__midi.playing && ![...document.querySelectorAll("[data-test=now-playing]")].some((el) => el.offsetParent !== null)', 3_000);
    $stopped = $page->evaluate('() => window.__midi.scheduled');
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1000))');
    expect($page->evaluate('() => window.__midi.scheduled'))->toBe($stopped)
        ->and($page->evaluate('() => window.__stacker.sound()'))->toMatchArray(['playlist' => 'idle', 'scheduling' => false]);

    // on again: it plays on
    $page->locator('[data-test=sound]:visible [data-test=sound-music-toggle]')->click();
    BrowserWait::until($page, '() => window.__midi.playing && window.__midi.scheduled > '.$stopped, 5_000);

    // a hidden tab stops it, a shown one starts it again
    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => !window.__midi.playing', 3_000);
    $hidden = $page->evaluate('() => window.__midi.scheduled');
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1000))');
    expect($page->evaluate('() => window.__midi.scheduled'))->toBe($hidden);
    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => false }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => window.__midi.playing && window.__midi.scheduled > '.$hidden, 5_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    soundPositiveControl($page);
})->with([
    'phone 375' => [375, 812],
    'desktop 1440' => [1440, 900],
]);

<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\LeagueTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Tournament times in the browser
|--------------------------------------------------------------------------
|
| The "when" block in the hero of the tournament page at 375 × 812 and
| 1440 × 900, English and German: its real bounding box ends above the fold,
| it does not move after load (the viewer's-time line has its height
| reserved), the calendar file answers 200 in UTC. On the edit page the
| preview under the start follows the typed value without a round trip,
| names MEZ or MESZ for that date, refuses the spring gap, and a save and
| reload keep the time. Console and network stay clean throughout; a thrown
| error and a broken image at the end prove the collector sees both.
| TIME_SHOTS=<dir> writes the screenshots there.
|
*/

const TIME_STATE = <<<'JS'
    () => {
        const box = (selector) => { const el = document.querySelector(selector); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) }; };
        const text = (selector) => document.querySelector(selector)?.innerText.replace(/\s+/g, ' ').trim() ?? null;
        return {
            when: box('[data-test=tournament-when]'),
            cta: box('[data-test=signup-cta]'),
            time: box('[data-test=when-time]'),
            local: box('[data-test=when-local]'),
            dateText: text('[data-test=when-date]'),
            timeText: text('[data-test=when-time]'),
            zoneText: text('[data-test=when-zone]'),
            startsIn: text('[data-test=when-starts-in]'),
            localText: text('[data-test=when-local]'),
            datetime: document.querySelector('[data-test=when-start]')?.getAttribute('datetime') ?? null,
            viewport: [innerWidth, innerHeight],
            scrollY: Math.round(scrollY),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            browserZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            shifts: window.__shifts ?? ['observer missing'],
            fontShifts: window.__fontShifts ?? [],
            errors: window.__errors ?? ['collector missing'],
            lang: document.documentElement.lang,
        };
    }
    JS;

/**
 * Layout shifts that touched the when block, from the first paint on, split
 * at the moment the web fonts are in: before it, the site-wide swap from the
 * fallback face to Unbounded (font-display: swap) moves text by its width;
 * after it, only this page's own scripts could move anything.
 */
const TIME_SHIFTS = <<<'JS'
    window.__shifts = [];
    window.__fontShifts = [];
    window.__fontsAt = -1;
    document.fonts.addEventListener('loadingdone', () => { window.__fontsAt = performance.now(); });
    new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
            for (const source of entry.sources ?? []) {
                if (source.node && source.node.closest && source.node.closest('[data-test=tournament-when]')) {
                    const r = (x) => `${Math.round(x.x)},${Math.round(x.y)} ${Math.round(x.width)}x${Math.round(x.height)}`;
                    // A swap lands while fonts still load, or in the frame after the last one finished: one frame of slack.
                    const late = document.fonts.status === "loaded" && entry.startTime > window.__fontsAt + 50;
                    (late ? window.__shifts : window.__fontShifts).push(`${Math.round(entry.value * 10000) / 10000} ${source.node.nodeName}[${source.node.dataset?.test ?? ''}] ${r(source.previousRect)} -> ${r(source.currentRect)} @${Math.round(entry.startTime)}ms fonts@${Math.round(window.__fontsAt)} ${document.fonts.status}`);
                }
            }
        }
    }).observe({ type: 'layout-shift', buffered: true });
    JS;

const PREVIEW_STATE = <<<'JS'
    () => {
        const field = document.querySelector('[data-test=edit-starts-at]');
        return {
            value: field.querySelector('input').value,
            zone: field.querySelector('[data-zone-label]').innerText.trim(),
            preview: field.querySelector('[data-preview]').innerText.trim(),
            state: field.querySelector('[data-preview]').dataset.state,
            color: getComputedStyle(field.querySelector('[data-preview]')).color,
            box: (() => { const r = field.getBoundingClientRect(); return { width: Math.round(r.width), height: Math.round(r.height) }; })(),
            tops: ['[data-test=edit-name]', '[data-test=edit-starts-at] input', '[data-test=edit-closes-at] input']
                .map((selector) => Math.round(document.querySelector(selector).getBoundingClientRect().top + scrollY)),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors ?? ['collector missing'],
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

function timeShot(Page $page, string $name): void
{
    $dir = getenv('TIME_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    $page->evaluate('() => new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => resolve(true)), 1600))');
    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** A start five days ahead at 20:00 in the league's zone, as UTC. */
function eveningStart(int $days): CarbonImmutable
{
    return CarbonImmutable::now(LeagueTime::zone())->addDays($days)->setTime(20, 0)->utc();
}

test('the when block sits above the fold at 375 and 1440 px in both languages, holds still, and its calendar file is UTC', function () {
    $tournament = openTournament(['name' => 'Einundzwanzig Fifa 2026', 'starts_at' => eveningStart(5),
        'description' => 'Sixteen seats, one evening, one final on the big screen. Bring your own controller.'], rocketLeague: true);
    $iso = $tournament->starts_at->utc()->format('Y-m-d\TH:i:s\Z');
    $measured = [];

    foreach (['en', 'de'] as $locale) {
        $viewer = User::factory()->create(['name' => 'visitor-'.$locale, 'locale' => $locale]);
        $page = visit(BrowserLogin::url($viewer))->page();
        $page->context()->addInitScript(BrowserConsole::COLLECTOR);
        $page->context()->addInitScript(TIME_SHIFTS);

        foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
            BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-when]") !== null && window.Alpine !== undefined', 10_000);
            timeShot($page, "when-{$locale}-{$width}");
            $state = [...$page->evaluate(TIME_STATE), 'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES)];
            $measured["{$locale} {$width}"] = ['when' => $state['when'], 'local' => $state['localText'], 'zone' => $state['browserZone'], 'fontShifts' => $state['fontShifts']];

            app()->setLocale($locale);
            expect([$locale, $width, $state['lang'], $state['datetime'], $state['dateText'], $state['timeText'], $state['zoneText']])
                ->toBe([$locale, $width, $locale, $iso, LeagueTime::date($tournament->starts_at), LeagueTime::hour($tournament->starts_at),
                    LeagueTime::abbreviation($tournament->starts_at).', Berlin'])
                ->and($state['scrollY'])->toBe(0)
                ->and($state['when']['left'])->toBeGreaterThanOrEqual(0)
                ->and($state['when']['right'])->toBeLessThanOrEqual($width)
                ->and($state['local']['height'])->toBe(20)
                ->and($state['startsIn'])->toMatch($locale === 'de' ? '/^startet in [45] T \d+ Std$/' : '/^starts in [45] d \d+ h$/')
                ->and([$locale, $width, $state['overflow'], $state['shifts'], $state['errors'], $state['bad']])->toBe([$locale, $width, 0, [], [], []]);

            if ($width >= 1024) {
                // Above the fold, measured at the top of the page: the whole block, the time included.
                expect($state['when']['bottom'])->toBeLessThanOrEqual($height);
            } else {
                // Below lg the call to action comes first, right under the name (user 2026-10-03: Sign up in the phone's first screen), and a game played in
                // the player's own copy puts its "You need your own copy" box in front of the button. The block is the next thing after that card.
                expect($state['when']['top'] - $state['cta']['bottom'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(40);
            }

            // The viewer's-time line appears exactly when the browser's zone runs on another offset at the start and is not one a privacy browser reports instead of the real one (P53, SPOOFED_ZONES).
            $differs = $page->evaluate('(at) => { const o = (z) => { const p = Object.fromEntries(new Intl.DateTimeFormat("en-US", { timeZone: z, hourCycle: "h23", year: "numeric", month: "numeric", day: "numeric", hour: "numeric", minute: "numeric" }).formatToParts(new Date(at)).map((x) => [x.type, x.value])); return Date.UTC(p.year, p.month - 1, p.day, p.hour % 24, p.minute) - at; }; const own = Intl.DateTimeFormat().resolvedOptions().timeZone; return !["UTC", "Etc/UTC", "Etc/GMT", "GMT", "Etc/Universal", "Etc/Zulu", "Universal", "Zulu", "Atlantic/Reykjavik"].includes(own) && o(own) !== o("Europe/Berlin"); }', $tournament->starts_at->getTimestampMs());
            expect($state['localText'] !== '')->toBe($differs);
        }
    }

    // Positive control of that line: the same component with a league zone on another offset than the (stubbed, real) browser zone fills it.
    $forced = $page->evaluate('(at) => { const Original = Intl.DateTimeFormat; Intl.DateTimeFormat = function (...args) { const f = new Original(...args); return args.length === 0 ? { resolvedOptions: () => ({ ...f.resolvedOptions(), timeZone: "Asia/Tokyo" }) } : f; }; const el = document.createElement("div"); el.setAttribute("x-data", `localTime({ at: ${at}, zone: "Pacific/Kiritimati", label: "In your time: :time" })`); el.innerHTML = \'<span x-text="text"></span>\'; document.body.append(el); window.Alpine.initTree(el); const text = el.innerText.trim(); el.remove(); Intl.DateTimeFormat = Original; return text; }', $tournament->starts_at->getTimestampMs());
    expect($forced)->toStartWith('In your time: ')->toContain(':');

    // The calendar link answers 200 with the start in UTC.
    $ics = $page->evaluate('async () => { const a = document.querySelector("[data-test=when-calendar]"); const r = await fetch(a.href); return { status: r.status, type: r.headers.get("content-type"), body: await r.text() }; }');
    expect($ics['status'])->toBe(200)
        ->and($ics['type'])->toBe('text/calendar; charset=utf-8')
        ->and($ics['body'])->toContain('DTSTART:'.$tournament->starts_at->utc()->format('Ymd\THis\Z'));

    // Positive control of the shift probe: moving the zone chip once the fonts are in counts as a shift of this page.
    $moved = $page->evaluate('async () => { await document.fonts.ready; await new Promise((r) => setTimeout(r, 200)); document.querySelector("[data-test=when-time]").style.paddingLeft = "40px"; await new Promise((r) => setTimeout(r, 300)); return window.__shifts; }');
    expect($moved)->not->toBe([])->and($moved[0])->toContain('SPAN[when-zone]');

    // Positive control: a thrown error and a broken image reach the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);
    $control = implode("\n", $page->evaluate('() => window.__errors'));
    expect($control)->toContain('positive control')->toContain('__missing-positive-control.png');

    fwrite(STDERR, "\n[tournament-when] ".json_encode($measured)."\n");
});

test('the admin preview follows the typed start live, names MEZ or MESZ, refuses the spring gap, and a save keeps the time', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = openTournament(['name' => 'Rocket Sunday Munich', 'starts_at' => eveningStart(12)], rocketLeague: true);
    $url = route('admin.tournaments.edit', $tournament);

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]") !== null && window.Alpine !== undefined', 10_000);
        $state = $page->evaluate(PREVIEW_STATE);
        $measured[$width] = [...$state['box'], 'tops' => $state['tops']];

        // At 1440 name, start and sign-up close sit in one row: their inputs share one top edge.
        if ($width === 1440) {
            expect(array_unique($state['tops']))->toHaveCount(1);
        }

        // The browser's first frame is the server's, word for word.
        app()->setLocale('en');
        expect($state['value'])->toBe(LeagueTime::input($tournament->starts_at))
            ->and($state['preview'])->toBe(LeagueTime::preview($state['value'])['text'])
            ->and($state['zone'])->toBe(LeagueTime::zoneLabel($state['value']))
            ->and([$width, $state['overflow'], $state['errors']])->toBe([$width, 0, []]);
    }

    // Typing changes the preview without a round trip: count Livewire requests around it.
    $page->evaluate('() => { window.__requests = 0; const f = window.fetch; window.fetch = (...a) => { window.__requests++; return f(...a); }; }');
    $input = $page->locator('[data-test=edit-starts-at] input');

    $input->fill('2030-12-07T20:00');
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]").innerText.includes("Dec")', 5_000);
    $winter = $page->evaluate(PREVIEW_STATE);
    timeShot($page, 'admin-preview-winter-1440');

    $input->fill('2030-03-31T02:30');
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]").dataset.state === "invalid"', 5_000);
    $gap = $page->evaluate(PREVIEW_STATE);
    timeShot($page, 'admin-preview-gap-1440');

    $input->fill('2030-10-12T20:00');
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]").innerText.includes("12 Oct")', 5_000);
    $summer = $page->evaluate(PREVIEW_STATE);
    $requests = $page->evaluate('() => window.__requests');

    expect($winter['preview'])->toBe('= Sat, 7 Dec 2030, 8:00 PM CET · 7:00 PM UTC')
        ->and($winter['zone'])->toBe('Time zone: Europe/Berlin (CET, UTC+1)')
        ->and($gap['preview'])->toBe('2:30 AM does not exist in Berlin on Sun, 31 Mar 2030: the clocks jump forward an hour. Pick another time.')
        ->and($gap['state'])->toBe('invalid')
        ->and($gap['color'])->toBe('rgb(248, 113, 113)')
        ->and($summer['preview'])->toBe('= Sat, 12 Oct 2030, 8:00 PM CEST · 6:00 PM UTC')
        ->and($summer['zone'])->toBe('Time zone: Europe/Berlin (CEST, UTC+2)')
        ->and($requests)->toBe(0);

    // Save and reload: the typed Berlin time comes back, stored as UTC.
    $page->locator('[data-test=edit-save]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-notice]") !== null || document.querySelector("[data-test=edit-error]") !== null', 10_000);
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]") !== null', 10_000);
    $reloaded = $page->evaluate(PREVIEW_STATE);

    expect($tournament->refresh()->starts_at->utc()->format('Y-m-d H:i'))->toBe('2030-10-12 18:00')
        ->and($reloaded['value'])->toBe('2030-10-12T20:00')
        ->and($reloaded['preview'])->toBe('= Sat, 12 Oct 2030, 8:00 PM CEST · 6:00 PM UTC')
        ->and($reloaded['errors'])->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // German, for the eye.
    $admin->forceFill(['locale' => 'de'])->save();
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]") !== null', 10_000);
    $page->locator('[data-test=edit-starts-at] input')->fill('2030-12-07T20:00');
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-starts-at] [data-preview]").innerText.includes("Dez")', 5_000);
    $german = $page->evaluate(PREVIEW_STATE);
    timeShot($page, 'admin-preview-de-1440');

    expect($german['preview'])->toBe('= Sa, 7. Dez 2030, 20:00 MEZ · 19:00 UTC')
        ->and($german['zone'])->toBe('Zeitzone: Europa/Berlin (MEZ, UTC+1)')
        ->and($german['errors'])->toBe([]);

    // The create page uses the same field: clean, lined up with the name, no overflow.
    foreach ([[375, 812], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.tournaments.create')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=create-starts-at] [data-preview]") !== null && window.Alpine !== undefined', 10_000);
        timeShot($page, "admin-create-de-{$width}");
        $create = $page->evaluate('() => ({
            tops: ["[data-test=tournament-name]", "[data-test=create-starts-at] input"].map((s) => Math.round(document.querySelector(s).getBoundingClientRect().top)),
            preview: document.querySelector("[data-test=create-starts-at] [data-preview]").innerText.trim(),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            errors: window.__errors,
        })');

        expect($create['preview'])->toStartWith('= ')->toContain(' UTC')
            ->and([$width, $create['overflow'], $create['errors']])->toBe([$width, 0, []]);

        if ($width === 1440) {
            expect(array_unique($create['tops']))->toHaveCount(1);
        }
    }

    // Positive control on this page too.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control')->toContain('__missing-positive-control.png');

    fwrite(STDERR, "\n[admin-preview] ".json_encode($measured)."\n");
});

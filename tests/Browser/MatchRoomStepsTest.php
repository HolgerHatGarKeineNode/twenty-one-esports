<?php

use App\Enums\Platform;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualMatches;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The match room, one view per step (plan "Canvas and live alignment", P3)
|--------------------------------------------------------------------------
|
| Boards MatchRoom-1a … 4c and their phone boards against the room of a casual
| Rocket League 1v1, as an admin in German with the longest names the profile
| allows on both sides. Per state: only that step's view is on screen (the
| secondary material sits in one closed "Details" block), its primary action
| ends inside the first 1440×900 screen, the phone's action bar stands on the
| floor above the tab bar and its buttons take the clicks (hit test at
| 390×844), and the view with an opened Details block survives five
| `$refresh` roundtrips. Console and responses stay quiet throughout.
| ROOM_SHOTS=<dir> saves a screenshot per state and width.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database']);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** The longest names the profile allows, without a space to break at (BlockfillReplayTest). */
const ROOM_STEPS_ADMIN = 'Ada Admin Who Watches Every Replay Here';

const ROOM_STEPS_OPPONENT = 'SatoshiNakamotoStackedFortyBlocksFirst';

/**
 * What a state shows, measured in the page: the view, the primary action, the secondary modules on screen, the
 * phone bar's buttons and whether each takes its own click (elementFromPoint at its centre), the geometry.
 */
const ROOM_STEPS_PROBE = <<<'JS'
() => {
    const seen = (el) => !!el && el.checkVisibility() && el.getBoundingClientRect().height > 0;
    const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), height: Math.round(r.height) }; };
    const primary = [...document.querySelectorAll('[data-room-primary]')].find(seen) ?? null;
    const main = document.querySelector('[data-test=room-main]');
    const details = document.querySelector('[data-test=room-details]');
    const secondary = ['casual-line', 'nostr-bar', 'who-played', 'lobby-rules', 'room-problems', 'timeline']
        .filter((name) => [...document.querySelectorAll('[data-test=' + name + ']')].some(seen));
    const bar = [...document.querySelectorAll('[data-room-bar]')].find(seen) ?? null;
    const buttons = bar ? [...bar.querySelectorAll('button, a')].filter(seen).map((b) => {
        const r = b.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        const cut = [b, ...b.querySelectorAll('*')].some((el) => el.scrollWidth > el.clientWidth + 1);
        return { label: b.innerText.trim().replace(/\s+/g, ' '), hit: !!hit && b.contains(hit), cut, bottom: Math.round(r.bottom), height: Math.round(r.height) };
    }) : [];
    return {
        view: main?.dataset.view ?? null,
        primary: primary ? { label: primary.innerText.trim().replace(/\s+/g, ' '), ...box(primary) } : null,
        mainBottom: main ? box(main).bottom : null,
        page: document.documentElement.scrollHeight,
        detailsOpen: details ? details.open : null,
        secondary,
        bar: bar ? { position: getComputedStyle(bar).position, bottom: Math.round(innerHeight - bar.getBoundingClientRect().bottom), buttons } : null,
        floorTop: (() => { const f = document.createElement('div'); f.style.cssText = 'position:fixed;bottom:var(--floor-top);width:0;height:0'; document.body.append(f); const v = Math.round(innerHeight - f.getBoundingClientRect().bottom); f.remove(); return v; })(),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
}
JS;

function roomStepsAdmin(): User
{
    $admin = User::factory()->create(['name' => ROOM_STEPS_ADMIN]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/**
 * A casual Rocket League 1v1 of the admin (challenger) against the long-named opponent, in the ready check.
 *
 * @return array{0: SeriesMatch, 1: User, 2: User}
 */
function roomStepsPairing(User $admin): array
{
    $opponent = User::factory()->create(['name' => ROOM_STEPS_OPPONENT]);
    $choices = ['challenger' => ['platform' => Platform::Pc->value, 'crossplay' => true], 'challenged' => ['platform' => Platform::Pc->value, 'crossplay' => true]];
    $match = app(CasualMatches::class)->create($admin, $opponent, 'rocket-league', SeriesMatch::ORIGIN_QUEUE, $choices);
    // Ten minutes to answer: the state holds for the whole test.
    $match->forceFill(['ready_by' => now()->addMinutes(10)])->save();

    return [$match->refresh(), $admin, $opponent];
}

/** Both ready a minute ago; `$host` hosts ('admin' or 'opponent'). */
function roomStepsStarted(User $admin, string $host): array
{
    [$match, $admin, $opponent] = roomStepsPairing($admin);
    $match->forceFill(['ready_at_challenger' => now()->subMinute(), 'ready_at_challenged' => now()->subMinute(), 'start_at' => now()->subMinute(),
        'host_side' => $host === 'admin' ? 'challenger' : 'challenged'])->save();

    return [$match->refresh(), $admin, $opponent];
}

/**
 * Each board state as a match the admin sees: [board, build, the view, the primary action's label (null: none), the phone bar's labels].
 *
 * @return array<string, array{0: Closure(User): SeriesMatch, 1: string, 2: string|null, 3: list<string>}>
 */
function roomStepsStates(): array
{
    return [
        '1a ready' => [function (User $admin): SeriesMatch {
            [$match, , $opponent] = roomStepsPairing($admin);
            app(CasualMatches::class)->ready($match, $opponent);

            return $match->refresh();
        }, 'ready', 'Bereit', ['Bereit']],
        '1b check-in' => [function (User $admin): SeriesMatch {
            $opponent = User::factory()->create(['name' => ROOM_STEPS_OPPONENT]);
            $times = [now()->addHours(2)->getTimestamp()];
            $match = app(CasualChallenges::class)->challenge($admin, $opponent, 'rocket-league', Platform::Pc, true, $times, now()->addHour()->getTimestamp(), '');
            $match = app(CasualChallenges::class)->accept($match, $opponent, $times[0], Platform::Pc, true);
            // The agreed start in five minutes: the check-in window is open, the opponent is in.
            $casual = $match->casual;
            $casual['scheduled_at'] = now()->addMinutes(5)->getTimestamp();
            $match->forceFill(['casual' => $casual, 'start_at' => now()->addMinutes(5), 'ready_by' => now()->addMinutes(15), 'ready_at_challenged' => now()->subMinute()])->save();

            return $match->refresh();
        }, 'checkin', 'Einchecken', ['Chat', 'Einchecken']],
        '1b check-in later' => [function (User $admin): SeriesMatch {
            $opponent = User::factory()->create(['name' => ROOM_STEPS_OPPONENT]);
            $times = [now()->addHours(30)->getTimestamp()];
            $match = app(CasualChallenges::class)->challenge($admin, $opponent, 'rocket-league', Platform::Pc, true, $times, now()->addHour()->getTimestamp(), '');

            return app(CasualChallenges::class)->accept($match, $opponent, $times[0], Platform::Pc, true)->refresh();
        }, 'checkin', null, ['Chat']],
        '2a host shares' => [fn (User $admin): SeriesMatch => roomStepsStarted($admin, 'admin')[0], 'lobby', 'Lobby teilen', ['Chat', 'Lobby teilen']],
        '2b guest waits' => [fn (User $admin): SeriesMatch => roomStepsStarted($admin, 'opponent')[0], 'lobby', null, ['Chat']],
        '2c guest joins' => [function (User $admin): SeriesMatch {
            [$match] = roomStepsStarted($admin, 'opponent');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(30)])->save();

            return $match->refresh();
        }, 'lobby', 'Ich bin in der Lobby', ['Chat', 'Ich bin in der Lobby']],
        '2d host waits' => [function (User $admin): SeriesMatch {
            [$match] = roomStepsStarted($admin, 'admin');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(30)])->save();

            return $match->refresh();
            // The chat is off in the test (no relay), so the host's card is not in this tab: "Share lobby" sends it again.
        }, 'lobby', 'Lobby teilen', ['Chat', 'Lobby teilen']],
        '3 result' => [function (User $admin): SeriesMatch {
            [$match] = roomStepsStarted($admin, 'admin');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(50), 'joined_at' => now()->subSeconds(40)])->save();
            app(SeriesService::class)->saveLiveGame($match, $admin, 0, 3, 1, null);
            app(SeriesService::class)->saveLiveGame($match, $admin, 1, 2, 1, null);

            return $match->refresh();
        }, 'result', 'Ergebnis senden', ['Ergebnis senden']],
        '4a check' => [function (User $admin): SeriesMatch {
            [$match, , $opponent] = roomStepsStarted($admin, 'opponent');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(50), 'joined_at' => now()->subSeconds(40)])->save();
            app(SeriesService::class)->saveLiveGame($match, $opponent, 0, 3, 1, null);
            app(SeriesService::class)->saveLiveGame($match, $opponent, 1, 2, 1, null);
            app(SeriesService::class)->report($match->refresh(), $opponent, []);

            return $match->refresh();
        }, 'check', 'Ergebnis annehmen', ['Problem melden', 'Ergebnis annehmen']],
        '4b confirmed' => [function (User $admin): SeriesMatch {
            [$match, , $opponent] = roomStepsStarted($admin, 'admin');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(50), 'joined_at' => now()->subSeconds(40)])->save();
            app(SeriesService::class)->saveLiveGame($match, $admin, 0, 3, 1, null);
            app(SeriesService::class)->saveLiveGame($match, $admin, 1, 2, 1, null);
            app(SeriesService::class)->report($match->refresh(), $admin, []);
            app(SeriesService::class)->respond($match->refresh(), $opponent, 'confirmed', '', []);

            return $match->refresh();
        }, 'done', 'Revanche', ['Revanche']],
        '4c problem' => [function (User $admin): SeriesMatch {
            [$match, , $opponent] = roomStepsStarted($admin, 'opponent');
            $match->forceFill(['lobby_shared_at' => now()->subSeconds(50), 'joined_at' => now()->subSeconds(40)])->save();
            app(SeriesService::class)->saveLiveGame($match, $opponent, 0, 3, 1, null);
            app(SeriesService::class)->saveLiveGame($match, $opponent, 1, 2, 1, null);
            app(SeriesService::class)->report($match->refresh(), $opponent, []);
            app(SeriesService::class)->respond($match->refresh(), $admin, 'disputed', 'Spiel 2 endete 1:2, nicht 2:1. Das Endbild liegt im Chat.', []);

            return $match->refresh();
        }, 'problem', 'Korrigiertes Ergebnis senden', ['Korrigiertes Ergebnis senden']],
    ];
}

function roomStepsPage(User $user, SeriesMatch $match, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // The room polls: a full resource-timing buffer (250 by default) would hide a late 4xx from BAD_RESPONSES.
    $page->context()->addInitScript('performance.setResourceTimingBufferSize(5000)');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from(route('matches.room', $match)));
    $page->waitForLoadState('networkidle');

    return $page;
}

function roomStepsShot(Page $page, string $name): void
{
    $dir = getenv('ROOM_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function roomStepsQuiet(Page $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');
}

test('each match room state renders its own view: primary action in the first screen, phone bar on the floor, state kept over five refreshes', function (string $state) {
    [$build, $view, $primaryLabel, $barLabels] = roomStepsStates()[$state];
    $admin = roomStepsAdmin();
    $match = $build($admin);
    $slug = str_replace(' ', '-', $state);
    $measured = [];

    // Desktop, 1440×900: the step's view and its primary action inside the first screen; secondary material closed.
    $desk = roomStepsPage($admin, $match, 1440, 900);
    $probe = $desk->evaluate(ROOM_STEPS_PROBE);
    $measured['1440'] = $probe;
    fwrite(STDERR, "\nROOM-STEPS {$state} 1440 ".json_encode($probe)."\n");
    roomStepsShot($desk, "room-{$slug}-1440");
    expect($probe['secondary'])->toBe([], "{$state} 1440: secondary modules on screen")
        ->and($probe['view'])->toBe($view, "{$state} 1440: view")
        ->and($probe['mainBottom'])->toBeLessThanOrEqual(900, "{$state} 1440: the step's view ends in the first screen")
        ->and($probe['detailsOpen'])->toBeFalse()
        ->and($probe['overflow'])->toBe(0);

    if ($primaryLabel === null) {
        expect($probe['primary'])->toBeNull("{$state} 1440: no primary action of this player");
    } else {
        expect($probe['primary']['label'] ?? null)->toBe($primaryLabel)
            ->and($probe['primary']['bottom'])->toBeLessThanOrEqual(900, "{$state} 1440: primary above the fold");
    }

    expect($probe['bar'])->toBeNull("{$state} 1440: no phone bar");

    // The Details block opened, then five roundtrips: the view and the open block stay.
    $desk->evaluate('() => { const d = document.querySelector("[data-test=room-details]"); d.open = true; }');
    for ($i = 0; $i < 5; $i++) {
        $desk->evaluate('async () => { await Livewire.all().find((c) => c.el.matches("[data-test=match-room]")).$wire.$refresh(); }');
    }
    BrowserWait::until($desk, '() => !document.querySelector("[wire\\\\:loading]")', 2000);
    $after = $desk->evaluate('() => ({ view: document.querySelector("[data-test=room-main]")?.dataset.view, open: document.querySelector("[data-test=room-details]")?.open })');
    expect($after)->toBe(['view' => $view, 'open' => true], "{$state}: after five refreshes");
    roomStepsQuiet($desk, "{$state} 1440");

    // Phone, 390×844: the bar stands on the floor above the tab bar, each button takes its own click.
    $phone = roomStepsPage($admin, $match, 390, 844);
    $probe = $phone->evaluate(ROOM_STEPS_PROBE);
    $measured['390'] = $probe;
    fwrite(STDERR, "\nROOM-STEPS {$state} 390 ".json_encode($probe)."\n");
    roomStepsShot($phone, "room-{$slug}-390");
    expect($probe['secondary'])->toBe([], "{$state} 390: secondary modules on screen")
        ->and($probe['view'])->toBe($view)
        ->and($probe['overflow'])->toBe(0)
        ->and($probe['bar'])->not->toBeNull("{$state} 390: phone bar")
        ->and($probe['bar']['position'])->toBe('fixed')
        ->and($probe['bar']['bottom'])->toBe($probe['floorTop'], "{$state} 390: bar stands on the floor, above the tab bar")
        ->and(array_column($probe['bar']['buttons'], 'label'))->toBe($barLabels)
        ->and(array_column($probe['bar']['buttons'], 'hit'))->each->toBeTrue()
        ->and(array_column($probe['bar']['buttons'], 'cut'))->each->toBeFalse()
        ->and(array_column($probe['bar']['buttons'], 'height'))->each->toBeGreaterThanOrEqual(44);
    roomStepsQuiet($phone, "{$state} 390");

})->with(array_keys(roomStepsStates()));

test('the header chip shows the step\'s deadline, red with a countdown in its last 15 minutes, without pushing the header past its row', function () {
    $admin = roomStepsAdmin();
    $checkIn = roomStepsStates()['1b check-in'][0]($admin);
    $result = roomStepsStates()['3 result'][0](roomStepsAdmin());
    $chip = '() => { const a = document.querySelector("[data-test=nav-actions]"); const label = document.querySelector("[data-test=actions-urgent-label]"); const header = a.closest("header"); return { text: a.innerText.replace(/\\s+/g, " ").trim(), hot: a.querySelector(".rv-act").classList.contains("is-hot"), labelShown: label.checkVisibility() && label.getBoundingClientRect().width > 1, name: a.getAttribute("aria-label"), overflow: header.scrollWidth - header.clientWidth }; }';
    $measured = [];

    foreach ([1280, 1440] as $width) {
        $page = roomStepsPage($admin, $checkIn, $width, 900);
        BrowserWait::until($page, '() => document.querySelector("[data-test=actions-urgent]")?.checkVisibility() === true', 3000);
        $hot = $page->evaluate($chip);
        $page = roomStepsPage(User::query()->findOrFail($result->sides['challenger'][0]), $result, $width, 900);
        BrowserWait::until($page, '() => document.querySelector("[data-test=actions-urgent]")?.checkVisibility() === true', 3000);
        $calm = $page->evaluate($chip);
        $measured[$width] = [$hot, $calm];

        expect($hot['hot'])->toBeTrue()
            ->and($hot['text'])->toMatch('/^Check-in 1[45]:\d\d$/')
            ->and($hot['labelShown'])->toBeTrue()
            ->and($hot['name'])->toContain('Check-in')
            ->and($hot['overflow'])->toBe(0)
            ->and($calm['hot'])->toBeFalse()
            ->and($calm['text'])->toMatch('/^Ergebnis bis \d\d:\d\d$/')
            ->and($calm['overflow'])->toBe(0);
        roomStepsQuiet($page, "chip {$width}");
    }

    fwrite(STDERR, "\nROOM-CHIP ".json_encode($measured)."\n");
});

test('positive control: the probe and the collector see a throw, a failed response, a secondary module on screen and a covered bar button', function () {
    $admin = roomStepsAdmin();
    [$match] = roomStepsStarted($admin, 'opponent');
    $page = roomStepsPage($admin, $match, 390, 844);
    $page->evaluate('() => {
        setTimeout(() => { throw new Error("room positive control"); });
        fetch("/__room-missing").then((r) => r.text());
        document.querySelector("[data-test=room-details]").open = true;
        const cover = document.createElement("div");
        cover.style.cssText = "position:fixed;inset:0;z-index:99";
        document.body.append(cover);
    }');
    BrowserWait::until($page, '() => window.__errors.length > 0', 3000);
    BrowserWait::until($page, '() => JSON.stringify(('.BrowserConsole::BAD_RESPONSES.')()).includes("__room-missing")', 3000);
    $probe = $page->evaluate(ROOM_STEPS_PROBE);

    expect(implode(' ', $page->evaluate('() => window.__errors')))->toContain('room positive control')
        ->and(json_encode($page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('__room-missing')
        ->and($probe['secondary'])->not->toBe([])
        ->and(array_column($probe['bar']['buttons'] ?? [], 'hit'))->toContain(false);
});

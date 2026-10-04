<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
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
| The live control page under live load (2026-10-04)
|--------------------------------------------------------------------------
|
| A running knockout with eight open series in every state the direction has
| to see: one side checked in with the auto no-show minutes away, a reported
| no-show, an overdue series with the admins, a reported result awaiting its
| answer, one where both sides are in, one not started yet. The overview sorts
| them into lanes, most urgent first. Every name is 40 characters without a
| space and every match number has four digits.
|
| Measured at 390 and 1440 px, in German (the longer copy) and in English (the
| screenshots): no horizontal page scroll, no text box wider than its own box,
| no name squeezed to zero width, and every name inside its card. The console
| stays empty on the first load and after a Livewire roundtrip, every
| roundtrip answers 2xx, and a thrown error proves the collectors listen.
|
| LIVE_SHOTS=<dir> writes the English screenshots there.
|
*/

const LIVE_LONG = 'Bitcoinmaximalistausdemerzgebirge2140xyz';

/*
| What the page holds, measured in the page: the document's scroll width, every
| visible text box that overflows its own box (block-level: scrollWidth >
| clientWidth; inline: a line box past its block's right edge), every side name
| with its width and whether it sits inside its card, and the lane order.
*/
const LIVE_PROBE = <<<'JS'
    () => {
        const root = document.querySelector('[data-test=tournament-live]');
        const overflowing = [];
        for (const el of root.querySelectorAll('*')) {
            if (! el.checkVisibility()) continue;
            if (el.closest('[data-test=desk-chat]')) continue;
            const text = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
            if (! text) continue;
            const style = getComputedStyle(el);
            if (style.display === 'inline') {
                let block = el.parentElement;
                while (block && getComputedStyle(block).display === 'inline') block = block.parentElement;
                const edge = block.getBoundingClientRect().right + 0.5;
                if ([...el.getClientRects()].some((r) => r.right > edge)) overflowing.push(el.tagName + ' ' + el.textContent.trim().slice(0, 50));
            } else if (el.scrollWidth > el.clientWidth + 0.5 && style.overflowX !== 'auto' && style.overflowX !== 'scroll') {
                overflowing.push(el.tagName + '[' + el.scrollWidth + '>' + el.clientWidth + '] ' + el.textContent.trim().slice(0, 50));
            }
        }
        const names = [...root.querySelectorAll('[data-test=live-side-name]')].map((el) => {
            const box = el.getBoundingClientRect();
            const card = el.closest('[data-test=live-series]').getBoundingClientRect();
            return { w: Math.round(box.width), h: Math.round(box.height), inside: box.left >= card.left - 0.5 && box.right <= card.right + 0.5 };
        });
        const cards = [...root.querySelectorAll('[data-test=live-series]')].map((el) => Math.round(el.getBoundingClientRect().height));
        const box = (sel) => { const el = document.querySelector(sel); return el ? Math.round(el.getBoundingClientRect().top + scrollY) : null; };
        return {
            lang: document.documentElement.lang,
            scroll: [document.scrollingElement.scrollWidth, innerWidth],
            overflowing,
            names,
            cards,
            lanes: [...root.querySelectorAll('[data-test=live-lane]')].map((el) => el.dataset.lane + ':' + el.querySelectorAll('[data-test=live-series]').length),
            tops: { overview: box('[data-test=live-overview]'), control: box('[data-test=control]'), bracket: box('[data-test=live-bracket]'), page: document.scrollingElement.scrollHeight },
        };
    }
    JS;

/* Every Livewire roundtrip's answer, recorded before the page loads. */
const LIVE_ROUNDTRIPS = <<<'JS'
    window.__roundtrips = [];
    const liveFetch = window.fetch;
    window.fetch = (...args) => liveFetch(...args).then((r) => { if (String(r.url).includes('/livewire')) window.__roundtrips.push(r.status); return r; });
    JS;

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

function liveShot(Page $page, string $name): void
{
    $dir = getenv('LIVE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** A running players-mode knockout of `$entrants` entries, every name LIVE_LONG plus a 4-digit suffix. */
function liveTournament(int $entrants, TournamentStatus $status = TournamentStatus::Running): Tournament
{
    $profile = GameProfile::for('rocket-league', '1v1');
    $tournament = Tournament::factory()->create([
        'name' => 'Rocket Duel Night Weltmeisterschaftsqualifikation2140', 'game' => 'rocket-league', 'mode' => '1v1',
        'format' => TournamentFormat::SingleElimination, 'capacity' => $entrants,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], $profile)->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => $status,
        'slug' => 'live-ops-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, $entrants) as $index) {
        $name = LIVE_LONG.(1000 + $index);
        $player = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $name, 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    if ($status === TournamentStatus::Running) {
        app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
        app(TournamentRunner::class)->sync($tournament);
    }

    return $tournament->refresh();
}

/**
 * Puts the eight round-1 series into the states of the brief.
 *
 * @return list<SeriesMatch>
 */
function liveStates(Tournament $tournament): array
{
    $series = SeriesMatch::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->get()->all();
    $service = app(SeriesService::class);

    foreach ($series as $index => $one) {
        $one->forceFill(['number' => 4711 + $index, 'start_at' => now()->subMinutes(5)])->save();
    }

    // 0: one side in, the auto no-show in 5 minutes.
    $series[0]->forceFill(['start_at' => now()->subMinutes(25), 'ready_at_challenger' => now()->subMinutes(20)])->save();
    // 1: a reported no-show, the answer clock running.
    $series[1]->forceFill(['start_at' => now()->subMinutes(40), 'ready_at_challenger' => now()->subMinutes(38), 'noshow_side' => 'challenger', 'noshow_reported_at' => now()->subMinutes(4)])->save();
    // 2: nobody reported by the deadline: with the admins.
    $series[2]->forceFill(['start_at' => now()->subHours(3), 'ready_at_challenger' => now()->subHours(3), 'ready_at_challenged' => now()->subHours(3), 'overdue_at' => now()->subMinutes(30)])->save();
    // 3: a reported result awaiting the other side's answer.
    $series[3]->forceFill(['start_at' => now()->subMinutes(50), 'ready_at_challenger' => now()->subMinutes(48), 'ready_at_challenged' => now()->subMinutes(47)])->save();
    $winner = User::query()->findOrFail($series[3]->sides['challenger'][0]);
    foreach (range(0, intdiv($series[3]->best_of, 2)) as $game) {
        $service->saveLiveGame($series[3]->refresh(), $winner, $game, 3, 1, null);
    }
    $service->report($series[3]->refresh(), $winner, []);
    // 4: both in, playing.
    $series[4]->forceFill(['ready_at_challenger' => now()->subMinutes(4), 'ready_at_challenged' => now()->subMinutes(3)])->save();
    // 5: nobody in after the auto no-show time: the double no-show rule runs.
    $series[5]->forceFill(['start_at' => now()->subMinutes(35)])->save();
    // 6, 7: not started yet.
    $series[6]->forceFill(['start_at' => now()->addMinutes(20)])->save();
    $series[7]->forceFill(['start_at' => now()->addMinutes(80)])->save();

    return array_map(fn (SeriesMatch $one): SeriesMatch => $one->refresh(), $series);
}

test('the live page sorts eight open series by urgency and holds 40-character names at 390 and 1440 px, in German and English', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = liveTournament(16);
    liveStates($tournament);
    $url = ComputeUrl::from(route('tournaments.live', $tournament));

    $webpage = visit(BrowserLogin::url($admin));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(LIVE_ROUNDTRIPS);
    $measured = [];

    foreach (['de', 'en'] as $locale) {
        $admin->forceFill(['locale' => $locale])->save();

        foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto($url);
            BrowserWait::until($page, '() => document.querySelector("[data-test=live-overview]") !== null && document.querySelector("[data-test=control]") !== null', 10_000);
            $probe = $page->evaluate(LIVE_PROBE);
            $measured["{$locale}-{$width}"] = ['scroll' => $probe['scroll'], 'overflowing' => count($probe['overflowing']), 'nameMin' => min(array_column($probe['names'], 'w')),
                'cards' => $probe['cards'], 'lanes' => $probe['lanes'], 'tops' => $probe['tops']];

            if ($locale === 'en') {
                liveShot($page, "live-{$width}");
            }

            expect($probe['lang'])->toBe($locale)
                ->and($probe['scroll'][0])->toBeLessThanOrEqual($probe['scroll'][1])
                ->and($probe['overflowing'])->toBe([])
                ->and(count($probe['names']))->toBe(16)
                ->and(min(array_column($probe['names'], 'w')))->toBeGreaterThan(0)
                ->and(array_filter($probe['names'], fn (array $name): bool => ! $name['inside']))->toBe([])
                // Needs you (the reported no-show, the overdue one), then not checked in (one side in, nobody in), then the rest.
                ->and($probe['lanes'][0])->toBe('needs:2')
                ->and($probe['lanes'][1])->toBe('checkin:2')
                ->and($probe['tops']['control'])->toBeLessThan($probe['tops']['bracket']);
            $webpage->assertNoJavaScriptErrors();
        }
    }

    // A Livewire roundtrip: pick an entry to disqualify, then cancel; the console stays empty, every answer is 2xx.
    $page->locator('[data-test^=control-dq-]')->first()->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=control-dq-form]") !== null', 10_000);
    liveShot($page, 'live-dq-1440');
    $page->locator('[data-test=control-dq-cancel]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=control-dq-form]") === null', 10_000);
    $webpage->assertNoJavaScriptErrors();
    $roundtrips = $page->evaluate('() => window.__roundtrips');

    expect($roundtrips)->not->toBe([])
        ->and(array_filter($roundtrips, fn (int $status): bool => $status < 200 || $status > 299))->toBe([])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: a thrown error reaches both the plugin's list and the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.length >= 1', 10_000);

    expect(implode("\n", array_column($page->javaScriptErrors(), 'message')))->toContain('positive control')
        ->and(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control');

    fwrite(STDERR, "\n[tournament-live] ".json_encode([...$measured, 'roundtrips' => $roundtrips])."\n");
});

test('the live page without anything open: sign-up, no open series, a named director without manage rights, no desk', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger', 'locale' => 'en']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $signup = liveTournament(8, TournamentStatus::Signup);
    $quiet = liveTournament(2);
    SeriesMatch::query()->whereIn('tournament_match_id', $quiet->matches()->select('id'))->update(['start_at' => now()->addMinutes(30)]);
    $director = User::factory()->create(['name' => LIVE_LONG.'9999', 'locale' => 'en']);
    $quiet->directors()->attach($director->id);
    $cancelled = liveTournament(4, TournamentStatus::Cancelled);
    $measured = [];

    foreach ([[$admin, $signup, 'signup'], [$director, $quiet, 'director'], [$admin, $cancelled, 'cancelled']] as [$viewer, $tournament, $case]) {
        $webpage = visit(BrowserLogin::url($viewer));
        $page = $webpage->page();
        $page->setViewportSize(390, 844);
        $page->goto(ComputeUrl::from(route('tournaments.live', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-live]") !== null', 10_000);
        $probe = $page->evaluate(LIVE_PROBE);
        $hooks = $page->evaluate('() => ["live-overview", "live-empty", "control", "desk-chat", "live-no-bracket"].filter((t) => document.querySelector(`[data-test=${t}]`))');
        $measured[$case] = ['scroll' => $probe['scroll'], 'overflowing' => count($probe['overflowing']), 'hooks' => $hooks, 'height' => $probe['tops']['page']];
        liveShot($page, "live-{$case}-390");

        expect($probe['scroll'][0])->toBeLessThanOrEqual($probe['scroll'][1])
            ->and($probe['overflowing'])->toBe([]);
        $webpage->assertNoJavaScriptErrors();

        match ($case) {
            'signup' => expect($hooks)->toContain('control')->toContain('live-no-bracket')->not->toContain('live-overview'),
            // The series start in 30 minutes: on the board, in the "on track" lane; a director gets no control.
            'director' => expect($hooks)->not->toContain('control')->toContain('live-overview')->and($probe['lanes'])->toBe(['ontrack:1']),
            'cancelled' => expect($hooks)->not->toContain('desk-chat')->not->toContain('live-overview'),
        };
    }

    fwrite(STDERR, "\n[tournament-live-off] ".json_encode($measured)."\n");
});

test('the match room check-in block holds a 40-character name and says both clock times, at 390 and 1440 px', function () {
    $tournament = liveTournament(2);
    [$series] = liveStates2($tournament);
    $absent = User::query()->findOrFail($series->sides['challenged'][0]);
    $absent->forceFill(['locale' => 'en'])->save();
    $webpage = visit(BrowserLogin::url($absent));
    $page = $webpage->page();
    $measured = [];

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('matches.room', $series)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=lobby-checkin]") !== null', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=lobby-checkin]").scrollIntoView({ block: "center" })');
        $box = $page->evaluate(<<<'JS'
            () => {
                const block = document.querySelector('[data-test=lobby-checkin]');
                const edge = block.getBoundingClientRect();
                const texts = [...block.querySelectorAll('*')].filter((el) => el.checkVisibility() && [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== ''));
                return {
                    scroll: [document.scrollingElement.scrollWidth, innerWidth],
                    height: Math.round(edge.height),
                    outside: texts.filter((el) => [...el.getClientRects()].some((r) => r.right > edge.right + 0.5 || r.width === 0)).map((el) => el.textContent.trim().slice(0, 40)),
                    rule: block.querySelector('[data-test=checkin-rule]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
                    button: block.querySelector('[data-test=checkin-lobby]') !== null,
                };
            }
            JS);
        $measured[$width] = ['scroll' => $box['scroll'], 'height' => $box['height'], 'outside' => count($box['outside'])];
        liveShot($page, "room-checkin-{$width}");

        expect($box['scroll'][0])->toBeLessThanOrEqual($box['scroll'][1])
            ->and($box['outside'])->toBe([])
            ->and($box['button'])->toBeTrue()
            ->and($box['rule'])->toContain('Only one side in: the other counts as a no-show')
            ->and($box['rule'])->toContain('Nobody in: the double no-show rule decides the match.');
        $webpage->assertNoJavaScriptErrors();
    }

    fwrite(STDERR, "\n[room-checkin] ".json_encode($measured)."\n");
});

/**
 * The one round-1 series of a two-entry knockout: started 25 minutes ago, the challenger in.
 *
 * @return list<SeriesMatch>
 */
function liveStates2(Tournament $tournament): array
{
    $series = SeriesMatch::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->sole();
    $series->forceFill(['number' => 4711, 'start_at' => now()->subMinutes(25), 'ready_at_challenger' => now()->subMinutes(20)])->save();

    return [$series->refresh()];
}

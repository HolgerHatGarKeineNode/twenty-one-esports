<?php

use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The captain's lineup step of a chess team match (plan "Schach Rapid und
| Clan", P4)
|--------------------------------------------------------------------------
|
| In the match room of an accepted team match the captain picks one player
| per board and saves; measured at 390 and 1440 px: the boxes of the step,
| its pick rows and the save button, no horizontal overflow, an empty
| console and no answer >= 400 over the page load and the Livewire
| roundtrips of picking and saving. The collectors are proven with a
| positive control (a thrown error and a 404 fetch).
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    // A login that holds across the browser's requests, as in AccountMenuTest.
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/**
 * An accepted friendly team match over 2 boards between two chess rapid
 * lineups of three players; returns the match, the challenger's captain and
 * the ids of his lineup's players.
 *
 * @return array{0: SeriesMatch, 1: User, 2: list<int>}
 */
function teamLineupMatch(): array
{
    $lineup = function (string $name): array {
        $captain = User::factory()->create(['name' => $name.' Captain']);
        $lineup = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name])->id]);

        return [$lineup->load('clan', 'seats.user'), $captain];
    };
    [$a, $captainA] = $lineup('Orange Pill Squad');
    [$b, $captainB] = $lineup('Block 21');
    $service = app(SeriesService::class);
    $start = now()->addHours(2)->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a->id, $b->id, 1, false, [$start], $start - 3600, null, 2);
    $match = $service->challenge($captainA, $draft, []);
    $service->answer($match, $captainB, 'accepted', $start, []);

    return [$match->refresh(), $captainA, array_map(fn ($seat) => $seat->user_id, $a->fresh()->activeSeats())];
}

function teamLineupPage(User $user, SeriesMatch $match, int $width): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('matches.room', $match, false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && !! window.Livewire && !! document.querySelector("[data-test=team-lineup]")', 15_000);

    return $page;
}

const TEAM_LINEUP_BOXES = <<<'JS'
    () => {
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; };
        return {
            section: box(document.querySelector('[data-test=team-lineup]')),
            picks: [...document.querySelectorAll('[data-test^=team-lineup-pick-]')].map(box),
            save: box(document.querySelector('[data-test=team-lineup-save]')),
            sides: [...document.querySelectorAll('[data-test^=team-lineup-side-]')].map(box),
            viewport: document.documentElement.clientWidth,
            overflow: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        };
    }
    JS;

test('the captain picks one player per board and saves the lineup, measured at 390 and 1440 px with a quiet console', function () {
    $report = [];

    foreach ([390, 1440] as $width) {
        [$match, $captain, $players] = teamLineupMatch();
        $page = teamLineupPage($captain, $match, $width);
        $before = $page->evaluate(TEAM_LINEUP_BOXES);

        $page->locator('[data-test="team-lineup-pick-'.$players[0].'"]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=team-lineup-count]")?.textContent.includes("1 of 2")', 10_000);
        $page->locator('[data-test="team-lineup-pick-'.$players[1].'"]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=team-lineup-count]")?.textContent.includes("2 of 2")', 10_000);
        $page->locator('[data-test="team-lineup-save"]')->click();
        BrowserWait::until($page, '() => !! document.querySelector("[data-test=team-lineup-saved]")', 10_000);

        $report[$width] = ['before' => $before, 'after' => $page->evaluate(TEAM_LINEUP_BOXES)];

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([])
            ->and(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->pluck('user_id')->sort()->values()->all())
            ->toBe(collect([$players[0], $players[1]])->sort()->values()->all());
    }

    if ($file = env('TEAM_LINEUP_REPORT')) {
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
    }

    foreach ($report as $width => ['before' => $before, 'after' => $after]) {
        // Never wider than the page, every pick row and the save button a full touch target, all inside the step.
        expect($before['overflow'][0])->toBeLessThanOrEqual($before['overflow'][1])
            ->and($after['overflow'][0])->toBeLessThanOrEqual($after['overflow'][1])
            ->and($before['section']['w'])->toBeLessThanOrEqual($before['viewport'])
            ->and($before['section']['x'])->toBeGreaterThanOrEqual(0)
            ->and($before['picks'])->toHaveCount(3)
            ->and(collect($before['picks'])->every(fn (array $pick): bool => $pick['h'] >= 44 && $pick['x'] >= $before['section']['x'] && $before['section']['x'] + $before['section']['w'] >= $pick['x'] + $pick['w']))->toBeTrue()
            ->and($before['save']['h'])->toBeGreaterThanOrEqual(44)
            ->and($after['sides'])->toHaveCount(2);
    }

    // Two columns of sides from 640 px, one above the other on a phone.
    expect($report[1440]['after']['sides'][0]['y'])->toBe($report[1440]['after']['sides'][1]['y'])
        ->and($report[390]['after']['sides'][1]['y'])->toBeGreaterThan($report[390]['after']['sides'][0]['y']);
});

test('the lineup page collectors see a thrown error and a 404 answer (positive control)', function () {
    [$match, $captain] = teamLineupMatch();
    $page = teamLineupPage($captain, $match, 390);

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/team-lineup-positive-control-404").catch(() => null)');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 10_000);
    // The resource timing channel (BAD_RESPONSES) sees documents, images and scripts: a broken image proves it.
    $page->evaluate('() => { const img = document.createElement("img"); img.src = "/team-lineup-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.responseStatus === 404)', 10_000);

    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control')
        ->and(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('404');
});

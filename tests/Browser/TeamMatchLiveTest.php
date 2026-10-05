<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The live view of a chess team match (plan "Schach Rapid und Clan", P5)
|--------------------------------------------------------------------------
|
| A guest watches /matches/{number} of a team match over 2 boards (4
| players): both boards are on the page, the running clock counts down in
| the browser, and moves on board 1, played on the server, reach the page
| over the board's watch channel without a reload: first the position, then
| the mate, which turns the team score to 1 : 0. Measured at 390 and 1440
| px: the boxes of the boards and the score, no horizontal overflow, an
| empty console and no answer >= 400 over the page load and the Livewire
| renders. The collectors are proven with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/**
 * A friendly team match over 2 boards, locked and started: the boards are
 * live, the server clock frozen just after the start.
 *
 * @return array{0: SeriesMatch, 1: array<int, ChessGame>}
 */
function teamLiveMatch(): array
{
    $lineup = function (string $name): array {
        $captain = User::factory()->create(['name' => $name.' Captain']);
        $lineup = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id, 'name' => $name])->id]);

        return [$lineup->load('clan', 'seats.user'), $captain];
    };
    [$a, $captainA] = $lineup('Orange Pill Squad');
    [$b, $captainB] = $lineup('Block 21');
    $service = app(SeriesService::class);
    $teamMatches = app(ChessTeamMatches::class);
    $start = now()->addHours(2)->startOfMinute()->getTimestamp();
    $match = $service->challenge($captainA, new ChallengeDraft($a->id, $b->id, 1, false, [$start], $start - 3600, null, 2), []);
    $service->answer($match, $captainB, 'accepted', $start, []);
    $teamMatches->name($match, $captainA, array_slice(array_map(fn ($seat) => $seat->user_id, $a->fresh()->activeSeats()), 0, 2));
    $teamMatches->name($match, $captainB, array_slice(array_map(fn ($seat) => $seat->user_id, $b->fresh()->activeSeats()), 0, 2));
    test()->travelTo(ChessTeamMatches::lockAt($match->refresh())->copy()->addSecond());
    $teamMatches->lock($match);
    test()->travelTo($match->start_at->copy()->addSecond());
    $teamMatches->startDue();

    return [$match->refresh(), ChessGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all()];
}

/** Moves on a board, played on the server as its players would, White first. */
function teamLiveMoves(ChessGame $game, array $ucis): void
{
    foreach ($ucis as $uci) {
        $game->refresh()->loadMissing(['white', 'black']);
        app(ChessGameService::class)->move($game, $game->turn() === 'w' ? $game->white : $game->black, $uci, $game->ply + 1);
    }
}

function teamLivePage(SeriesMatch $match, int $width): mixed
{
    $page = visit(ComputeUrl::from(route('matches.show', $match, false)))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('matches.show', $match, false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && !! window.Livewire && !! window.Echo && !! document.querySelector("[data-test=team-board-2]")', 15_000);

    return $page;
}

const TEAM_LIVE_BOXES = <<<'JS'
    () => {
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; };
        return {
            score: box(document.querySelector('[data-test=team-score]')),
            boards: [...document.querySelectorAll('[data-test^=team-board-][data-game]')].map(box),
            minis: [1, 2].map((n) => box(document.querySelector('[data-test=team-board-fen-' + n + ']'))),
            links: [1, 2].map((n) => box(document.querySelector('[data-test=team-board-link-' + n + ']'))),
            viewport: document.documentElement.clientWidth,
            overflow: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        };
    }
    JS;

test('a guest watches both boards of a team match: the clock runs and a move on board 1 updates the team score without a reload, measured at 390 and 1440 px', function () {
    $report = [];

    foreach ([390, 1440] as $width) {
        [$match, $games] = teamLiveMatch();
        $page = teamLivePage($match, $width);
        $page->evaluate('() => { window.__marker = "no-reload"; }');

        // The first moves reach the page over the board's watch channel: the position changes, the clock runs.
        teamLiveMoves($games[1], ['e2e4', 'e7e5']);
        BrowserWait::until($page, '() => document.querySelector("[data-test=team-board-fen-1]")?.dataset.fen.includes("4p3/4P3")', 15_000);
        $clock = '() => document.querySelector("[data-test=team-board-clock-1-w]")?.textContent.trim()';
        $before = $page->evaluate($clock);
        BrowserWait::until($page, "() => document.querySelector('[data-test=team-board-clock-1-w]')?.textContent.trim() !== ".json_encode($before), 10_000);
        $ticked = $page->evaluate($clock);

        // Scholar's mate on board 1 (the challenger has White there): 1 : 0 for the challenger, on the same page.
        teamLiveMoves($games[1], ['f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7']);
        BrowserWait::until($page, '() => document.querySelector("[data-test=team-score-value]")?.textContent.replace(/\s+/g, " ").trim() === "1 : 0"', 15_000);

        $report[$width] = ['clock' => [$before, $ticked], 'boxes' => $page->evaluate(TEAM_LIVE_BOXES)];

        expect($page->evaluate('() => window.__marker'))->toBe('no-reload')
            ->and($page->evaluate('() => document.querySelector("[data-test=team-board-state-1]")?.textContent.trim()'))->toContain('wins')
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    if ($file = env('TEAM_LIVE_REPORT')) {
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
    }

    $seconds = fn (string $clock): int => (int) explode(':', $clock)[0] * 60 + (int) explode(':', $clock)[1];

    foreach ($report as $width => ['boxes' => $boxes, 'clock' => [$before, $ticked]]) {
        // The running clock counted down in the browser (no move in between).
        expect($seconds($ticked))->toBeLessThan($seconds($before))
            // The team score above the fold of a 900 px high window.
            ->and($boxes['score']['y'] + $boxes['score']['h'])->toBeLessThanOrEqual(900);

        // Never wider than the page; both boards, each with a square mini board inside its card and a full touch target.
        expect($boxes['overflow'][0])->toBeLessThanOrEqual($boxes['overflow'][1])
            ->and($boxes['boards'])->toHaveCount(2)
            ->and($boxes['score']['w'])->toBeLessThanOrEqual($boxes['viewport'])
            ->and(collect($boxes['boards'])->every(fn (array $board): bool => $board['x'] >= 0 && $board['x'] + $board['w'] <= $boxes['viewport']))->toBeTrue()
            ->and(collect($boxes['minis'])->every(fn (array $mini): bool => abs($mini['w'] - $mini['h']) <= 1 && $mini['w'] >= 200))->toBeTrue()
            ->and(collect($boxes['links'])->every(fn (array $link): bool => $link['h'] >= 44))->toBeTrue();
    }

    // One board above the other on a phone, side by side on a desktop.
    expect($report[390]['boxes']['boards'][1]['y'])->toBeGreaterThan($report[390]['boxes']['boards'][0]['y'])
        ->and($report[1440]['boxes']['boards'][1]['y'])->toBe($report[1440]['boxes']['boards'][0]['y']);
});

test('the team match page collectors see a thrown error and a 404 answer (positive control)', function () {
    [$match] = teamLiveMatch();
    $page = teamLivePage($match, 390);

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/team-live-positive-control-404").catch(() => null)');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 10_000);
    $page->evaluate('() => { const img = document.createElement("img"); img.src = "/team-live-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.responseStatus === 404)', 10_000);

    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control')
        ->and(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('404');
});

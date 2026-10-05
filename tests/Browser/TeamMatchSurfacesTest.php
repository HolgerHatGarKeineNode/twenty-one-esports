<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Team match surfaces (plan "Schach Rapid und Clan", P6)
|--------------------------------------------------------------------------
|
| Three pages a team match changed, measured at 390 and 1440 px for an
| outsider (a player of neither clan) and a board player: the clan page with
| its "Chess team matches" card (rapid lineup, next team match, challenge),
| the chess lobby's Team match entry (a tile from 48rem, a slim link below)
| and a board's game page (banner back to the team match, no abort). Each
| measured by its boxes, no horizontal overflow, an empty console and no
| answer >= 400 over load and a Livewire roundtrip; the collectors are
| proven with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/**
 * A friendly team match over 2 boards, locked and started.
 *
 * @return array{0: SeriesMatch, 1: Clan, 2: array<int, ChessGame>}
 */
function surfacesTeamMatch(): array
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

    return [$match->refresh(), $a->clan, ChessGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all()];
}

function surfacesPage(User $user, string $to, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 15_000);

    return $page;
}

/** Boxes of the selectors that are rendered visibly, null for the others, and the page's widths. */
const SURFACES_BOXES = <<<'JS'
    (selectors) => {
        const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) }; };
        return { boxes: Object.fromEntries(selectors.map((sel) => [sel, box(sel)])), widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth] };
    }
    JS;

/** One Livewire roundtrip of the page's first component, so its answers are collected too. */
function surfacesRoundtrip(Page $page): void
{
    $page->evaluate('() => { window.__rt = false; const c = window.Livewire.all()[0]; c.$wire.$refresh().then(() => { window.__rt = true; }); }');
    BrowserWait::until($page, '() => window.__rt === true', 10_000);
}

test('clan page, lobby entry and a team board page are measured at 390 and 1440 px: boxes, no overflow, quiet console, no answer >= 400', function () {
    $report = [];

    foreach ([390, 1440] as $width) {
        [$match, $clan, $boards] = surfacesTeamMatch();
        $outsider = User::factory()->create(['name' => 'outsider '.$width]);
        $player = $boards[1]->white;

        $pages = [
            'clan' => [surfacesPage($outsider, route('clans.show', $clan, false), $width), ['[data-test=clan-team-matches]', '[data-test=rapid-lineup]', '[data-test=team-match-row]', '[data-test=challenge-team-match]']],
            'lobby' => [surfacesPage($outsider, route('chess.lobby', absolute: false), $width), ['[data-test=play-team]', '[data-test=play-team-link]', '[data-test=play-grid]']],
            'board' => [surfacesPage($player, route('games.show', $boards[1], false), $width), ['[data-test=team-board-banner]', '[data-test=chess-game]']],
        ];

        foreach ($pages as $name => [$page, $selectors]) {
            surfacesRoundtrip($page);
            $report[$width][$name] = $page->evaluate(SURFACES_BOXES, $selectors) + [
                'errors' => $page->evaluate('() => window.__errors'),
                'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES),
            ];
        }

        // A board page has no abort for its players; the banner leads back to the team match.
        expect($pages['board'][0]->evaluate('() => !! document.querySelector("[data-test=abort]")'))->toBeFalse()
            ->and($pages['board'][0]->evaluate('() => document.querySelector("[data-test=team-board-banner]").getAttribute("href")'))->toEndWith('/matches/'.$match->number);
    }

    if ($file = env('TEAM_SURFACES_REPORT')) {
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
    }

    foreach ($report as $width => $pagesAt) {
        foreach ($pagesAt as $name => $measured) {
            expect($measured['widths'][0])->toBeLessThanOrEqual($measured['widths'][1], "{$name} overflow at {$width}")
                ->and($measured['errors'])->toBe([], "{$name} console at {$width}")
                ->and($measured['bad'])->toBe([], "{$name} answers at {$width}");

            foreach ($measured['boxes'] as $selector => $box) {
                if ($box !== null) {
                    expect($box['x'])->toBeGreaterThanOrEqual(0, "{$selector} at {$width}")
                        ->and($box['x'] + $box['w'])->toBeLessThanOrEqual($measured['widths'][1], "{$selector} at {$width}");
                }
            }
        }

        $clanBoxes = $pagesAt['clan']['boxes'];
        expect($clanBoxes['[data-test=clan-team-matches]'])->not->toBeNull()
            ->and($clanBoxes['[data-test=team-match-row]']['h'])->toBeGreaterThanOrEqual(44)
            ->and($clanBoxes['[data-test=challenge-team-match]']['h'])->toBeGreaterThanOrEqual(44)
            ->and($pagesAt['board']['boxes']['[data-test=team-board-banner]']['h'])->toBeGreaterThanOrEqual(44);
    }

    // The lobby: a slim link on a phone, the tile in the wide grid; never both.
    expect($report[390]['lobby']['boxes']['[data-test=play-team]'])->toBeNull()
        ->and($report[390]['lobby']['boxes']['[data-test=play-team-link]']['h'])->toBeGreaterThanOrEqual(44)
        ->and($report[1440]['lobby']['boxes']['[data-test=play-team-link]'])->toBeNull()
        ->and($report[1440]['lobby']['boxes']['[data-test=play-team]'])->not->toBeNull();
});

test('the collectors of the team match surfaces see a thrown error and a 404 answer (positive control)', function () {
    [, $clan] = surfacesTeamMatch();
    $page = surfacesPage(User::factory()->create(), route('clans.show', $clan, false), 390);

    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    $page->evaluate('() => fetch("/team-surfaces-positive-control-404").catch(() => null)');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 10_000);
    $page->evaluate('() => { const img = document.createElement("img"); img.src = "/team-surfaces-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.responseStatus === 404)', 10_000);

    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control')
        ->and(implode("\n", $page->evaluate(BrowserConsole::BAD_RESPONSES)))->toContain('404');
});

<?php

use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SeasonSlides;

/**
 * The planner as the stream builds it from config (match 45, blitz 60, gallery 20, teaser 12, 3 teasers, a loop every
 * 6th idle round of 60 s, tournament 15, season slides 15 s, 3 games a round).
 */
function seasonPlanner(): RotationPlanner
{
    return new RotationPlanner(45, 60, 20, 12, 3, 6, 60, 15, seasonSeconds: 15, seasonGamesPerRound: 3);
}

/**
 * Every slot the planner starts over `$rounds` idle-cycle seconds, as "kind scene [#game]", with its length.
 *
 * @param  array{lead?: string|null, games?: list<int>, boost?: bool}  $season
 * @param  list<array{id: int, blitz: bool}>  $games
 * @return list<array{0: string, 1: float}>
 */
function seasonSlots(RotationPlanner $planner, float $seconds, array $season, array $games = [], array $live = []): array
{
    $slots = [];
    $t = 0.0;

    while ($t < $seconds) {
        $slot = $planner->at($t, $games, [], 'off', $live, 'off', null, [], [], [], $season);
        $slots[] = [trim($slot['kind'].' '.($slot['scene'] ?? '-').($slot['gameId'] === null ? '' : ' #'.$slot['gameId'])), $slot['until'] - $t];
        $t = $slot['until'];
    }

    return $slots;
}

/**
 * Seconds per part of one full idle cycle (6 rounds, the loop round first): season slides, casual teasers, the loop.
 *
 * @return array{season: float, casual: float, loop: float, share: float}
 */
function idleComposition(array $season): array
{
    $planner = seasonPlanner();
    $sums = ['season' => 0.0, 'casual' => 0.0, 'loop' => 0.0];
    $rounds = 0;
    $t = 0.0;

    while (true) {
        $slot = $planner->at($t, [], [], 'off', [], 'off', null, [], [], [], $season);

        if ($slot['kind'] === RotationPlanner::LOOP && ++$rounds > 1) {
            break;
        }

        $kind = match ($slot['kind']) {
            RotationPlanner::SEASON => 'season',
            RotationPlanner::LOOP => 'loop',
            default => 'casual',
        };
        $sums[$kind] += $slot['until'] - $t;
        $t = $slot['until'];
    }

    return [...$sums, 'share' => round($sums['season'] / array_sum($sums) * 100, 1)];
}

test('outside a season with no plan, an idle cycle is unchanged: 420 s, a 60 s loop and 360 s of teasers, no season slide', function () {
    expect(idleComposition([]))->toBe(['season' => 0.0, 'casual' => 360.0, 'loop' => 60.0, 'share' => 0.0])
        ->and(idleComposition(['lead' => null, 'games' => [], 'boost' => false]))->toBe(idleComposition([]));
});

test('while the season leads, season slides outweigh the casual ones two to one: 360 of 600 s with the launch, 270 of 510 s after it', function () {
    expect(idleComposition(['lead' => SeasonSlides::LAUNCH, 'games' => [0, 2, 5, 7], 'boost' => true]))->toBe(['season' => 360.0, 'casual' => 180.0, 'loop' => 60.0, 'share' => 60.0])
        ->and(idleComposition(['lead' => null, 'games' => [0, 2, 5, 7], 'boost' => true]))->toBe(['season' => 270.0, 'casual' => 180.0, 'loop' => 60.0, 'share' => 52.9]);
});

test('every round carries the lead and three game slides taking turns across games, the loop round included, each 15 s', function () {
    $slots = seasonSlots(seasonPlanner(), 216, ['lead' => SeasonSlides::COUNTDOWN, 'games' => [0, 2, 5, 7], 'boost' => true]);

    expect(array_column($slots, 0))->toBe([
        'loop -', 'season s1', 'season s3 #0', 'season s3 #2', 'season s3 #5',
        'season s1', 'season s3 #7', 'season s3 #0', 'season s3 #2', 'teaser d1', 'teaser d2', 'teaser e1',
    ])->and(array_values(array_unique(array_column(array_filter($slots, fn (array $slot): bool => str_starts_with($slot[0], 'season')), 1))))->toBe([15.0]);
});

test('before a far or undated Block 0 the countdown takes one closing teaser\'s place, a different one each round, and no game slide shows', function () {
    $season = ['lead' => SeasonSlides::COUNTDOWN, 'games' => [], 'boost' => false];
    $slots = seasonSlots(seasonPlanner(), 60 + 3 * 87 + 1, $season);

    expect(array_column($slots, 0))->toBe([
        'loop -',
        // The pool of closing teasers goes on where it stopped, so the countdown delays a teaser and drops none.
        'teaser d1', 'teaser d2', 'teaser e1', 'teaser a3', 'teaser a4', 'season s1',
        'teaser e4', 'teaser m1', 'teaser e2', 'teaser a5', 'season s1', 'teaser b3',
        'teaser d1', 'teaser d2', 'teaser e3', 'season s1', 'teaser b4', 'teaser b5',
        'teaser e4', 'teaser m1', 'teaser e5', 'teaser c3',
    ])
        ->and(idleComposition($season))->toBe(['season' => 75.0, 'casual' => 300.0, 'loop' => 60.0, 'share' => 17.2]);
});

test('with nothing that mines the season leads with its lead slide alone and the closing teasers stay', function () {
    $slots = seasonSlots(seasonPlanner(), 60 + 15 + 72 + 1, ['lead' => SeasonSlides::LAUNCH, 'games' => [], 'boost' => true]);

    expect(array_column($slots, 0))->toBe(['loop -', 'season s2', 'teaser d1', 'teaser d2', 'teaser e1', 'teaser a3', 'teaser a4', 'teaser a5', 'season s2']);
});

test('a live match keeps its place at the head of every round while the season leads', function () {
    $slots = seasonSlots(seasonPlanner(), 60 + 15 + 36 + 60 + 1, ['lead' => null, 'games' => [3], 'boost' => true], [['id' => 9, 'blitz' => true]]);

    expect(array_column($slots, 0))->toBe(['match a1 #9', 'season s3 #3', 'teaser d1', 'teaser d2', 'teaser e1', 'match b1 #9', 'season s3 #3']);
});

test('a running tournament still takes the stream alone in exclusive mode while the season leads', function () {
    $planner = new RotationPlanner(45, 60, 20, 12, 3, 6, 60, 15, runningSeconds: 40, runningExclusive: true);

    $slots = seasonSlots($planner, 80, ['lead' => SeasonSlides::LAUNCH, 'games' => [0], 'boost' => true], [], [['id' => 4, 'phase' => 'running', 'fomo' => false]]);

    expect(array_unique(array_map(fn (array $slot): string => explode(' ', $slot[0])[0], $slots)))->toBe(['tournament']);
});

test('a planned slide is skipped once its lead or game no longer applies (the launch window closed, the game stopped mining)', function () {
    $planner = seasonPlanner();
    $season = ['lead' => SeasonSlides::LAUNCH, 'games' => [0, 2], 'boost' => true];

    // The loop round: loop, then s2, s3 #0, s3 #2 are queued.
    expect($planner->at(0, [], [], 'off', [], 'off', null, [], [], [], $season)['kind'])->toBe(RotationPlanner::LOOP);

    $next = $planner->at(60, [], [], 'off', [], 'off', null, [], [], [], ['lead' => null, 'games' => [2], 'boost' => true]);

    expect([$next['kind'], $next['scene'], $next['gameId']])->toBe([RotationPlanner::SEASON, SeasonSlides::GAME, 2]);
});

<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\DurationRange;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\RoundClock;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The honest duration (P18, user decision 2026-09-27)
|--------------------------------------------------------------------------
|
| Online a match needs its games plus an overhead (finding the opponent,
| lobby, report), and in the worst case every round runs out every deadline
| of the round clock: no-show wait, the longest play (extra time, overtime),
| grace, response. The profiles' overheads and stretch factors are
| unmeasured assumptions; the reference case is the one the user saw.
|
*/

/** The league's round clock: no-show 15, grace 5, response 10, blitz check-in 5 min. */
function leagueClock(?int $reportHours = null, int $checkinMinutes = 5): RoundClock
{
    return new RoundClock(15, 5, 10, $reportHours, $checkinMinutes);
}

test('each profile has its typical online slot and its hard end', function (string $game, string $mode, int $bestOf, float $typical, float $latest) {
    $profile = GameProfile::for($game, $mode);
    // Daily chess: the league's first-move window is a day.
    $clock = leagueClock(checkinMinutes: $profile->isDaily() ? 1440 : 5);

    expect($profile->onlineSlot($bestOf))->toEqual($typical)
        ->and($clock->hardEnd($profile, $bestOf))->toEqual($latest);
})->with([
    // 5 setup + 15 game + 10 overhead; 15 no-show + (5 + 15 × 1.3) + 5 grace, rounded up to 45, + 10 response.
    'FC 1v1, Bo1' => ['ea-sports-fc-26', '1v1', 1, 30.0, 55.0],
    // Final: 5 + 3 × 15 + 10; 15 + (5 + 3 × 19.5) + 5 = 83.5 → 84, + 10.
    'FC 1v1, Bo3' => ['ea-sports-fc-27', '1v1', 3, 60.0, 94.0],
    // 5 + 3 × 8 + 5; 15 + (5 + 3 × 8 × 1.25) + 5 = 55, + 10.
    'RL 3v3, Bo3' => ['rocket-league', '3v3', 3, 34.0, 65.0],
    // 14 + 3 overhead; 5 min to the first move, then the whole clock.
    'blitz' => ['chess', 'blitz', 1, 17.0, 19.0],
    // Days: no overhead; one day to the first move, then the game.
    'daily chess' => ['chess', 'correspondence', 1, 30.0, 31.0],
]);

test('the reference case: FC 26 1v1, 16 players, Two Stage is about 3 h planned, 4.5 h typical and 7 h at worst (±15 %)', function () {
    $estimator = new Estimator;
    $profile = GameProfile::for('ea-sports-fc-26', '1v1');
    $options = FormatOptions::defaults($profile);
    $structure = $estimator->structure(TournamentFormat::TwoStage, 16, $options);

    $range = $estimator->range(TournamentFormat::TwoStage, $structure, $profile, $options, null, leagueClock());

    // 3 group rounds, quarterfinal, semifinal, Bo3 final: 6 blocks, 5 breaks of 5 min.
    expect(count($structure->rounds))->toBe(6)
        ->and($range->planned)->toEqual(175.0)
        ->and($range->typical)->toEqual(235.0)
        ->and($range->latest)->toEqual(394.0)
        ->and($range->planned)->toBeGreaterThanOrEqual(180 * 0.85)->toBeLessThanOrEqual(180 * 1.15)
        ->and($range->typical)->toBeGreaterThanOrEqual(270 * 0.85)->toBeLessThanOrEqual(270 * 1.15)
        ->and($range->latest)->toBeGreaterThanOrEqual(420 * 0.85)->toBeLessThanOrEqual(420 * 1.15);
});

test('the range is ordered planned ≤ typical ≤ latest for every profile and format', function (string $game, string $mode) {
    $estimator = new Estimator;
    $profile = GameProfile::for($game, $mode);
    $options = FormatOptions::defaults($profile);

    foreach ([TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination, TournamentFormat::Swiss, TournamentFormat::RoundRobin, TournamentFormat::TwoStage] as $format) {
        $range = $estimator->range($format, $estimator->structure($format, 8, $options->withSwissRounds(3)), $profile, $options->withSwissRounds(3), null, leagueClock());

        expect($range->typical)->toBeGreaterThanOrEqual($range->planned)
            ->and($range->latest)->toBeGreaterThan($range->typical);
    }
})->with([
    ['chess', 'blitz'], ['rocket-league', '1v1'], ['rocket-league', '3v3'], ['ea-sports-fc-26', '1v1'], ['ea-sports-fc-27', '2v2'],
]);

test('on site the overhead and the online deadlines do not apply', function () {
    $estimator = new Estimator;
    $profile = GameProfile::for('rocket-league', '3v3');
    $options = FormatOptions::defaults($profile);
    $structure = $estimator->structure(TournamentFormat::SingleElimination, 8, $options);

    $range = $estimator->range(TournamentFormat::SingleElimination, $structure, $profile, $options, 2, leagueClock());

    expect($range->typical)->toEqual($range->planned)
        ->and($range->latest)->toEqual($range->planned);
});

test('a report deadline of the tournament\'s own, in hours, replaces the derived one', function () {
    $profile = GameProfile::for('ea-sports-fc-26', '1v1');

    expect(leagueClock(1)->reportDueMinutes($profile, 1))->toBe(60)
        ->and(leagueClock(1)->hardEnd($profile, 1))->toEqual(70.0)
        ->and(leagueClock()->reportDueMinutes($profile, 1))->toBe(45);
});

test('the chooser warns when the typical end is after midnight in Berlin', function (string $start, float $typical, bool $warns) {
    $range = new DurationRange(100, $typical, 300);
    $at = CarbonImmutable::parse($start, 'Europe/Berlin');

    expect(in_array('after-midnight', $range->warnings($at, GameProfile::for('ea-sports-fc-26', '1v1'), 'Europe/Berlin'), true))->toBe($warns);
})->with([
    'ends 23:59' => ['2026-10-03 20:00', 239, false],
    'ends 00:00' => ['2026-10-03 20:00', 240, true],
    'ends 00:30' => ['2026-10-03 20:00', 270, true],
    // The start in UTC is 18:00; midnight is Berlin's.
    'UTC start, Berlin midnight' => ['2026-10-03 18:00 UTC', 239, false],
]);

test('the chooser warns when the worst case exceeds 10 hours, and never for daily chess', function () {
    $at = CarbonImmutable::parse('2026-10-03 12:00', 'Europe/Berlin');
    $fc = GameProfile::for('ea-sports-fc-26', '1v1');

    expect((new DurationRange(100, 200, 600))->warnings($at, $fc, 'Europe/Berlin'))->toBe([])
        ->and((new DurationRange(100, 200, 601))->warnings($at, $fc, 'Europe/Berlin'))->toBe(['too-long'])
        ->and((new DurationRange(90, 90, 5000))->warnings($at, GameProfile::for('chess', 'correspondence'), 'Europe/Berlin'))->toBe([]);
});

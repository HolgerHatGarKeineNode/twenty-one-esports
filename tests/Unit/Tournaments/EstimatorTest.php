<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;

/*
|--------------------------------------------------------------------------
| The estimator against the worked examples (TOURNAMENT-FORMATS.md, section 5)
|--------------------------------------------------------------------------
|
| Every row of the four tables, with the default options (DE with reset, RR
| once, Two Stage = RR groups of 4, top 2, SE final). Columns: N, format,
| rounds (time blocks; "all at once" = 1, "groups at once + k" = 1 + k),
| matches, games per participant (fewest, most), guaranteed, planned minutes
| or days, the same without the grand-final reset (DE), fit.
|
| The "recommended" column is pinned per table below. For Rocket League it
| follows the user's decision of 2026-09-26 (a format with a final first),
| which came after the tables were generated: the tables say Swiss there.
|
*/

const WORKED = [
    'blitz online' => ['chess', 'blitz', null, 180, [
        [8, 'swiss', 5, 20, [5, 5], 5, 82, null, 'fits'],
        [8, 'round-robin', 7, 28, [7, 7], 7, 116, null, 'fits'],
        [8, 'two-stage', 5, 15, [3, 5], 3, 82, null, 'fits'],
        [8, 'single-elimination', 3, 7, [1, 3], 1, 48, null, 'fits'],
        [8, 'double-elimination', 7, 15, [2, 7], 2, 116, 99, 'fits'],
        [12, 'swiss', 6, 36, [6, 6], 6, 99, null, 'fits'],
        [12, 'round-robin', 11, 66, [11, 11], 11, 184, null, 'over'],
        [12, 'two-stage', 6, 23, [3, 6], 3, 99, null, 'fits'],
        [12, 'single-elimination', 4, 11, [1, 4], 1, 65, null, 'fits'],
        [12, 'double-elimination', 9, 23, [2, 9], 2, 150, 133, 'fits'],
        [13, 'swiss', 6, 36, [5, 6], 5, 99, null, 'fits'],
        [13, 'round-robin', 13, 78, [12, 12], 12, 218, null, 'long'],
        [13, 'two-stage', 6, 22, [2, 6], 2, 99, null, 'fits'],
        [13, 'single-elimination', 4, 12, [1, 4], 1, 65, null, 'fits'],
        [13, 'double-elimination', 9, 25, [2, 9], 2, 150, 133, 'fits'],
        [32, 'swiss', 7, 112, [7, 7], 7, 116, null, 'fits'],
        [32, 'round-robin', 31, 496, [31, 31], 31, 524, null, 'long'],
        [32, 'two-stage', 7, 63, [3, 7], 3, 116, null, 'fits'],
        [32, 'single-elimination', 5, 31, [1, 5], 1, 82, null, 'fits'],
        [32, 'double-elimination', 11, 63, [2, 11], 2, 184, 167, 'over'],
    ]],
    'rl 3v3 online' => ['rocket-league', '3v3', null, 180, [
        [8, 'swiss', 5, 20, [5, 5], 5, 165, null, 'fits'],
        [8, 'round-robin', 7, 28, [7, 7], 7, 233, null, 'long'],
        [8, 'two-stage', 5, 15, [3, 5], 3, 181, null, 'over'],
        [8, 'single-elimination', 3, 7, [1, 3], 1, 113, null, 'fits'],
        [8, 'double-elimination', 7, 15, [2, 7], 2, 265, 215, 'long'],
        [12, 'swiss', 5, 30, [5, 5], 5, 165, null, 'fits'],
        [12, 'round-robin', 11, 66, [11, 11], 11, 369, null, 'long'],
        [12, 'two-stage', 6, 23, [3, 6], 3, 215, null, 'long'],
        [12, 'single-elimination', 4, 11, [1, 4], 1, 147, null, 'fits'],
        [12, 'double-elimination', 9, 23, [2, 9], 2, 333, 283, 'long'],
        [13, 'swiss', 5, 30, [4, 5], 4, 165, null, 'fits'],
        [13, 'round-robin', 13, 78, [12, 12], 12, 437, null, 'long'],
        [13, 'two-stage', 6, 22, [2, 6], 2, 215, null, 'long'],
        [13, 'single-elimination', 4, 12, [1, 4], 1, 147, null, 'fits'],
        [13, 'double-elimination', 9, 25, [2, 9], 2, 333, 283, 'long'],
        [32, 'swiss', 5, 80, [5, 5], 5, 165, null, 'fits'],
        [32, 'round-robin', 31, 496, [31, 31], 31, 1049, null, 'long'],
        [32, 'two-stage', 7, 63, [3, 7], 3, 249, null, 'long'],
        [32, 'single-elimination', 5, 31, [1, 5], 1, 181, null, 'over'],
        [32, 'double-elimination', 11, 63, [2, 11], 2, 401, 351, 'long'],
    ]],
    'daily, 3 months' => ['chess', 'correspondence', null, 90, [
        [8, 'swiss', 3, 12, [3, 3], 3, 92, null, 'over'],
        [8, 'round-robin', 1, 28, [7, 7], 7, 30, null, 'fits'],
        [8, 'two-stage', 3, 15, [3, 5], 3, 92, null, 'over'],
        [8, 'single-elimination', 3, 7, [1, 3], 1, 92, null, 'over'],
        [8, 'double-elimination', 7, 15, [2, 7], 2, 216, 185, 'long'],
        [12, 'swiss', 4, 24, [4, 4], 4, 123, null, 'long'],
        [12, 'round-robin', 1, 66, [11, 11], 11, 30, null, 'fits'],
        [12, 'two-stage', 4, 23, [3, 6], 3, 123, null, 'long'],
        [12, 'single-elimination', 4, 11, [1, 4], 1, 123, null, 'long'],
        [12, 'double-elimination', 9, 23, [2, 9], 2, 278, 247, 'long'],
        [13, 'swiss', 4, 24, [3, 4], 3, 123, null, 'long'],
        [13, 'round-robin', 1, 78, [12, 12], 12, 30, null, 'fits'],
        [13, 'two-stage', 4, 22, [2, 6], 2, 123, null, 'long'],
        [13, 'single-elimination', 4, 12, [1, 4], 1, 123, null, 'long'],
        [13, 'double-elimination', 9, 25, [2, 9], 2, 278, 247, 'long'],
        [32, 'swiss', 5, 80, [5, 5], 5, 154, null, 'long'],
        [32, 'round-robin', 1, 496, [31, 31], 31, 30, null, 'fits'],
        [32, 'two-stage', 5, 63, [3, 7], 3, 154, null, 'long'],
        [32, 'single-elimination', 5, 31, [1, 5], 1, 154, null, 'long'],
        [32, 'double-elimination', 11, 63, [2, 11], 2, 340, 309, 'long'],
    ]],
    'blitz, 4 boards' => ['chess', 'blitz', 4, 180, [
        [8, 'swiss', 5, 20, [5, 5], 5, 82, null, 'fits'],
        [8, 'round-robin', 7, 28, [7, 7], 7, 116, null, 'fits'],
        [8, 'two-stage', 5, 15, [3, 5], 3, 82, null, 'fits'],
        [8, 'single-elimination', 3, 7, [1, 3], 1, 48, null, 'fits'],
        [8, 'double-elimination', 7, 15, [2, 7], 2, 116, 99, 'fits'],
        [12, 'swiss', 5, 30, [5, 5], 5, 152, null, 'fits'],
        [12, 'round-robin', 11, 66, [11, 11], 11, 338, null, 'long'],
        [12, 'two-stage', 6, 23, [3, 6], 3, 141, null, 'fits'],
        [12, 'single-elimination', 4, 11, [1, 4], 1, 65, null, 'fits'],
        [12, 'double-elimination', 9, 23, [2, 9], 2, 164, 147, 'fits'],
        [13, 'swiss', 5, 30, [4, 5], 4, 152, null, 'fits'],
        [13, 'round-robin', 13, 78, [12, 12], 12, 400, null, 'long'],
        [13, 'two-stage', 6, 22, [2, 6], 2, 141, null, 'fits'],
        [13, 'single-elimination', 4, 12, [1, 4], 1, 79, null, 'fits'],
        [13, 'double-elimination', 9, 25, [2, 9], 2, 192, 175, 'over'],
        [32, 'swiss', 5, 80, [5, 5], 5, 292, null, 'long'],
        [32, 'round-robin', 31, 496, [31, 31], 31, 1826, null, 'long'],
        [32, 'two-stage', 7, 63, [3, 7], 3, 256, null, 'long'],
        [32, 'single-elimination', 5, 31, [1, 5], 1, 138, null, 'fits'],
        [32, 'double-elimination', 11, 63, [2, 11], 2, 324, 307, 'long'],
    ]],
];

/**
 * @return array<string, array{0: string, 1: string, 2: int|null, 3: int, 4: array<int, mixed>}>
 */
function workedRows(): array
{
    $rows = [];

    foreach (WORKED as $table => [$game, $mode, $stations, $window, $list]) {
        foreach ($list as $row) {
            $rows["{$table}: {$row[0]} {$row[1]}"] = [$game, $mode, $stations, $window, $row];
        }
    }

    return $rows;
}

test('every row of the worked examples', function (string $game, string $mode, ?int $stations, int $window, array $row) {
    [$n, $format, $rounds, $matches, [$fewest, $most], $guaranteed, $total, $withoutReset, $fit] = $row;
    $profile = GameProfile::for($game, $mode);

    $evaluation = (new Estimator)->evaluate($n, $profile, FormatOptions::defaults($profile), $stations, $window);
    $estimate = $evaluation->row(TournamentFormat::from($format));

    expect($estimate->enabled)->toBeTrue()
        ->and(count($estimate->duration->blocks))->toBe($rounds)
        ->and($estimate->structure->matches)->toBe($matches)
        ->and([$estimate->structure->min, $estimate->structure->max])->toBe([$fewest, $most])
        ->and($estimate->structure->guaranteed)->toBe($guaranteed)
        ->and($estimate->duration->total)->toEqual($total)
        ->and($estimate->fit)->toBe($fit);

    if ($withoutReset !== null) {
        expect($estimate->duration->withoutIfNeeded)->toEqual($withoutReset);
    }
})->with(workedRows());

test('the recommendation of each worked table', function (string $game, string $mode, ?int $stations, int $window, int $n, string $recommended, bool $nothingFits = false) {
    $profile = GameProfile::for($game, $mode);

    $evaluation = (new Estimator)->evaluate($n, $profile, FormatOptions::defaults($profile), $stations, $window);

    expect($evaluation->recommended)->toBe(TournamentFormat::from($recommended))
        ->and($evaluation->nothingFits)->toBe($nothingFits);
})->with([
    'blitz 8' => ['chess', 'blitz', null, 180, 8, 'round-robin'],
    'blitz 12 (the layperson test)' => ['chess', 'blitz', null, 180, 12, 'swiss'],
    'blitz 13' => ['chess', 'blitz', null, 180, 13, 'swiss'],
    'blitz 32' => ['chess', 'blitz', null, 180, 32, 'swiss'],
    // Rocket League: a format with a final first (user, 2026-09-26); the table says Swiss.
    'rl 3v3 8: single elimination fits, two stage is 1 min over' => ['rocket-league', '3v3', null, 180, 8, 'single-elimination'],
    'rl 3v3 12' => ['rocket-league', '3v3', null, 180, 12, 'single-elimination'],
    'rl 3v3 13' => ['rocket-league', '3v3', null, 180, 13, 'single-elimination'],
    'rl 3v3 32: no format with a final fits, so Swiss' => ['rocket-league', '3v3', null, 180, 32, 'swiss'],
    'daily 8' => ['chess', 'correspondence', null, 90, 8, 'round-robin'],
    'daily 12' => ['chess', 'correspondence', null, 90, 12, 'round-robin'],
    'daily 13' => ['chess', 'correspondence', null, 90, 13, 'round-robin'],
    'daily 32: nothing fits, the shortest' => ['chess', 'correspondence', null, 90, 32, 'swiss', true],
    'blitz 4 boards 8' => ['chess', 'blitz', 4, 180, 8, 'round-robin'],
    'blitz 4 boards 12' => ['chess', 'blitz', 4, 180, 12, 'swiss'],
    'blitz 4 boards 13' => ['chess', 'blitz', 4, 180, 13, 'swiss'],
    'blitz 4 boards 32' => ['chess', 'blitz', 4, 180, 32, 'single-elimination'],
]);

test('Swiss gets as many rounds as fit, within log2 N and log2 N + 2', function () {
    $blitz = GameProfile::for('chess', 'blitz');
    $estimator = new Estimator;

    expect($estimator->evaluate(12, $blitz, FormatOptions::defaults($blitz), null, 180)->swissRounds)->toBe(6)
        ->and($estimator->evaluate(12, $blitz, FormatOptions::defaults($blitz), null, 60)->swissRounds)->toBe(4)
        ->and($estimator->evaluate(32, $blitz, FormatOptions::defaults($blitz), null, 480)->swissRounds)->toBe(7)
        // An organizer's own round count wins over the fit.
        ->and($estimator->evaluate(12, $blitz, new FormatOptions(swissRounds: 3), null, 180)->swissRounds)->toBe(3);
});

test('Challonge\'s two-thirds rule caps Swiss rounds', function () {
    expect(Estimator::swissMax(4))->toBe(2)
        ->and(Estimator::swissMax(12))->toBe(7)
        ->and(Estimator::swissMax(3))->toBe(1)
        ->and(Estimator::swissDefault(4))->toBe(2);
});

test('the daily round robin with more than 12 games at once is heavy and never recommended', function () {
    $daily = GameProfile::for('chess', 'correspondence');

    $row = (new Estimator)->evaluate(32, $daily, FormatOptions::defaults($daily), null, 90)->row(TournamentFormat::RoundRobin);

    expect($row->atOnce)->toBe(31)->and($row->heavy)->toBeTrue()->and($row->fit)->toBe('fits');
});

test('free for all and leaderboard are disabled with a reason for chess and Rocket League', function (string $game, string $mode, string $who) {
    $profile = GameProfile::for($game, $mode);
    $evaluation = (new Estimator)->evaluate(12, $profile, FormatOptions::defaults($profile), null, 180);

    expect($evaluation->row(TournamentFormat::FreeForAll)->enabled)->toBeFalse()
        ->and($evaluation->row(TournamentFormat::FreeForAll)->reason)->toContain($who)
        ->and($evaluation->row(TournamentFormat::Leaderboard)->enabled)->toBeFalse()
        ->and($evaluation->row(TournamentFormat::Leaderboard)->reason)->toStartWith('Needs a game with a score or time');
})->with([
    'chess' => ['chess', 'blitz', 'Chess is always one player against one.'],
    'rocket league' => ['rocket-league', '3v3', 'Rocket League is always one team against one.'],
]);

test('small fields disable the formats that need more players', function () {
    $blitz = GameProfile::for('chess', 'blitz');
    $evaluation = (new Estimator)->evaluate(3, $blitz, FormatOptions::defaults($blitz), null, 180);

    expect($evaluation->row(TournamentFormat::Swiss)->reason)->toBe('Needs at least 4 players.')
        ->and($evaluation->row(TournamentFormat::TwoStage)->reason)->toBe('Needs at least 6 players for two groups.')
        ->and($evaluation->row(TournamentFormat::DoubleElimination)->enabled)->toBeTrue()
        ->and((new Estimator)->evaluate(2, $blitz, FormatOptions::defaults($blitz), null, 180)->row(TournamentFormat::DoubleElimination)->reason)->toBe('Needs at least 3 players.');
});

test('the grand-final options and the 3rd-place match change the counts', function () {
    $estimator = new Estimator;

    expect($estimator->doubleElimination(8, 'single')->matches)->toBe(14)
        ->and($estimator->doubleElimination(8, 'skip')->matches)->toBe(13)
        ->and($estimator->doubleElimination(8, 'reset')->max)->toBe(1 + 4 + 2)
        ->and($estimator->singleElimination(8, true)->matches)->toBe(8)
        ->and($estimator->singleElimination(2, true)->matches)->toBe(1);
});

test('3 entrants have one semifinal, so no match for 3rd place is counted', function () {
    $estimator = new Estimator;

    expect($estimator->singleElimination(3, true)->matches)->toBe(2)
        ->and(array_column($estimator->singleElimination(3, true)->rounds, 'm'))->toBe([1, 1])
        ->and($estimator->singleElimination(4, true)->matches)->toBe(4)
        ->and($estimator->singleElimination(5, true)->matches)->toBe(5);
});

test('split: upper round 1 is not played, and the lowest seeds may play only 1 match', function () {
    $estimator = new Estimator;
    $split = $estimator->doubleElimination(12, 'reset', true);

    // 12 of 16: seeds 9–12 start in the lower bracket, 4 upper round-1 matches are not played.
    expect($split->matches)->toBe(2 * 12 - 3 - 4 + 2)
        ->and($split->guaranteed)->toBe(1)
        ->and($split->max)->toBe(6 + 2)
        ->and($estimator->doubleElimination(12, 'reset')->guaranteed)->toBe(2);
});

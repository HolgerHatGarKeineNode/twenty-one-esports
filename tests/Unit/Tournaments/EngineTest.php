<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\Bracket;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\Entrant;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Seeding;
use App\Support\Tournaments\Engine\Slot;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\Engine\Swiss;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;

/*
|--------------------------------------------------------------------------
| The bracket engines (P8a)
|--------------------------------------------------------------------------
|
| Entrants are ids 1..N with falling Elo, so id = seed unless a test says
| otherwise. "Play" means: every ready match is decided by `$decide` until
| nothing is ready any more.
|
*/

/**
 * @return list<Entrant>
 */
function entrants(int $n): array
{
    return array_map(fn (int $id): Entrant => new Entrant($id, 2000 - $id), range(1, $n));
}

function bracket(TournamentFormat $format, int $n, array $options = []): Bracket
{
    return BracketBuilder::build($format, entrants($n), new FormatOptions(...$options), 'seed');
}

/**
 * @param  Closure(list<int|null>, BracketMatch): MatchResult  $decide
 * @return array{0: array<string, MatchResult>, 1: array<string, array<string, mixed>>}
 */
function play(Bracket $bracket, Closure $decide, array $options = []): array
{
    $results = [];
    $options = new FormatOptions(...$options);

    for ($guard = 0; $guard < 1000; $guard++) {
        $state = Advancement::resolve($bracket, $results, $options);
        $ready = array_filter($state, fn (array $match): bool => $match['status'] === 'ready');

        if ($ready === []) {
            return [$results, $state];
        }

        foreach ($ready as $key => $match) {
            $results[$key] = $decide($match['entrants'], $bracket->match($key));
        }
    }

    throw new RuntimeException('The bracket never finished.');
}

/** The better seed (lower id) wins. */
function favourite(): Closure
{
    return fn (array $entrants): MatchResult => MatchResult::win($entrants[0] < $entrants[1] ? 0 : 1);
}

/**
 * Matches per (stage, round) in play order, as the estimator counts rounds.
 *
 * @return list<int>
 */
function roundLoads(Bracket $bracket): array
{
    $loads = [];

    foreach ($bracket->played() as $match) {
        $loads[$match->stage][$match->round] = ($loads[$match->stage][$match->round] ?? 0) + 1;
    }

    ksort($loads);
    $flat = [];

    foreach ($loads as $rounds) {
        ksort($rounds);
        array_push($flat, ...array_values($rounds));
    }

    return $flat;
}

test('seeding: Elo first, equal Elo ordered by the seed, reproducibly', function () {
    $field = [new Entrant(1, 1000), new Entrant(2, 1200), new Entrant(3, 1000), new Entrant(4, 1000), new Entrant(5, 1000)];

    $a = array_map(fn (Entrant $e): int => $e->id, Seeding::order($field, 'block-900000'));
    $again = array_map(fn (Entrant $e): int => $e->id, Seeding::order(array_reverse($field), 'block-900000'));
    $orders = collect(range(1, 20))->map(fn (int $i): string => implode(',', array_map(fn (Entrant $e): int => $e->id, Seeding::order($field, "seed-{$i}"))))->unique();

    expect($a[0])->toBe(2)
        ->and($again)->toBe($a)
        ->and($orders->count())->toBeGreaterThan(1)
        ->and(Seeding::bracketOrder(8))->toBe([1, 8, 4, 5, 2, 7, 3, 6]);
});

test('the same entrants, options and seed give the same bracket', function (TournamentFormat $format) {
    $first = BracketBuilder::build($format, entrants(13), new FormatOptions, 'seed');
    $second = BracketBuilder::build($format, array_reverse(entrants(13)), new FormatOptions, 'seed');

    expect($second)->toEqual($first);
})->with([TournamentFormat::SingleElimination, TournamentFormat::DoubleElimination, TournamentFormat::RoundRobin, TournamentFormat::Swiss, TournamentFormat::TwoStage]);

test('single elimination: N − 1 matches, byes to the top seeds, the favourite wins', function (int $n) {
    $bracket = bracket(TournamentFormat::SingleElimination, $n);
    $size = 1 << Estimator::log2($n);
    $roundOne = array_filter($bracket->matches, fn (BracketMatch $m): bool => $m->round === 1);
    $inRoundOne = collect($roundOne)->flatMap(fn (BracketMatch $m) => array_map(fn (Slot $s) => $s->entrant, $m->slots))->all();

    [, $state] = play($bracket, favourite());
    $final = collect($state)->last();

    expect(count($bracket->matches))->toBe($n - 1)
        ->and(count($roundOne))->toBe($n - intdiv($size, 2))
        // The byes: seeds 1 … P − N skip round 1.
        ->and(array_intersect($inRoundOne, $size > $n ? range(1, $size - $n) : []))->toBe([])
        ->and($final['winner'])->toBe(1)
        ->and($final['loser'])->toBe(2);
})->with([2, 3, 8, 12, 13, 32]);

test('single elimination: the match for 3rd place takes both semifinal losers', function () {
    $bracket = bracket(TournamentFormat::SingleElimination, 8, ['thirdPlace' => true]);
    [, $state] = play($bracket, favourite(), ['thirdPlace' => true]);

    expect(count($bracket->matches))->toBe(8)
        ->and($bracket->match('3rd')->round)->toBe(3)
        ->and(collect($state['3rd']['entrants'])->sort()->values()->all())->toBe([3, 4])
        ->and($state['3rd']['winner'])->toBe(3);
});

test('double elimination: 2N − 3 matches plus the grand final, round by round as the estimator plans', function (int $n, string $grandFinal) {
    $bracket = bracket(TournamentFormat::DoubleElimination, $n, ['grandFinal' => $grandFinal]);
    $planned = (new Estimator)->doubleElimination($n, $grandFinal);

    expect(count($bracket->matches))->toBe($planned->matches)
        ->and(roundLoads($bracket))->toBe(array_column($planned->rounds, 'm'));
})->with([3, 5, 8, 12, 13, 32])->with(['reset', 'single', 'skip']);

test('double elimination: everybody but the winner loses twice, the favourite wins without the reset', function (int $n) {
    $bracket = bracket(TournamentFormat::DoubleElimination, $n);
    [$results, $state] = play($bracket, favourite());

    $losses = [];

    foreach ($state as $match) {
        if ($match['loser'] !== null) {
            $losses[$match['loser']] = ($losses[$match['loser']] ?? 0) + 1;
        }
    }

    expect($state['gf']['winner'])->toBe(1)
        ->and($state['gf2']['status'])->toBe('skipped')
        // Seed 2 loses the upper final and the grand final to seed 1.
        ->and(array_count_values($losses))->toBe([2 => $n - 1])
        ->and($losses)->not->toHaveKey(1)
        ->and(count($results))->toBe(2 * $n - 2);
})->with([3, 8, 12, 13]);

test('double elimination: the first lower rounds pair no rematches of the upper bracket', function () {
    $bracket = bracket(TournamentFormat::DoubleElimination, 8);
    [, $state] = play($bracket, favourite());
    $met = [];
    $rematches = [];

    foreach ($bracket->matches as $match) {
        $pair = $state[$match->key]['entrants'];
        sort($pair);
        $key = implode('-', $pair);

        if (in_array($match->key, ['l1-1', 'l1-2', 'l2-1', 'l2-2'], true) && isset($met[$key])) {
            $rematches[] = $key;
        }

        $met[$key] = true;
    }

    expect($state['l2-1']['entrants'])->toBe([5, 3])->and($rematches)->toBe([]);
});

test('double elimination: the grand-final reset is played when the lower-bracket winner wins', function () {
    $bracket = bracket(TournamentFormat::DoubleElimination, 8);

    // Seed 1 loses the upper final to seed 2, wins the lower final, then the grand final.
    [, $state] = play($bracket, function (array $entrants, BracketMatch $match): MatchResult {
        if ($match->key === 'gf') {
            return MatchResult::win(1);
        }

        if ($match->bracket === 'upper' && $match->round === 3) {
            return MatchResult::win($entrants[0] === 2 ? 0 : 1);
        }

        return MatchResult::win($entrants[0] < $entrants[1] ? 0 : 1);
    });

    expect($state['gf']['entrants'])->toBe([2, 1])
        ->and($state['gf2']['status'])->toBe('done')
        ->and($state['gf2']['entrants'])->toBe([1, 2]);
});

test('double elimination: split sends the lower seeds straight to the lower bracket', function () {
    $bracket = bracket(TournamentFormat::DoubleElimination, 8, ['split' => true]);
    $lowerFirst = collect($bracket->matches)->first(fn (BracketMatch $m): bool => $m->bracket === 'lower');

    expect(collect($bracket->matches)->where('bracket', 'upper')->count())->toBe(3)
        ->and(array_map(fn (Slot $s) => $s->entrant, $lowerFirst->slots))->toBe([8, 5])
        ->and($lowerFirst->round)->toBe(1);
});

test('round robin: everyone meets everyone once per iteration, an odd field has one bye per round', function (int $n, int $iterations) {
    $bracket = bracket(TournamentFormat::RoundRobin, $n, ['iterations' => $iterations]);
    $pairs = [];
    $perRound = [];

    foreach ($bracket->matches as $match) {
        [$a, $b] = array_map(fn (Slot $s) => $s->entrant, $match->slots);
        $pairs[min($a, $b).'-'.max($a, $b)][] = [$a, $b];
        $perRound[$match->round] = [...($perRound[$match->round] ?? []), $a, $b];
    }

    $planned = (new Estimator)->roundRobin($n, $iterations);

    expect(count($pairs))->toBe(intdiv($n * ($n - 1), 2))
        ->and(collect($pairs)->every(fn (array $meetings) => count($meetings) === $iterations))
        ->toBeTrue()
        ->and(count($perRound))->toBe(count($planned->rounds))
        ->and(collect($perRound)->every(fn (array $players) => count($players) === count(array_unique($players)) && count($players) === 2 * intdiv($n, 2)))->toBeTrue();

    if ($iterations === 2) {
        // The return match swaps the sides (in chess: the colors).
        expect(collect($pairs)->every(fn (array $meetings) => $meetings[0] === array_reverse($meetings[1])))->toBeTrue();
    }
})->with([2, 3, 8, 12, 13])->with([1, 2]);

test('swiss: 1 meets N/2 + 1 in round 1, nobody meets twice, the bye goes to the lowest without one', function (int $n, int $rounds) {
    $ids = range(1, $n);
    $games = [];
    $byes = [];

    for ($round = 1; $round <= $rounds; $round++) {
        $matches = Swiss::pairRound($round, $ids, $games);

        if ($round === 1) {
            $first = array_map(fn (Slot $s) => $s->entrant, $matches[0]->slots);
            sort($first);
            expect($first)->toBe([1, intdiv($n, 2) + 1]);
        }

        $seen = [];

        foreach ($matches as $match) {
            $entrants = array_map(fn (Slot $s) => $s->entrant, $match->slots);
            array_push($seen, ...$entrants);

            if ($match->bracket === 'bye') {
                $byes[] = $entrants[0];
                $games[] = [$entrants[0], null, MatchResult::win(0)];

                continue;
            }

            // Draws between neighbours in seed, else the better seed wins.
            $result = abs($entrants[0] - $entrants[1]) === 1 ? MatchResult::draw() : MatchResult::win($entrants[0] < $entrants[1] ? 0 : 1);
            $games[] = [$entrants[0], $entrants[1], $result];
        }

        sort($seen);
        expect($seen)->toBe($ids);
    }

    $pairings = collect($games)->filter(fn (array $g) => $g[1] !== null)->map(fn (array $g) => min($g[0], $g[1]).'-'.max($g[0], $g[1]));

    expect($pairings->count())->toBe($rounds * intdiv($n, 2))
        ->and($pairings->unique()->count())->toBe($pairings->count())
        ->and(count($byes))->toBe($n % 2 === 1 ? $rounds : 0)
        ->and(count(array_unique($byes)))->toBe(count($byes));

    if ($n % 2 === 1) {
        // Round 1: the lowest seed sits out.
        expect($byes[0])->toBe($n);
    }
})->with([
    '2 players, 1 round' => [2, 1],
    '3 players, 2 rounds' => [3, 2],
    '8 players, 5 rounds' => [8, 5],
    '12 players, 6 rounds' => [12, 6],
    '13 players, 6 rounds' => [13, 6],
    '32 players, 7 rounds' => [32, 7],
]);

test('swiss: after round 1 the winners meet the winners', function () {
    $round1 = Swiss::pairRound(1, range(1, 8), []);
    $games = array_map(fn (BracketMatch $m) => [$m->slots[0]->entrant, $m->slots[1]->entrant,
        MatchResult::win($m->slots[0]->entrant < $m->slots[1]->entrant ? 0 : 1)], $round1);

    $round2 = Swiss::pairRound(2, range(1, 8), $games);
    $pairs = array_map(function (BracketMatch $m): array {
        $p = [$m->slots[0]->entrant, $m->slots[1]->entrant];
        sort($p);

        return $p;
    }, $round2);

    expect($pairs)->toBe([[1, 3], [2, 4], [5, 7], [6, 8]]);
});

test('tie-breaks: median Buchholz, then head-to-head, then games won, then the seed', function () {
    $w = fn (int $slot, array $games = []) => MatchResult::win($slot, $games);
    // 1 and 2 both end on 2 points; 1 beat stronger opponents.
    $games = [
        [1, 3, $w(0)], [1, 4, $w(1)], [1, 5, $w(0)],
        [2, 6, $w(0)], [2, 4, $w(1)], [2, 7, $w(0)],
        [3, 4, $w(0)], [3, 6, $w(0)], [5, 4, $w(1)], [5, 6, $w(0)],
        [6, 7, $w(0)], [7, 8, $w(0)],
    ];
    $table = Standings::table(range(1, 8), $games, tieBreaks: ['median-buchholz', 'head-to-head', 'game-wins']);
    $rank = array_column($table, 'rank', 'entrant');
    $row = collect($table)->keyBy('entrant');

    expect($row[1]->points)->toBe($row[2]->points)
        ->and($row[1]->medianBuchholz)->toBeGreaterThan($row[2]->medianBuchholz)
        ->and($rank[1])->toBeLessThan($rank[2]);

    // Level on points, 3 beat 2: head-to-head puts 3 ahead of the better seed.
    $h2h = Standings::table([2, 3, 8, 9], [[2, 3, $w(1)], [2, 8, $w(0)], [2, 9, $w(0)], [3, 8, $w(0)]], tieBreaks: ['head-to-head']);
    $level = Standings::table([2, 3, 9], [[2, 9, $w(0)], [3, 9, $w(0)], [2, 3, MatchResult::draw()]], tieBreaks: ['head-to-head', 'game-wins']);
    $games2 = Standings::table([2, 3, 9], [[2, 9, $w(0, [2, 1])], [3, 9, $w(0, [2, 0])]], tieBreaks: ['game-difference']);

    expect(array_column($h2h, 'entrant'))->toBe([3, 2, 8, 9])
        // All level: the seed decides.
        ->and(array_column($level, 'entrant'))->toBe([2, 3, 9])
        ->and(array_column($games2, 'entrant'))->toBe([3, 2, 9]);
});

test('median Buchholz drops the best and the worst opponent', function () {
    $w = fn (int $slot) => MatchResult::win($slot);
    // Entrant 1 met 2 (2 pts), 3 (1 pt), 4 (0 pts), 5 (3 pts).
    $games = [[1, 2, $w(0)], [1, 3, $w(0)], [1, 4, $w(0)], [1, 5, $w(0)],
        [2, 6, $w(0)], [2, 7, $w(0)], [3, 6, $w(0)], [5, 6, $w(0)], [5, 7, $w(0)], [5, 8, $w(0)]];

    $row = collect(Standings::table(range(1, 8), $games))->firstWhere('entrant', 1);

    expect($row->buchholz)->toBe(6.0)->and($row->medianBuchholz)->toBe(3.0);
});

test('two stage: snake groups, the group places feed a final where the two of a group meet late', function () {
    $bracket = bracket(TournamentFormat::TwoStage, 8);
    [, $state] = play($bracket, favourite());

    $firstFinalRound = collect($bracket->matches)->filter(fn (BracketMatch $m) => $m->stage === 2 && $m->round === 1)
        ->map(fn (BracketMatch $m) => $state[$m->key]['entrants'])->values()->all();

    expect($bracket->groups)->toBe([1 => [1, 4, 5, 8], 2 => [2, 3, 6, 7]])
        ->and(count($bracket->played()))->toBe(15)
        ->and($firstFinalRound)->toBe([[1, 3], [2, 4]])
        ->and($state['f-m2-1']['winner'])->toBe(1);
});

test('two stage: groups of every kind fit the estimator\'s rounds', function (int $n, string $groupStage, string $finalStage) {
    $options = ['groupStage' => $groupStage, 'finalStage' => $finalStage];
    $bracket = bracket(TournamentFormat::TwoStage, $n, $options);
    $planned = (new Estimator)->twoStage($n, new FormatOptions(...$options));

    expect(count($bracket->played()))->toBe($planned->matches)
        ->and(roundLoads($bracket))->toBe(array_column($planned->rounds, 'm'));

    [, $state] = play($bracket, favourite(), $options);
    expect(collect($state)->where('status', 'waiting')->count())->toBe(0);
})->with([8, 12, 13, 32])->with(['round-robin', 'single-elimination', 'double-elimination'])->with(['single-elimination', 'double-elimination']);

test('every format matches the estimator\'s match count from 2 to 40 entrants', function (TournamentFormat $format, array $options) {
    $estimator = new Estimator;

    foreach (range(2, 40) as $n) {
        if ($estimator->disabledReason($format, new GameProfile('blitz', 'chess', 'blitz', 'min', 14, 0, 3, 1, 1, [1, 2], false, 'game'), $n) !== null) {
            continue;
        }

        $bracket = bracket($format, $n, $options);
        $planned = $estimator->structure($format, $n, new FormatOptions(...$options));

        expect(count($bracket->played()))->toBe($planned->matches, "{$format->value} with {$n}")
            ->and(roundLoads($bracket))->toBe(array_column($planned->rounds, 'm'), "{$format->value} rounds with {$n}");
    }
})->with([
    'single elimination' => [TournamentFormat::SingleElimination, []],
    'double elimination, reset' => [TournamentFormat::DoubleElimination, ['grandFinal' => 'reset']],
    'double elimination, one match' => [TournamentFormat::DoubleElimination, ['grandFinal' => 'single']],
    'round robin twice' => [TournamentFormat::RoundRobin, ['iterations' => 2]],
    'two stage' => [TournamentFormat::TwoStage, []],
]);

test('free for all: heats of 4, the best 2 move on, until one final heat', function () {
    $bracket = bracket(TournamentFormat::FreeForAll, 13);
    $planned = (new Estimator)->freeForAll(13, 4, 2);

    [, $state] = play($bracket, fn (array $entrants): MatchResult => MatchResult::ranked(array_map(fn (int $id) => $id, $entrants)));

    expect(count($bracket->matches))->toBe($planned->matches)
        ->and(roundLoads($bracket))->toBe(array_column($planned->rounds, 'm'))
        ->and(collect($bracket->matches)->where('round', 1)->map(fn (BracketMatch $m) => count($m->slots))->sort()->values()->all())->toBe([3, 3, 3, 4])
        ->and($state['final']['ranking'][0])->toBe(1);
});

test('leaderboard: one board with every entrant', function () {
    $bracket = bracket(TournamentFormat::Leaderboard, 5);

    expect($bracket->matches)->toHaveCount(1)->and($bracket->matches[0]->slots)->toHaveCount(5);
});

<?php

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\TournamentChampion;

/*
| Final places from the bracket (P9): the input of the prize split. The
| better seed wins every match (playOutAsDirector), so the places are known
| in advance; place 1 is always the share card's champion.
*/

/**
 * Places as seed numbers: [[place, [seeds …]], …].
 *
 * @return list<array{0: int, 1: list<int>}>
 */
function placesBySeed(Tournament $tournament): array
{
    $seeds = TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('seed', 'id');

    return array_map(function (array $row) use ($seeds): array {
        $group = array_map(fn (int $id): int => (int) $seeds[$id], $row['participants']);
        sort($group);

        return [$row['place'], $group];
    }, app(TournamentPlacements::class)->of($tournament) ?? []);
}

test('places per format, ties shared, the winner the champion', function (TournamentFormat $format, int $n, array $options, array $expected) {
    $tournament = runningChess($format, $n, options: $options);
    expect(app(TournamentPlacements::class)->of($tournament))->toBeNull();

    playOutAsDirector($tournament);
    $places = placesBySeed($tournament->refresh());

    expect($places)->toBe($expected)
        ->and(app(TournamentPlacements::class)->of($tournament)[0]['participants'])->toBe([app(TournamentChampion::class)->of($tournament)?->id]);
})->with([
    'single elimination, no match for third' => [TournamentFormat::SingleElimination, 4, [], [[1, [1]], [2, [2]], [3, [3, 4]]]],
    'single elimination with a match for third' => [TournamentFormat::SingleElimination, 4, ['thirdPlace' => true], [[1, [1]], [2, [2]], [3, [3]], [4, [4]]]],
    'single elimination of 8' => [TournamentFormat::SingleElimination, 8, [], [[1, [1]], [2, [2]], [3, [3, 4]], [5, [5, 6, 7, 8]]]],
    'double elimination' => [TournamentFormat::DoubleElimination, 4, [], [[1, [1]], [2, [2]], [3, [3]], [4, [4]]]],
    'round robin' => [TournamentFormat::RoundRobin, 4, [], [[1, [1]], [2, [2]], [3, [3]], [4, [4]]]],
]);

test('tied places share their percentages and every division rounds down to the reserve', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 8);
    playOutAsDirector($tournament);
    $plan = app(PayoutPlan::class)->compute($tournament->refresh(), 10_001);
    $amounts = collect($plan['rows'])->map(fn (array $row): array => [$row['place'], $row['amount']])->all();

    // 50 / 30 / 20 of 10 001: 5 000, 3 000, and 2 000 for the two third places, 1 000 each; fifth gets nothing.
    expect($amounts)->toBe([[1, 5000], [2, 3000], [3, 1000], [3, 1000]])
        ->and($plan['remainder'])->toBe(1);
});

test('a team’s share is split equally among its roster', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2);
    $winner = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', 1)->sole();
    $mates = User::factory()->count(2)->create();
    $winner->forceFill(['members' => [$winner->user_id, ...$mates->pluck('id')->all()]])->save();
    playOutAsDirector($tournament);

    $plan = app(PayoutPlan::class)->compute($tournament->refresh(), 1_000);
    $first = collect($plan['rows'])->where('place', 1);

    expect($first->pluck('amount')->all())->toBe([166, 166, 166])
        ->and($first->pluck('user.id')->sort()->values()->all())->toBe(collect([$winner->user_id, ...$mates->pluck('id')])->sort()->values()->all())
        ->and($plan['remainder'])->toBe(1_000 - 3 * 166 - 300);
});

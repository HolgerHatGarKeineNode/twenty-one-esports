<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| A table tie nothing splits (found in P25 S2)
|--------------------------------------------------------------------------
|
| When points and every tie-break are equal, the table falls back to the
| seed order. The public table, the champion and the payout places must
| read the same order: before, the champion and the places took the order
| the entrants first appear in the schedule, the table the seed order.
|
*/

test('a full round-robin circle gives the same order in the table, the champion and the payout places', function () {
    Queue::fake();
    $tournament = runningChess(TournamentFormat::RoundRobin, 3);
    [$first, $second, $third] = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();
    $runner = app(TournamentRunner::class);

    // Everyone beats one and loses to one: 1 point each, head-to-head and wins equal.
    foreach ([[$first, $second], [$second, $third], [$third, $first]] as [$winner, $loser]) {
        $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots')->get()
            ->first(fn (TournamentMatch $match) => array_diff($match->slots->pluck('tournament_participant_id')->all(), [$winner->id, $loser->id]) === []);
        $slot = $match->slots[0]->tournament_participant_id === $winner->id ? 0 : 1;
        $runner->store($match, ['winner' => $slot, 'games_won' => $slot === 0 ? [1.0, 0.0] : [0.0, 1.0], 'points' => [], 'forfeit' => false, 'label' => '1–0', 'by' => 'director']);
    }

    $runner->sync($tournament);
    $tournament->refresh();

    $table = array_column((new TournamentView($tournament))->stages()[0]['parts'][0]['rows'], 'name');
    $places = array_map(fn (array $place) => TournamentParticipant::query()->findOrFail($place['participants'][0])->name, app(TournamentPlacements::class)->of($tournament) ?? []);

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($table)->toBe([$first->name, $second->name, $third->name])
        ->and(app(TournamentChampion::class)->of($tournament)?->name)->toBe($table[0])
        ->and($places)->toBe($table);
});

<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\FirstPlaceTie;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A tie for place 1 of a final table: tie-break match or shared place
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-05, on Blitz Blockli #55: 6:2 against 6:2, 2:2 head-to-head, every tie-break level, and the
| higher seed was champion. 2026-11-06: "entweder Stichkampf oder geteilter erster Platz, soll der Turnierleiter im
| Editor auswählen können (und dann auch irgendwo sichtbar sein)". FormatOptions::$firstPlaceTie, FirstPlaceTie.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** Store a decided game of the open match between `$a` and `$b`: `$a` wins, or a draw when `$draw`. */
function tieGame(Tournament $tournament, TournamentParticipant $a, TournamentParticipant $b, bool $draw = false): void
{
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNull('result')->with('slots')->get()
        ->first(fn (TournamentMatch $match) => count($match->slots) === 2 && array_diff($match->slots->pluck('tournament_participant_id')->all(), [$a->id, $b->id]) === []);
    $slot = $match->slots[0]->tournament_participant_id === $a->id ? 0 : 1;
    // Who entered it, as the director desk stores it (TournamentRunner::enterResult()), so a desk correction finds it.
    $entered = ['by' => 'director', 'user_id' => null, 'name' => 'Director', 'at' => now()->toIso8601String()];
    app(TournamentRunner::class)->store($match, ($draw
        ? ['winner' => null, 'games_won' => [0.5, 0.5], 'points' => [], 'forfeit' => false, 'label' => '½–½']
        : ['winner' => $slot, 'games_won' => $slot === 0 ? [1.0, 0.0] : [0.0, 1.0], 'points' => [], 'forfeit' => false, 'label' => '1–0']) + $entered);
}

/**
 * A round robin of three where everyone beats one and loses to one: level on everything.
 *
 * @return array{0: Tournament, 1: list<TournamentParticipant>}
 */
function circleOfThree(array $options): array
{
    $tournament = runningChess(TournamentFormat::RoundRobin, 3, options: $options);
    $seeds = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();
    [$first, $second, $third] = $seeds;

    foreach ([[$first, $second], [$second, $third], [$third, $first]] as [$winner, $loser]) {
        tieGame($tournament, $winner, $loser);
    }

    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), $seeds];
}

/** @return list<list<string>> the names per place, best first */
function placeNames(Tournament $tournament): array
{
    return array_map(fn (array $place): array => array_map(fn (int $id): string => TournamentParticipant::query()->findOrFail($id)->name, $place['participants']),
        app(TournamentPlacements::class)->of($tournament) ?? []);
}

test('the options: a tie-break match by default, shared on choice, the seeds only where the options predate the choice', function () {
    $profile = GameProfile::for('chess', 'blitz');

    expect(FormatOptions::defaults($profile)->firstPlaceTie)->toBe('playoff')
        ->and(FormatOptions::fromArray(['firstPlaceTie' => 'shared'], $profile)->firstPlaceTie)->toBe('shared')
        ->and(FormatOptions::fromArray(['firstPlaceTie' => 'coin'], $profile)->firstPlaceTie)->toBe('playoff')
        // Stored before the choice (Blitz Blockli #55 among them): the seeds still decide, so no finished result moves.
        ->and(FormatOptions::fromArray(['iterations' => 2], $profile)->firstPlaceTie)->toBe('seed')
        ->and(FormatOptions::fromArray(FormatOptions::defaults($profile)->toArray(), $profile)->firstPlaceTie)->toBe('playoff');
});

test('shared: those level on everything share place 1 and its prize, there is no single champion, and the page says so', function () {
    [$tournament, [$first, $second, $third]] = circleOfThree(['firstPlaceTie' => 'shared']);

    $part = (new TournamentView($tournament))->stages()[0]['parts'][0];

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament))->toBeNull()
        ->and(app(TournamentPlacements::class)->of($tournament))->toBe([['place' => 1, 'participants' => [$first->id, $second->id, $third->id]]])
        ->and(array_column($part['rows'], 'rank'))->toBe([1, 1, 1])
        ->and($part['first_tie'])->toBe(['mode' => 'shared', 'names' => [$first->name, $second->name, $third->name]]);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('Level on points and every tie-break: '.$first->name.', '.$second->name.', '.$third->name.' share 1st place.', false)
        ->assertSee('data-fact="first-tie"', false)
        ->assertSee('Shared 1st place', false)
        // No single winner to zap: the hero leads to the results instead.
        ->assertDontSee('data-test="to-zap-winner"', false);
});

test('playoff: the table opens a knockout among the tied; its winner is champion and the knockout places them', function () {
    [$tournament, [$first, $second, $third]] = circleOfThree(['firstPlaceTie' => 'playoff']);

    $stage = TournamentStage::query()->where('tournament_id', $tournament->id)->where('number', 2)->first();
    $playoff = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', FirstPlaceTie::PREFIX.'%')->with('slots')->orderBy('id')->get();

    // Three level: seeds 2 and 3 play, seed 1 has a bye into the final. No draw, as in any knockout.
    expect($tournament->status)->toBe(TournamentStatus::Running)
        ->and($stage?->format)->toBe(TournamentFormat::SingleElimination)
        ->and($playoff)->toHaveCount(2)
        ->and($playoff[0]->status)->toBe('ready')
        ->and($playoff[0]->slots->pluck('tournament_participant_id')->all())->toBe([$second->id, $third->id])
        ->and($playoff[1]->status)->toBe('waiting')
        ->and(TournamentRunner::allowsDraw($playoff[0]->load('round.stage')))->toBeFalse();

    $view = (new TournamentView($tournament))->stages();
    expect($view[0]['parts'][0]['first_tie']['mode'])->toBe('playoff')
        ->and(array_column($view[0]['parts'][0]['rows'], 'rank'))->toBe([1, 1, 1])
        ->and($view[1]['title'])->toBe('Tie-break matches for 1st place')
        ->and($view[1]['playoff'])->toBeTrue();

    tieGame($tournament, $third, $second);
    app(TournamentRunner::class)->sync($tournament);
    tieGame($tournament->refresh(), $third, $first);
    app(TournamentRunner::class)->sync($tournament);
    $tournament->refresh();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($third->id)
        ->and(placeNames($tournament))->toBe([[$third->name], [$first->name], [$second->name]]);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('A tie-break match decides 1st place.', false)
        ->assertSee('Tie-break matches for 1st place', false)
        ->assertSee('data-fact="first-tie"', false);
});

test('playoff: two level at the top play one match, everyone else keeps the table place after them', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 4, options: ['firstPlaceTie' => 'playoff']);
    [$a, $b, $c, $d] = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();

    // A and B 2½ each, drawn against each other; C 1, D 0.
    foreach ([[$a, $c], [$a, $d], [$b, $c], [$b, $d], [$c, $d]] as [$winner, $loser]) {
        tieGame($tournament, $winner, $loser);
    }
    tieGame($tournament, $a, $b, draw: true);
    app(TournamentRunner::class)->sync($tournament);

    $playoff = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', FirstPlaceTie::PREFIX.'%')->with('slots')->get();
    expect($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($playoff)->toHaveCount(1)
        ->and($playoff[0]->slots->pluck('tournament_participant_id')->all())->toBe([$a->id, $b->id])
        ->and(array_column((new TournamentView($tournament))->stages()[0]['parts'][0]['rows'], 'rank'))->toBe([1, 1, 3, 4]);

    tieGame($tournament, $b, $a);
    app(TournamentRunner::class)->sync($tournament);
    $tournament->refresh();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($b->id)
        ->and(placeNames($tournament))->toBe([[$b->name], [$a->name], [$c->name], [$d->name]]);
});

test('a clear place 1 opens no knockout and shares nothing', function (string $rule) {
    $tournament = runningChess(TournamentFormat::RoundRobin, 3, options: ['firstPlaceTie' => $rule]);
    [$a, $b, $c] = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();

    foreach ([[$a, $b], [$a, $c], [$b, $c]] as [$winner, $loser]) {
        tieGame($tournament, $winner, $loser);
    }
    app(TournamentRunner::class)->sync($tournament);
    $tournament->refresh();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(TournamentStage::query()->where('tournament_id', $tournament->id)->count())->toBe(1)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($a->id)
        ->and(placeNames($tournament))->toBe([[$a->name], [$b->name], [$c->name]])
        ->and((new TournamentView($tournament))->stages()[0]['parts'][0]['first_tie'])->toBeNull();
})->with(['playoff', 'shared']);

test('Swiss plays a tie for place 1 off too, after its last round', function () {
    // Players report here, so the round closes by itself once its results are in (a director closes it by hand).
    $tournament = runningChess(TournamentFormat::Swiss, 2, TournamentResultsMode::Players, ['firstPlaceTie' => 'playoff', 'swissRounds' => 1]);
    [$a, $b] = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();

    tieGame($tournament, $a, $b, draw: true);
    app(TournamentRunner::class)->sync($tournament);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and(FirstPlaceTie::playoffStage($tournament))->not->toBeNull();

    tieGame($tournament, $b, $a);
    app(TournamentRunner::class)->sync($tournament);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($b->id);
});

test('the editor offers the choice for a round robin and Swiss, stores it, and not for a knockout', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Blitz Liga')
        ->set('players', '6')
        ->call('select', TournamentFormat::RoundRobin->value)
        ->assertSet('options.firstPlaceTie', 'playoff')
        ->call('pickFirstPlaceTie', 'shared')->assertSet('options.firstPlaceTie', 'shared')
        // Never the old seed rule from the form.
        ->call('pickFirstPlaceTie', 'seed')->assertSet('options.firstPlaceTie', 'shared')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();
    expect($tournament->formatOptions()->firstPlaceTie)->toBe('shared');

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="first-place-tie-option"');

    $tournament->forceFill(['format' => TournamentFormat::Swiss])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertSeeHtml('data-test="first-place-tie-option"');

    $tournament->forceFill(['format' => TournamentFormat::SingleElimination])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertDontSeeHtml('data-test="first-place-tie-option"');
});

/*
| A result of the table corrected after the tie-break knockout opened (review of PR #21): the knockout follows the
| table while nobody has played it, and reads nothing once it is under way. FirstPlaceTie::isStale().
*/

/**
 * A round robin of four: A, B and C draw each other, A and B beat D, D beats C. A and B level on everything at 2, C and
 * D 1 each; the directors closed every round of the table, so a correction goes through the control.
 *
 * @return array{0: Tournament, 1: list<TournamentParticipant>}
 */
function drawnPairOfFour(): array
{
    $tournament = runningChess(TournamentFormat::RoundRobin, 4, options: ['firstPlaceTie' => 'playoff']);
    $seeds = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->get()->all();
    [$a, $b, $c, $d] = $seeds;

    tieGame($tournament, $a, $b, draw: true);
    tieGame($tournament, $a, $c, draw: true);
    tieGame($tournament, $b, $c, draw: true);
    tieGame($tournament, $a, $d);
    tieGame($tournament, $b, $d);
    tieGame($tournament, $d, $c);
    app(TournamentRunner::class)->sync($tournament);

    while (($round = TournamentRunner::currentRound($tournament->refresh())) !== null && $round->stage->number === 1) {
        app(TournamentRunner::class)->closeRound($round, $tournament->creator);
    }

    return [$tournament->refresh(), $seeds];
}

/** An admin sets the result of the table's match between `$winner` and `$loser`: `$winner` wins, or a draw. */
function correctTable(Tournament $tournament, TournamentParticipant $winner, TournamentParticipant $loser, bool $draw = false): void
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'not like', FirstPlaceTie::PREFIX.'%')->with('slots')->get()
        ->first(fn (TournamentMatch $match) => count($match->slots) === 2 && array_diff($match->slots->pluck('tournament_participant_id')->all(), [$winner->id, $loser->id]) === []);
    $input = ['result' => $draw ? '1/2-1/2' : ($match->slots[0]->tournament_participant_id === $winner->id ? '1-0' : '0-1')];

    expect(app(TournamentControl::class)->setResult($tournament, $admin, $match->id, $input, 'The scoresheet said otherwise'))->toBeTrue();
}

/** @return list<int> the participants the tie-break knockout was opened for, sorted; empty without one */
function playoffEntrants(Tournament $tournament): array
{
    $stage = FirstPlaceTie::playoffStage($tournament);

    return $stage === null ? [] : FirstPlaceTie::playoffEntrants($stage);
}

test('a correction that clears place 1 takes the unplayed tie-break back, and the table places everyone', function () {
    [$tournament, [$a, $b, $c, $d]] = drawnPairOfFour();

    expect(playoffEntrants($tournament))->toBe([$a->id, $b->id]);

    correctTable($tournament, $a, $b);
    $tournament->refresh();

    // A 2½, B 1½; C and D 1 each, D ahead on the head-to-head.
    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(TournamentStage::query()->where('tournament_id', $tournament->id)->count())->toBe(1)
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', FirstPlaceTie::PREFIX.'%')->exists())->toBeFalse()
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($a->id)
        ->and(placeNames($tournament))->toBe([[$a->name], [$b->name], [$d->name], [$c->name]]);
});

test('a correction that leaves another tie draws the unplayed tie-break up anew for the new tie', function () {
    [$tournament, [$a, $b, $c, $d]] = drawnPairOfFour();

    // C beats D instead: A, B and C level on everything at 2.
    correctTable($tournament, $c, $d);
    $tournament->refresh();

    $playoff = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', FirstPlaceTie::PREFIX.'%')->with('slots')->orderBy('id')->get();

    expect($tournament->status)->toBe(TournamentStatus::Running)
        ->and(TournamentStage::query()->where('tournament_id', $tournament->id)->count())->toBe(2)
        ->and(playoffEntrants($tournament))->toBe([$a->id, $b->id, $c->id])
        ->and(FirstPlaceTie::isStale($tournament))->toBeFalse()
        // Seeds 2 and 3 play, seed 1 has a bye into the final.
        ->and($playoff)->toHaveCount(2)
        ->and($playoff[0]->slots->pluck('tournament_participant_id')->all())->toBe([$b->id, $c->id]);
});

test('a correction after the tie-break was played leaves it, reads no places and no champion, and tells the directors', function () {
    [$tournament, [$a, $b, $c, $d]] = drawnPairOfFour();
    $runner = app(TournamentRunner::class);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', FirstPlaceTie::PREFIX.'%')->with('slots')->sole();

    $runner->enterResult($final, $tournament->creator, ['result' => '1-0']);
    $runner->closeRound(TournamentRunner::currentRound($tournament), $tournament->creator);
    $tournament->refresh();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($final->slots[0]->tournament_participant_id)
        ->and(FirstPlaceTie::isStale($tournament))->toBeFalse();

    // Before the payouts: C beat D after all, so A, B and C were level, but the knockout of A and B is played.
    correctTable($tournament, $c, $d);
    $tournament->refresh();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(playoffEntrants($tournament))->toBe([$a->id, $b->id])
        ->and(FirstPlaceTie::isStale($tournament))->toBeTrue()
        ->and(app(TournamentPlacements::class)->of($tournament))->toBeNull()
        ->and(app(TournamentChampion::class)->of($tournament))->toBeNull()
        ->and(app(PayoutPlan::class)->compute($tournament, 100_000))->toBeNull();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('tournament-control', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="control-stale-tie"')
        ->assertSee('The tie-break for 1st place no longer matches the table');
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="desk-stale-tie"');
});

test('director mode: a correction at the desk while the table\'s round is still open is caught at the next sync', function () {
    // The knockout opened while the directors had not closed the table's rounds yet (circleOfThree() syncs once all its
    // results are in), so a correction of the open round goes through the desk, which does not sync.
    [$tournament] = circleOfThree(['firstPlaceTie' => 'playoff']);
    $round = TournamentRunner::currentRound($tournament);
    $match = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('bracket', '!=', 'bye')->with('slots')->sole();
    $loser = $match->slots[1 - $match->result['winner']]->tournament_participant_id;
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    expect($round->stage->number)->toBe(1)
        ->and(FirstPlaceTie::playoffStage($tournament))->not->toBeNull();

    // Flipping any game of the circle gives its loser two wins: place 1 is clear.
    app(TournamentControl::class)->setResult($tournament, $admin, $match->id, ['result' => $match->result['winner'] === 0 ? '0-1' : '1-0'], 'The scoresheet said otherwise');

    expect(FirstPlaceTie::isStale($tournament->refresh()))->toBeTrue();
    Livewire::actingAs($tournament->creator)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="desk-stale-tie"');

    app(TournamentRunner::class)->closeRound($round, $tournament->creator);
    $tournament->refresh();

    expect(FirstPlaceTie::playoffStage($tournament))->toBeNull()
        ->and($tournament->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentChampion::class)->of($tournament)?->id)->toBe($loser);
});

test('the director desk and the control name the tie-break round as such, not as another round of the table', function () {
    [$tournament] = drawnPairOfFour();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($tournament->creator)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="desk-tie-break"')
        ->assertSee('(Tie-break match for 1st place)');

    $control = Livewire::actingAs($admin)->test('tournament-control', ['tournament' => $tournament]);
    $control->assertSeeHtml('data-test="control-tie-break"');
    // Only the knockout's round: the table's three rounds keep their plain names.
    expect(substr_count($control->html(), 'data-test="control-tie-break"'))->toBe(1);
});

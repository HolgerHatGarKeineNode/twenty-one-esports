<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong tournaments (plan "Proof of Pong", P4)
|--------------------------------------------------------------------------
|
| Behind ESPORTS_PONG_TOURNAMENTS (off by default): off, the chooser does not offer the game and no tournament is made
| of it; on, it is offered. In a bracket each pairing is one live match the league starts and sends both players to;
| its winner, played honestly through the referee, wins the pairing and plays on. A player who never opens their
| match loses it by forfeit once the check-in is over; a match nobody opened is started again.
|
*/

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\PongMatchStarted;
use App\Events\PongMatchUpdated;
use App\Games\ProofOfPong;
use App\Models\Admin;
use App\Models\PongMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Pong\PongMatches;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\PongLive;
use Tests\Support\PongOn;

beforeEach(function () {
    $this->withoutVite();
    PongOn::play();
});

/** A running Proof of Pong knockout of `$n` players, drawn, its first round started. */
function pongTournament(int $n, ?int $checkinMinutes = 3): Tournament
{
    $profile = GameProfile::for(ProofOfPong::SLUG, 'live');
    $tournament = Tournament::factory()->create([
        'game' => ProofOfPong::SLUG, 'mode' => 'live', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], $profile)->toArray(), 'capacity' => $n, 'checkin_minutes' => $checkinMinutes,
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'pong-cup-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create(['name' => "Pong Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Pong Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

test('Proof of Pong tournaments are offered only while ESPORTS_PONG_TOURNAMENTS is on (off by default); one that exists keeps its game', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $key = TournamentGames::keyOf(ProofOfPong::SLUG, 'live');

    expect($key)->toBe('proof-of-pong/live')
        ->and(config('esports.pong.tournaments'))->toBeFalse()
        ->and(array_column(TournamentGames::grouped(), 'slug'))->not->toContain(ProofOfPong::SLUG)
        ->and(TournamentGames::offers($key))->toBeFalse()
        ->and(array_column(TournamentGames::grouped($key), 'slug'))->toContain(ProofOfPong::SLUG);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->assertDontSee('data-test="game-row-proof-of-pong"', false)
        ->call('pickGame', $key)
        ->assertSet('game', 'blitz')
        ->set('game', $key)
        ->set('name', 'Sneaky Pong')
        ->call('create')
        ->assertHasErrors('game');

    expect(Tournament::query()->where('game', ProofOfPong::SLUG)->exists())->toBeFalse();

    $chess = Tournament::factory()->create(['status' => TournamentStatus::Draft]);
    expect(fn () => app(TournamentEditor::class)->update($chess, $chess->creator, ['game' => ProofOfPong::SLUG, 'mode' => 'live']))
        ->toThrow(TournamentRuleViolation::class);

    config(['esports.pong.tournaments' => true]);

    expect(array_column(TournamentGames::grouped(), 'slug'))->toContain(ProofOfPong::SLUG)
        ->and(TournamentGames::offers($key))->toBeTrue();

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->assertSee('data-test="game-row-proof-of-pong"', false)
        ->set('name', 'Pong Night')
        ->call('pickGame', $key)
        ->assertSet('game', $key)
        ->set('players', '8')
        ->call('select', 'single-elimination')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->where('game', ProofOfPong::SLUG)->sole();

    expect([$tournament->mode, $tournament->format])->toBe(['live', TournamentFormat::SingleElimination])
        ->and($tournament->profile()->isPong())->toBeTrue()
        ->and((new Estimator)->disabledReason(TournamentFormat::FreeForAll, $tournament->profile(), 8))->toBe('Needs 3 or more players in one match. Proof of Pong is always one player against one.');
});

test('a bracket pairing is one live match both players are sent to; its winner, played through the referee, wins the pairing and plays on', function () {
    Event::fake([PongMatchStarted::class]);
    $tournament = pongTournament(4);
    $first = PongMatch::query()->with(['left', 'right'])->orderBy('id')->get();

    expect($first)->toHaveCount(2)
        ->and($first->every(fn (PongMatch $match): bool => $match->status === PongMatchStatus::Waiting && $match->tournament_match_id !== null))->toBeTrue();
    Event::assertDispatched(PongMatchStarted::class, fn (PongMatchStarted $event): bool => $event->ulid === $first[0]->ulid && $event->userIds === [(int) $first[0]->left_id, (int) $first[0]->right_id]);

    // The tournament page sends a player of it to the match, in a tab of its own.
    $this->actingAs($first[0]->left)->get(route('tournaments.show', $tournament))->assertOk()->assertSee(route('pong.match', $first[0]), false)->assertSee(__('Go to your match'));

    // Both open it, and the match is played to its end, every contact reported to the referee.
    $matches = app(PongMatches::class);
    $matches->sync($first[0], $first[0]->left);
    $matches->sync($first[0], $first[0]->right);
    PongLive::play($first[0]->refresh(), [4, 1]);
    $played = $first[0]->refresh();
    $pairing = TournamentMatch::query()->findOrFail($played->tournament_match_id);
    $winnerSlot = in_array((int) $played->winner_id, $pairing->slots()->with('participant')->orderBy('slot')->get()[0]->participant->memberIds(), true) ? 0 : 1;

    expect($played->status)->toBe(PongMatchStatus::Finished)
        ->and(max($played->score()))->toBeGreaterThanOrEqual(21)
        ->and($pairing->result)->toMatchArray(['winner' => $winnerSlot, 'by' => 'players', 'forfeit' => false, 'pong' => $played->ulid, 'label' => max($played->score()).':'.min($played->score())])
        ->and($pairing->status)->toBe('done');

    // The other pairing: one player never opens the match; once the check-in is over the other wins it by forfeit.
    $matches->sync($first[1], $first[1]->right);
    $this->travel(3 * 60 + 5)->seconds();
    $matches->sweep();
    $noShow = $first[1]->refresh();
    $other = TournamentMatch::query()->findOrFail($noShow->tournament_match_id);

    expect($noShow->status)->toBe(PongMatchStatus::Finished)
        ->and($noShow->winner_id)->toBe($noShow->right_id)
        ->and($noShow->end_reason)->toBe(PongEndReason::Forfeit)
        ->and($noShow->left_rating_after)->toBeNull()
        ->and($other->result)->toMatchArray(['forfeit' => true, 'by' => 'players']);

    // The final: the two winners, in a new live match.
    $final = PongMatch::query()->whereKeyNot($first->modelKeys())->sole();

    expect([$final->left_id, $final->right_id])->toEqualCanonicalizing([(int) $played->winner_id, (int) $noShow->winner_id])
        ->and($final->status)->toBe(PongMatchStatus::Waiting);
});

test('a match nobody opened is called off and started again with the sides swapped, then the double no-show rule decides', function () {
    config(['esports.tournaments.first_move_restarts' => 1]);
    $tournament = pongTournament(2, checkinMinutes: 1);
    $first = PongMatch::query()->sole();
    $pairing = TournamentMatch::query()->findOrFail($first->tournament_match_id);

    $this->travel(65)->seconds();
    app(PongMatches::class)->sweep();
    $again = PongMatch::query()->whereKeyNot($first->id)->sole();

    expect($first->refresh()->status)->toBe(PongMatchStatus::Aborted)
        ->and([$again->left_id, $again->right_id])->toBe([$first->right_id, $first->left_id])
        ->and($pairing->refresh()->result)->toBeNull();

    $this->travel(65)->seconds();
    app(PongMatches::class)->sweep();

    expect(PongMatch::query()->count())->toBe(2)
        ->and($pairing->refresh()->result)->toMatchArray(['by' => 'league'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a result that failed to reach the bracket is reported again by pong:check-clocks, a finished match and a called-off one', function () {
    config(['esports.tournaments.first_move_restarts' => 0]);
    $broken = fn () => app()->bind(TournamentRunner::class, fn () => throw new RuntimeException('bracket unreachable'));
    $mended = fn () => app()->offsetUnset(TournamentRunner::class);

    // Played to the end while the bracket cannot be written: the match is over, the pairing still open.
    $tournament = pongTournament(2);
    $played = PongMatch::query()->sole();
    $matches = app(PongMatches::class);
    $matches->sync($played, $played->left);
    $matches->sync($played, $played->right);
    $broken();
    PongLive::play($played->refresh(), [4, 1]);
    $mended();
    $pairing = TournamentMatch::query()->findOrFail($played->tournament_match_id);

    expect($played->refresh()->status)->toBe(PongMatchStatus::Finished)->and($pairing->refresh()->result)->toBeNull();

    // Not within the grace the ending request has for its own report, then once.
    $this->artisan('pong:check-clocks')->assertSuccessful();
    expect($pairing->refresh()->result)->toBeNull();

    $this->travel(31)->seconds();
    $this->artisan('pong:check-clocks')->assertSuccessful();

    expect($pairing->refresh()->result)->toMatchArray(['pong' => $played->ulid, 'by' => 'players', 'forfeit' => false])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    // Called off (nobody opened it) while the bracket cannot be written: the double no-show rule decides on the sweep.
    $other = pongTournament(2, checkinMinutes: 1);
    $noShow = PongMatch::query()->where('id', '>', $played->id)->sole();
    $broken();
    $this->travel(65)->seconds();
    $matches->sweep();
    $mended();
    $otherPairing = TournamentMatch::query()->findOrFail($noShow->tournament_match_id);

    expect($noShow->refresh()->status)->toBe(PongMatchStatus::Aborted)->and($otherPairing->refresh()->result)->toBeNull();

    $this->travel(31)->seconds();
    $this->artisan('pong:check-clocks')->assertSuccessful();

    expect($otherPairing->refresh()->result)->toMatchArray(['by' => 'league'])
        ->and($other->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(PongMatch::query()->count())->toBe(2);
});

test('the league voids a tournament match: called off unrated, both pages hear it, the bracket hears nothing, and once only', function () {
    Event::fake([PongMatchUpdated::class]);
    $tournament = pongTournament(2);
    $played = PongMatch::query()->sole();
    $played->forceFill(['rated' => true])->save();
    $version = (int) $played->state['version'];

    expect(app(PongMatches::class)->void($played))->toBeTrue();
    $played->refresh();

    expect($played->status)->toBe(PongMatchStatus::Aborted)
        ->and($played->end_reason)->toBe(PongEndReason::Abort)
        ->and($played->winner_id)->toBeNull()
        ->and($played->rated)->toBeFalse()
        ->and((int) $played->state['version'])->toBe($version + 1)
        // The bracket heard nothing: a called-off match it heard of is started again (TournamentRunner::pongMatchAborted()).
        ->and(TournamentMatch::query()->findOrFail($played->tournament_match_id)->result)->toBeNull()
        ->and(PongMatch::query()->where('tournament_match_id', $played->tournament_match_id)->count())->toBe(1);
    Event::assertDispatchedTimes(PongMatchUpdated::class, 1);
    Event::assertDispatched(PongMatchUpdated::class, fn (PongMatchUpdated $event): bool => $event->ulid === $played->ulid);

    // Over already: nothing changes, nobody hears anything.
    expect(app(PongMatches::class)->void($played))->toBeFalse()
        ->and((int) $played->refresh()->state['version'])->toBe($version + 1)
        ->and(fn () => app(PongMatches::class)->void(PongMatch::factory()->create()))->toThrow(InvalidArgumentException::class);
    Event::assertDispatchedTimes(PongMatchUpdated::class, 1);
});

test('a correction that re-pairs a Proof of Pong match calls its live match off and starts one for the new pairing', function () {
    $tournament = pongTournament(4);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $control = app(TournamentControl::class);
    $round = fn (int $number) => TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->where('number', $number))->with('slots.participant')->orderBy('id')->get();
    [$semi1, $semi2] = $round(1)->all();
    $userOf = fn (TournamentMatch $match, int $slot): int => (int) $match->slots[$slot]->participant->user_id;

    // Both semi-finals decided by the league: their live matches are called off, so the final starts at once.
    $control->setResult($tournament, $admin, $semi1->id, ['result' => '1-0'], 'Reported by phone');
    $control->setResult($tournament, $admin, $semi2->id, ['result' => '1-0'], 'Reported by phone');
    $final = $round(2)->sole();
    $old = $final->pongMatch;

    expect(PongMatch::query()->whereIn('tournament_match_id', [$semi1->id, $semi2->id])->get()->every(fn (PongMatch $match): bool => $match->status === PongMatchStatus::Aborted))->toBeTrue()
        ->and($old?->status)->toBe(PongMatchStatus::Waiting)
        ->and([(int) $old->left_id, (int) $old->right_id])->toEqualCanonicalizing([$userOf($semi1, 0), $userOf($semi2, 0)]);

    // The other player won semi-final 1 after all: the final has a new side.
    $control->setResult($tournament, $admin, $semi1->id, ['result' => '0-1'], 'The other player won');
    $old->refresh();
    $final->refresh();
    $new = PongMatch::query()->where('tournament_match_id', $final->id)->whereKeyNot($old->id)->sole();

    expect($old->status)->toBe(PongMatchStatus::Aborted)
        ->and($old->rated)->toBeFalse()
        ->and($final->isReplaced($old->id))->toBeTrue()
        ->and($final->result)->toBeNull()
        ->and($new->status)->toBe(PongMatchStatus::Waiting)
        ->and([(int) $new->left_id, (int) $new->right_id])->toEqualCanonicalizing([$userOf($semi1, 1), $userOf($semi2, 0)]);
});

test('a correction that sets aside a played Proof of Pong final starts a new match once its round is restarted', function () {
    $tournament = pongTournament(4);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $control = app(TournamentControl::class);
    $round = fn (int $number) => TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->where('number', $number))->with('slots.participant')->orderBy('id')->get();
    [$semi1, $semi2] = $round(1)->all();
    $userOf = fn (TournamentMatch $match, int $slot): int => (int) $match->slots[$slot]->participant->user_id;

    $control->setResult($tournament, $admin, $semi1->id, ['result' => '1-0'], 'Reported by phone');
    $control->setResult($tournament, $admin, $semi2->id, ['result' => '1-0'], 'Reported by phone');
    $final = $round(2)->sole();
    $played = $final->pongMatch;
    $matches = app(PongMatches::class);
    $matches->sync($played, $played->left);
    $matches->sync($played, $played->right);
    PongLive::play($played->refresh(), [4, 1]);

    expect($played->refresh()->status)->toBe(PongMatchStatus::Finished)
        ->and($final->refresh()->result)->not->toBeNull();

    // The other player won semi-final 1 after all: the played final is set aside and a new one starts.
    $control->setResult($tournament, $admin, $semi1->id, ['result' => '0-1'], 'The other player won');
    $final->refresh();

    expect($final->result)->toBeNull()
        ->and($final->held)->not->toBeNull()
        ->and($final->isReplaced($played->id))->toBeTrue();

    // The organizer restarts the final's round: the new pairing gets a match of its own.
    $control->restartRound($tournament, $admin, $final->tournament_round_id, $final->round->restarts, 'Replay the final');
    $new = PongMatch::query()->where('tournament_match_id', $final->id)->whereKeyNot($played->id)->sole();

    expect($played->refresh()->status)->toBe(PongMatchStatus::Finished)
        ->and($new->status)->toBe(PongMatchStatus::Waiting)
        ->and([(int) $new->left_id, (int) $new->right_id])->toEqualCanonicalizing([$userOf($semi1, 1), $userOf($semi2, 0)]);
});

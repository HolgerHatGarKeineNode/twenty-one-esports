<?php

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Lineup;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentResultEntry;
use App\Models\User;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Tournament director mode (P8b, TOURNAMENT-FORMATS.md, section 6)
|--------------------------------------------------------------------------
|
| Directors (the creator and the named ones) enter and correct results of
| the open round, every entry is logged, the public views mark them, the
| players report nothing, and closing a round locks it and rates it.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** A running director-mode RL 3v3 tournament of two lineups (one final). */
function directedSeries(): array
{
    $tournament = Tournament::factory()->rocketLeague(TournamentFormat::SingleElimination)->create([
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Director, 'status' => TournamentStatus::Running, 'slug' => 'rl-desk',
    ]);
    $lineups = [];

    foreach ([1, 2] as $index) {
        $lineup = Lineup::factory()->mode('3v3')->ready()->create();
        $lineups[] = $lineup;
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'lineup_id' => $lineup->id, 'name' => $lineup->clan->name, 'rating' => 1100 - $index, 'members' => $lineup->seats->pluck('user_id')->all()]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), $lineups];
}

test('in a director tournament the players report, score and accept nothing; the room says so', function () {
    [, [$lineup]] = directedSeries();
    $series = SeriesMatch::query()->sole();
    $captain = $lineup->clan->owner;
    $service = app(SeriesService::class);

    expect($series->status)->toBe(SeriesStatus::Accepted)
        ->and(fn () => $service->saveLiveGame($series, $captain, 0, 3, 1, null))->toThrow(SeriesRuleViolation::class, 'tournament directors')
        ->and(fn () => $service->prepareReport($series, $captain))->toThrow(SeriesRuleViolation::class, 'tournament directors')
        ->and(fn () => $service->reportNoShow($series, $captain))->toThrow(SeriesRuleViolation::class, 'tournament directors');

    $this->actingAs($captain)->get(route('matches.room', $series))->assertOk()
        ->assertSee('Results are entered by the tournament directors.')
        ->assertDontSee('data-test="open-submit"', false);
});

test('a director enters a series by games, it is logged, and closing the round decides and rates it', function () {
    [$tournament, [$a, $b]] = directedSeries();
    $runner = app(TournamentRunner::class);
    $match = TournamentMatch::query()->sole();

    expect(fn () => $runner->enterResult($match, $tournament->creator, ['games' => [[3, 1], [0, 2]]]))->toThrow(TournamentRuleViolation::class, 'not finished');

    $runner->enterResult($match, $tournament->creator, ['games' => [[3, 1], [0, 2], [2, 1], [1, 4], [5, 0]]]);
    $runner->closeRound(TournamentRunner::currentRound($tournament), $tournament->creator);

    $series = SeriesMatch::query()->sole();

    expect($series->status)->toBe(SeriesStatus::Resolved)
        ->and($series->resolution)->toBe(SeriesResolution::Admin)
        ->and($series->winner)->toBe('challenger')
        ->and(count($series->result_games))->toBe(5)
        ->and(RatingChange::query()->count())->toBe(2)
        ->and(TournamentResultEntry::query()->count())->toBe(1)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a named director enters, the creator corrects, both are logged and marked, and a closed round is locked', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $director = User::factory()->create();
    $tournament->directors()->attach($director->id);
    $runner = app(TournamentRunner::class);
    $round = TournamentRunner::currentRound($tournament);
    [$first, $second] = TournamentMatch::query()->where('tournament_round_id', $round->id)->with('slots')->orderBy('position')->get()->all();

    $runner->enterResult($first, $director, ['result' => '1-0']);
    $runner->enterResult($first, $tournament->creator, ['result' => '0-1']);
    $runner->enterResult($second, $director, ['result' => 'noshow-0']);

    $entries = TournamentResultEntry::query()->orderBy('id')->get();
    $first->refresh();

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->user_id)->toBe($director->id)
        ->and($entries[0]->previous)->toBeNull()
        ->and($entries[1]->previous['label'])->toBe('1–0')
        ->and($entries[1]->result['label'])->toBe('0–1')
        ->and($first->result['name'])->toBe($director->displayName())
        ->and($first->result['corrected']['name'])->toBe($tournament->creator->displayName())
        ->and(fn () => $entries[0]->delete())->toThrow(LogicException::class)
        ->and(fn () => $runner->enterResult($first, $director, ['result' => '1/2-1/2']))->toThrow(TournamentRuleViolation::class, 'knockout');

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('Entered by the tournament director, corrected')
        ->assertSee('Corrected by '.$tournament->creator->displayName())
        ->assertSee('Not confirmed by the players.');

    $runner->closeRound($round, $director);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->orderByDesc('id')->with('slots')->first();

    // The corrected result moved the bracket: the loser of the first entry plays the final.
    expect($final->slots->pluck('tournament_participant_id')->all())->toContain($first->slots[1]->tournament_participant_id)
        ->and(fn () => $runner->enterResult($first->refresh(), $director, ['result' => '1-0']))->toThrow(TournamentRuleViolation::class, 'closed');
});

test('a director who plays may not enter their own match', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 4);
    $match = TournamentMatch::query()->where('status', 'ready')->with('slots.participant')->first();
    $player = User::query()->find($match->slots[0]->participant->user_id);
    $tournament->directors()->attach($player->id);

    expect(fn () => app(TournamentRunner::class)->enterResult($match, $player, ['result' => '1-0']))
        ->toThrow(TournamentRuleViolation::class, 'another director');
});

test('who may do what: guest, member, organizer, admin and named director, also by direct Livewire calls', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $creator = $tournament->creator;
    TournamentOrganizer::query()->create(['pubkey' => $creator->pubkey]);
    $director = User::factory()->create();
    $tournament->directors()->attach($director->id);
    $member = User::factory()->create();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $match = TournamentMatch::query()->where('status', 'ready')->first();

    $this->get(route('tournaments.director', $tournament))->assertRedirect(route('login'));
    $this->get(route('tournaments.show', $tournament))->assertOk();
    $this->actingAs($member)->get(route('tournaments.director', $tournament))->assertForbidden();
    $this->actingAs($admin)->get(route('tournaments.director', $tournament))->assertForbidden();
    $this->actingAs($director)->get(route('tournaments.director', $tournament))->assertOk()->assertSee('Director desk');
    $this->actingAs($creator)->get(route('tournaments.director', $tournament))->assertOk();

    // A member who got hold of the desk component: every action checks again.
    $desk = Livewire::actingAs($director)->test('pages::tournaments.director', ['tournament' => $tournament]);
    auth()->login($member);
    $desk->call('enter', $match->id, ['result' => '1-0'])->assertSet('error', 'Only the tournament directors can enter results.');
    $desk->call('addDirector')->assertForbidden();

    expect($match->refresh()->result)->toBeNull();

    // A named director enters, but only the organizer or an admin changes the directors.
    Livewire::actingAs($director)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->call('enter', $match->id, ['result' => '1-0'])->assertSet('error', '')
        ->set('directorKey', $member->npub)->call('addDirector')->assertForbidden();
    Livewire::actingAs($admin)->test('pages::tournaments.director', ['tournament' => $tournament])->assertForbidden();

    expect($match->refresh()->result['by'])->toBe('director');

    Livewire::actingAs($creator)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->set('directorKey', $member->npub)->call('addDirector')->assertHasNoErrors();

    expect($tournament->refresh()->isDirectedBy($member))->toBeTrue();

    // Publishing a draft: its organizer or an admin, nobody else (direct call included).
    $draft = Tournament::factory()->create(['created_by_id' => $creator->id]);
    Livewire::actingAs($admin)->test('pages::tournaments.show', ['tournament' => $draft])->assertOk();
    Livewire::actingAs($creator)->test('pages::tournaments.show', ['tournament' => $draft])->assertSee('Publish tournament');
    $published = Tournament::factory()->signup()->create(['created_by_id' => $creator->id]);
    Livewire::actingAs($member)->test('pages::tournaments.show', ['tournament' => $published])->call('publish')->assertForbidden();
    $this->actingAs($member)->get(route('admin.tournaments.create'))->assertForbidden();
});

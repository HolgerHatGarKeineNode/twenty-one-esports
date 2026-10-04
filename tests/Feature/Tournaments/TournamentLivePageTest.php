<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| The live control page (user, 2026-10-04): control, desk chat and bracket for the tournament direction, reachable
| from the tournament page's manage bar; nobody else gets in.
*/

beforeEach(function () {
    Queue::fake();
    Event::fake([TournamentChanged::class]);
});

function livePageTournament(): Tournament
{
    $profile = GameProfile::for('rocket-league', '1v1');
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], $profile)->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'live-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

test('the direction opens the live page with control, chat and bracket; a player is refused', function () {
    $tournament = livePageTournament();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('tournaments.live', $tournament))->assertOk()
        ->assertSee('data-test="tournament-live"', false)->assertSee('data-test="live-bracket"', false)
        ->assertSee('data-test="desk-chat"', false)->assertSee('Tournament control')
        ->assertSee('data-test="live-overview"', false)->assertSee('Not checked in = no-show')->assertSee('Result due')->assertSee('not checked in yet');

    $this->actingAs($tournament->creator)->get(route('tournaments.live', $tournament))->assertOk();
    $this->actingAs($tournament->creator)->get(route('tournaments.show', $tournament))->assertSee(route('tournaments.live', $tournament), false);

    $this->actingAs(User::query()->where('name', 'Player 1')->firstOrFail())->get(route('tournaments.live', $tournament))->assertForbidden();
});

test('a player checks in to the lobby; one side in and the other not after 30 minutes is a no-show the league reports', function () {
    $tournament = livePageTournament();
    $series = SeriesMatch::query()->whereNotNull('tournament_match_id')->orderBy('id')->firstOrFail();
    $in = User::query()->findOrFail($series->sides['challenger'][0]);
    $service = app(SeriesService::class);

    $this->actingAs($in)->get(route('matches.room', $series))->assertOk()->assertSee('data-test="checkin-lobby"', false);

    $service->checkInLobby($series, $in);
    expect($series->refresh()->ready_at_challenger)->not->toBeNull()
        ->and($series->ready_at_challenged)->toBeNull();

    $this->travel(29)->minutes();
    expect($service->autoNoShow($series->refresh()))->toBeFalse();

    $this->travel(2)->minutes();
    app(TournamentScheduler::class)->tick();
    expect($series->refresh()->noshow_side)->toBe('challenger')
        ->and($series->noshow_reported_at)->not->toBeNull();

    // Both in, or nobody in: no automatic no-show.
    $both = SeriesMatch::query()->whereNotNull('tournament_match_id')->whereKeyNot($series->id)->firstOrFail();
    $both->forceFill(['ready_at_challenger' => now(), 'ready_at_challenged' => now()])->save();
    expect($service->autoNoShow($both))->toBeFalse();
});

test('two entries of one match disqualified together: the match is a double no-show, nobody of it wins by forfeit', function () {
    $tournament = livePageTournament();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('round', fn ($q) => $q->where('number', 1))
        ->with('slots')->orderBy('position')->firstOrFail();
    $ids = $match->slots->pluck('tournament_participant_id')->all();

    expect(app(TournamentControl::class)->disqualifyMany($tournament, $admin, $ids, 'Both did not show'))->toBe(2);

    $match->refresh();
    // A knockout allows no draw: the higher seed is carried on without a game (seedDecision), and is out there too.
    expect($match->result['decided'] ?? null)->toBe('noshow')
        ->and(TournamentParticipant::query()->whereKey($ids)->whereNotNull('disqualified_at')->count())->toBe(2);
});

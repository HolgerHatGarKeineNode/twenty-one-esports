<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Events\TournamentChanged;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
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
        ->assertSee('data-test="desk-chat"', false)->assertSee('Tournament control');

    $this->actingAs($tournament->creator)->get(route('tournaments.live', $tournament))->assertOk();
    $this->actingAs($tournament->creator)->get(route('tournaments.show', $tournament))->assertSee(route('tournaments.live', $tournament), false);

    $this->actingAs(User::query()->where('name', 'Player 1')->firstOrFail())->get(route('tournaments.live', $tournament))->assertForbidden();
});

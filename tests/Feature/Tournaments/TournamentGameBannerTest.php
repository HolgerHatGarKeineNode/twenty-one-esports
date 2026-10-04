<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| A tournament game says so at the top of its page (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Dann muss aber hier auch groß fett rein, oben, dass es sich um ein
| Turnierspiel handelt": the chess game page, the board game page and the
| series room open with the tournament banner (trophy, tournament, round,
| the game of a duel, what is at stake, the tournament page), live and
| after the end. The first-move deadline reads as the cup rule it is, and a
| casual rating is not shown as what the game is about.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** @return list<ChessGame> */
function bannerChessGames(Tournament $tournament): array
{
    return ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->with(['white', 'black'])->orderBy('id')->get()->all();
}

test('a live tournament chess game opens with the banner above the game, the cup first-move rule and no casual rating', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$game] = bannerChessGames($tournament);

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-banner"', false)
        ->assertSeeInOrder(['data-test="tournament-banner"', $tournament->name, 'Round 1', 'Tournament game — counts for the tournament', 'Tournament page', 'data-test="chess-game"'], false)
        ->assertSee(route('tournaments.show', $tournament), false)
        ->assertSee('Make your first move within :time or you lose this cup game')
        ->assertSee('data-test="tournament-chip"', false)
        ->assertDontSee('data-test="rating"', false);

    // A spectator sees the banner too.
    $this->actingAs(User::factory()->create())->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-banner"', false);
});

test('a finished tournament chess game keeps the banner above its result', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$game] = bannerChessGames($tournament);
    app(ChessGameService::class)->resign($game, $game->black);

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-banner"', $tournament->name, 'Round 1', 'data-test="chess-game-done"', 'data-test="tournament-panel"'], false);
});

test('a chess duel names the game of the duel in the banner', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 2, TournamentResultsMode::Players, ['iterations' => 3, 'rankBy' => 'points']);
    [$first] = bannerChessGames($tournament);

    expect(TournamentGameEnd::banner($first))->toMatchArray(['step' => 'Game 1 of 3', 'round' => 'Round 1', 'url' => route('tournaments.show', $tournament)]);

    $this->actingAs($first->white)->get(route('games.show', $first))->assertOk()
        ->assertSee('data-test="tournament-banner-step"', false)
        ->assertSee('Game 1 of 3');
});

test('a casual chess game has no banner and keeps its rating and plain first-move line', function () {
    $game = ChessGame::factory()->create();

    expect(TournamentGameEnd::banner($game))->toBeNull();

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="tournament-banner"', false)
        ->assertDontSee('data-test="tournament-chip"', false)
        ->assertDontSee('or you lose this cup game')
        ->assertSee('data-test="rating"', false);
});

test('a tournament board game and a tournament series room open with the banner', function () {
    NineMensMorrisOn::play();
    $board = Tournament::factory()->create([
        'game' => NineMensMorris::SLUG, 'mode' => 'blitz', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(NineMensMorris::SLUG, 'blitz'))->toArray(),
        'capacity' => 4, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-banner-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach (range(1, 4) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $board->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($board, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($board);
    $game = BoardGame::query()->orderBy('id')->firstOrFail();

    $this->actingAs($game->white)->get(route('board.show', $game))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-banner"', $board->name, 'Round 1', 'data-test="board-game"'], false)
        ->assertSee('Make your first move within :time or you lose this cup game');

    $series = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'series-banner-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $series->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($series, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($series);
    $match = SeriesMatch::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $series->id)->select('id'))->orderBy('id')->firstOrFail();

    $this->actingAs(User::query()->findOrFail($match->rosterSide('challenger')[0]))->get(route('matches.room', $match))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-banner"', $series->name, 'Round 1', 'Tournament page', 'data-test="room-status"'], false);
});

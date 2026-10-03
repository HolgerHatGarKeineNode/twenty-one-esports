<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
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
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| The end of a tournament game (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Nach einem Turnier-Match braucht es kein ‚Nächste Spieler suchen‘ …,
| sondern einfache Hinweise für den Spieler, wie es weitergeht": the chess
| game page, the board game page and the series room show the tournament
| panel (result, where the match stands, what comes next, the others still
| playing, "Back to the tournament", the countdown) and no rematch, next
| opponent or new game. A casual game keeps its follow-ups.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** The first-round chess games of a players-mode tournament, in bracket order. */
function endChessGames(Tournament $tournament): array
{
    return ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->get()->all();
}

test('a finished tournament chess game shows the tournament panel and no rematch or new search', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$game] = endChessGames($tournament);
    [$white, $black] = [$game->white, $game->black];

    app(ChessGameService::class)->resign($game, $black);

    $this->actingAs($white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertSee('data-state="waiting"', false)
        ->assertSeeInOrder([$tournament->name, 'Round 1', 'Your match is done: wait for the next round', 'You won this game', 'Your next match starts as soon as your opponent is known.', '1 other match is still being played.', 'Back to the tournament'])
        ->assertSee(route('tournaments.show', $tournament), false)
        ->assertSee('data-test="tournament-countdown"', false)
        ->assertSee("The tournament page opens in 10\u{00A0}s.")
        ->assertDontSee('data-test="challenge-again"', false)
        ->assertDontSee('data-test="done-find-next"', false)
        ->assertDontSee('data-test="play-again"', false)
        ->assertDontSeeText('Find next opponent')
        ->assertDontSeeText('Rematch');

    // The loser of a knockout is out.
    $this->actingAs($black)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-state="out"', false)
        ->assertSeeInOrder(['You are out', 'You lost this game', 'Thanks for playing! Follow the rest of the tournament on the tournament page.'])
        ->assertDontSeeText('Rematch');

    // A spectator sees the panel without the countdown; nobody is moved off the page.
    $this->actingAs(User::factory()->create())->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertSee('The match is over')
        ->assertDontSee('data-test="tournament-countdown"', false);

    // Opened again later, the game no longer counts down.
    $this->travel(TournamentGameEnd::RECENT_MINUTES + 1)->minutes();
    $this->actingAs($white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertDontSee('data-test="tournament-countdown"', false);

    expect(TournamentGameEnd::redirect($game, $white))->toBe(route('tournaments.show', $tournament));
});

test('the live board of a tournament chess game renders the panel once the game is over and offers no rematch', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    [$game] = endChessGames($tournament);
    [$white, $black] = [$game->white, $game->black];

    $page = Livewire::actingAs($white)->test('pages::games.show', ['game' => $game]);
    $page->assertSeeHtml('data-test="tournament-panel-slot"')
        ->assertDontSeeHtml('data-test="rematch"')
        ->assertDontSeeHtml('data-test="find-next"')
        ->assertDontSeeHtml('data-test="search-again"');

    app(ChessGameService::class)->resign($game, $black);

    $html = $page->instance()->tournamentPanel();

    expect($html)->toContain('data-state="won"')
        ->and($html)->toContain('You won the tournament')
        ->and($html)->toContain('You won this game')
        ->and($html)->toContain('data-test="tournament-countdown"')
        ->and($page->instance()->tournamentNext())->toBe(route('tournaments.show', $tournament))
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a chess duel shows game 1 of 3 with the score, and the countdown goes straight to game 2 once it exists', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 2, TournamentResultsMode::Players, ['iterations' => 3, 'rankBy' => 'points']);
    [$first] = endChessGames($tournament);
    [$white, $black] = [$first->white, $first->black];

    app(ChessGameService::class)->resign($first, $black);
    $second = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->whereKeyNot($first->id)->where('status', ChessGameStatus::Active)->sole();
    $panel = TournamentGameEnd::of($first->refresh(), $white);

    expect($panel['state'])->toBe('continues')
        ->and($panel['step'])->toBe('Game 1 of 3')
        ->and($panel['score'])->toBe('Score 1 : 0')
        ->and($panel['line'])->toBe('Game 2 follows.')
        ->and($panel['next'])->toBeTrue()
        ->and(TournamentGameEnd::redirect($first, $white))->toBe(route('games.show', $second))
        ->and(TournamentGameEnd::redirect($first, $black))->toBe(route('games.show', $second))
        ->and(TournamentGameEnd::of($first, $black)['score'])->toBe('Score 0 : 1');

    $this->actingAs($black)->get(route('games.show', $first))->assertOk()
        ->assertSeeInOrder(['Game 1 of 3', 'Score 0 : 1', 'Your match goes on', 'You lost this game', 'Game 2 follows.'])
        ->assertSee("Your next game opens in 10\u{00A0}s.")
        ->assertDontSeeText('Rematch');
});

test('a finished tournament board game shows the tournament panel and no next opponent', function () {
    NineMensMorrisOn::play();
    $tournament = Tournament::factory()->create([
        'game' => NineMensMorris::SLUG, 'mode' => 'blitz', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(NineMensMorris::SLUG, 'blitz'))->toArray(),
        'capacity' => 4, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-end-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach (range(1, 4) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $game = BoardGame::query()->orderBy('id')->firstOrFail();
    app(BoardGameService::class)->resign($game, $game->black);

    $this->actingAs($game->white)->get(route('board.show', $game))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertSeeInOrder(['Your match is done: wait for the next round', 'You won this game', 'Back to the tournament'])
        ->assertSee('data-test="tournament-countdown"', false)
        ->assertDontSee('data-test="next-opponent"', false)
        ->assertDontSeeText('Find next opponent');

    $this->actingAs($game->black)->get(route('board.show', $game))->assertOk()
        ->assertSee('data-state="out"', false)
        ->assertSee('You are out');
});

test('a confirmed tournament series shows the tournament panel in the room and no rematch or another series', function () {
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'series-end-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create(['name' => "Player {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $tournament->id)->select('id'))->orderBy('id')->firstOrFail();
    [$winner, $loser] = [User::query()->findOrFail($series->rosterSide('challenger')[0]), User::query()->findOrFail($series->rosterSide('challenged')[0])];

    $service = app(SeriesService::class);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series->refresh(), $winner, $index, 3, 1, null);
    }

    $service->report($series->refresh(), $winner, []);
    $service->respond($series->refresh(), $loser, 'confirmed', '', []);

    expect($series->refresh()->status)->toBe(SeriesStatus::Confirmed);

    $this->actingAs($winner)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertSeeInOrder(['Your match is done: wait for the next round', 'You won the series', 'Back to the tournament'])
        ->assertSee('data-test="tournament-countdown"', false)
        ->assertDontSee('data-test="casual-rematch-card"', false)
        ->assertDontSee('data-test="series-again"', false);

    $this->actingAs($loser)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-state="out"', false)
        ->assertSee('You lost the series')
        ->assertDontSee('data-test="casual-rematch-card"', false);

    expect(Livewire::actingAs($winner)->test('pages::matches.room', ['match' => $series])->instance()->tournamentNext())
        ->toBe(route('tournaments.show', $tournament));
});

test('a casual chess game keeps its rematch and next search and shows no tournament panel', function () {
    $game = ChessGame::factory()->finished()->create();
    $white = $game->white;

    expect(TournamentGameEnd::of($game, $white))->toBeNull()
        ->and(TournamentGameEnd::redirect($game, $white))->toBeNull();

    $this->actingAs($white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="play-again"', false)
        ->assertSee('data-test="done-find-next"', false)
        ->assertSee('Find next opponent')
        ->assertDontSee('data-test="tournament-panel"', false);

    $live = ChessGame::factory()->create();
    Livewire::actingAs($live->white)->test('pages::games.show', ['game' => $live])
        ->assertSeeHtml('data-test="rematch"')->assertSeeHtml('data-test="find-next"')->assertSeeHtml('data-test="search-again"')
        ->assertDontSeeHtml('data-test="tournament-panel-slot"');
});

<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Series\CasualInvites;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The end of a tournament game (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Nach einem Turnier-Match braucht es kein ‚Nächste Spieler suchen‘ …,
| sondern einfache Hinweise für den Spieler, wie es weitergeht": the chess
| game page, the board game page and the series room show the tournament
| panel (result, where the match stands, what comes next, the others still
| playing, "Back to the tournament") and no rematch, next opponent or new
| game. A casual game keeps its follow-ups. Nobody is moved off the page on
| their own ("Da ist ein Auto-Redirect irgendwie drin oder? Das bitte
| ausmachen"): no countdown, no redirect.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** The first-round chess games of a players-mode tournament, in bracket order. */
function endChessGames(Tournament $tournament): array
{
    return ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->with(['white', 'black'])->orderBy('id')->get()->all();
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
        ->assertDontSee('data-test="tournament-countdown"', false)
        ->assertDontSee('data-test="tournament-stay"', false)
        ->assertDontSee('tournamentGameEnd(', false)
        ->assertDontSeeText('The tournament page opens in')
        ->assertDontSeeText('Stay here')
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

    // A spectator sees the panel too.
    $this->actingAs(User::factory()->create())->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-panel"', false)
        ->assertSee('The match is over')
        ->assertDontSee('data-test="tournament-countdown"', false);

    // No redirect anywhere: the panel carries no countdown data, and nothing answers where to go.
    expect(TournamentGameEnd::of($game, $white))->not->toHaveKeys(['countdown', 'seconds', 'next'])
        ->and(method_exists(TournamentGameEnd::class, 'redirect'))->toBeFalse();
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
        ->and($html)->not->toContain('data-test="tournament-countdown"')
        ->and($html)->not->toContain('tournamentGameEnd(')
        ->and(method_exists($page->instance(), 'tournamentNext'))->toBeFalse()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a chess duel shows game 1 of 3 with the score and that game 2 follows, and stays on game 1', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 2, TournamentResultsMode::Players, ['iterations' => 3, 'rankBy' => 'points']);
    [$first] = endChessGames($tournament);
    [$white, $black] = [$first->white, $first->black];

    app(ChessGameService::class)->resign($first, $black);
    $panel = TournamentGameEnd::of($first->refresh(), $white);

    expect($panel['state'])->toBe('continues')
        ->and($panel['step'])->toBe('Game 1 of 3')
        ->and($panel['score'])->toBe('Score 1 : 0')
        ->and($panel['line'])->toBe('Game 2 follows.')
        ->and($panel)->not->toHaveKey('next')
        ->and(TournamentGameEnd::of($first, $black)['score'])->toBe('Score 0 : 1');

    $this->actingAs($black)->get(route('games.show', $first))->assertOk()
        ->assertSeeInOrder(['Game 1 of 3', 'Score 0 : 1', 'Your match goes on', 'You lost this game', 'Game 2 follows.'])
        ->assertDontSeeText('Your next game opens in')
        ->assertDontSee('data-test="tournament-countdown"', false)
        ->assertDontSeeText('Rematch');
});

test('a player whose last Swiss or round-robin match is done is told the matches are done, not to wait for a next round', function () {
    // Swiss, one round planned: round 1 is the last, and the other match is still playing.
    $swiss = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players, ['swissRounds' => 1]);
    [$game] = endChessGames($swiss);
    app(ChessGameService::class)->resign($game, $game->black);

    expect($swiss->refresh()->status)->toBe(TournamentStatus::Running);
    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-state="done"', false)
        ->assertSeeInOrder(['Your matches are done — waiting for the others', 'You won this game', 'The final standings come when the last games end.', '1 other match is still being played.', 'Back to the tournament'])
        ->assertDontSeeText('wait for the next round')
        ->assertDontSeeText('The next round starts');

    // Round robin of three, results by the directors: after two rounds the entry with the round-3 bye has played both its games.
    $table = runningChess(TournamentFormat::RoundRobin, 3);
    $runner = app(TournamentRunner::class);

    foreach (range(1, 2) as $number) {
        $round = TournamentRunner::currentRound($table->refresh());

        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->get() as $match) {
            $runner->enterResult($match, $table->creator, ['result' => '1-0']);
        }

        $runner->closeRound($round, $table->creator);
    }

    $open = TournamentMatch::query()->where('tournament_id', $table->id)->whereNull('result')->where('bracket', '!=', 'bye')->with('slots.participant')->sole();
    $player = TournamentParticipant::query()->where('tournament_id', $table->id)->whereNotIn('id', $open->slots->pluck('tournament_participant_id'))->sole()->user;
    $last = ChessGame::query()->whereIn('tournament_match_id', $table->matches()->select('id'))
        ->where(fn ($query) => $query->where('white_id', $player->id)->orWhere('black_id', $player->id))->orderByDesc('id')->firstOrFail();

    expect($table->refresh()->status)->toBe(TournamentStatus::Running);
    $this->actingAs($player)->get(route('games.show', $last))->assertOk()
        ->assertSee('data-state="done"', false)
        ->assertSee('Your matches are done — waiting for the others')
        ->assertDontSeeText('wait for the next round');

    // A two-stage group's last round is not the end: the knockout stage follows.
    $groups = runningChess(TournamentFormat::TwoStage, 8);

    while (($round = TournamentRunner::currentRound($groups->refresh())->load('stage'))->number < $round->stage->rounds()->count()) {
        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->get() as $match) {
            $runner->enterResult($match, $groups->creator, ['result' => '1-0']);
        }

        $runner->closeRound($round, $groups->creator);
    }

    $match = TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with(['round.stage', 'slots.participant'])->firstOrFail();
    $runner->enterResult($match, $groups->creator, ['result' => '1-0']);

    expect(TournamentGameEnd::after($groups->refresh(), $match->refresh()->load(['round.stage', 'slots.participant']), 0))
        ->toBe(['waiting', 'Your match is done: wait for the next round', 'The next round starts once the open matches are decided.']);
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
        ->assertDontSee('data-test="tournament-countdown"', false)
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
        ->assertDontSee('data-test="tournament-countdown"', false)
        ->assertDontSee('data-test="casual-rematch-card"', false)
        ->assertDontSee('data-test="series-again"', false);

    $this->actingAs($loser)->get(route('matches.room', $series))->assertOk()
        ->assertSee('data-state="out"', false)
        ->assertSee('You lost the series')
        ->assertDontSee('data-test="casual-rematch-card"', false);

    expect(method_exists(Livewire::actingAs($winner)->test('pages::matches.room', ['match' => $series])->instance(), 'tournamentNext'))->toBeFalse();
});

test('the server refuses a rematch of a tournament chess game and of a cup series with the reason, not only the buttons are gone', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['rocket-league']]);
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$game] = endChessGames($tournament);
    [$white, $black] = [$game->white, $game->black];
    app(ChessGameService::class)->resign($game, $black);
    $board = Livewire::actingAs($white)->test('pages::games.show', ['game' => $game->refresh()]);

    expect($board->call('offerRematch')->effects['returns'][0])->toMatchArray(['ok' => false, 'error' => 'tournament_rematch'])
        ->and($game->refresh()->rematch_offer)->toBeNull();
    expect($board->instance()->labels()['errors']['tournament_rematch'] ?? null)->toBe('A tournament game has no rematch: the tournament decides your next game.');

    // An offer stored before the rule is not accepted either.
    $game->forceFill(['rematch_offer' => 'w'])->save();
    expect(Livewire::actingAs($black)->test('pages::games.show', ['game' => $game->refresh()])->call('acceptRematch')->effects['returns'][0])
        ->toMatchArray(['ok' => false, 'error' => 'tournament_rematch'])
        ->and(ChessGame::query()->where('rematch_of_id', $game->id)->exists())->toBeFalse();

    // A cup series is a casual pairing (origin "cup"), so the casual rematch would have taken it.
    $cup = runningCup(2, TournamentFormat::SingleElimination, ['bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    cupTick();
    $series = SeriesMatch::query()->where('origin', SeriesMatch::ORIGIN_CUP)->whereIn('tournament_match_id', $cup->matches()->select('id'))->sole();
    $host = User::query()->findOrFail($series->rosterSide('challenger')[0]);
    $series->forceFill(['status' => SeriesStatus::Confirmed, 'finished_at' => now()])->save();
    app(TournamentRunner::class)->store(TournamentMatch::query()->findOrFail($series->tournament_match_id), ['winner' => 0, 'games_won' => [2.0, 0.0], 'points' => [], 'forfeit' => false, 'label' => '2-0', 'by' => 'players']);
    app(TournamentRunner::class)->sync($cup->refresh());

    // The cup of two is over, so no cup match locks the pair any more: only the tournament origin stands in the way.
    expect($series->refresh()->status)->toBe(SeriesStatus::Confirmed)
        ->and($cup->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(casualRefusal(fn () => app(CasualInvites::class)->rematch($series->refresh(), $host)))->toBe('tournament_rematch')
        ->and(SeriesInvite::query()->count())->toBe(0);
});

test('a casual chess game keeps its rematch and next search and shows no tournament panel', function () {
    $game = ChessGame::factory()->finished()->create();
    $white = $game->white;

    expect(TournamentGameEnd::of($game, $white))->toBeNull();

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

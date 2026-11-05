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
        ->assertDontSee('data-test="rating"', false)
        // On a phone the shell header carries it too, always at the top (2026-10-15).
        ->assertSeeInOrder(['data-test="shell-tournament"', $tournament->name, 'Round 1', 'data-test="tournament-banner"'], false)
        // A tap on the chip opens the whole banner over the page, the tournament page in it (DerCaddy, 2026-10-15).
        ->assertSeeInOrder(['aria-haspopup="dialog" aria-controls="tournament-banner-sheet"', 'data-test="shell-tournament"', 'data-test="tournament-banner"', 'id="tournament-banner-sheet"', $tournament->name, 'Round 1', 'Tournament game — counts for the tournament', 'data-test="banner-sheet-link"'], false);

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

    expect(TournamentGameEnd::banner($first))->toMatchArray(['step' => 'Game 1 of 3', 'round' => 'Round 1', 'url' => route('tournaments.show', $tournament), 'games' => []]);

    $this->actingAs($first->white)->get(route('games.show', $first))->assertOk()
        ->assertSee('data-test="tournament-banner-step"', false)
        ->assertSee('Game 1 of 3');
});

test('a casual chess game has no banner and keeps its rating and plain first-move line', function () {
    $game = ChessGame::factory()->create();

    expect(TournamentGameEnd::banner($game))->toBeNull();

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="tournament-banner"', false)
        ->assertDontSee('data-test="shell-tournament"', false)
        ->assertDontSee('data-test="tournament-chip"', false)
        ->assertDontSee('or you lose this cup game')
        ->assertSee('data-test="rating"', false);
});

test('a tournament board game and a tournament series room open with the banner', function () {
    NineMensMorrisOn::play();
    $board = Tournament::factory()->create([
        'game' => NineMensMorris::SLUG, 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(NineMensMorris::SLUG, 'correspondence'))->toArray(),
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
        ->assertSeeInOrder(['data-test="tournament-banner"', $series->name, 'Round 1', 'Tournament page', 'data-test="room-status"'], false)
        ->assertSee('data-test="shell-tournament"', false);
});

/** The cup badge of the header as rendered: its link and its classes, or null. */
function bannerCupBadge(string $html): ?array
{
    return preg_match('/<a href="([^"]*)"[^>]*?class="([^"]*)"[^>]*?data-test="cup-badge"/s', $html, $found) === 1
        ? ['href' => html_entity_decode($found[1]), 'class' => $found[2]] : null;
}

test('on a phone the cup badge gives way to the chip when it leads to the game on the page, not when it leads elsewhere', function () {
    // DerCaddy, 2026-11-04: scrolled down, the chip and the badge stood as two trophies side by side, and the chip's round lay over the search.
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    [$first, $second] = bannerChessGames($tournament);

    $own = bannerCupBadge($this->actingAs($first->white)->get(route('games.show', $first))->assertOk()->assertSee('data-test="shell-tournament"', false)->getContent());

    expect($own)->not->toBeNull()
        ->and($own['href'])->toBe(route('games.show', $first))
        ->and($own['class'])->toContain('max-lg:hidden');

    // Watching another game of the tournament, the own live game is elsewhere: the badge stays, it is the way back to the board.
    $elsewhere = bannerCupBadge($this->actingAs($second->white)->get(route('games.show', $first))->assertOk()->assertSee('data-test="shell-tournament"', false)->getContent());

    expect($elsewhere)->not->toBeNull()
        ->and($elsewhere['href'])->toBe(route('games.show', $second))
        ->and($elsewhere['class'])->not->toContain('max-lg:hidden');

    // Any other page keeps it.
    $home = bannerCupBadge($this->actingAs($first->white)->get(route('home'))->assertOk()->getContent());

    expect($home)->not->toBeNull()->and($home['class'])->not->toContain('max-lg:hidden');
});

test('on a game page of its own tournament the waiting badge stays in a phone header: it gives way only where it leads to the page itself', function () {
    // Review of PR #20 (2026-11-05): "your match" for the tournament page says the player is up, which the chip does not say.
    $tournament = runningChess(TournamentFormat::SingleElimination, 8, TournamentResultsMode::Players);
    [$first, , $third] = bannerChessGames($tournament);
    $waiting = $third->white;
    // No game for this match yet: the player waits, and the badge leads to the tournament page.
    $third->delete();

    $badge = bannerCupBadge($this->actingAs($waiting)->get(route('games.show', $first))->assertOk()->assertSee('data-test="shell-tournament"', false)->getContent());

    expect($badge)->not->toBeNull()
        ->and(parse_url($badge['href'], PHP_URL_PATH))->toBe(parse_url(route('tournaments.show', $tournament), PHP_URL_PATH))
        ->and($badge['class'])->not->toContain('max-lg:hidden');
});

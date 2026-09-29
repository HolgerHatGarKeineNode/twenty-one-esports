<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Rating\EloRating;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckersGame;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Elo of a corrected board game result (plan "Mühle und Dame", P5 review, P6)
|--------------------------------------------------------------------------
|
| A board game played in a rated tournament moves the rated Elo of its board
| game; an organizer or admin correcting its result on the tournament
| control reverts that Elo exactly once and rates the corrected outcome,
| delta only, as for a chess game (docs/nips/esports.md, "Elo of a corrected
| result"). A forfeit reverts and rates nothing. Casual Elo stays as it is.
| A tournament game never mines, so a correction has no block to take back.
|
*/

beforeEach(function () {
    Queue::fake();
    CheckersGame::play();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/**
 * A running players-mode checkers final of two players, its game started;
 * rated when a season is live (the open ladder frozen).
 *
 * @return array{0: Tournament, 1: TournamentMatch, 2: BoardGame}
 */
function boardCorrectionFinal(): array
{
    $tournament = Tournament::factory()->create([
        'game' => Checkers::SLUG, 'mode' => 'blitz', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(Checkers::SLUG, 'blitz'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-correction-'.fake()->unique()->numberBetween(1, 1_000_000),
        'ladder_address' => Ladders::address(Checkers::SLUG, 'blitz'),
    ]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('0f', 32));
    app(TournamentRunner::class)->sync($tournament);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->sole();

    return [$tournament->refresh(), $match, BoardGame::query()->where('tournament_match_id', $match->id)->sole()];
}

/** Both first moves, then Black resigns: White wins the match. */
function boardCorrectionWhiteWins(BoardGame $game): BoardGame
{
    $service = app(BoardGameService::class);
    $game = $service->move($game, $game->white, 'c3-d4', 1);
    $game = $service->move($game->refresh(), $game->black, 'f6-e5', 2);

    return $service->resign($game->refresh(), $game->black)->refresh();
}

function boardCorrectionAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function boardCorrectionRating(?User $user, string $pool = Rating::RATED): int
{
    return (int) Rating::query()->where(['pool' => $pool, 'game' => Checkers::SLUG, 'mode' => 'blitz', 'user_id' => $user?->id])->value('rating');
}

test('a corrected winner of a rated board game is re-rated delta only, exactly once, and the old rows stay as the audit trail', function () {
    publishLadders(openSeason());
    [$tournament, $match, $game] = boardCorrectionFinal();

    expect($game->rated)->toBeTrue();

    $game = boardCorrectionWhiteWins($game);
    [$white, $black] = [$game->white, $game->black];
    $whiteSlot = in_array($white->id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;
    // Black won after all (the players swapped the pieces): the slot that had Black.
    $blackWins = ['result' => $whiteSlot === 0 ? '0-1' : '1-0'];
    $old = RatingChange::query()->where('source', RatingChange::BOARD)->where('source_id', $game->id)->orderBy('id')->get();
    $corrected = EloRating::fromConfig('rating')->rate($old[0]->before, $old[1]->before, 0.0, $old[0]->results_before, $old[1]->results_before);
    $control = app(TournamentControl::class);

    expect([boardCorrectionRating($white), boardCorrectionRating($black)])->toBe([1020, 980])
        ->and($old)->toHaveCount(2)
        // The form states the effect before the save.
        ->and($control->eloPreview($tournament, $match->id, $blackWins))->not->toBeNull();

    $control->setResult($tournament, boardCorrectionAdmin(), $match->id, $blackWins, 'Black won; the players swapped the pieces');

    expect(boardCorrectionRating($white))->toBe(1000 + $corrected['challenger_delta'])
        ->and(boardCorrectionRating($black))->toBe(1000 + $corrected['challenged_delta'])
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->where('source', RatingChange::BOARD)->where('source_id', $game->id)->whereNotNull('reverted_at')->count())->toBe(2)
        ->and(RatingChange::query()->where('source', RatingChange::BOARD)->where('source_id', $game->id)->pluck('revision')->unique()->all())->toBe([$old[0]->revision + 1])
        // Chess is never touched, and a tournament game never mined anything to take back.
        ->and(Rating::query()->where('game', 'chess')->count())->toBe(0)
        ->and(SeasonAttestation::query()->whereNotNull('height')->count())->toBe(0);

    // The same outcome again moves nothing new.
    $after = [boardCorrectionRating($white), boardCorrectionRating($black)];
    expect(app(RatingService::class)->correct($game->refresh(), 0.0))->toBeNull()
        ->and([boardCorrectionRating($white), boardCorrectionRating($black)])->toBe($after);
});

test('a correction of a rated board game to a forfeit reverts its Elo and rates nothing', function () {
    publishLadders(openSeason());
    [$tournament, $match, $game] = boardCorrectionFinal();
    $game = boardCorrectionWhiteWins($game);
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;

    app(TournamentControl::class)->setResult($tournament, boardCorrectionAdmin(), $match->id, ['result' => 'noshow-'.$whiteSlot], 'White never showed up at the board');

    expect([boardCorrectionRating($game->white), boardCorrectionRating($game->black)])->toBe([1000, 1000])
        ->and(RatingChange::query()->where('source', RatingChange::BOARD)->count())->toBe(0);
});

test('a correction of a casual board game (no season) leaves its casual Elo as it is', function () {
    [$tournament, $match, $game] = boardCorrectionFinal();
    $game = boardCorrectionWhiteWins($game);
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;
    $before = [boardCorrectionRating($game->white, Rating::CASUAL), boardCorrectionRating($game->black, Rating::CASUAL)];

    expect($game->rated)->toBeFalse()->and($before)->toBe([1020, 980]);

    app(TournamentControl::class)->setResult($tournament, boardCorrectionAdmin(), $match->id, ['result' => $whiteSlot === 0 ? '0-1' : '1-0'], 'Black won after all');

    expect([boardCorrectionRating($game->white, Rating::CASUAL), boardCorrectionRating($game->black, Rating::CASUAL)])->toBe($before)
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->whereNotNull('reverted_at')->count())->toBe(0);
});

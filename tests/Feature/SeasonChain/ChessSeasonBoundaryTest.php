<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| A rated chess game belongs to the ladder it started on (P8c)
|--------------------------------------------------------------------------
|
| The ladder open when a rated game starts is pinned on the game (a
| tournament game: the tournament's frozen ladder); the game is rated and
| attested only while that ladder is still the open one (NIP rule 16). A
| daily game that outlives its season counts for nothing, also once the next
| season is open. A rated game without a pinned ladder is never rated.
|
*/

beforeEach(function () {
    Queue::fake();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/** A rated game started through the service with the league's pinned gate, White to move. */
function boundaryChessGame(string $mode): ChessGame
{
    [$white, $black] = [User::factory()->create(), User::factory()->create()];
    $gate = app(RatedTrustGate::class)->pin([$white->pubkey, $black->pubkey], [$white->pubkey, $black->pubkey]);

    return app(ChessGameService::class)->start($white, $black, $mode, null, $gate);
}

/** 1. e4, then Black resigns: White wins. */
function boundaryChessResign(ChessGame $game): ChessGame
{
    $service = app(ChessGameService::class);
    $game = $service->move($game, $game->white, 'e2e4');

    return $service->resign($game->refresh(), $game->black)->refresh();
}

dataset('rated chess modes', ['blitz', 'correspondence']);

test('control: a rated game started and finished inside season 1 is pinned to the season-1 ladder, rated and attested there', function (string $mode) {
    openSeason(['slug' => 'season-1']);
    $game = boundaryChessResign(boundaryChessGame($mode));

    expect($game->rated)->toBeTrue()
        ->and($game->ladder_address)->toBe(Ladders::address('chess', $mode))->toContain('/season-1')
        ->and(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $game->id)->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('season', 'season-1')->count())->toBe(2)
        ->and(SeasonAttestation::query()->where('source', 'chess')->sole()->ladder_address)->toBe($game->ladder_address);
})->with('rated chess modes');

dataset('season 1 closed before the daily game ended', [
    'season 2 is open by then' => [fn () => openSeason(['slug' => 'season-2', 'genesis_at' => now()->subMinute()->startOfSecond()])],
    'rest between seasons' => [fn () => null],
]);

test('a daily game started in season 1 and finished after season 1 closed is neither rated nor attested, on no ladder', function (Closure $after) {
    $first = openSeason(['slug' => 'season-1']);
    $game = boundaryChessGame(ChessGame::CORRESPONDENCE);
    $game = app(ChessGameService::class)->move($game, $game->white, 'e2e4');

    $first->forceFill(['ends_at' => now()->subMinutes(2)])->save();
    $after();
    $game = app(ChessGameService::class)->resign($game->refresh(), $game->black)->refresh();

    expect($game->rated)->toBeTrue()
        ->and($game->result)->toBe('1-0')
        ->and($game->ladder_address)->toContain('/season-1')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(Rating::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);
})->with('season 1 closed before the daily game ended');

test('a rated game without a pinned ladder (a row from before the column) is never rated, fail closed', function () {
    openSeason(['slug' => 'season-1']);
    $game = boundaryChessGame('blitz');
    $game->forceFill(['ladder_address' => null])->save();

    $game = boundaryChessResign($game->refresh());

    expect($game->rated)->toBeTrue()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);
});

test('casual games pin no ladder; tournament games pin the tournament\'s frozen ladder, players and director mode alike', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    $casual = app(ChessGameService::class)->start($a, $b, 'blitz');

    $players = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $played = ChessGame::query()->whereHas('tournamentMatch', fn ($query) => $query->where('tournament_id', $players->id))->sole();

    $director = runningChess(TournamentFormat::SingleElimination, 2);
    playOutAsDirector($director);
    $entered = ChessGame::query()->whereHas('tournamentMatch', fn ($query) => $query->where('tournament_id', $director->id))->sole();

    expect($casual->rated)->toBeFalse()
        ->and($casual->ladder_address)->toBeNull()
        ->and($played->rated)->toBeTrue()
        ->and($played->ladder_address)->toBe($players->ladder_address)->not->toBeNull()
        ->and($entered->rated)->toBeTrue()
        ->and($entered->ladder_address)->toBe($director->ladder_address)->not->toBeNull();
});

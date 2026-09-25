<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Events\ChessGameUpdated;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use Illuminate\Support\Facades\Event;

// Clocks are compared to the millisecond: time only moves when a test travels.
beforeEach(fn () => $this->freezeTime());

/**
 * Plays the given UCI moves in turn, each by the player whose move it is.
 *
 * @param  list<string>  $ucis
 */
function playChess(ChessGame $game, array $ucis): ChessGame
{
    $service = app(ChessGameService::class);

    foreach ($ucis as $uci) {
        $game->refresh();
        $game = $service->move($game, $game->turn() === 'w' ? $game->white : $game->black, $uci);
    }

    return $game->refresh();
}

function chessViolation(Closure $action): ?string
{
    try {
        $action();
    } catch (ChessRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

test('the server refuses illegal moves and stores nothing', function (string $uci) {
    $game = ChessGame::factory()->create();

    expect(chessViolation(fn () => app(ChessGameService::class)->move($game, $game->white, $uci)))->toBe('illegal_move')
        ->and($game->refresh()->ply)->toBe(0)
        ->and($game->fen)->toBe(ChessGame::START_FEN)
        ->and($game->moves()->count())->toBe(0);
})->with([
    'pawn three squares' => 'e2e5',
    'king into its own pawn' => 'e1e2',
    'black piece on white\'s turn' => 'e7e5',
    'not a move at all' => 'x9',
]);

test('a player cannot move out of turn and a spectator cannot move at all', function () {
    $game = ChessGame::factory()->create();
    $service = app(ChessGameService::class);

    expect(chessViolation(fn () => $service->move($game, $game->black, 'e7e5')))->toBe('not_your_turn')
        ->and(chessViolation(fn () => $service->move($game, User::factory()->create(), 'e2e4')))->toBe('not_a_player')
        ->and($game->refresh()->ply)->toBe(0);

    $service->move($game, $game->white, 'e2e4');

    expect(chessViolation(fn () => $service->move($game->refresh(), $game->white, 'd2d4')))->toBe('not_your_turn')
        ->and($game->refresh()->ply)->toBe(1);
});

test('the increment is added after each move once the clocks run', function () {
    $game = playChess(ChessGame::factory()->create(), ['e2e4', 'e7e5']);

    // Both first moves are free: no clock ran, no increment was added.
    expect([$game->white_ms, $game->black_ms])->toBe([300_000, 300_000]);

    $this->travel(10)->seconds();
    $game = playChess($game, ['g1f3']);

    $this->travel(4)->seconds();
    $game = playChess($game, ['b8c6']);

    expect($game->white_ms)->toBe(300_000 - 10_000 + 3_000)
        ->and($game->black_ms)->toBe(300_000 - 4_000 + 3_000)
        ->and($game->moves()->pluck('spent_ms')->all())->toBe([0, 0, 10_000, 4_000]);
});

test('a flag falls on the server without anyone moving again', function () {
    Event::fake([ChessGameUpdated::class]);
    $game = playChess(ChessGame::factory()->create(), ['e2e4', 'e7e5', 'g1f3']);

    $this->travel(302)->seconds();
    $this->artisan('chess:check-clocks')->assertSuccessful();

    $game->refresh();
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe(ChessEndReason::Timeout)
        ->and($game->black_ms)->toBe(0)
        ->and($game->white_ms)->toBe(303_000);

    Event::assertDispatched(ChessGameUpdated::class, fn (ChessGameUpdated $event) => $event->state['reason'] === 'timeout');
});

test('with no first move in time the server aborts the game', function () {
    $game = playChess(ChessGame::factory()->create(), ['e2e4']);

    $this->travel(29)->seconds();
    $this->artisan('chess:check-clocks');
    expect($game->refresh()->status)->toBe(ChessGameStatus::Active);

    $this->travel(2)->seconds();
    $this->artisan('chess:check-clocks');

    expect($game->refresh()->status)->toBe(ChessGameStatus::Aborted)
        ->and($game->result)->toBeNull();
});

test('a move after the flag fell is refused and the game ends on time', function () {
    $game = playChess(ChessGame::factory()->create(), ['e2e4', 'e7e5']);

    $this->travel(301)->seconds();

    expect(chessViolation(fn () => app(ChessGameService::class)->move($game, $game->white, 'g1f3')))->toBe('game_over')
        ->and($game->refresh()->result)->toBe('0-1')
        ->and($game->end_reason)->toBe(ChessEndReason::Timeout)
        ->and($game->ply)->toBe(2);
});

test('every way a game ends ends it', function (?string $fen, array $moves, ?Closure $act, string $result, ChessEndReason $reason) {
    $game = ChessGame::factory()->create($fen === null ? [] : ['start_fen' => $fen, 'fen' => $fen]);
    $game = playChess($game, $moves);

    if ($act !== null) {
        $act(app(ChessGameService::class), $game);
    }

    $game->refresh();
    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->result)->toBe($result)
        ->and($game->end_reason)->toBe($reason)
        ->and($game->deadline_ms)->toBeNull()
        ->and(chessViolation(fn () => app(ChessGameService::class)->resign($game, $game->white)))->toBe('game_over');
})->with([
    'checkmate' => [null, ['f2f3', 'e7e5', 'g2g4', 'd8h4'], null, '0-1', ChessEndReason::Checkmate],
    'stalemate' => [null, explode(' ', 'e2e3 a7a5 d1h5 a8a6 h5a5 h7h5 h2h4 a6h6 a5c7 f7f6 c7d7 e8f7 d7b7 d8d3 b7b8 d3h7 b8c8 f7g6 c8e6'), null, '1/2-1/2', ChessEndReason::Stalemate],
    'threefold repetition' => [null, explode(' ', 'g1f3 g8f6 f3g1 f6g8 g1f3 g8f6 f3g1 f6g8'), null, '1/2-1/2', ChessEndReason::ThreefoldRepetition],
    'insufficient material' => ['7k/8/8/8/8/8/n7/K7 w - - 0 1', ['a1a2'], null, '1/2-1/2', ChessEndReason::InsufficientMaterial],
    '50-move rule' => ['k7/8/8/8/8/8/8/KR6 w - - 99 80', ['b1c1'], null, '1/2-1/2', ChessEndReason::FiftyMoveRule],
    'resignation' => [null, ['e2e4'], fn (ChessGameService $s, ChessGame $g) => $s->resign($g, $g->white), '0-1', ChessEndReason::Resignation],
    'draw by agreement' => [null, ['e2e4', 'e7e5'], function (ChessGameService $s, ChessGame $g) {
        $s->offerDraw($g, $g->white);
        $s->acceptDraw($g->refresh(), $g->black);
    }, '1/2-1/2', ChessEndReason::Agreement],
    'time' => [null, ['e2e4', 'e7e5'], function (ChessGameService $s, ChessGame $g) {
        test()->travel(301)->seconds();
        $s->checkClock($g);
    }, '0-1', ChessEndReason::Timeout],
]);

test('a game can be aborted before both first moves, not after', function () {
    $service = app(ChessGameService::class);
    $game = playChess(ChessGame::factory()->create(), ['e2e4']);

    $service->abort($game, $game->black);
    expect($game->refresh()->status)->toBe(ChessGameStatus::Aborted);

    $late = playChess(ChessGame::factory()->create(), ['e2e4', 'e7e5']);
    expect(chessViolation(fn () => $service->abort($late, $late->white)))->toBe('too_late_to_abort')
        ->and($late->refresh()->status)->toBe(ChessGameStatus::Active);
});

test('a draw offer lapses when the player it was made to moves instead', function () {
    $service = app(ChessGameService::class);
    $game = playChess(ChessGame::factory()->create(), ['e2e4']);

    $service->offerDraw($game, $game->white);
    expect($game->refresh()->draw_offer)->toBe('w');

    $game = playChess($game, ['e7e5']);
    expect($game->draw_offer)->toBeNull()
        ->and(chessViolation(fn () => $service->acceptDraw($game, $game->black)))->toBe('no_draw_offer');
});

test('a rematch starts a new game with the colours swapped', function () {
    $service = app(ChessGameService::class);
    $game = playChess(ChessGame::factory()->create(), ['f2f3', 'e7e5', 'g2g4', 'd8h4']);

    expect($service->offerRematch($game, $game->white))->toBeNull()
        ->and(chessViolation(fn () => $service->offerRematch($game->refresh(), User::factory()->create())))->toBe('not_a_player');

    $rematch = $service->acceptRematch($game->refresh(), $game->black);

    expect($rematch->white_id)->toBe($game->black_id)
        ->and($rematch->black_id)->toBe($game->white_id)
        ->and($rematch->isActive())->toBeTrue()
        ->and($game->refresh()->rematch_id)->toBe($rematch->id)
        ->and(chessViolation(fn () => $service->acceptRematch($game, $game->black)))->toBe('no_rematch');
});

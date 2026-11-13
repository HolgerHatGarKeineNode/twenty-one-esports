<?php

use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\GoRules;
use Illuminate\Support\Facades\Route;
use Tests\Support\GoOn;

/*
|--------------------------------------------------------------------------
| A push fits into one Reverb message, for every board game
|--------------------------------------------------------------------------
|
| Reverb refuses a request above `max_request_size` (10 000 bytes, headers
| included: measured, the largest body it takes is 9 637 bytes), and a board
| whose push is refused stands still for the other player. So every board game
| the league registers is measured here as the push goes over the wire: at its
| start, and Go on each size with the board nearly full, a ko and a move on
| the record.
|
*/

/** A board push as it goes to Reverb: the event's name, its data as JSON in JSON, both channels. */
function boardPushBytes(BoardGame $game): int
{
    $push = app(BoardGameService::class)->snapshot($game->refresh(), withMoves: false);

    return strlen((string) json_encode(['name' => 'board.updated', 'data' => json_encode($push), 'channels' => ['private-board.'.$game->id, 'board.'.$game->id.'.watch']]));
}

/**
 * A late position, written as GoRules serializes it: every third point of every third row is empty (eight of nine
 * points hold a stone), the top three rows are Black's and the rest White's, Black is to move and the ko stands on a
 * point of Black's own.
 */
function goDensePosition(int $size): string
{
    $board = '';

    for ($row = 0; $row < $size; $row++) {
        for ($file = 0; $file < $size; $file++) {
            $board .= $row % 3 === 1 && $file % 3 === 1 ? '.' : ($row < 3 ? 'x' : 'o');
        }
    }

    $rules = new GoRules($size);

    return $rules->serialize(['board' => $board, 'turn' => 'w', 'ko' => $size + 1, 'passes' => 0]);
}

beforeEach(function () {
    $slugs = array_keys(config('esports.board_games.games'));
    config(['esports.board_games.enabled' => true, ...array_combine(array_map(fn (string $slug): string => "esports.board_games.games.{$slug}.enabled", $slugs), array_fill(0, count($slugs), true))]);
    app()->forgetInstance(GameRegistry::class);

    if (! Route::has('board.show')) {
        Route::middleware('web')->group(base_path('routes/board.php'));
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
});

test('the first push of every board game fits into a Reverb message', function () {
    $boards = app(GameRegistry::class)->boards();
    $bytes = [];

    foreach ($boards as $board) {
        $bytes[$board->slug()] = boardPushBytes(app(BoardGameService::class)->start($board->slug(), User::factory()->create(), User::factory()->create()));
    }

    expect(array_keys($bytes))->toEqualCanonicalizing(array_keys(config('esports.board_games.games')))
        ->and(array_filter($bytes, fn (int $size): bool => $size > 9000))->toBe([]);
});

test('a push of a nearly full go board with a ko fits into a Reverb message', function (string $slug) {
    $games = app(BoardGameService::class);
    $game = $games->start($slug, User::factory()->create(), User::factory()->create());
    $game = $games->move($game, $game->player('w'), 'a1', 1);
    $size = (int) substr($slug, 3);
    $game = GoOn::setUp($game, goDensePosition($size));
    $rules = new GoRules($size);
    $position = $rules->deserialize($game->position);

    expect(substr_count($position['board'], '.') / ($size * $size))->toBeLessThan(0.2)
        ->and($position['ko'])->not->toBeNull()
        ->and(boardPushBytes($game))->toBeLessThanOrEqual(9000);
})->with(['go-9', 'go-13', 'go-19']);

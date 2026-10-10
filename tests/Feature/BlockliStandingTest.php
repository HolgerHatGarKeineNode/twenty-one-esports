<?php

use App\Models\User;
use App\Support\Board\BoardGameService;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| The race standing in every Blockli snapshot (DerCaddy, 2026-10-09)
|--------------------------------------------------------------------------
|
| The page and every push carry who would be ahead: each side's steps to
| its goal and blocks left, a block worth one and a half steps
| (BlockliRules::standing(), its sums checked in the unit test). The board
| shows it beside the game.
|
*/

beforeEach(function () {
    BlockliOn::play();
});

test('a Blockli snapshot carries the race standing of the position on the board, after every move anew', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $game = BlockliOn::setUp($service->start('blockli', $anna, $bert), 'e7 e3 10 10 w - 0');

    expect($service->snapshot($game)['standing'])->toBe([
        'w' => ['steps' => 2, 'blocks' => 10, 'score' => -13.0],
        'b' => ['steps' => 2, 'blocks' => 10, 'score' => -13.0],
        'margin' => 0.0, 'lead' => null, 'rate' => 1.5,
    ]);

    // White steps to e8: one step from the goal against two, White leads by one.
    $game = $service->move($game, $anna, 'e7-e8', 1);
    $standing = $service->snapshot($game->refresh(), withMoves: false)['standing'];

    expect($standing['w'])->toBe(['steps' => 1, 'blocks' => 10, 'score' => -14.0])
        ->and($standing['margin'])->toBe(1.0)
        ->and($standing['lead'])->toBe('w');
});

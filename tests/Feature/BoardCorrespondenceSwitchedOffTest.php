<?php

/*
| Correspondence board games with their routes not registered (plan "Mühle
| und Dame", P8 review): routes/board.php is loaded only while the board
| games are switched on at boot. A game started while they were on still
| ends by the clock sweep after the switch went off, its players still get
| the result with a link to its board, and no reminder goes out for a board
| game that is off.
*/

use App\Enums\BoardGameStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Jobs\SendNostrDm;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

test('the sweep ends a correspondence game and reports it, and the reminders skip it, without the board routes', function () {
    $this->freezeTime();
    Queue::fake();
    Bus::fake([SendNostrDm::class]);
    config([
        'esports.notifications.nsec' => bin2hex(random_bytes(32)),
        'esports.board_games.enabled' => true,
        'esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => true,
        'esports.board_games.games.'.Checkers::SLUG.'.enabled' => true,
    ]);
    app()->forgetInstance(GameRegistry::class);

    expect(Route::has('board.show'))->toBeFalse()
        ->and(Route::has('board.correspondence'))->toBeFalse();

    [$anna, $bert] = User::factory()->count(2)->create(['chess_settings' => ['dm' => true]]);
    $challenges = app(BoardChallenges::class);
    $service = app(BoardGameService::class);

    $played = $challenges->accept($challenges->challenge($anna, $bert, NineMensMorris::SLUG, 'white'), $bert);
    $played = $service->move($played, $anna, 'd2', 1);
    $played = $service->move($played->refresh(), $bert, 'd6', 2);
    $waiting = $challenges->accept($challenges->challenge($anna, $bert, Checkers::SLUG, 'white'), $bert);

    expect($anna->notifications()->where('type', 'game_started')->pluck('data')->pluck('url')->all())
        ->toBe([url('board/'.$played->id), url('board/'.$waiting->id)]);

    // The board games go off.
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    // In both reminder windows now, but the board games are off: no reminder.
    $this->travel(18 * 60 + 1)->minutes();
    $this->artisan('board:daily-reminders')->expectsOutputToContain('Sent 0 reminder(s).')->assertSuccessful();

    // Past both deadlines: the sweep ends both games, and the players hear the result with a link to the board.
    $this->travel(6)->hours();
    $this->artisan('board:check-clocks')->expectsOutputToContain('Checked 2 game(s).')->assertSuccessful();

    expect($played->refresh()->only(['status', 'result', 'end_reason']))->toBe(['status' => BoardGameStatus::Finished, 'result' => '0-1', 'end_reason' => 'timeout'])
        ->and($waiting->refresh()->status)->toBe(BoardGameStatus::Aborted)
        ->and($anna->notifications()->where('type', 'reminder')->count())->toBe(0)
        ->and($anna->notifications()->where('type', 'game_over')->pluck('data')->pluck('url')->all())
        ->toEqualCanonicalizing([url('board/'.$played->id), url('board/'.$waiting->id)]);
});

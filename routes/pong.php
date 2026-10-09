<?php

use App\Http\Controllers\PongController;
use Illuminate\Support\Facades\Route;

/*
 * Proof of Pong (plan "Proof of Pong", P1/P2/P4): the lobby and the Elo ladder in the league's shell (for everybody), a game against a bot
 * on its own full-screen page for a logged-in player, and the live 1v1 matches (P2): a match's full-screen page (its
 * score for anybody who is not playing it), the referee's endpoints for its two players (`pong-live`, per player)
 * and the lobby's accept of an invite. A game against a bot runs in the browser and stores nothing; a live match is
 * checked and rated by the server (App\Support\Pong\PongMatches). Loaded by routes/web.php only while
 * `esports.pong.enabled` is on, so with the switch off none of these routes exists. A cached route table keeps the
 * switch as it was when it was cached: flipping it needs the deploy's `optimize`.
 */
Route::prefix('proof-of-pong')->name('pong.')->group(function () {
    Route::get('/', [PongController::class, 'index'])->name('index');
    // The Elo ladder (P4), for everybody.
    Route::get('ladder', [PongController::class, 'ladder'])->name('ladder');
    Route::get('m/{match}', [PongController::class, 'match'])->whereUlid('match')->name('match');

    Route::middleware('auth')->group(function () {
        Route::get('bot', [PongController::class, 'bot'])->name('bot');
        Route::post('m/{match}/sync', [PongController::class, 'sync'])->whereUlid('match')->middleware('throttle:pong-live')->name('sync');
        Route::post('m/{match}/report', [PongController::class, 'report'])->whereUlid('match')->middleware('throttle:pong-live')->name('report');
        Route::post('m/{match}/figure', [PongController::class, 'figure'])->whereUlid('match')->middleware('throttle:20,1')->name('figure');
        Route::post('m/{match}/resign', [PongController::class, 'resign'])->whereUlid('match')->middleware('throttle:20,1')->name('resign');
        Route::post('m/{match}/rematch', [PongController::class, 'rematch'])->whereUlid('match')->middleware('throttle:20,1')->name('rematch');
        Route::post('invites/{invite}/accept', [PongController::class, 'accept'])->whereNumber('invite')->middleware('throttle:20,1')->name('accept');
    });
});

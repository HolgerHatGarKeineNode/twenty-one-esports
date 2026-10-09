<?php

use App\Http\Controllers\PongController;
use Illuminate\Support\Facades\Route;

/*
 * Proof of Pong (plan "Proof of Pong", P1): the lobby in the league's shell (for everybody) and, for a logged-in
 * player, a game against a bot on its own full-screen page. The game runs in the browser; P1 stores no result
 * (a casual game against a bot), P2 adds live 1v1 with the server's check. Loaded by routes/web.php only while
 * `esports.pong.enabled` is on, so with the switch off none of these routes exists. A cached route table keeps the
 * switch as it was when it was cached: flipping it needs the deploy's `optimize`.
 */
Route::prefix('proof-of-pong')->name('pong.')->group(function () {
    Route::get('/', [PongController::class, 'index'])->name('index');
    Route::get('bot', [PongController::class, 'bot'])->middleware('auth')->name('bot');
});

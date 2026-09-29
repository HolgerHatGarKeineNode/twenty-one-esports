<?php

use Illuminate\Support\Facades\Route;

/*
 * Board games next to chess (plan "Mühle und Dame", P2): one game, live or
 * finished, for guests too (watching). Loaded by routes/web.php only while
 * `esports.board_games.enabled` is on, so with the switch off the route does
 * not exist at all; the page itself answers 404 for a game whose own switch
 * is off (App\Games\GameRegistry::isBoard()). A cached route table keeps the
 * switch as it was when it was cached: flipping it needs the deploy's
 * `optimize`, like every config change.
 */
Route::livewire('board/{boardGame}', 'pages::board.show')->whereNumber('boardGame')->name('board.show');

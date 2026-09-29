<?php

use Illuminate\Support\Facades\Route;

/*
 * Board games next to chess (plan "Mühle und Dame", P2): one game, live or
 * finished, for guests too (watching); since P5 the lobby of each board
 * game. Loaded by routes/web.php only while
 * `esports.board_games.enabled` is on, so with the switch off the route does
 * not exist at all; the page itself answers 404 for a game whose own switch
 * is off (App\Games\GameRegistry::isBoard()). A cached route table keeps the
 * switch as it was when it was cached: flipping it needs the deploy's
 * `optimize`, like every config change.
 */
Route::livewire('board/{boardGame}', 'pages::board.show')->whereNumber('boardGame')->name('board.show');

// The lobby of each board game (P5): its casual queue, invites, and the board games running now. Matched after
// the series games' `games/{slug}` and chess's numeric `games/{game}`; its parameter has its own name, since a
// route with the same URI string would replace theirs. The page answers 404 for a slug that is no board game
// switched on (App\Games\BoardGame::RESERVED_SLUGS are the real ones).
Route::livewire('games/{board}', 'pages::board.lobby')->where('board', '[a-z][a-z0-9-]*')->name('board.lobby');

// Correspondence (P8): one move a day. The challenges received and sent, the player's correspondence games of this
// board game, and the challenge form; guests see what it is and the login.
Route::livewire('games/{board}/correspondence', 'pages::board.correspondence')->where('board', '[a-z][a-z0-9-]*')->name('board.correspondence');

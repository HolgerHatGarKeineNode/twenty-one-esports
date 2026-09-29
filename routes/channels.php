<?php

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Nostr\PlayerProfile;
use App\Support\Rating\Ratings;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * A live chess game's player channel. Spectators listen on the public
 * `game.{id}.watch` channel instead (App\Events\ChessGameUpdated sends both).
 */
Broadcast::channel('game.{game}', function (User $user, ChessGame $game) {
    return $game->colorOf($user) !== null;
});

/*
 * The two players of a live game, present while their game page is open.
 * The other player's page shows "opponent disconnected" when one leaves,
 * and the server asks Reverb for this channel's members before it grants a
 * claim-win (App\Support\Chess\PresenceLookup).
 */
Broadcast::channel('game.{game}.players', function (User $user, ChessGame $game) {
    $color = $game->colorOf($user);

    return $color === null ? false : ['id' => $user->id, 'color' => $color];
});

/*
 * A live board game's player channel (nine men's morris, checkers; plan
 * "Mühle und Dame", P2). Spectators listen on the public `board.{id}.watch`
 * channel instead (App\Events\BoardGameUpdated sends both).
 */
Broadcast::channel('board.{boardGame}', function (User $user, BoardGame $boardGame) {
    return $boardGame->colorOf($user) !== null;
});

/*
 * Global presence: every logged-in page joins it (resources/js/echo.js), so
 * the lobby shows everyone online, and who is looking to play. What a member
 * shares here is shown to every other logged-in player.
 */
Broadcast::channel('online', function (User $user) {
    $blitz = Ratings::headline($user->id, 'chess', 'blitz');

    return [
        'id' => $user->id,
        'name' => $user->displayName(),
        'avatar' => $user->avatarUrl(),
        // P10a: the Blockpile when there is no picture, and the key for the player card.
        'generated' => PlayerProfile::generatedAvatarUrl($user->pubkey),
        'npub' => $user->npub,
        'pubkey' => $user->pubkey,
        'looking' => $user->looking_to_play,
        // Blitz Elo as of joining: casual before Block 0, rated after (P7b).
        'elo' => $blitz['rating'],
        'provisional' => $blitz['provisional'],
    ];
});

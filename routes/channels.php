<?php

use App\Models\ChessGame;
use App\Models\User;
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
 * Global presence: who is online on the chess pages, and who is looking to
 * play. What a member shares here is shown to every other logged-in player.
 */
Broadcast::channel('online', function (User $user) {
    return [
        'id' => $user->id,
        'name' => $user->displayName(),
        'avatar' => $user->avatarUrl(),
        'looking' => $user->looking_to_play,
    ];
});

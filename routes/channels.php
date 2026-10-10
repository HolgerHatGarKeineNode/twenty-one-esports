<?php

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Chess\ChessModes;
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
 * A Hyperbitcoinization match's player channel (plan "Hyperbitcoinization", P2), by its ulid: the seated
 * players, a seat a bot took over included (its player may still watch). Spectators listen on the public
 * `hyper.{ulid}.watch` channel instead (App\Events\HyperMatchUpdated and HyperEmoteSent send both).
 */
Broadcast::channel('hyper.{match}', function (User $user, HyperMatch $match) {
    return $match->seatOf($user) !== null;
});

/*
 * One seat's secrets (its cards, App\Events\HyperHandUpdated): only that seat's player, and only while they
 * still play it (not after leaving or a bot takeover).
 */
Broadcast::channel('hyper.{match}.seat.{seat}', function (User $user, HyperMatch $match, string $seat) {
    return $match->handSeatOf($user) === (int) $seat;
});

/*
 * Who of the seated players is at the table now: the page shows a seat as connected while its player is
 * here. A member shares its seat and name with the other players.
 */
Broadcast::channel('hyper.{match}.here', function (User $user, HyperMatch $match) {
    $seat = $match->seatOf($user);

    return $seat === null ? false : ['id' => $user->id, 'seat' => $seat->seat, 'name' => $user->displayName()];
});

/*
 * A live Proof of Pong match (plan "Proof of Pong", P2): its two players only. The referee's snapshots come here
 * (App\Events\PongMatchUpdated), the pages stream their paddles to each other as client events (whisper), and
 * presence tells each page when the other player is gone. A member shares its side and name.
 */
Broadcast::channel('pong.{match}', function (User $user, PongMatch $match) {
    $side = $match->sideOf($user);

    return $side === null ? false : ['id' => $user->id, 'side' => $side, 'name' => $user->displayName()];
});

/*
 * Global presence: every logged-in page joins it (resources/js/echo.js), so
 * the lobby shows everyone online, and who is looking to play. What a member
 * shares here is shown to every other logged-in player.
 */
Broadcast::channel('online', function (User $user) {
    // The rapid Elo, the lobby's default mode and the clans' (plan "Schach Rapid und Clan", P6); the list labels it.
    $rapid = Ratings::headline($user->id, 'chess', ChessModes::DEFAULT);
    // "Looking to play" read fresh, stamped with a server time taken BEFORE the read (plan "Proof of Pong", P6): a
    // switch saved after it carries a later stamp (LookingToPlayChanged::$at), so a page that hears the switch before
    // this join keeps the switch (resources/js/echo.js). The user the request loaded may be older than the stamp.
    $lookingAt = round(microtime(true) * 1000, 3);
    $looking = User::query()->whereKey($user->id)->value('looking_to_play');

    return [
        'id' => $user->id,
        'name' => $user->displayName(),
        'avatar' => $user->avatarUrl(),
        // P10a: the Blockpile when there is no picture, and the key for the player card.
        'generated' => PlayerProfile::generatedAvatarUrl($user->pubkey),
        'npub' => $user->npub,
        'pubkey' => $user->pubkey,
        'looking' => $looking,
        'lookingAt' => $lookingAt,
        // Rapid Elo as of joining: casual before Block 0, rated after (P7b).
        'elo' => $rapid['rating'],
        'eloMode' => ChessModes::DEFAULT,
        'provisional' => $rapid['provisional'],
    ];
});

/*
 * `league.feed` (plan "OBS-Broadcast-Overlays", P2) is a public channel: App\Events\LeagueFeedEvent sends what
 * happened in the league to the OBS overlays, names as the site shows them publicly. Public channels need no
 * authorization callback; it is listed here so the next reader finds it.
 */

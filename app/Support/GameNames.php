<?php

namespace App\Support;

use App\Games\GameRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Display names and pages of the registered games, translated: the game
 * classes stay free of the framework, so the words and routes live here.
 * Views and copy call this instead of comparing a slug with `'chess'` or
 * `'rocket-league'`, so a new game shows up everywhere by its registry entry.
 */
final class GameNames
{
    /** "Chess" (translated), "Rocket League", "EA Sports FC 27"; the slug of an unregistered game. */
    public static function game(string $game): string
    {
        return __(app(GameRegistry::class)->name($game));
    }

    /** "Blitz 5+3", "Daily" (translated), "1v1"; the slug of an unregistered mode. */
    public static function mode(string $game, string $mode): string
    {
        return __(app(GameRegistry::class)->mode($game, $mode)->name ?? $mode);
    }

    /** "Chess Blitz 5+3", "EA Sports FC 27 1v1". */
    public static function full(string $game, string $mode): string
    {
        return self::game($game).' '.self::mode($game, $mode);
    }

    /**
     * The page of a game: the chess lobby, the overview of a series game, the
     * lobby of a board game (plan "Mühle und Dame", P5) or the leaderboards of
     * a score game (plan "AoE2 und Trackmania", P4), never the chess lobby for
     * either.
     */
    public static function page(string $game): string
    {
        // A route table cached before the switch went on has no lobby yet: the list of all games then.
        if (app(GameRegistry::class)->isBoard($game)) {
            return Route::has('board.lobby') ? route('board.lobby', $game) : route('play');
        }

        // A score game (plan "AoE2 und Trackmania", P4): its leaderboards, never the chess lobby.
        if (app(GameRegistry::class)->isScore($game)) {
            return Route::has('scores.show') ? route('scores.show', $game) : route('play');
        }

        return app(GameRegistry::class)->isSeries($game) ? route('games.series', $game) : route('chess.lobby');
    }
}

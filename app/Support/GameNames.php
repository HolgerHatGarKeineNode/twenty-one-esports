<?php

namespace App\Support;

use App\Games\GameRegistry;

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
     * The page of a game: the chess lobby, or the overview of a series game.
     * Chess is the one game that is not played as a series. A board game has
     * no page of its own before P5 of plan "Mühle und Dame": the list of all
     * games, never the chess lobby.
     */
    public static function page(string $game): string
    {
        if (app(GameRegistry::class)->isBoard($game)) {
            return route('play');
        }

        return app(GameRegistry::class)->isSeries($game) ? route('games.series', $game) : route('chess.lobby');
    }
}

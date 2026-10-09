<?php

namespace App\Support;

use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
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

    /** The longest name a mempool cube's first line holds (96 px at 11 px mono, 88 px on phones). */
    public const CUBE_NAME_MAX = 13;

    /**
     * The name a mempool cube shows (user, 2026-10-08: the mini icon and the
     * mode alone did not say which game it was): the translated name while it
     * fits ("Schach", "Rocket League", "Mühle", "Blockli"), otherwise the
     * game's short label plus a trailing edition number ("AoE2", "TMNF",
     * "FC 26").
     */
    public static function cube(string $game): string
    {
        $name = self::game($game);

        if (mb_strlen($name) <= self::CUBE_NAME_MAX) {
            return $name;
        }

        $short = app(GameRegistry::class)->find($game)?->assets()->shortLabel ?? $name;

        return preg_match('/\s(\d+)$/', $name, $edition) === 1 && ! str_ends_with($short, $edition[1]) ? $short.' '.$edition[1] : $short;
    }

    /** The credit line of a game ("by DerCaddy", from its config entry), null for a game without one or not registered. */
    public static function credit(string $game): ?string
    {
        return app(GameRegistry::class)->find($game)?->assets()->credit;
    }

    /**
     * The name with its credit for text without markup (share posts, stream
     * texts, the drawn cards, alt text, structured data): "Blockli by
     * DerCaddy"; the plain name for a game without a credit.
     */
    public static function credited(string $game): string
    {
        $credit = self::credit($game);

        return $credit === null ? self::game($game) : self::game($game).' '.$credit;
    }

    /** "Blitz 5+3", "Daily" (translated), "1v1"; the slug of an unregistered mode. */
    public static function mode(string $game, string $mode): string
    {
        return __(app(GameRegistry::class)->mode($game, $mode)->name ?? self::RETIRED_MODES[$mode] ?? $mode);
    }

    /**
     * The names of modes a game no longer offers, so the records of games
     * played in them keep their name: the board games' blitz, dropped on
     * 2026-10-07 (user: correspondence only, fast modes are for chess).
     */
    private const RETIRED_MODES = ['blitz' => 'Blitz 5+3'];

    /** "Chess Blitz 5+3", "EA Sports FC 27 1v1". */
    public static function full(string $game, string $mode): string
    {
        return self::game($game).' '.self::mode($game, $mode);
    }

    /** full() with the credit after it, for text without markup: "Blockli Blitz 5+3 by DerCaddy". */
    public static function fullCredited(string $game, string $mode): string
    {
        $credit = self::credit($game);

        return self::full($game, $mode).($credit === null ? '' : ' '.$credit);
    }

    /**
     * The page of a game: the chess lobby, the overview of a series game, the
     * lobby of a board game (plan "Mühle und Dame", P5) or the leaderboards of
     * a score game (plan "AoE2 und Trackmania", P4), Hyperbitcoinization's lobby
     * (plan "Hyperbitcoinization", P6), never the chess lobby for either.
     */
    public static function page(string $game): string
    {
        if ($game === Hyperbitcoinization::SLUG && app(GameRegistry::class)->find($game) !== null) {
            return Route::has('hyper.index') ? route('hyper.index') : route('play');
        }

        // A route table cached before the switch went on has no lobby yet: the list of all games then.
        if (app(GameRegistry::class)->isBoard($game)) {
            return Route::has('board.lobby') ? route('board.lobby', $game) : route('play');
        }

        // Blockfill (plan "Blockfill", P6): the game itself; its leaderboards are one link further.
        if ($game === Blockfill::SLUG && app(GameRegistry::class)->isScore($game) && Route::has('stacker.play')) {
            return route('stacker.play');
        }

        // A score game (plan "AoE2 und Trackmania", P4): its leaderboards, never the chess lobby.
        if (app(GameRegistry::class)->isScore($game)) {
            return Route::has('scores.show') ? route('scores.show', $game) : route('play');
        }

        return app(GameRegistry::class)->isSeries($game) ? route('games.series', $game) : route('chess.lobby');
    }
}

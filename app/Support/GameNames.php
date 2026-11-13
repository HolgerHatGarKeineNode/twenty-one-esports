<?php

namespace App\Support;

use App\Games\Blockfill;
use App\Games\BoardGame;
use App\Games\BoardSide;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Games\ProofOfPong;
use Illuminate\Support\Facades\Route;

/**
 * Display names and pages of the registered games, translated: the game
 * classes stay free of the framework, so the words and routes live here.
 * Views and copy call this instead of comparing a slug with `'chess'` or
 * `'rocket-league'`, so a new game shows up everywhere by its registry entry.
 */
final class GameNames
{
    /**
     * Soft hyphens in the game names that are one long word, for a big headline (plan "Canvas and live alignment",
     * P1): "Hyperbitcoinization" broke mid-word without a hyphen ("Hyperbitcoiniz|ation") beside the cover at 390 px;
     * now it breaks as "Hyper-|bitcoinization" only where it must. Plain text in, plain text out (escaped by Blade).
     */
    public static function breakable(string $text): string
    {
        return str_replace('Hyperbitcoinization', "Hyper\u{00AD}bitcoin\u{00AD}ization", $text);
    }

    /** "Chess" (translated), "Rocket League", "EA Sports FC 27"; the slug of an unregistered game. */
    public static function game(string $game): string
    {
        return __(app(GameRegistry::class)->name($game));
    }

    /** The name of a game's entry in a list of games: its group's ("Go" for every size), else game(). */
    public static function listed(string $game): string
    {
        return __(app(GameRegistry::class)->listedName($game));
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

    /**
     * The two sides of a game as its registry entry names them (BoardGame::sides()), the side that moves first (`w`)
     * first; White and Black for chess and for a game no longer registered.
     *
     * @return array{w: BoardSide, b: BoardSide}
     */
    public static function boardSides(string $game): array
    {
        $definition = app(GameRegistry::class)->find($game);

        return $definition instanceof BoardGame ? $definition->sides() : ['w' => BoardSide::white(), 'b' => BoardSide::black()];
    }

    /**
     * The colour each side's pieces are drawn in, the side that moves first (`w`) first: white and black, Go's black first.
     *
     * @return array{w: 'white'|'black', b: 'white'|'black'}
     */
    public static function sideColours(string $game): array
    {
        $sides = self::boardSides($game);

        return ['w' => $sides['w']->stone, 'b' => $sides['b']->stone];
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

    /**
     * What the two sides of a board game are called, the side that moves first (`w`) first: White and Black, in
     * Blockspace the colours of its stones, in Go Black and White, as Black moves first there (DerCaddy, 2026-10-27).
     *
     * @return array{w: string, b: string}
     */
    public static function sides(string $game, ?string $locale = null): array
    {
        $sides = self::boardSides($game);

        return ['w' => __($sides['w']->name, [], $locale), 'b' => __($sides['b']->name, [], $locale)];
    }

    /**
     * The sides as a note after a player's name: "(white)", "(Weiß)", "(Orange)"; Go's first side "(black)".
     *
     * @return array{w: string, b: string}
     */
    public static function sideNotes(string $game): array
    {
        $note = fn (BoardSide $side): string => $side->note === null ? '('.__($side->name).')' : __($side->note);
        $sides = self::boardSides($game);

        return ['w' => $note($sides['w']), 'b' => $note($sides['b'])];
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
     * (plan "Hyperbitcoinization", P6) or Proof of Pong's (plan "Proof of Pong",
     * P4), never the chess lobby for either.
     */
    public static function page(string $game): string
    {
        if ($game === Hyperbitcoinization::SLUG && app(GameRegistry::class)->find($game) !== null) {
            return Route::has('hyper.index') ? route('hyper.index') : route('play');
        }

        if ($game === ProofOfPong::SLUG && app(GameRegistry::class)->find($game) !== null) {
            return Route::has('pong.index') ? route('pong.index') : route('play');
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

<?php

namespace App\Support\Chess;

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Support\GameNames;

/**
 * The chess modes as the pages name them, read from the registry
 * (app/Games/Chess.php), so a page never writes "blitz" for every game that
 * is not daily (plan "Schach Rapid und Clan", P3). A mode added to the
 * registry shows up wherever a page lists or names the chess modes.
 *
 * Rapid 10+5 is the default live mode (user, 2026-10-05): the first lobby
 * tile, the ladder navigation and the lobby open on it. Daily
 * (`correspondence`) is the one mode that is not played live.
 */
final class ChessModes
{
    /** The mode the lobby, the ladder link and a new search open on. */
    public const DEFAULT = 'rapid';

    /**
     * Every chess mode, the default first, daily last.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $modes = array_keys(app(GameRegistry::class)->get('chess')->modes());
        usort($modes, fn (string $a, string $b): int => self::rank($a) <=> self::rank($b));

        return $modes;
    }

    /**
     * The modes played live on a clock (everything but daily), the default first.
     *
     * @return list<string>
     */
    public static function live(): array
    {
        return array_values(array_filter(self::all(), self::isLive(...)));
    }

    public static function isLive(string $mode): bool
    {
        return $mode !== ChessGame::CORRESPONDENCE && app(GameRegistry::class)->mode('chess', $mode) !== null;
    }

    /** The registry name, translated (in `$locale`, else the current one): "Rapid 10+5", "Blitz 5+3", "Daily". */
    public static function label(string $mode, ?string $locale = null): string
    {
        return $locale === null ? GameNames::mode('chess', $mode) : __(app(GameRegistry::class)->mode('chess', $mode)->name ?? $mode, [], $locale);
    }

    /**
     * The translated name without its clock: "Rapid", "Blitz", "Daily"
     * ("Rapid", "Blitz", "Fernschach" in German), the first word of label(),
     * so a tile and a chip say what the panel says (de "Rapid 10+5").
     */
    public static function short(string $mode, ?string $locale = null): string
    {
        return explode(' ', self::label($mode, $locale))[0];
    }

    /** The clock as players write it ("10+5", "5+3"), or null for daily. */
    public static function clock(string $mode): ?string
    {
        $control = app(GameRegistry::class)->mode('chess', $mode)?->timeControl;

        if ($control === null || preg_match('/^(\d+)\+(\d+)$/', $control, $parts) !== 1) {
            return null;
        }

        return intdiv((int) $parts[1], 60).'+'.$parts[2];
    }

    /** The lower-case word a sentence uses: "rapid", "blitz", "daily" (PGN Event, record text). */
    public static function word(string $mode): string
    {
        return $mode === ChessGame::CORRESPONDENCE ? 'daily' : $mode;
    }

    private static function rank(string $mode): int
    {
        return match (true) {
            $mode === self::DEFAULT => 0,
            $mode === ChessGame::CORRESPONDENCE => 2,
            default => 1,
        };
    }
}

<?php

namespace App\Support\Chess;

/**
 * Shows a move in the reader's language: German uses K D T L S for king,
 * queen, rook, bishop and knight (`Lg2` for `Bg2`). Stored moves, PGN and
 * Nostr events stay in English SAN; this only changes what is displayed.
 * resources/js/sanNotation.js is the client twin.
 */
final class SanNotation
{
    /** @var array<string, array<string, string>> */
    private const LETTERS = [
        'de' => ['K' => 'K', 'Q' => 'D', 'R' => 'T', 'B' => 'L', 'N' => 'S'],
    ];

    public static function display(string $san, ?string $locale = null): string
    {
        $letters = self::LETTERS[substr($locale ?? app()->getLocale(), 0, 2)] ?? null;

        if ($letters === null) {
            return $san;
        }

        // Only the leading piece letter and a promotion piece are pieces; files are lowercase.
        return (string) preg_replace_callback('/^[KQRBN]|=[QRBN]/', fn (array $match): string => strtr($match[0], $letters), $san);
    }
}

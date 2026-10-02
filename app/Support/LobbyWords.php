<?php

namespace App\Support;

use RuntimeException;

/**
 * Readable lobby names and passwords from Bitcoin words (user 2026-10-02:
 * "ALLE generierten Lobby Daten bitte mit für menschlich besser lesbaren
 * Name und Passwort. Also Wörter bitte", "Gerne an Bitcoin Wörter
 * herangeführt"). The words live in resources/data/lobby-words.json, the one
 * list the league reads here and the room chat imports
 * (resources/js/lobbyCards.js): lowercase a–z, 3 to 8 letters.
 *
 * A name is the league's "21", a word and the match's numbers: the word
 * follows from the numbers, so the same match or lobby always gets the same
 * name, and the numbers keep it unique. A password is two random words and
 * two digits. Names and passwords stored before stay as they are.
 */
final class LobbyWords
{
    /** The longest name or password, as easy to type in a game's lobby field. */
    public const MAX_LENGTH = 20;

    /**
     * The word list. An unreadable or empty list throws: no lobby is named or
     * locked with a guess.
     *
     * @return non-empty-list<string>
     */
    public static function all(): array
    {
        $json = @file_get_contents(resource_path('data/lobby-words.json'));
        $words = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($words) || $words === [] || ! array_is_list($words)) {
            throw new RuntimeException('The lobby word list resources/data/lobby-words.json is missing or not a list.');
        }

        return array_map(strval(...), $words);
    }

    /** A fresh password: "mempool-halving-42", two words and two digits, each drawn uniformly. */
    public static function password(): string
    {
        $words = self::all();
        $last = count($words) - 1;

        return $words[random_int(0, $last)].'-'.$words[random_int(0, $last)].'-'.sprintf('%02d', random_int(0, 99));
    }

    /** The name of a series or casual match's lobby: "21-satoshi-53" (match 53). */
    public static function matchName(int $number): string
    {
        return self::name("match:{$number}", (string) $number);
    }

    /** The name of a tournament lobby: "21-hodl-42-2" (tournament 42, lobby 2). */
    public static function lobbyName(int $tournamentId, int $position): string
    {
        return self::name("lobby:{$tournamentId}:{$position}", $tournamentId.'-'.$position);
    }

    /**
     * "21-<word>-<numbers>" with the word picked by `$key`; numbers too long
     * for a word drop the word, and only what still exceeds
     * {@see MAX_LENGTH} is cut.
     */
    private static function name(string $key, string $numbers): string
    {
        $words = self::all();
        $named = '21-'.$words[crc32($key) % count($words)].'-'.$numbers;

        return strlen($named) <= self::MAX_LENGTH ? $named : substr('21-'.$numbers, 0, self::MAX_LENGTH);
    }
}

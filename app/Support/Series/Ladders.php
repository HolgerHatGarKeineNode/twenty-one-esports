<?php

namespace App\Support\Series;

use App\Support\Nostr\NostrKeys;

/**
 * Where rated play would be recorded: the ladder address of a game and mode
 * (NIP kind 32152, `d` = `<game>/<mode>/<season>`), or null while no ladder
 * is open. Before Block 0 there is none, so every series is casual.
 *
 * P7 replaces this seam with the ladders of the released season.
 */
final class Ladders
{
    public const KIND = 32152;

    public static function address(string $game, string $mode): ?string
    {
        $pubkey = config('esports.ladder.league_pubkey');
        $season = config('esports.ladder.season');

        if (! NostrKeys::isHexPubkey($pubkey) || ! is_string($season) || $season === '') {
            return null;
        }

        return self::KIND.':'.$pubkey.':'.$game.'/'.$mode.'/'.$season;
    }

    public static function isOpen(string $game, string $mode): bool
    {
        return self::address($game, $mode) !== null;
    }
}

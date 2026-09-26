<?php

namespace App\Support\Series;

use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;

/**
 * Where rated play is recorded: the ladder address of a game and mode (NIP
 * kind 32152, `d` = `<game>/<mode>/<season>`, signed by the league key), or
 * null while no ladder is open. A ladder is open while a released chain
 * season is live ({@see Seasons}); before Block 0 and between seasons there
 * is none, so every match is casual.
 */
final class Ladders
{
    public const KIND = 32152;

    public static function address(string $game, string $mode): ?string
    {
        $season = Seasons::live();

        if ($season === null || ! NostrKeys::isHexPubkey($season->league_pubkey)) {
            return null;
        }

        return self::KIND.':'.$season->league_pubkey.':'.$game.'/'.$mode.'/'.$season->slug;
    }

    public static function isOpen(string $game, string $mode): bool
    {
        return self::address($game, $mode) !== null;
    }

    /** The slug of the live season, or null. */
    public static function season(): ?string
    {
        return Seasons::live()?->slug;
    }
}

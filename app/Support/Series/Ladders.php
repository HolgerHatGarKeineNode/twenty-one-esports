<?php

namespace App\Support\Series;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;

/**
 * Where rated play is recorded: the ladder address of a game and mode (NIP
 * kind 32152, `d` = `<game>/<mode>/<season>`, signed by the league key), or
 * null while no ladder is open. A ladder is open while a released chain
 * season is live ({@see Seasons}); before Block 0 and between seasons there
 * is none, so every match is casual. A board game other than chess has none
 * until it joins the season chain (plan "Mühle und Dame", P6).
 */
final class Ladders
{
    public const KIND = 32152;

    /**
     * The open ladder now, or the one that was open at `$at` (a tournament
     * re-derives its frozen ladder from its first publish time).
     */
    public static function address(string $game, string $mode, ?CarbonImmutable $at = null): ?string
    {
        // A board game other than chess has no rated ladder before it mines (plan "Mühle und Dame", P6):
        // closed, so nothing of it is rated, pinned or published as a season ladder (fail closed).
        if (in_array($game, BoardGame::RESERVED_SLUGS, true) || app(GameRegistry::class)->isBoard($game)) {
            return null;
        }

        $season = Seasons::live($at);

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

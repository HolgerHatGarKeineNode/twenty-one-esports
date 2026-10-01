<?php

namespace App\Support\Series;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\NostrEvent;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;

/**
 * Where rated play is recorded: the ladder address of a game and mode (NIP
 * kind 32152, `d` = `<game>/<mode>/<season>`, signed by the league key), or
 * null while no ladder is open. A ladder is open while a released chain
 * season is live ({@see Seasons}); before Block 0 and between seasons there
 * is none, so every match is casual.
 *
 * A board game other than chess (plan "Mühle und Dame", P6) has one while it
 * is switched on AND the league has published the first version of its
 * ladder in the live season (NIP rev. 9.13): Block 0 publishes it with every
 * other ladder, and in a season released before the board games joined, the
 * first parameter change after it does (LadderEvents::publish()). Until then
 * nothing of it is rated, so no attestation names a ladder that relays do
 * not have. A switched-off board game has none (fail closed).
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
        // A score game (plan "AoE2 und Trackmania", P4) has no Elo ladder: nothing of it is rated or pinned.
        if (app(GameRegistry::class)->isScore($game)) {
            return null;
        }

        $board = in_array($game, BoardGame::RESERVED_SLUGS, true) || app(GameRegistry::class)->isBoard($game);

        // A board game that is switched off (reserved, not in the registry) has no ladder (fail closed):
        // nothing of it is rated, pinned or published as a season ladder.
        if ($board && ! app(GameRegistry::class)->isBoard($game)) {
            return null;
        }

        $season = Seasons::live($at);

        if ($season === null || ! NostrKeys::isHexPubkey($season->league_pubkey)) {
            return null;
        }

        $address = self::KIND.':'.$season->league_pubkey.':'.$game.'/'.$mode.'/'.$season->slug;

        return ! $board || self::published($address, $season->league_pubkey, $game.'/'.$mode.'/'.$season->slug, $at) ? $address : null;
    }

    /**
     * Whether the league had signed a version of this ladder by `$at` (now
     * when null): a board game's ladder opens with it (P6). A tournament that
     * re-derives its frozen ladder from its first publish time gets none
     * that did not exist then. Kept once per request or job when it has, for
     * now only: a published ladder stays published.
     */
    private static function published(string $address, string $pubkey, string $d, ?CarbonImmutable $at = null): bool
    {
        $attributes = request()->attributes;
        $memo = 'ladder-published.'.$address;

        if ($at === null && $attributes->get($memo) === true) {
            return true;
        }

        $published = NostrEvent::query()->where(['kind' => self::KIND, 'pubkey' => $pubkey, 'd' => $d])
            ->when($at !== null, fn ($query) => $query->where('signed_at', '<=', $at?->getTimestamp()))
            ->exists();

        if ($published && $at === null) {
            $attributes->set($memo, true);
        }

        return $published;
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

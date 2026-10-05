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
 * Every ladder opens only once the league has published its first version
 * in the live season (NIP rev. 9.13 for the board games, rev. 9.22 for chess
 * rapid and any mode that joins later): Block 0 publishes every ladder of the
 * registry, and in a season released before a game or mode joined, the first
 * parameter change after it does (LadderEvents::publish()). Until then
 * nothing of it is rated, so no attestation names a ladder that relays do
 * not have (plan "Schach Rapid und Clan", P1: chess rapid in a live season).
 * A board game other than chess (plan "Mühle und Dame", P6) has one only
 * while it is switched on; a switched-off board game has none (fail closed).
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

        return self::published($season->league_pubkey, $game.'/'.$mode.'/'.$season->slug, $at) ? $address : null;
    }

    /**
     * Whether the league had signed a version of this ladder by `$at` (now
     * when null): every ladder opens with it. A tournament that
     * re-derives its frozen ladder from its first publish time gets none
     * that did not exist then.
     *
     * Every ladder of the season the league signed, with the time of its
     * first version, is read once per request or job (one query, whatever the
     * number of ladders and moments a page asks about), and a published
     * ladder stays published. A ladder missing from that read is asked again
     * on its own, since it may be published later in the same request or job
     * (a parameter change); it is never kept as closed.
     */
    private static function published(string $pubkey, string $d, ?CarbonImmutable $at = null): bool
    {
        $query = fn () => NostrEvent::query()->where(['kind' => self::KIND, 'pubkey' => $pubkey]);
        $attributes = request()->attributes;
        $season = substr($d, (int) strrpos($d, '/') + 1);
        $memo = 'ladders-published.'.$pubkey.'.'.$season;
        $first = $attributes->get($memo);
        $fresh = ! is_array($first);

        if ($fresh) {
            /** @var array<string, int> $first */
            $first = $query()->where('d', 'like', '%/'.$season)->groupBy('d')->selectRaw('d, min(signed_at) as first_signed_at')
                ->pluck('first_signed_at', 'd')->map(fn ($signedAt): int => (int) $signedAt)->all();
            $attributes->set($memo, $first);
        }

        if (! isset($first[$d])) {
            $signedAt = $fresh ? null : $query()->where('d', $d)->min('signed_at');

            if ($signedAt === null) {
                return false;
            }

            $first[$d] = (int) $signedAt;
            $attributes->set($memo, $first);
        }

        return $at === null || $first[$d] <= $at->getTimestamp();
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

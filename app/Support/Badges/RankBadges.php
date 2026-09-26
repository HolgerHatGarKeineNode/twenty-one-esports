<?php

namespace App\Support\Badges;

use App\Games\GameRegistry;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\Rating;
use App\Models\User;
use App\Support\Rating\RankTiers;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * The NIP-58 rank badges (docs/nips/esports.md, "Rank badges"): one
 * definition per player, game and mode (`30009`, `d` =
 * `rank/<game>/<mode>/<pubkey>`), signed by the badge key and replaced on
 * every rank change, and exactly one award (`8`) per definition.
 *
 * A reconciler, not an event handler: sync() reads the player's tier on the
 * RATED ladder of the live season and signs a new version only when tier or
 * season differ from the newest one it signed. Running it twice, or for a
 * result that moved the rating inside the same tier, signs nothing. The
 * casual ladder has no tiers and is never read, so casual play never gives
 * a badge; a provisional player keeps the last tier (NIP: no badge for
 * `provisional`, and in a new season the definition keeps the last tier
 * until the first tier of that season replaces it).
 *
 * The tier of a lineup ladder (Rocket League 2v2, 3v3) is the tier of the
 * lineup the player holds an active seat in (NIP: "the tier of the lineup
 * the player plays for"); a player ladder (chess, RL 1v1) rates the player.
 *
 * Fail closed: without the badge key (`esports.badges.nsec`) or outside a
 * live season nothing is signed.
 */
final class RankBadges
{
    /**
     * The players whose tier may have moved with a rated result: the rated
     * entities (`user:<id>`, `lineup:<id>`) of that result.
     *
     * @param  list<string>  $subjects
     */
    public function syncSubjects(string $game, string $mode, array $subjects): void
    {
        $users = [];

        foreach ($subjects as $subject) {
            [$kind, $id] = array_pad(explode(':', $subject, 2), 2, '0');

            if ($kind === 'user') {
                $users[] = (int) $id;
            } elseif ($kind === 'lineup') {
                $lineup = Lineup::query()->with('seats')->find((int) $id);

                foreach ($lineup?->activeSeats() ?? [] as $seat) {
                    $users[] = $seat->user_id;
                }
            }
        }

        foreach (User::query()->whereKey(array_unique($users))->orderBy('id')->get() as $user) {
            $this->sync($user, $game, $mode);
        }
    }

    /**
     * Bring the player's badge for this game and mode in line with the
     * rated ladder. Returns the new version, or null when nothing was signed.
     */
    public function sync(User $user, string $game, string $mode): ?RankBadgeVersion
    {
        $key = LeagueKey::badge();
        $season = Ladders::season();

        if ($key === null || $season === null || ! Ladders::isOpen($game, $mode)) {
            return null;
        }

        $standing = $this->standing($user, $game, $mode, $season);

        if ($standing === null || $standing['tier'] === RankTiers::Provisional) {
            return null;
        }

        return DB::transaction(function () use ($key, $user, $game, $mode, $season, $standing): ?RankBadgeVersion {
            $d = 'rank/'.$game.'/'.$mode.'/'.$user->pubkey;
            RankBadge::query()->insertOrIgnore([[
                'user_id' => $user->id, 'pubkey' => $user->pubkey, 'game' => $game, 'mode' => $mode, 'd' => $d,
                'badge_pubkey' => $key->pubkey(), 'tier' => RankTiers::Provisional, 'season' => '',
                'created_at' => now(), 'updated_at' => now(),
            ]]);

            // One writer per definition: versions and the single award follow the lock order.
            $badge = RankBadge::query()->where('d', $d)->lockForUpdate()->firstOrFail();

            if ($badge->tier === $standing['tier'] && $badge->season === $season && $badge->badge_pubkey === $key->pubkey()) {
                return null;
            }

            $last = $badge->versions()->max('signed_at');
            $at = max(now()->getTimestamp(), $last === null ? 0 : (int) $last + 1);
            $previous = $badge->definition_event_id === null ? null : $badge->tier;

            // A new badge key makes a new definition address: it needs its own award.
            if ($badge->badge_pubkey !== $key->pubkey()) {
                $badge->forceFill(['badge_pubkey' => $key->pubkey(), 'award_event_id' => null]);
            }

            $definition = $key->publish(RankBadge::DEFINITION, $this->definitionTags($badge, $user, $standing['tier'], $season), '', $at);

            $version = $badge->versions()->create([
                'tier' => $standing['tier'],
                'previous_tier' => $previous,
                'season' => $season,
                'rating' => $standing['rating'],
                'signed_at' => $at,
                'nostr_event_id' => $definition->id,
            ]);

            $badge->forceFill(['tier' => $standing['tier'], 'season' => $season, 'definition_event_id' => $definition->id]);

            // Exactly once per definition (NIP: "Never re-awarded").
            if ($badge->award_event_id === null) {
                $award = $key->publish(RankBadge::AWARD, [
                    ['a', $badge->address()],
                    ['p', $user->pubkey],
                    ['alt', 'Badge award: '.$this->inEnglish(fn (): string => BadgeCopy::ladder($game, $mode)).' rank in TWENTY ONE Esports'],
                ], '', $at);
                $badge->award_event_id = $award->id;
            }

            $badge->save();

            return $version;
        });
    }

    /**
     * The badge image of a tier: a function of game, tier and artwork version
     * only, so every player of a tier shares one URL and clients that cache
     * by URL show a new rank at once (NIP "Image URL per rank").
     */
    public static function imageUrl(string $game, string $tier, bool $thumb = false): string
    {
        return rtrim((string) config('app.url'), '/').'/badges/rank/'.$game.'/'.$tier.'-v'.(int) config('esports.badges.artwork').($thumb ? '-256' : '').'.png';
    }

    /**
     * @return list<list<string>>
     */
    private function definitionTags(RankBadge $badge, User $user, string $tier, string $season): array
    {
        return $this->inEnglish(fn (): array => [
            ['d', $badge->d],
            ['name', BadgeCopy::name($badge->game, $badge->mode, $tier)],
            ['description', BadgeCopy::description($badge->game, $badge->mode, $tier, $season)],
            ['image', self::imageUrl($badge->game, $tier), '1024x1024'],
            ['thumb', self::imageUrl($badge->game, $tier, true), '256x256'],
            ['p', $user->pubkey],
            ['a', (string) Ladders::address($badge->game, $badge->mode)],
            ['alt', 'Badge: '.BadgeCopy::name($badge->game, $badge->mode, $tier).' in TWENTY ONE Esports'],
        ]);
    }

    /**
     * The player's rated standing in the live season, or null without a
     * rated row (no rated result yet).
     *
     * @return array{tier: string, rating: int}|null
     */
    private function standing(User $user, string $game, string $mode, string $season): ?array
    {
        $rates = app(GameRegistry::class)->mode($game, $mode)?->rates;

        if ($rates === null) {
            return null;
        }

        $subject = 'user:'.$user->id;

        if ($rates === 'lineup') {
            $seat = LineupSeat::query()->where('user_id', $user->id)->whereNotNull('accepted_at')
                ->whereHas('lineup', fn ($query) => $query->where(['game' => $game, 'mode' => $mode]))
                ->with('lineup')->get()
                ->first(fn (LineupSeat $seat): bool => $seat->isActive($seat->lineup->clan_id));

            if ($seat === null) {
                return null;
            }

            $subject = 'lineup:'.$seat->lineup_id;
        }

        $rating = Rating::query()->where([
            'pool' => Rating::RATED, 'season' => $season, 'game' => $game, 'mode' => $mode, 'subject' => $subject,
        ])->first();

        return $rating === null ? null : [
            'tier' => RankTiers::fromConfig()->tierFor($rating->rating, $rating->results),
            'rating' => $rating->rating,
        ];
    }

    /**
     * Signed text is public and permanent: always English, whatever the
     * locale of the request that triggered it.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function inEnglish(\Closure $callback): mixed
    {
        $locale = App::getLocale();
        App::setLocale('en');

        try {
            return $callback();
        } finally {
            App::setLocale($locale);
        }
    }
}

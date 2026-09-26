<?php

namespace App\Support\Badges;

use App\Models\NostrEvent;
use App\Models\QuestBadgeAward;
use App\Models\RankBadge;
use App\Models\User;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Support\Facades\DB;

/**
 * Quest badges (NIP "Rank badges", Limits): ordinary NIP-58 definitions with
 * `d` = `quest/<slug>`, one per quest and shared by everyone, signed by the
 * badge key, and one award per player and quest.
 *
 * The interface P10's quests call once a quest is complete. P10 is not built
 * yet, so nothing calls award() so far; it is complete and tested on its own.
 * The definition is signed on the first award, and signed again only when its
 * name, description or image change.
 *
 * Fail closed: without the badge key nothing is signed and award() is null.
 */
final class QuestBadges
{
    /**
     * @param  string  $slug  lower-case letters, digits and dashes, e.g. `first-block`
     * @param  string  $image  https URL of the 1024 × 1024 artwork
     */
    public function award(User $user, string $slug, string $name, string $description, string $image): ?QuestBadgeAward
    {
        $key = LeagueKey::badge();

        if ($key === null || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($key, $user, $slug, $name, $description, $image): QuestBadgeAward {
            QuestBadgeAward::query()->insertOrIgnore([[
                'slug' => $slug, 'user_id' => $user->id, 'pubkey' => $user->pubkey, 'created_at' => now(), 'updated_at' => now(),
            ]]);
            $award = QuestBadgeAward::query()->where(['slug' => $slug, 'pubkey' => $user->pubkey])->lockForUpdate()->firstOrFail();

            if ($award->award_event_id !== null) {
                return $award;
            }

            $d = 'quest/'.$slug;
            $tags = [
                ['d', $d],
                ['name', $name],
                ['description', $description],
                ['image', $image, '1024x1024'],
                ['alt', 'Badge: '.$name.' in TWENTY ONE Esports'],
            ];
            $newest = NostrEvent::query()->where(['kind' => RankBadge::DEFINITION, 'pubkey' => $key->pubkey(), 'd' => $d])->orderByDesc('signed_at')->first();

            if ($newest === null || ($newest->payload()['tags'] ?? null) !== $tags) {
                $key->publish(RankBadge::DEFINITION, $tags, '', max(now()->getTimestamp(), $newest === null ? 0 : $newest->signed_at + 1));
            }

            $event = $key->publish(RankBadge::AWARD, [
                ['a', RankBadge::DEFINITION.':'.$key->pubkey().':'.$d],
                ['p', $user->pubkey],
                ['alt', 'Badge award: '.$name.' in TWENTY ONE Esports'],
            ], '', now()->getTimestamp());

            $award->forceFill(['award_event_id' => $event->id])->save();

            return $award;
        });
    }
}

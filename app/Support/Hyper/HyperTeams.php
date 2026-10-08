<?php

namespace App\Support\Hyper;

use App\Models\Clan;
use App\Models\User;

/**
 * Who a Hyperbitcoinization team is (plan "Hyperbitcoinization", P4). There is one team source: the
 * player's clan (`clan_members`, one clan per player). A clan linked to a meetup (`meetup_name` set on the
 * clan) plays as that meetup: its side shows the meetup's name and city, still with the clan's logo and
 * tag; any other clan plays as itself (user decision 2026-10-09: "Meetup vs Meetup" is not a second
 * affiliation system). A side without a clan is the bots' side.
 *
 * A side as the pages read it: `{side, clan_id, name, tag, meetup, city, logo, url}`; `logo` only when the
 * league stored it itself (Clan::localLogoUrl()), never a foreign picture.
 */
final class HyperTeams
{
    /** Seats alternate between two sides: 2v2 and 3v3, the sizes the balance gate measured. */
    public const array SEATS = [4, 6];

    /**
     * The player's clan, or null.
     */
    public static function clanOf(User $user): ?Clan
    {
        return $user->clanMember()->with('clan')->first()?->clan;
    }

    /**
     * Both sides of a clan table or team match, side 0 first.
     *
     * @param  list<int|null>|null  $teamClans
     * @return list<array{side: int, clan_id: int|null, name: string, tag: string|null, meetup: bool, city: string|null, logo: string|null, url: string|null}>
     */
    public static function sides(?array $teamClans): array
    {
        if ($teamClans === null) {
            return [];
        }

        $clans = Clan::query()->whereIn('id', array_filter($teamClans))->get()->keyBy('id');

        return array_map(fn (int $side): array => self::side($side, $clans->get($teamClans[$side] ?? 0)), [0, 1]);
    }

    /**
     * @return array{side: int, clan_id: int|null, name: string, tag: string|null, meetup: bool, city: string|null, logo: string|null, url: string|null}
     */
    public static function side(int $side, ?Clan $clan): array
    {
        if ($clan === null) {
            return ['side' => $side, 'clan_id' => null, 'name' => __('Bots'), 'tag' => null, 'meetup' => false, 'city' => null, 'logo' => null, 'url' => null];
        }

        $meetup = filled($clan->meetup_name);

        return [
            'side' => $side,
            'clan_id' => $clan->id,
            'name' => $meetup ? (string) $clan->meetup_name : $clan->name,
            'tag' => $clan->clantag,
            'meetup' => $meetup,
            'city' => $meetup ? $clan->meetup_city : null,
            'logo' => $clan->localLogoUrl(),
            'url' => route('clans.show', $clan),
        ];
    }
}

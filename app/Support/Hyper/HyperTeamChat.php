<?php

namespace App\Support\Hyper;

use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\Nostr\PlayerProfile;
use App\Support\Notifications\DmRelays;

/**
 * The private team chat of a Hyperbitcoinization team match (plan "Hyperbitcoinization", P4, Ansatz 6):
 * NIP-17 between the players of one team, written and read in the browser (resources/js/hyper/teamChat.js)
 * with the viewer's own signer, exactly as the match rooms do (nostrChat.js: a kind-14 rumor sealed in 13
 * and gift-wrapped in 1059 per member with NIP-44, `match` = `hyper:<ulid>`). The league never sees a
 * message; what it hands out is who the team is.
 *
 * members() answers only a player who sits in the match for that team (and has not left it): the team's
 * players with a Nostr key, their league names and avatars. Opponents, spectators and a player who left
 * get nothing (`not_teammate`), so they learn neither the team's keys nor where its wraps go. Bots have
 * no key and get no message; a seat a bot took over after timeouts still belongs to its player.
 */
final class HyperTeamChat
{
    /**
     * @return array{match: string, since: int|null, relays: list<string>, lookupRelays: list<string>, members: list<array{seat: int, pubkey: string, name: string, avatar: string}>}
     *
     * @throws HyperRuleViolation `no_teams` for a match without teams, `not_teammate` for anybody not seated in it
     */
    public static function members(HyperMatch $match, User $viewer): array
    {
        if (! $match->isTeamMatch()) {
            throw new HyperRuleViolation('no_teams', 'This match has no teams.');
        }

        $match->loadMissing('seats.user');
        $mine = $match->seatOf($viewer);

        if ($mine === null || $mine->team === null || $mine->left_at !== null || ! self::hasKey($mine)) {
            throw new HyperRuleViolation('not_teammate', 'Only a player of the team reads its chat.');
        }

        $members = $match->seats
            ->filter(fn (HyperSeat $seat): bool => $seat->team === $mine->team && $seat->left_at === null && self::hasKey($seat))
            ->map(function (HyperSeat $seat): array {
                $user = $seat->user;
                assert($user instanceof User);

                return ['seat' => $seat->seat, 'pubkey' => $user->pubkey, 'name' => $user->displayName(), 'avatar' => $user->avatarUrl() ?? PlayerProfile::generatedAvatarUrl($user->pubkey)];
            });

        return [
            'match' => self::tag($match),
            'since' => $match->created_at?->getTimestamp(),
            'relays' => array_values((array) config('esports.chat.relays', [])),
            'lookupRelays' => DmRelays::lookupRelays(),
            'members' => array_values($members->all()),
        ];
    }

    /**
     * The rumors' `match` tag: the match's own, never a league match number.
     */
    public static function tag(HyperMatch $match): string
    {
        return 'hyper:'.$match->ulid;
    }

    private static function hasKey(HyperSeat $seat): bool
    {
        return $seat->user !== null && preg_match('/^[0-9a-f]{64}$/', $seat->user->pubkey) === 1;
    }
}

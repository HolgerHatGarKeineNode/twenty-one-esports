<?php

namespace App\Support\Engagement;

use App\Models\Cosmetic;
use App\Models\InviteLinkUse;
use App\Models\User;

/**
 * Cosmetics (P10): looks a player owns, never an input to ratings, trust,
 * blocks or rewards (NIP "Invite links": referrals are "kept by the league
 * for cosmetic perks").
 *
 * A player owns each cosmetic at most once: the unique (player, cosmetic)
 * makes a second grant a no-op.
 */
final class Cosmetics
{
    /** The "Brought a friend" frame: both sides of an accepted invite link. */
    public const INVITE_FRAME = 'invite-frame';

    /**
     * @return bool whether the player got it now (false: owned already)
     */
    public function grant(int $userId, string $cosmetic, string $source): bool
    {
        return Cosmetic::query()->insertOrIgnore([
            'user_id' => $userId,
            'cosmetic' => $cosmetic,
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * An accepted invite link credits the frame to the inviter and to the
     * player who took the link.
     */
    public function creditInvite(InviteLinkUse $use): void
    {
        foreach ([$use->inviter_id, $use->user_id] as $userId) {
            $this->grant($userId, self::INVITE_FRAME, 'invite:'.$use->id);
        }
    }

    public function owns(?User $user, string $cosmetic): bool
    {
        return $user !== null && Cosmetic::query()->where('user_id', $user->id)->where('cosmetic', $cosmetic)->exists();
    }
}

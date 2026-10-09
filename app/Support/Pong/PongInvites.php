<?php

namespace App\Support\Pong;

use App\Enums\BoardInviteStatus;
use App\Events\PongInviteChanged;
use App\Events\PongMatchStarted;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\PongInvite;
use App\Models\PongMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\Broadcasts;
use App\Support\Moderation\SiteModeration;
use App\Support\Series\CasualInvites;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invites to a live Proof of Pong match (plan "Proof of Pong", P2), built after App\Support\Board\BoardInvites:
 *
 * - only a player whose "Looking to play" is on for Proof of Pong (`users.looking_to_play` = LOOKING) can be invited
 *   (`not_looking` otherwise); turning it off declines the invites still open (declineAll());
 * - one open invite per inviter: a new one withdraws the previous, and an inviter stops searching anywhere else;
 * - one live game at a time (busy()): nobody in a running Proof of Pong match, a live chess or board game or a casual
 *   1v1 invites or accepts (BoardQueue::assertFree's rule, plus Proof of Pong). An inviter who plays by the time the
 *   invite is accepted cannot start it any more, so it is withdrawn (`opponent_playing`);
 * - accepting starts the match with the sides drawn (PongMatches::create()), which opens it for both players; both
 *   players' rows are locked first (PongMatches::lockPlayers()), so two accepts at once cannot give one player two
 *   matches.
 */
final class PongInvites
{
    /** The `users.looking_to_play` value of Proof of Pong. */
    public const string LOOKING = 'proof-of-pong/live';

    public function __construct(private PongMatches $matches, private CasualInvites $casualInvites) {}

    /**
     * Why this player cannot start a live match now, or null: `already_playing` (a Proof of Pong match runs),
     * `playing_elsewhere` (a live chess or board game, or a casual 1v1).
     */
    public static function busy(User $user): ?string
    {
        if (PongMatches::activeMatchOf($user) !== null) {
            return 'already_playing';
        }

        try {
            BoardQueue::assertFree(app(BoardGameService::class), $user);
        } catch (BoardRuleViolation) {
            return 'playing_elsewhere';
        }

        return null;
    }

    /**
     * @throws PongRuleViolation
     */
    public function invite(User $inviter, User $invitee): PongInvite
    {
        if ($inviter->is($invitee)) {
            throw new PongRuleViolation('invite_self');
        }

        // A key banned from the site takes part in nothing (SiteModeration); the page says only that it did not work.
        if (SiteModeration::isBanned($invitee->pubkey) || SiteModeration::isBanned($inviter->pubkey)) {
            throw new PongRuleViolation('not_available');
        }

        if (($busy = self::busy($inviter)) !== null) {
            throw new PongRuleViolation($busy);
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $invitee, $previous): PongInvite|string {
            // Read fresh and locked, so a switch turned off a moment ago is seen.
            if (User::query()->whereKey($invitee->id)->lockForUpdate()->value('looking_to_play') !== self::LOOKING) {
                return 'not_looking';
            }

            $previous?->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
            // One intent at a time: an inviter stops searching anywhere.
            BoardQueueEntry::query()->where('user_id', $inviter->id)->delete();
            ChessQueueEntry::query()->where('user_id', $inviter->id)->delete();
            SeriesQueueEntry::query()->where('user_id', $inviter->id)->delete();

            return PongInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'status' => BoardInviteStatus::Pending,
                'expires_at' => now()->addSeconds((int) config('esports.pong.invite_seconds', 120)),
            ]);
        });

        if (is_string($invite)) {
            throw new PongRuleViolation($invite, __(':name is not looking for a game right now.', ['name' => $invitee->displayName()]));
        }

        if ($previous !== null) {
            $this->announce($previous);
        }

        $this->casualInvites->withdrawOutgoing($inviter);
        $this->announce($invite);

        return $invite;
    }

    /**
     * The invitee accepts: the match starts, its sides drawn.
     *
     * @throws PongRuleViolation
     */
    public function accept(PongInvite $invite, User $invitee): PongMatch
    {
        $result = DB::transaction(function () use ($invite, $invitee): PongMatch|string {
            $invite = PongInvite::query()->lockForUpdate()->findOrFail($invite->id);

            if ($invite->invitee_id !== $invitee->id || ! $invite->isOpen()) {
                return 'invite_closed';
            }

            // Both players locked before they are checked: a second start for either waits and then sees this match.
            PongMatches::lockPlayers($invite->inviter, $invitee);

            if (self::busy($invite->inviter) !== null) {
                $invite->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
                $this->announce($invite);

                return 'opponent_playing';
            }

            if (($busy = self::busy($invitee)) !== null) {
                return $busy;
            }

            [$left, $right] = random_int(0, 1) === 0 ? [$invite->inviter, $invitee] : [$invitee, $invite->inviter];
            $match = $this->matches->create($left, $right);
            $invite->forceFill(['status' => BoardInviteStatus::Accepted, 'pong_match_id' => $match->id])->save();
            $this->announce($invite);

            return $match;
        });

        if (! $result instanceof PongMatch) {
            throw new PongRuleViolation($result);
        }

        // The invitee's accept opens the match in its own tab; the inviter's lobby hears of it and opens it too.
        Broadcasts::send(new PongMatchStarted($result->ulid, route('pong.match', $result), [$invite->inviter_id]));

        return $result;
    }

    /**
     * The invitee declines, or the inviter withdraws. Only a still pending row changes.
     *
     * @throws PongRuleViolation
     */
    public function close(PongInvite $invite, User $user): void
    {
        $status = match ($user->id) {
            $invite->invitee_id => BoardInviteStatus::Declined,
            $invite->inviter_id => BoardInviteStatus::Withdrawn,
            default => throw new PongRuleViolation('invite_closed'),
        };

        $closed = PongInvite::query()->whereKey($invite->id)->where('status', BoardInviteStatus::Pending)
            ->update(['status' => $status, 'updated_at' => now()]);

        $invite->refresh();

        if ($closed > 0) {
            $this->announce($invite);
        }
    }

    /** The invitee turned "Looking to play" off: every open invite to them is declined. */
    public function declineAll(User $invitee): void
    {
        foreach ($this->incoming($invitee) as $invite) {
            $this->close($invite, $invitee);
        }
    }

    public function withdrawOutgoing(User $inviter): void
    {
        $invite = $this->outgoing($inviter);

        if ($invite !== null) {
            $this->close($invite, $inviter);
        }
    }

    public function outgoing(User $inviter): ?PongInvite
    {
        return PongInvite::query()
            ->where('inviter_id', $inviter->id)
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('invitee')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, PongInvite>
     */
    public function incoming(User $invitee): Collection
    {
        return PongInvite::query()
            ->where('invitee_id', $invitee->id)
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    private function announce(PongInvite $invite): void
    {
        Broadcasts::send(new PongInviteChanged($invite->id, $invite->status->value, $invite->inviter_id, $invite->invitee_id));
    }
}

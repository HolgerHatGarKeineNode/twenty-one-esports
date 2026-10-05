<?php

namespace App\Support\Series;

use App\Enums\ChessInviteStatus;
use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Events\SeriesInviteChanged;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Moderation\SiteModeration;
use App\Support\Notifications\CasualNotifications;
use App\Support\Tournaments\CupMatchNow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A direct casual 1v1 invite (P23), as the blitz invite (ChessInvites):
 *
 * - only a player whose "Looking to play" is `<game>/1v1` can be invited
 *   (`not_looking`), read fresh in the invite's transaction;
 * - one open invite per inviter: a new one withdraws the previous, and an
 *   inviter leaves the queue (one intent at a time);
 * - open for `esports.casual.invite_seconds`, then simply past its time;
 * - an invite to a player who is searching the same game on a platform
 *   that fits starts the match at once, as a found match;
 * - accept and decline race on the invite row: each is a conditional
 *   update on `pending`, so exactly one of them wins, and a stale copy
 *   never overwrites the winner;
 * - crossing invites (A -> B and B -> A, two "Rematch" clicks) close each
 *   other: the accept of one withdraws the other in its transaction, and
 *   the match's claim on both players refuses a second match either way
 *   (CasualMatches::create()).
 *
 * The inviter gives their platform and crossplay when inviting, the invitee
 * when accepting; platforms that cannot play each other are refused
 * (`platforms_incompatible`). The accepted invite starts the ready check.
 */
final class CasualInvites
{
    public function __construct(private CasualMatches $matches, private CasualNotifications $notifications) {}

    /**
     * Returns the invite; its `series_match_id` is set when the invitee was
     * searching and the match has already been made.
     *
     * @throws SeriesRuleViolation
     */
    public function invite(User $inviter, User $invitee, string $game, Platform $platform, bool $crossplay): SeriesInvite
    {
        return $this->send($inviter, $invitee, $game, $platform, $crossplay, (int) config('esports.casual.invite_seconds'), lookingOnly: true);
    }

    /**
     * "Rematch" in the room of a finished casual 1v1 (P23 S3): a direct
     * invite to the opponent of that match, open for
     * `esports.casual.rematch_seconds`, with the platforms both played on.
     * It needs no "Looking to play": the two just played each other.
     * Only within `esports.casual.rematch_minutes` of the result, and only
     * for a match with a result (a void one has none). A tournament series
     * has none at all: the tournament decides the next match.
     *
     * @throws SeriesRuleViolation
     */
    public function rematch(SeriesMatch $match, User $inviter): SeriesInvite
    {
        $side = collect(SeriesMatch::SIDES)->first(fn (string $side): bool => $match->isRosterSideMember($side, $inviter));
        $opponentId = $side === null ? null : ($match->rosterSide(SeriesMatch::otherSide($side))[0] ?? null);
        $finished = $match->finished_at;

        if (! $match->isCasualPairing() || $side === null || $opponentId === null) {
            throw CasualMatches::refuse('not_player');
        }

        if ($match->tournament_match_id !== null || $match->origin === SeriesMatch::ORIGIN_CUP) {
            throw CasualMatches::refuse('tournament_rematch');
        }

        if (! $match->status->hasResult() || $match->resolution === SeriesResolution::Void || $finished === null
            || $finished->lt(now()->subMinutes((int) config('esports.casual.rematch_minutes')))) {
            throw CasualMatches::refuse('rematch_closed');
        }

        $choice = (array) ($match->casual['queue'][$side] ?? []);
        $platform = Platform::tryFrom((string) ($choice['platform'] ?? '')) ?? $inviter->platform ?? Platform::Pc;

        return $this->send($inviter, User::query()->findOrFail($opponentId), $match->game, $platform, (bool) ($choice['crossplay'] ?? true),
            (int) config('esports.casual.rematch_seconds'), lookingOnly: false);
    }

    /**
     * @throws SeriesRuleViolation
     */
    private function send(User $inviter, User $invitee, string $game, Platform $platform, bool $crossplay, int $seconds, bool $lookingOnly): SeriesInvite
    {
        if ($inviter->is($invitee)) {
            throw CasualMatches::refuse('invite_self');
        }

        // A key banned from the site takes part in nothing (SiteModeration).
        if (SiteModeration::isBanned($invitee->pubkey) || SiteModeration::isBanned($inviter->pubkey)) {
            throw CasualMatches::refuse('not_available');
        }

        $this->matches->assertGame($game);
        $this->matches->assertMayPlay($inviter);

        // The casual lock (user, 2026-10-03): the invitee's open cup match comes first.
        if (CupMatchNow::lockOf($invitee) !== null) {
            throw CasualMatches::refuse(CupMatchNow::OTHER_LOCKED, ['name' => $invitee->displayName()]);
        }

        $previous = $this->outgoing($inviter);
        $mode = CasualMatches::mode();

        $invite = DB::transaction(function () use ($inviter, $invitee, $game, $mode, $platform, $crossplay, $previous, $seconds, $lookingOnly): SeriesInvite|string {
            $looking = User::query()->whereKey($invitee->id)->lockForUpdate()->value('looking_to_play');

            if ($lookingOnly && $looking !== $game.'/'.$mode) {
                return 'not_looking';
            }

            if ($previous !== null) {
                SeriesInvite::query()->whereKey($previous->id)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn, 'updated_at' => now()]);
            }

            SeriesQueueEntry::query()->where('user_id', $inviter->id)->delete();

            return SeriesInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'game' => $game,
                'mode' => $mode,
                'platform' => $platform,
                'crossplay' => $crossplay,
                'status' => ChessInviteStatus::Pending,
                'expires_at' => now()->addSeconds($seconds),
            ]);
        });

        if (is_string($invite)) {
            throw CasualMatches::refuse($invite, ['name' => $invitee->displayName()]);
        }

        if ($previous !== null) {
            $this->announce($previous->refresh());
        }

        $this->announce($invite);

        try {
            $this->answer($invite, $invitee, null, null, searchingOnly: true);

            return $invite->refresh();
        } catch (SeriesRuleViolation) {
            // Not searching (the usual case), or on a platform that does not fit: an ordinary open invite.
        }

        $this->notifications->inviteReceived($invite);

        return $invite;
    }

    /**
     * @throws SeriesRuleViolation
     */
    public function accept(SeriesInvite $invite, User $invitee, Platform $platform, bool $crossplay): SeriesMatch
    {
        return $this->answer($invite, $invitee, $platform, $crossplay, searchingOnly: false);
    }

    /**
     * Makes the invite's match. `searchingOnly`: only if the invitee is in
     * the queue for the invite's game, with the platform they search on. A
     * refusal is returned from the transaction rather than thrown, so a
     * withdrawal made on the way still commits.
     *
     * @throws SeriesRuleViolation
     */
    private function answer(SeriesInvite $invite, User $invitee, ?Platform $platform, ?bool $crossplay, bool $searchingOnly): SeriesMatch
    {
        $result = DB::transaction(function () use ($invite, $invitee, $platform, $crossplay, $searchingOnly): SeriesMatch|string {
            $invite = SeriesInvite::query()->with('inviter')->lockForUpdate()->findOrFail($invite->id);

            if ($invite->invitee_id !== $invitee->id || ! $invite->isOpen()) {
                return 'invite_closed';
            }

            if ($searchingOnly) {
                $entry = SeriesQueueEntry::query()->where('user_id', $invitee->id)->where('game', $invite->game)->where('mode', $invite->mode)->first();

                if ($entry === null) {
                    return 'not_searching';
                }

                [$platform, $crossplay] = [$entry->platform, $entry->crossplay];
            }

            if ($platform === null || $crossplay === null) {
                return 'platforms_incompatible';
            }

            if ($this->matches->lockedUntil($invitee) !== null) {
                return 'queue_locked';
            }

            if ($this->matches->busyReason($invite->inviter) !== null) {
                SeriesInvite::query()->whereKey($invite->id)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn, 'updated_at' => now()]);
                $this->announce($invite->refresh());

                return 'opponent_playing';
            }

            if (($busy = $this->matches->busyReason($invitee)) !== null) {
                return $busy === CupMatchNow::LOCKED ? $busy : 'accept_while_playing';
            }

            if (! CasualMatches::compatible($invite->game, $invite->platform, $invite->crossplay, $platform, $crossplay)) {
                return 'platforms_incompatible';
            }

            // The race with a decline or a withdrawal: only a still pending, unexpired row is taken.
            $taken = SeriesInvite::query()->whereKey($invite->id)->where('status', ChessInviteStatus::Pending)->where('expires_at', '>', now())
                ->update(['status' => ChessInviteStatus::Accepted, 'updated_at' => now()]);

            if ($taken !== 1) {
                return 'invite_closed';
            }

            SeriesQueueEntry::query()->whereIn('user_id', [$invite->inviter_id, $invitee->id])->delete();

            $crossing = SeriesInvite::query()->where('inviter_id', $invitee->id)->where('invitee_id', $invite->inviter_id)
                ->where('status', ChessInviteStatus::Pending)->pluck('id');
            SeriesInvite::query()->whereKey($crossing)->where('status', ChessInviteStatus::Pending)->update(['status' => ChessInviteStatus::Withdrawn, 'updated_at' => now()]);

            $match = $this->matches->create($invite->inviter, $invitee, $invite->game, SeriesMatch::ORIGIN_INVITE, [
                'challenger' => ['platform' => $invite->platform->value, 'crossplay' => $invite->crossplay],
                'challenged' => ['platform' => $platform->value, 'crossplay' => $crossplay],
            ], $invite->inviter);

            $invite->forceFill(['series_match_id' => $match->id])->save();
            $this->announce($invite->refresh());
            SeriesInvite::query()->whereKey($crossing)->get()->each(fn (SeriesInvite $withdrawn) => $this->announce($withdrawn));

            return $match;
        });

        return $result instanceof SeriesMatch ? $result : throw CasualMatches::refuse($result);
    }

    /**
     * The invitee declines, or the inviter withdraws. Only a still pending
     * row changes: an accept that committed in the meantime stays.
     *
     * @throws SeriesRuleViolation
     */
    public function close(SeriesInvite $invite, User $user): void
    {
        $status = match ($user->id) {
            $invite->invitee_id => ChessInviteStatus::Declined,
            $invite->inviter_id => ChessInviteStatus::Withdrawn,
            default => throw CasualMatches::refuse('invite_closed'),
        };

        $closed = SeriesInvite::query()->whereKey($invite->id)->where('status', ChessInviteStatus::Pending)
            ->update(['status' => $status, 'updated_at' => now()]);

        $invite->refresh();

        if ($closed === 1) {
            $this->announce($invite);
        }
    }

    /**
     * "Looking to play" no longer names the invites' game: every open invite
     * to this player is declined.
     */
    public function declineAll(User $invitee): void
    {
        foreach ($this->incoming($invitee) as $invite) {
            $this->close($invite, $invitee);
        }
    }

    /** One intent at a time: the player searches or plays something else now. */
    public function withdrawOutgoing(User $inviter): void
    {
        $invite = $this->outgoing($inviter);

        if ($invite !== null) {
            $this->close($invite, $inviter);
        }
    }

    public function outgoing(User $inviter): ?SeriesInvite
    {
        return SeriesInvite::query()
            ->where('inviter_id', $inviter->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('invitee')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, SeriesInvite>
     */
    public function incoming(User $invitee): Collection
    {
        return SeriesInvite::query()
            ->where('invitee_id', $invitee->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->latest('id')
            ->get();
    }

    private function announce(SeriesInvite $invite): void
    {
        Broadcasts::send(new SeriesInviteChanged($invite->id, $invite->status->value, $invite->inviter_id, $invite->invitee_id));
    }
}

<?php

namespace App\Support\Chess;

use App\Enums\ChessInviteStatus;
use App\Events\ChessInviteChanged;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Invite a friend who is online" (plan: one of the two blitz exceptions to
 * the queue). The lobby only offers players it sees on the presence channel;
 * sending an invite does not ask Reverb who is online, so an invite to
 * someone who just left simply expires unanswered
 * (esports.chess.invite_seconds).
 *
 * One open invite per inviter: a new one withdraws the previous. A live
 * game that starts for a player withdraws all their open invites, sent and
 * received (ChessGameService::start).
 *
 * One intent at a time (P5e): a player who invites leaves the queue. An
 * invite to a player who is searching in the same mode starts the game at
 * once (they asked for any opponent, the inviter chose them), and a player
 * who searches while holding an open invite is paired with its inviter
 * (ChessQueue::join). Both are announced as a found match.
 */
final class ChessInvites
{
    public function __construct(private ChessGameService $games, private ChessNotifications $notifications) {}

    /**
     * Returns the invite; its `chess_game_id` is set when the invitee was
     * searching and the game has already started.
     *
     * @throws ChessRuleViolation
     */
    public function invite(User $inviter, User $invitee, string $mode = 'blitz'): ChessInvite
    {
        if ($inviter->is($invitee)) {
            throw new ChessRuleViolation('invite_self');
        }

        if ($this->games->activeGameOf($inviter) !== null) {
            throw new ChessRuleViolation('already_playing');
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $invitee, $mode, $previous): ChessInvite {
            $previous?->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
            ChessQueueEntry::query()->where('user_id', $inviter->id)->delete();

            return ChessInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'mode' => $mode,
                'status' => ChessInviteStatus::Pending,
                'expires_at' => now()->addSeconds((int) config('esports.chess.invite_seconds')),
            ]);
        });

        if ($previous !== null) {
            $this->announce($previous);
        }

        $this->announce($invite);

        try {
            $this->answer($invite, $invitee, asMatch: true, searchingOnly: true);

            return $invite->refresh();
        } catch (ChessRuleViolation) {
            // Not searching (the usual case), or the pairing lost a race: an ordinary open invite.
        }

        $this->notifications->inviteReceived($invite);

        return $invite;
    }

    /**
     * One live game at a time (P5d): accepting is refused while either side
     * plays a live game. An inviter who is playing cannot start this game any
     * more, so the invite is withdrawn (`opponent_playing`); an invitee who is
     * playing keeps it and may accept once the game is over, while it is
     * still open (`accept_while_playing`).
     *
     * @throws ChessRuleViolation
     */
    public function accept(ChessInvite $invite, User $invitee): ChessGame
    {
        return $this->answer($invite, $invitee, asMatch: false, searchingOnly: false);
    }

    /**
     * The invitee pressed "Find opponent" while holding this invite
     * (ChessQueue::join): accepted as above, announced as a found match.
     *
     * @throws ChessRuleViolation
     */
    public function acceptAsMatch(ChessInvite $invite, User $invitee): ChessGame
    {
        return $this->answer($invite, $invitee, asMatch: true, searchingOnly: false);
    }

    /**
     * Starts the invite's game. `searchingOnly`: only if the invitee is in
     * the queue for the invite's mode, casual (`not_searching` otherwise).
     * A refusal is returned from the transaction rather than thrown, so a
     * withdrawal made on the way still commits.
     *
     * @throws ChessRuleViolation
     */
    private function answer(ChessInvite $invite, User $invitee, bool $asMatch, bool $searchingOnly): ChessGame
    {
        $result = ChessTransaction::run(function () use ($invite, $invitee, $asMatch, $searchingOnly): ChessGame|string {
            $invite = ChessInvite::query()->lockForUpdate()->findOrFail($invite->id);

            if ($invite->invitee_id !== $invitee->id || ! $invite->isOpen()) {
                return 'invite_closed';
            }

            if ($searchingOnly && ! $this->searchesFor($invitee, $invite)) {
                return 'not_searching';
            }

            $live = $invite->mode !== ChessGame::CORRESPONDENCE;

            if ($live && $this->games->activeGameOf($invite->inviter) !== null) {
                $invite->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
                $this->announce($invite);

                return 'opponent_playing';
            }

            if ($live && $this->games->activeGameOf($invitee) !== null) {
                return 'accept_while_playing';
            }

            // Accepted before the game starts, so the start's withdrawal of
            // both players' open invites leaves this one alone.
            $invite->forceFill(['status' => ChessInviteStatus::Accepted])->save();

            [$white, $black] = random_int(0, 1) === 0 ? [$invite->inviter, $invitee] : [$invitee, $invite->inviter];
            $game = $this->games->start($white, $black, $invite->mode);

            $invite->forceFill(['chess_game_id' => $game->id])->save();
            $this->announce($invite);

            if ($asMatch) {
                $this->notifications->matchFound($game);
            } else {
                $this->notifications->inviteAccepted($invite, $game);
            }

            return $game;
        });

        return $result instanceof ChessGame ? $result : throw new ChessRuleViolation($result);
    }

    private function searchesFor(User $invitee, ChessInvite $invite): bool
    {
        return ChessQueueEntry::query()
            ->where('user_id', $invitee->id)
            ->where('mode', $invite->mode)
            ->where('rated', false)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * The invitee declines, or the inviter withdraws.
     *
     * @throws ChessRuleViolation
     */
    public function close(ChessInvite $invite, User $user): void
    {
        $status = match ($user->id) {
            $invite->invitee_id => ChessInviteStatus::Declined,
            $invite->inviter_id => ChessInviteStatus::Withdrawn,
            default => throw new ChessRuleViolation('invite_closed'),
        };

        if ($invite->status !== ChessInviteStatus::Pending) {
            return;
        }

        $invite->forceFill(['status' => $status])->save();
        $this->announce($invite);
    }

    public function outgoing(User $inviter): ?ChessInvite
    {
        return ChessInvite::query()
            ->where('inviter_id', $inviter->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('invitee')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, ChessInvite>
     */
    public function incoming(User $invitee): Collection
    {
        return ChessInvite::query()
            ->where('invitee_id', $invitee->id)
            ->where('status', ChessInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->latest('id')
            ->get();
    }

    private function announce(ChessInvite $invite): void
    {
        Broadcasts::send(new ChessInviteChanged($invite->id, $invite->status->value, $invite->inviter_id, $invite->invitee_id));
    }
}

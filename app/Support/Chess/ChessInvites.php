<?php

namespace App\Support\Chess;

use App\Enums\ChessInviteStatus;
use App\Events\ChessInviteChanged;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Invite a friend who is online" (plan: one of the two blitz exceptions to
 * the queue). The lobby only offers players it sees on the presence channel;
 * the server does not ask Reverb who is online, so an invite to someone who
 * just left simply expires unanswered (esports.chess.invite_seconds).
 *
 * One open invite per inviter: a new one withdraws the previous.
 */
final class ChessInvites
{
    public function __construct(private ChessGameService $games, private ChessNotifications $notifications) {}

    /**
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
        $this->notifications->inviteReceived($invite);

        return $invite;
    }

    /**
     * @throws ChessRuleViolation
     */
    public function accept(ChessInvite $invite, User $invitee): ChessGame
    {
        return DB::transaction(function () use ($invite, $invitee): ChessGame {
            $invite = ChessInvite::query()->lockForUpdate()->findOrFail($invite->id);

            if ($invite->invitee_id !== $invitee->id || ! $invite->isOpen()) {
                throw new ChessRuleViolation('invite_closed');
            }

            [$white, $black] = random_int(0, 1) === 0 ? [$invite->inviter, $invitee] : [$invitee, $invite->inviter];
            $game = $this->games->start($white, $black, $invite->mode);

            $invite->forceFill(['status' => ChessInviteStatus::Accepted, 'chess_game_id' => $game->id])->save();
            $this->announce($invite);
            $this->notifications->inviteAccepted($invite, $game);

            return $game;
        });
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

<?php

namespace App\Support\Chess;

use App\Enums\ChessInviteStatus;
use App\Enums\TournamentStatus;
use App\Events\ChessInviteChanged;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\SeriesQueueEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
use App\Support\Tournaments\CasualCupNotices;
use App\Support\Tournaments\TournamentMatchMaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Invite a friend who is online" (plan: one of the two blitz exceptions to
 * the queue). The lobby only offers players it sees on the presence channel;
 * sending an invite does not ask Reverb who is online, so an invite to
 * someone who just left simply expires unanswered
 * (esports.chess.invite_seconds).
 *
 * Only players with "Looking to play" on can be invited (`not_looking`
 * otherwise), and turning it off declines the invites still open
 * (declineAll). The switch is stored on the user and stays as set across
 * visits and disconnects; nothing resets it on its own.
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
 *
 * A casual cup's match (P25) is played through the same invites: "Play your
 * cup match" invites only the opponent of that match, needs no "Looking to
 * play", and accepting it starts the match's tournament game instead of a
 * casual one (TournamentMatchMaker::startInvited). Two players who invite
 * each other are paired at once.
 */
final class ChessInvites
{
    public function __construct(
        private ChessGameService $games,
        private ChessNotifications $notifications,
        private CasualInvites $casualInvites,
        private TournamentMatchMaker $maker,
        private CasualCupNotices $cupNotices,
    ) {}

    /**
     * "Play your cup match": invite the opponent of a casual cup's chess
     * match whose round is open and that nobody started yet. When the
     * opponent has invited this player for it already, that invite is
     * accepted instead and the game starts (its `chess_game_id` is set).
     *
     * @throws ChessRuleViolation
     */
    public function inviteToCupMatch(User $inviter, TournamentMatch $match): ChessInvite
    {
        $opponent = $this->cupOpponent($inviter, $match);

        if ($this->games->activeGameOf($inviter) !== null) {
            throw new ChessRuleViolation('already_playing');
        }

        // One live game at a time across games (P23): the cup match waits until the casual 1v1 is over.
        if (CasualMatches::runningMatchOf($inviter) !== null) {
            throw ChessGameService::casualPlaying();
        }

        $waiting = ChessInvite::query()->where('tournament_match_id', $match->id)->where('inviter_id', $opponent->id)
            ->where('invitee_id', $inviter->id)->where('status', ChessInviteStatus::Pending)->where('expires_at', '>', now())->latest('id')->first();

        if ($waiting !== null) {
            $this->accept($waiting, $inviter);

            return $waiting->refresh();
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $opponent, $match, $previous): ChessInvite {
            $previous?->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
            ChessQueueEntry::query()->where('user_id', $inviter->id)->delete();
            SeriesQueueEntry::query()->where('user_id', $inviter->id)->delete();

            return ChessInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $opponent->id,
                'mode' => $match->tournament->mode,
                'status' => ChessInviteStatus::Pending,
                'expires_at' => now()->addMinutes(max(1, (int) config('esports.casual_cups.invite_minutes', 10))),
                'tournament_match_id' => $match->id,
            ]);
        });

        if ($previous !== null) {
            $this->announce($previous);
        }

        // One intent at a time (P23): the cup invite withdraws the casual 1v1 invite sent.
        $this->casualInvites->withdrawOutgoing($inviter);

        $this->announce($invite);
        $this->cupNotices->invited($match->tournament, $invite);

        return $invite;
    }

    /**
     * The other player of a cup match this player may start now.
     *
     * @throws ChessRuleViolation
     */
    private function cupOpponent(User $user, TournamentMatch $match): User
    {
        $match->loadMissing(['tournament', 'round', 'slots.participant', 'chessGame']);
        $tournament = $match->tournament;
        $ids = $match->slots->map(fn ($slot): ?int => $slot->participant?->memberIds()[0] ?? null)->all();
        $mine = array_search($user->id, $ids, true);

        $open = $tournament->isCasualCup() && $tournament->profile()->isChess() && $tournament->status === TournamentStatus::Running && ! $tournament->isPaused()
            && $match->status === 'ready' && $match->result === null && $match->held === null
            // Only a match nobody started: a replay or restart follows its game at once.
            && $match->round->window_ends_at?->isFuture() === true && $match->chessGame === null;

        if ($mine === false || count($ids) !== 2) {
            throw new ChessRuleViolation('not_your_match');
        }

        if (! $open) {
            throw new ChessRuleViolation('match_not_open');
        }

        return User::query()->find($ids[1 - $mine]) ?? throw new ChessRuleViolation('match_not_open');
    }

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

        if (CasualMatches::runningMatchOf($inviter) !== null) {
            throw ChessGameService::casualPlaying();
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $invitee, $mode, $previous): ChessInvite|string {
            // Only a player whose "Looking to play" is on for this mode can be invited
            // (2026-09-27: a player with it off got unwanted requests). Read fresh and
            // locked, so a switch turned off a moment ago is seen; setLookingToPlay
            // declines what is still open when it turns off.
            $looking = User::query()->whereKey($invitee->id)->lockForUpdate()->value('looking_to_play');

            if ($looking !== 'chess/'.$mode) {
                return 'not_looking';
            }

            $previous?->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
            ChessQueueEntry::query()->where('user_id', $inviter->id)->delete();
            SeriesQueueEntry::query()->where('user_id', $inviter->id)->delete();

            return ChessInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'mode' => $mode,
                'status' => ChessInviteStatus::Pending,
                'expires_at' => now()->addSeconds((int) config('esports.chess.invite_seconds')),
            ]);
        });

        if (is_string($invite)) {
            throw new ChessRuleViolation($invite, __(':name is not looking for a game right now.', ['name' => $invitee->displayName()]));
        }

        if ($previous !== null) {
            $this->announce($previous);
        }

        // One intent at a time (P23): a blitz invite withdraws the casual 1v1 invite sent.
        $this->casualInvites->withdrawOutgoing($inviter);

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

            if ($live && ($this->games->activeGameOf($invite->inviter) !== null || CasualMatches::runningMatchOf($invite->inviter) !== null)) {
                $invite->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
                $this->announce($invite);

                return 'opponent_playing';
            }

            if ($live && $this->games->activeGameOf($invitee) !== null) {
                return 'accept_while_playing';
            }

            if ($live && CasualMatches::runningMatchOf($invitee) !== null) {
                return 'casual_playing';
            }

            // Accepted before the game starts, so the start's withdrawal of
            // both players' open invites leaves this one alone.
            $invite->forceFill(['status' => ChessInviteStatus::Accepted])->save();

            if ($invite->tournament_match_id !== null) {
                // A cup match (P25): its own game, colours as the bracket has them. Refused = nothing changes.
                $game = $this->maker->startInvited(TournamentMatch::query()->findOrFail($invite->tournament_match_id), $invitee)
                    ?? throw new ChessRuleViolation('match_not_open');

                $invite->forceFill(['chess_game_id' => $game->id])->save();
                $this->announce($invite);

                return $game;
            }

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

        // Only a still pending row changes: an accept that committed in the
        // meantime (another tab) must not be overwritten by a stale copy.
        $closed = ChessInvite::query()
            ->whereKey($invite->id)
            ->where('status', ChessInviteStatus::Pending)
            ->update(['status' => $status, 'updated_at' => now()]);

        if ($closed === 0) {
            $invite->refresh();

            return;
        }

        $invite->refresh();
        $this->announce($invite);
    }

    /**
     * The invitee turned "Looking to play" off: every open invite to them
     * is declined, as if they had pressed Decline. The inviters' lobbies
     * learn it by the usual push; no notification goes out.
     */
    public function declineAll(User $invitee): void
    {
        foreach ($this->incoming($invitee) as $invite) {
            $this->close($invite, $invitee);
        }
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

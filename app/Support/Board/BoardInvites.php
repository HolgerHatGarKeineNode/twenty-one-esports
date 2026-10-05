<?php

namespace App\Support\Board;

use App\Enums\BoardInviteStatus;
use App\Enums\TournamentStatus;
use App\Events\BoardInviteChanged;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\SeriesQueueEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Moderation\SiteModeration;
use App\Support\Series\CasualInvites;
use App\Support\Tournaments\CasualCupNotices;
use App\Support\Tournaments\CupMatchNow;
use App\Support\Tournaments\TournamentMatchMaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invites to a game of one board game other than chess (plan "Mühle und
 * Dame", P5), built after App\Support\Chess\ChessInvites and not on it.
 *
 * Only a player whose "Looking to play" is on for this board game and mode
 * (`users.looking_to_play` = `<slug>/<mode>`) can be invited (`not_looking`
 * otherwise), as in chess; turning it off declines the invites still open
 * (declineAll). One open invite per inviter: a new one withdraws the
 * previous. An invite to a player who is searching this board game starts
 * the game at once, and a player who searches while holding an invite is
 * paired with its inviter (BoardQueue::join). A game that starts withdraws
 * both players' open invites (BoardGameService::start()).
 *
 * A casual cup's board game match is played through the same invites
 * ("Play your cup match", inviteToCupMatch()): only to the opponent of that
 * match, no "Looking to play" needed, and accepting it starts the match's
 * tournament game (TournamentMatchMaker::startInvitedBoard()). Two players
 * who invite each other for it are paired at once.
 */
final class BoardInvites
{
    public function __construct(
        private BoardGameService $games,
        private GameRegistry $registry,
        private CasualInvites $casualInvites,
        private TournamentMatchMaker $maker,
        private CasualCupNotices $cupNotices,
    ) {}

    /**
     * Returns the invite; its `board_game_id` is set when the invitee was
     * searching this board game and the game has already started.
     *
     * @throws BoardRuleViolation
     */
    public function invite(User $inviter, User $invitee, string $slug, string $mode = 'blitz'): BoardInvite
    {
        if ($inviter->is($invitee)) {
            throw new BoardRuleViolation('invite_self');
        }

        // A key banned from the site takes part in nothing (SiteModeration); the page says only that it did not work.
        if (SiteModeration::isBanned($invitee->pubkey) || SiteModeration::isBanned($inviter->pubkey)) {
            throw new BoardRuleViolation('not_available');
        }

        $definition = $this->registry->find($slug);

        if (! $definition instanceof BoardGameDefinition || $definition->mode($mode) === null) {
            throw new BoardRuleViolation('unknown_game');
        }

        BoardQueue::assertFree($this->games, $inviter);

        // The casual lock (user, 2026-10-03): an open cup match in a running round comes first.
        if (($cup = CupMatchNow::refusal($inviter, $invitee)) !== null) {
            throw new BoardRuleViolation($cup['reason'], $cup['message']);
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $invitee, $slug, $mode, $previous): BoardInvite|string {
            // Read fresh and locked, so a switch turned off a moment ago is seen.
            $looking = User::query()->whereKey($invitee->id)->lockForUpdate()->value('looking_to_play');

            if ($looking !== $slug.'/'.$mode) {
                return 'not_looking';
            }

            $previous?->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
            $this->leaveQueues($inviter);

            return BoardInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'game' => $slug,
                'mode' => $mode,
                'status' => BoardInviteStatus::Pending,
                'expires_at' => now()->addSeconds((int) config('esports.board_games.invite_seconds')),
            ]);
        });

        if (is_string($invite)) {
            throw new BoardRuleViolation($invite, __(':name is not looking for a game right now.', ['name' => $invitee->displayName()]));
        }

        if ($previous !== null) {
            $this->announce($previous);
        }

        // One intent at a time: a board invite withdraws the casual 1v1 invite sent.
        $this->casualInvites->withdrawOutgoing($inviter);
        $this->announce($invite);

        try {
            $this->answer($invite, $invitee, searchingOnly: true);

            return $invite->refresh();
        } catch (BoardRuleViolation) {
            // Not searching (the usual case), or the pairing lost a race: an ordinary open invite.
        }

        return $invite;
    }

    /**
     * "Play your cup match": invite the opponent of a casual cup's board
     * game match whose round is open and that nobody started yet. When the
     * opponent has invited this player for it already, that invite is
     * accepted instead and the game starts.
     *
     * @throws BoardRuleViolation
     */
    public function inviteToCupMatch(User $inviter, TournamentMatch $match): BoardInvite
    {
        $opponent = $this->cupOpponent($inviter, $match);

        BoardQueue::assertFree($this->games, $inviter);

        $waiting = BoardInvite::query()->where('tournament_match_id', $match->id)->where('inviter_id', $opponent->id)
            ->where('invitee_id', $inviter->id)->where('status', BoardInviteStatus::Pending)->where('expires_at', '>', now())->latest('id')->first();

        if ($waiting !== null) {
            $this->accept($waiting, $inviter);

            return $waiting->refresh();
        }

        $previous = $this->outgoing($inviter);

        $invite = DB::transaction(function () use ($inviter, $opponent, $match, $previous): BoardInvite {
            $previous?->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
            $this->leaveQueues($inviter);

            return BoardInvite::query()->create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $opponent->id,
                'game' => $match->tournament->game,
                'mode' => $match->tournament->mode,
                'status' => BoardInviteStatus::Pending,
                'expires_at' => now()->addMinutes(max(1, (int) config('esports.casual_cups.invite_minutes', 10))),
                'tournament_match_id' => $match->id,
            ]);
        });

        if ($previous !== null) {
            $this->announce($previous);
        }

        $this->casualInvites->withdrawOutgoing($inviter);
        $this->announce($invite);
        $this->cupNotices->invited($match->tournament, $invite);

        return $invite;
    }

    /**
     * The other player of a cup match this player may start now.
     *
     * @throws BoardRuleViolation
     */
    private function cupOpponent(User $user, TournamentMatch $match): User
    {
        $match->loadMissing(['tournament', 'round', 'slots.participant', 'boardGame']);
        $tournament = $match->tournament;
        $ids = $match->slots->map(fn ($slot): ?int => $slot->participant?->memberIds()[0] ?? null)->all();
        $mine = array_search($user->id, $ids, true);

        $open = $tournament->isCasualCup() && $tournament->profile()->isBoard() && $tournament->status === TournamentStatus::Running && ! $tournament->isPaused()
            && $match->status === 'ready' && $match->result === null && $match->held === null
            // Only a match nobody started: a replay or restart follows its game at once.
            && $match->round->window_ends_at?->isFuture() === true && $match->boardGame === null;

        if ($mine === false || count($ids) !== 2) {
            throw new BoardRuleViolation('not_your_match');
        }

        if (! $open) {
            throw new BoardRuleViolation('match_not_open');
        }

        return User::query()->find($ids[1 - $mine]) ?? throw new BoardRuleViolation('match_not_open');
    }

    /**
     * One live game at a time: accepting is refused while either side plays
     * a live game. An inviter who is playing cannot start this game any
     * more, so the invite is withdrawn (`opponent_playing`); an invitee who
     * is playing keeps it and may accept once free, while it is still open.
     *
     * @throws BoardRuleViolation
     */
    public function accept(BoardInvite $invite, User $invitee): BoardGame
    {
        return $this->answer($invite, $invitee, searchingOnly: false);
    }

    /**
     * Starts the invite's game. `searchingOnly`: only if the invitee is in
     * the queue of the invite's board game and mode (`not_searching`
     * otherwise). A refusal is returned from the transaction rather than
     * thrown, so a withdrawal made on the way still commits.
     *
     * @throws BoardRuleViolation
     */
    private function answer(BoardInvite $invite, User $invitee, bool $searchingOnly): BoardGame
    {
        $result = DB::transaction(function () use ($invite, $invitee, $searchingOnly): BoardGame|string {
            $invite = BoardInvite::query()->lockForUpdate()->findOrFail($invite->id);

            if ($invite->invitee_id !== $invitee->id || ! $invite->isOpen()) {
                return 'invite_closed';
            }

            if ($searchingOnly && ! BoardQueueEntry::query()->where(['user_id' => $invitee->id, 'game' => $invite->game, 'mode' => $invite->mode])->lockForUpdate()->exists()) {
                return 'not_searching';
            }

            try {
                BoardQueue::assertFree($this->games, $invite->inviter);
            } catch (BoardRuleViolation) {
                $invite->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
                $this->announce($invite);

                return 'opponent_playing';
            }

            try {
                BoardQueue::assertFree($this->games, $invitee);
            } catch (BoardRuleViolation $violation) {
                return $violation->reason;
            }

            // The casual lock (user, 2026-10-03): a cup match in a running round comes first; a cup invite is that match.
            if ($invite->tournament_match_id === null && CupMatchNow::lockReason($invite->inviter) !== null) {
                $invite->forceFill(['status' => BoardInviteStatus::Withdrawn])->save();
                $this->announce($invite);

                return 'opponent_playing';
            }

            if ($invite->tournament_match_id === null && ($lock = CupMatchNow::lockReason($invitee)) !== null) {
                return $lock;
            }

            // Accepted before the game starts, so the start's withdrawal of both players' open invites leaves this one alone.
            $invite->forceFill(['status' => BoardInviteStatus::Accepted])->save();

            if ($invite->tournament_match_id !== null) {
                $game = $this->maker->startInvitedBoard(TournamentMatch::query()->findOrFail($invite->tournament_match_id), $invitee);

                if ($game === null) {
                    return 'match_not_open';
                }
            } else {
                [$white, $black] = random_int(0, 1) === 0 ? [$invite->inviter, $invitee] : [$invitee, $invite->inviter];
                $game = $this->games->start($invite->game, $white, $black, $invite->mode);
            }

            $invite->forceFill(['board_game_id' => $game->id])->save();
            $this->announce($invite);

            return $game;
        });

        return $result instanceof BoardGame ? $result : throw new BoardRuleViolation($result);
    }

    /**
     * The invitee declines, or the inviter withdraws. Only a still pending
     * row changes: an accept committed meanwhile (another tab) stands.
     *
     * @throws BoardRuleViolation
     */
    public function close(BoardInvite $invite, User $user): void
    {
        $status = match ($user->id) {
            $invite->invitee_id => BoardInviteStatus::Declined,
            $invite->inviter_id => BoardInviteStatus::Withdrawn,
            default => throw new BoardRuleViolation('invite_closed'),
        };

        $closed = BoardInvite::query()->whereKey($invite->id)->where('status', BoardInviteStatus::Pending)
            ->update(['status' => $status, 'updated_at' => now()]);

        $invite->refresh();

        if ($closed > 0) {
            $this->announce($invite);
        }
    }

    /**
     * The invitee turned "Looking to play" off: every open invite to them
     * (not a cup match's) is declined.
     */
    public function declineAll(User $invitee): void
    {
        foreach ($this->incoming($invitee)->whereNull('tournament_match_id') as $invite) {
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

    public function outgoing(User $inviter): ?BoardInvite
    {
        return BoardInvite::query()
            ->where('inviter_id', $inviter->id)
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('invitee')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, BoardInvite>
     */
    public function incoming(User $invitee): Collection
    {
        return BoardInvite::query()
            ->where('invitee_id', $invitee->id)
            ->where('status', BoardInviteStatus::Pending)
            ->where('expires_at', '>', now())
            ->with('inviter')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /** One intent at a time: an inviter stops searching anywhere. */
    private function leaveQueues(User $user): void
    {
        BoardQueueEntry::query()->where('user_id', $user->id)->delete();
        ChessQueueEntry::query()->where('user_id', $user->id)->delete();
        SeriesQueueEntry::query()->where('user_id', $user->id)->delete();
    }

    private function announce(BoardInvite $invite): void
    {
        Broadcasts::send(new BoardInviteChanged($invite->id, $invite->status->value, $invite->inviter_id, $invite->invitee_id));
    }
}

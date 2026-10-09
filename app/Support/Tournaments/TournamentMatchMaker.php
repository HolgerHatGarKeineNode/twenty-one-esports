<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\LineupRole;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\MatchNumber;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\RatedChess;
use App\Support\Hyper\HyperCups;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperTournamentTeams;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\Series\CasualMatches;
use App\Support\Series\SeriesEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;
use InvalidArgumentException;

/**
 * Tournament matches are played as normal matches (NIP: "Tournament matches
 * are ordinary challenges"; plan: they count for Elo): a Rocket League
 * series in the match room, or a chess game on the league's board, each
 * carrying its tournament match (`tournament_match_id`).
 *
 * The league pairs the two sides, so the pairing is their accept, as in a
 * queue pairing (NIP "Queue pairings"; sign-up is the consent). A pairing is
 * rated while the ladder is open and the trust gate passes on rank: a
 * league-made pairing skips the mutual opponent listing, not the rank (NIP
 * "Trust", item 4). Otherwise it is casual and moves the casual Elo.
 *
 * A Rocket League series is rated under the same gate in both results
 * modes; a mix team (a roster side of several players) is never rated (NIP:
 * mix teams are unrated), an RL 1v1 player is (rev. 7.1, a player ladder).
 * The rated subjects are pinned at the pairing (`rated_subjects`,
 * `gate_at_accept`, `clans_at_accept`), so a lineup or an account gone
 * later is still rated (security gate F3).
 *
 * - Director mode: the result is the league's (resolution `admin`), entered
 *   by a disinterested director (TournamentInterest); nothing is signed.
 * - Players mode (NIP rev. 8.1): the league key signs the challenge (2150,
 *   `pairing` `tournament`) at the pairing, the players' sign-up consent
 *   (22150) being their agreement; no answer follows. The captains then
 *   report (2152) and the other side confirms (2153) as in a ladder series,
 *   and a dispute goes to an admin. A pairing whose two sides share a clan
 *   stays casual (NIP state machine: a challenge needs different clans; the
 *   confirmation would come from the same party). Without the league key or
 *   the tournament's address no challenge can be signed: casual.
 *
 * Chess in director mode is played over the board: no game is started; the
 * finished game record is written when the round closes (TournamentRunner).
 *
 * Hyperbitcoinization (plan "Hyperbitcoinization", P5) is always played on the league's server, in either results
 * mode: every table starts as soon as its entries are known, rated on the season's terms (HyperMatches).
 *
 * A casual cup's match (P25, CasualCups) waits for its round's window and
 * then for its players ("Play your cup match", startInvited()) or the auto
 * slot on the window's last evening; a replay or restart follows at once.
 */
final class TournamentMatchMaker
{
    public function __construct(
        private ChessGameService $chess,
        private RatedTrustGate $gate,
        private GameRegistry $games,
        private CasualCupNotices $cupNotices,
        private BoardGameService $boards,
        private HyperMatches $hyper,
    ) {}

    /**
     * Start the normal match of every tournament match that is ready and
     * has none yet. A chess player still busy in another live game is left
     * for the next run (the scheduler tries again every minute).
     *
     * In players mode a match starts as soon as its two sides are known: a
     * knockout match does not wait for the rest of its round (P18). A
     * round-robin match waits until both sides are done with the earlier
     * rounds of its stage ({@see waitsForEarlierRound()}); Swiss pairs one
     * round at a time anyway. The first match that starts marks its round
     * as started (`tournament_rounds.started_at`, the measurement of P18).
     */
    public function startReady(Tournament $tournament): void
    {
        // Paused (P18): nothing new starts until it is resumed. A game switched off since (its profile is a stand-in) starts nothing.
        if ($tournament->status !== TournamentStatus::Running || $tournament->isPaused() || $tournament->profile()->isUnknown()) {
            return;
        }

        // Drawn at the sign-up close (`draw_committed_at`), it runs (and takes the stream) before its start, but no match starts before
        // `starts_at` (tournament 2, 2026-10-04: round 1 paired an hour early, its report deadline ran out at the
        // start). A casual cup schedules each series itself (CasualCups::seriesStartsAt()).
        if (! $tournament->isCasualCup() && $tournament->draw_committed_at !== null && $tournament->starts_at->isFuture()) {
            return;
        }

        // A held match (P18) waits for an organizer's or admin's decision.
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->whereNull('result')->whereNull('held')
            ->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame', 'boardGame', 'hyperMatch'])->orderBy('id')->get();
        $current = TournamentRunner::currentRound($tournament);

        foreach ($matches as $match) {
            if ($tournament->isDirectorMode() && $match->tournament_round_id !== $current?->id) {
                continue;
            }

            try {
                // Each start under the tournament's lock (the one TournamentControl's pause takes), with the
                // tournament and the match read anew: a pause, a correction or a restart after the list was
                // read wins (P18). Null = paused or no longer running: nothing more starts.
                $go = DB::transaction(fn (): ?bool => $this->startLocked($tournament, $match));
            } catch (UniqueConstraintViolationException) {
                // A concurrent run started this match first: it has its series or game.
                continue;
            } catch (TournamentRuleViolation $violation) {
                report($violation);

                continue;
            }

            if ($go === null) {
                return;
            }
        }
    }

    /**
     * A casual cup's chess match whose players agreed to play now (an
     * accepted "Play your cup match" invite, ChessInvites): its game starts
     * inside the caller's transaction. Null when it cannot start (the round
     * is not open, the match is decided or under way, a player is busy).
     * The player who accepted is at the board already and gets no notice.
     *
     * @throws TournamentRuleViolation
     */
    public function startInvited(TournamentMatch $match, User $acceptedBy): ?ChessGame
    {
        $tournament = $match->tournament()->firstOrFail();

        if (! $tournament->isCasualCup() || ! $tournament->profile()->isChess() || $match->chessGame !== null) {
            return null;
        }

        $match = TournamentMatch::query()->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame'])->findOrFail($match->id);

        if ($this->startLocked($tournament, $match, $acceptedBy) !== true) {
            return null;
        }

        return ChessGame::query()->where('tournament_match_id', $match->id)->latest('id')->first();
    }

    /**
     * A casual cup's board game match whose players agreed to play now (an
     * accepted "Play your cup match" invite, BoardInvites; plan "Mühle und
     * Dame", P5): as startInvited() for chess. Null when it cannot start.
     *
     * @throws TournamentRuleViolation
     */
    public function startInvitedBoard(TournamentMatch $match, User $acceptedBy): ?BoardGame
    {
        $tournament = $match->tournament()->firstOrFail();

        if (! $tournament->isCasualCup() || ! $tournament->profile()->isBoard() || $match->boardGame !== null) {
            return null;
        }

        $match = TournamentMatch::query()->with(['round.stage', 'slots.participant', 'seriesMatch', 'chessGame', 'boardGame'])->findOrFail($match->id);

        if ($this->startLocked($tournament, $match, $acceptedBy) !== true) {
            return null;
        }

        return BoardGame::query()->where('tournament_match_id', $match->id)->latest('id')->first();
    }

    /**
     * Start one match inside startReady()'s transaction. Null when the
     * tournament is paused or no longer running, false when the match
     * cannot start now, true when it started.
     *
     * @throws TournamentRuleViolation
     */
    private function startLocked(Tournament $tournament, TournamentMatch $match, ?User $acceptedBy = null): ?bool
    {
        $locked = Tournament::query()->lockForUpdate()->find($tournament->id);

        if ($locked === null || $locked->status !== TournamentStatus::Running || $locked->isPaused()) {
            return null;
        }

        // refresh() reloads the slots but not their participants: those are read under the lock too.
        $match->refresh()->loadMissing('slots.participant');

        if ($match->status !== 'ready' || $match->result !== null || $match->held !== null) {
            return false;
        }

        [$a, $b] = [$match->slots[0]->participant ?? null, $match->slots[1]->participant ?? null];

        if ($a === null || $b === null || self::waitsForEarlierRound($tournament, $match)) {
            return false;
        }

        // Hyperbitcoinization (plan "Hyperbitcoinization", P5): a free-for-all table or a 1v1, one match on the league's
        // server with every entry of the table seated, started at once; its places come back at its end.
        if ($tournament->profile()->isHyper()) {
            if ($match->slots->contains(fn ($slot): bool => $slot->participant === null)) {
                return false;
            }

            TournamentRound::query()->whereKey($match->tournament_round_id)->whereNull('started_at')->update(['started_at' => now()]);

            return ! self::needsHyperMatch($match) || $this->startHyperMatch($tournament, $match) !== null;
        }

        // A lobby (P10, Lobbies): its players meet in the game's lobby the league named at the draw, and report its
        // places themselves (LobbyResults). No series starts: a series is two sides, a lobby up to eight.
        if ($match->lobby !== null) {
            TournamentRound::query()->whereKey($match->tournament_round_id)->whereNull('started_at')->update(['started_at' => now()]);

            return true;
        }

        // A casual cup (P25): only in its round's window, when its players agreed or at the auto slot.
        if ($locked->isCasualCup() && ! CasualCups::mayStart($match, $acceptedBy !== null)) {
            return false;
        }

        TournamentRound::query()->whereKey($match->tournament_round_id)->whereNull('started_at')->update(['started_at' => now()]);

        // A score leaderboard (plan "AoE2 und Trackmania", P4): nobody meets anyone, so nothing starts. Every entry plays
        // alone inside the window, and the league writes the end (ScoreLeaderboards).
        if ($tournament->profile()->isScore()) {
            return true;
        }

        if ($tournament->profile()->isChess()) {
            if ($tournament->isDirectorMode()) {
                $this->pinPairing($tournament, $match, $a, $b);
            } elseif (self::needsGame($match)) {
                return $this->startGame($tournament, $match, $a, $b, $acceptedBy) !== null;
            }

            return true;
        }

        // A board game other than chess (plan "Mühle und Dame", P5): one game on the board game core,
        // started as a chess game is; in director mode played elsewhere, with the result entered.
        if ($tournament->profile()->isBoard()) {
            if (! $tournament->isDirectorMode() && self::needsBoardGame($match)) {
                return $this->startBoardGame($tournament, $match, $a, $b, $acceptedBy) !== null;
            }

            return true;
        }

        // A casual cup's series match (P25 S3) is played once: a void is decided by the cup's rule
        // (TournamentRunner::seriesFinished()), never replayed.
        if ($tournament->isCasualCup()) {
            if ($match->seriesMatch === null) {
                $this->createCupSeries($tournament, $match, $a, $b);
            }

            return true;
        }

        // No series yet, or the last one was voided by an admin or the league, or superseded by a
        // correction: it is played again (P18). The attempt follows from the series seen here, so a
        // concurrent run hits the unique index.
        if ($match->seriesMatch === null || $match->seriesMatch->resolution === SeriesResolution::Void || $match->isReplaced($match->seriesMatch->id)) {
            $this->createSeries($tournament, $match, $a, $b, ($match->seriesMatch->tournament_attempt ?? 0) + 1);
        }

        return true;
    }

    /**
     * A round-robin match of a one-day players tournament (the format, or
     * the groups of Two Stage) whose sides still have a match of an earlier
     * round of the same stage to finish: it waits for them, so nobody plays
     * two series at once, and nobody waits for an unrelated match. Daily
     * chess starts every round-robin game at once (the estimator plans it
     * so), and directors open one round at a time themselves.
     */
    public static function waitsForEarlierRound(Tournament $tournament, TournamentMatch $match): bool
    {
        if ($tournament->isDirectorMode() || $tournament->profile()->isDaily() || $match->round->stage->format !== TournamentFormat::RoundRobin) {
            return false;
        }

        $sides = $match->slots->pluck('tournament_participant_id')->filter()->all();

        return TournamentMatch::query()->where('tournament_id', $tournament->id)
            ->whereNotIn('status', ['done', 'skipped'])->where('bracket', '!=', 'bye')
            ->whereHas('round', fn ($round) => $round->where('tournament_stage_id', $match->round->tournament_stage_id)->where('number', '<', $match->round->number))
            ->whereHas('slots', fn ($slot) => $slot->whereIn('tournament_participant_id', $sides))
            ->exists();
    }

    /**
     * A chess match needs a (new) game when it has none, when its last game
     * was drawn in a knockout, where a draw decides nothing (replayed with
     * the colours swapped, at most `drawn_replays` times), or when both sides
     * missed the first move (restarted `first_move_restarts` times, P18).
     */
    public static function needsGame(TournamentMatch $match): bool
    {
        $game = $match->chessGame;

        return match (true) {
            // None yet, or the last one was voided or superseded by the league (P18).
            $game === null, $match->isReplaced($game->id) => true,
            $game->status === ChessGameStatus::Aborted => TournamentRunner::abortedGames($match) <= TournamentRunner::firstMoveRestarts(),
            $game->status === ChessGameStatus::Finished && $game->result === '1/2-1/2' && ! TournamentRunner::allowsDraw($match) => TournamentRunner::drawnGames($match) <= TournamentRunner::drawnReplays($match->tournament),
            default => false,
        };
    }

    /**
     * A board game match needs a (new) game as a chess match does
     * ({@see needsGame()}): none yet, a knockout draw (replayed with the
     * colours swapped, at most `drawn_replays` times), or a game aborted
     * because White missed the first move (restarted `first_move_restarts` times).
     */
    public static function needsBoardGame(TournamentMatch $match): bool
    {
        $game = $match->boardGame;

        return match (true) {
            $game === null, $match->isReplaced($game->id) => true,
            $game->status === BoardGameStatus::Aborted => TournamentRunner::abortedBoardGames($match) <= TournamentRunner::firstMoveRestarts(),
            // Two legs (plan "Blockli", P4): a finished game of an undecided match calls the next leg or the decider.
            $game->status === BoardGameStatus::Finished && TournamentRunner::playsTwoLegs($game->game) => $match->result === null,
            $game->status === BoardGameStatus::Finished && $game->result === '1/2-1/2' && ! TournamentRunner::allowsDraw($match) => TournamentRunner::drawnBoardGames($match) <= TournamentRunner::drawnReplays($match->tournament),
            default => false,
        };
    }

    /**
     * A Hyperbitcoinization tournament match needs a (new) match when it has none, or when the league voided or
     * superseded its last one (P18).
     */
    public static function needsHyperMatch(TournamentMatch $match): bool
    {
        $played = $match->hyperMatch;

        return $played === null || $match->isReplaced($played->id);
    }

    /**
     * One Hyperbitcoinization match for a tournament match (P5): each entry's player in slot order, factions drawn,
     * in the tournament's mode (live or correspondence). Rated on the season's terms (every seat a player, a live
     * season); a Hyperbitcoinization cup (HyperCups) is never rated, and bots fill its table up to the table size.
     * A clan bracket (P5b) seats the two clans' named players as a team match (HyperTournamentTeams::seating()).
     * Null when a slot has no player any more, or a clan still has time to name its players.
     */
    private function startHyperMatch(Tournament $tournament, TournamentMatch $match): ?HyperMatch
    {
        // A clan bracket (P5b): a team table of the two clans' named players, A B A B, once both are known.
        if (HyperTournamentTeams::isClanBracket($tournament)) {
            $seating = HyperTournamentTeams::seating($tournament, $match);

            return $seating === null ? null : $this->createHyperMatch($tournament, $match, $seating['seats'], $seating['clans']);
        }

        $seats = [];

        foreach ($match->slots->sortBy('slot') as $slot) {
            $user = User::query()->find($slot->participant?->memberIds()[0] ?? 0);

            if ($user === null) {
                return null;
            }

            $seats[] = ['user' => $user];
        }

        $cup = HyperCups::isCup($tournament);

        if ($cup) {
            $table = min(6, max(count($seats), $tournament->formatOptions()->heatSize));

            while (count($seats) < $table) {
                $seats[] = ['bot' => true];
            }
        }

        return $this->createHyperMatch($tournament, $match, $seats, null, $cup ? false : null);
    }

    /**
     * @param  list<array{user?: User, bot?: bool, team?: int}>  $seats
     * @param  list<int|null>|null  $teamClans
     */
    private function createHyperMatch(Tournament $tournament, TournamentMatch $match, array $seats, ?array $teamClans, ?bool $rated = null): ?HyperMatch
    {
        try {
            $played = $this->hyper->create($seats, mode: $tournament->mode, teamClans: $teamClans, rated: $rated, tournamentMatch: $match->id);
        } catch (InvalidArgumentException $invalid) {
            report($invalid);

            return null;
        }

        // A tournament match may start while its players are away: they are told on every channel (as a cup game is).
        $this->cupNotices->hyperMatchStarted($tournament, $played);

        return $played;
    }

    private function startBoardGame(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b, ?User $acceptedBy = null): ?BoardGame
    {
        $first = User::query()->find($a->memberIds()[0] ?? 0);
        $second = User::query()->find($b->memberIds()[0] ?? 0);

        if ($first === null || $second === null) {
            return null;
        }

        // Slot 0 has White; a knockout replay after a draw swaps the colours, a restart keeps them. Two legs
        // (plan "Blockli", P4): the second leg swaps them too; the first decider after 1:1 draws them by lot, in the
        // tournament's own mode (user, 2026-10-07: the board games are correspondence only, so no blitz decider).
        $last = $match->boardGame !== null && ! $match->isReplaced($match->boardGame->id) ? $match->boardGame : null;
        $swap = $last !== null && ($last->status === BoardGameStatus::Aborted ? $last->white_id !== $first->id : $last->white_id === $first->id);
        $mode = $tournament->mode;

        $finished = TournamentRunner::playsTwoLegs($tournament->game) ? TournamentRunner::finishedBoardGames($match)->count() : 0;

        if ($finished >= 2) {
            $swap = $last?->status === BoardGameStatus::Finished && $finished === 2
                ? Lottery::odds(1, 2)->winner(fn (): bool => true)->loser(fn (): bool => false)->choose()
                : $swap;
        }

        [$white, $black] = $swap ? [$second, $first] : [$first, $second];

        try {
            // Rated as a tournament chess game is (P6): the frozen ladder still open and the trust gate passing at the pairing.
            $game = $this->boards->start($tournament->game, $white, $black, $mode, $match->id,
                BoardGame::query()->where('tournament_match_id', $match->id)->count() + 1, TournamentDeadlines::checkinSeconds($tournament),
                $this->chessPin($tournament, $white, $black));
        } catch (BoardRuleViolation) {
            // Busy in another live game, or the board game is switched off: the next run tries again.
            return null;
        }

        // A tournament game may start while its players are away (the auto slot, the next round): they are told on
        // every channel, a sound and a toast on the page (user, 2026-10-03: in a live cup players missed their games).
        $this->cupNotices->gameStarted($tournament, $game, $acceptedBy);

        return $game;
    }

    /**
     * Director chess is played over the board and gets its game record only
     * when the round closes; what the league reads at the pairing (the trust
     * gate on the frozen ladder, each player's clan) is kept on the match now
     * (NIP "Director results": everything "at the accept" is read at the pairing).
     */
    private function pinPairing(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b): void
    {
        if ($match->pairing !== null) {
            return;
        }

        $white = User::query()->find($a->memberIds()[0] ?? 0);
        $black = User::query()->find($b->memberIds()[0] ?? 0);
        $pin = null;
        $clans = [];

        if ($white !== null && $black !== null) {
            $pin = $this->chessPin($tournament, $white, $black);
            $clans = $pin === null ? [] : RatedChess::clans($white, $black);
        }

        // The ladder is pinned with the gate: the game counts only while it is still open (NIP rule 16).
        $match->forceFill(['pairing' => ['gate' => $pin?->toArray(), 'clans' => $clans, 'ladder' => $pin === null ? null : $tournament->openLadder()]])->save();
    }

    private function startGame(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b, ?User $acceptedBy = null): ?ChessGame
    {
        $first = User::query()->find($a->memberIds()[0] ?? 0);
        $second = User::query()->find($b->memberIds()[0] ?? 0);

        if ($first === null || $second === null) {
            return null;
        }

        // Slot 0 has White; a knockout replay after a draw swaps the colours, a restart after both
        // sides missed the first move keeps them.
        $last = $match->chessGame !== null && ! $match->isReplaced($match->chessGame->id) ? $match->chessGame : null;
        $swap = $last !== null && ($last->status === ChessGameStatus::Aborted ? $last->white_id !== $first->id : $last->white_id === $first->id);
        [$white, $black] = $swap ? [$second, $first] : [$first, $second];

        try {
            $game = $this->chess->start($white, $black, $tournament->mode, null, $this->chessPin($tournament, $white, $black), $match->id,
                ChessGame::query()->where('tournament_match_id', $match->id)->count() + 1);
        } catch (ChessRuleViolation) {
            // Busy in another live game: the next run tries again.
            return null;
        }

        // A tournament game may start while its players are away (the auto slot, the next round): they are told on
        // every channel, a sound and a toast on the page (user, 2026-10-03: in a live cup players missed their games).
        $this->cupNotices->gameStarted($tournament, $game, $acceptedBy);

        return $game;
    }

    /**
     * The gate of a rated tournament game, chess or a board game (P6): open
     * ladder, trust ranks, both at or above the minimum. Null = casual.
     */
    public function chessPin(Tournament $tournament, User $white, User $black): ?GatePin
    {
        if ($tournament->openLadder() === null || ! $this->gate->isAvailable()) {
            return null;
        }

        $pin = $this->gate->pin([$white->pubkey, $black->pubkey], [$white->pubkey, $black->pubkey]);

        return $pin->isEligible($white->pubkey) && $pin->isEligible($black->pubkey) ? $pin : null;
    }

    /**
     * @param  int  $attempt  1, or the replay after an admin voided attempt `$attempt - 1` (P18)
     */
    /**
     * A casual cup's series match (P25 S3): a scheduled casual 1v1 (origin
     * `cup`) for the agreed time, the cup's auto slot or a live evening's
     * round start, never rated. The casual clock runs it: check-in from
     * `checkin_before_minutes` before until `checkin_after_minutes` after
     * (a side not in forfeits, neither: void), then lobby, join, report and
     * confirm with the casual deadlines pinned now (CasualScheduler). Slot 0
     * is the challenger; the host is drawn at random.
     */
    public function createCupSeries(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b): SeriesMatch
    {
        $mode = $this->games->mode($tournament->game, $tournament->mode);
        $numberOwner = User::query()->whereIn('id', [...$a->memberIds(), ...$b->memberIds()])->orderBy('id')->value('id')
            ?? throw new TournamentRuleViolation('no_players', "Tournament match {$match->id} has no player with an account left.");
        $start = CasualCups::seriesStartsAt($match);
        $pinned = CasualMatches::pinned();
        $now = now();

        $series = SeriesMatch::query()->create([
            'number' => MatchNumber::query()->create(['user_id' => $numberOwner, 'used_at' => $now])->id,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'best_of' => $this->bestOf($tournament, $match, $mode === null ? [1, 3] : $mode->bestOf),
            'rated' => false,
            'challenger_lineup_id' => null,
            'challenged_lineup_id' => null,
            'challenger_name' => mb_substr($a->name, 0, 255),
            'challenged_name' => mb_substr($b->name, 0, 255),
            'challenger_tag' => self::tag($a),
            'challenged_tag' => self::tag($b),
            'challenger_lineup_address' => '',
            'challenged_lineup_address' => '',
            'status' => SeriesStatus::Accepted,
            'proposals' => [$start->getTimestamp()],
            'respond_by' => $now,
            'start_at' => $start,
            'answered_at' => $now,
            'ready_by' => $start->addMinutes($pinned['checkin_after_minutes'] ?? 10),
            'casual' => [...$pinned, 'scheduled_at' => $start->getTimestamp()],
            'origin' => SeriesMatch::ORIGIN_CUP,
            'host_side' => SeriesMatch::SIDES[random_int(0, 1)],
            'sides' => ['challenger' => $a->memberIds(), 'challenged' => $b->memberIds()],
            'tournament_match_id' => $match->id,
            'tournament_attempt' => 1,
        ]);

        app(CasualMatches::class)->announce($series);
        $this->cupNotices->seriesOpened($tournament, $series, ['challenger' => $a->memberIds(), 'challenged' => $b->memberIds()]);

        return $series;
    }

    public function createSeries(Tournament $tournament, TournamentMatch $match, TournamentParticipant $a, TournamentParticipant $b, int $attempt = 1): SeriesMatch
    {
        $lineups = [$this->lineup($a), $this->lineup($b)];
        $mode = $this->games->mode($tournament->game, $tournament->mode);
        $bestOf = $this->bestOf($tournament, $match, $mode === null ? [3, 5] : $mode->bestOf);
        // The number is recorded for a player who still has an account (a mix team can lose some).
        $numberOwner = User::query()->whereIn('id', [...$a->memberIds(), ...$b->memberIds()])->orderBy('id')->value('id')
            ?? throw new TournamentRuleViolation('no_players', "Tournament match {$match->id} has no player with an account left.");
        // RL 1v1 entries are rated as players (rev. 7.1), team modes as lineups; a mix team never.
        $players = $this->singlePlayers($tournament, $a, $b, $lineups);
        $pin = $players === null ? $this->seriesPin($tournament, $lineups[0], $lineups[1]) : $this->playersPin($tournament, $players[0], $players[1], $lineups);
        $clans = $pin === null ? null : ($players === null ? $this->clans($lineups[0], $lineups[1]) : RatedChess::clans($players[0], $players[1]));
        // Players mode signs a league challenge (rev. 8.1); it needs the league key, the published
        // tournament and two sides of different clans. Otherwise the pairing is casual.
        $league = $pin !== null && ! $tournament->isDirectorMode() ? LeagueKey::fromConfig() : null;

        if (! $tournament->isDirectorMode() && ($league === null || $tournament->address() === null || self::sharesClan($clans ?? [], $players, $lineups))) {
            $pin = null;
            $clans = null;
        }

        $now = now();

        $sides = [];

        foreach (['challenger' => [$a, $lineups[0]], 'challenged' => [$b, $lineups[1]]] as $side => [$participant, $lineup]) {
            if ($lineup === null) {
                $sides[$side] = $participant->memberIds();
            }
        }

        $series = SeriesMatch::query()->create([
            'number' => MatchNumber::query()->create(['user_id' => $numberOwner, 'used_at' => $now])->id,
            'game' => $tournament->game,
            'mode' => $tournament->mode,
            'best_of' => $bestOf,
            'rated' => $pin !== null,
            'challenger_lineup_id' => $lineups[0]?->id,
            'challenged_lineup_id' => $lineups[1]?->id,
            'challenger_name' => mb_substr($lineups[0]?->clan->name ?? $a->name, 0, 255),
            'challenged_name' => mb_substr($lineups[1]?->clan->name ?? $b->name, 0, 255),
            'challenger_tag' => $lineups[0]?->clan->clantag ?? self::tag($a),
            'challenged_tag' => $lineups[1]?->clan->clantag ?? self::tag($b),
            'challenger_lineup_address' => $lineups[0]?->address() ?? '',
            'challenged_lineup_address' => $lineups[1]?->address() ?? '',
            'ladder_address' => $pin !== null ? $tournament->openLadder() : null,
            'status' => SeriesStatus::Accepted,
            'proposals' => [$now->getTimestamp()],
            'respond_by' => $now,
            'start_at' => $now,
            'answered_at' => $now,
            'clans_at_accept' => $clans,
            'gate_at_accept' => $pin?->toArray(),
            'rated_subjects' => match (true) {
                $pin === null => null,
                $players !== null => ['challenger' => 'user:'.$players[0]->id, 'challenged' => 'user:'.$players[1]->id],
                default => ['challenger' => 'lineup:'.$lineups[0]?->id, 'challenged' => 'lineup:'.$lineups[1]?->id],
            },
            'tournament_match_id' => $match->id,
            'tournament_attempt' => $attempt,
            'sides' => $sides === [] ? null : $sides,
            // The league runs the deadlines where the players report; pinned now, so a later edit reaches only later pairings (P18).
            'deadlines' => $tournament->isDirectorMode() ? null : TournamentDeadlines::forSeries($tournament, $bestOf),
        ]);

        if ($league !== null && $pin !== null) {
            $template = SeriesEvents::tournamentChallenge($series, $tournament, [
                'challenger' => $lineups[0] === null && $players !== null ? $players[0]->pubkey : null,
                'challenged' => $lineups[1] === null && $players !== null ? $players[1]->pubkey : null,
            ], array_values(array_filter([$lineups[0]?->clan->owner_pubkey, $lineups[1]?->clan->owner_pubkey])), $now->getTimestamp());
            $event = $league->publish($template['kind'], $template['tags'], $template['content'], $template['created_at']);
            $series->forceFill(['challenge_event_id' => $event->id])->save();
        }

        // Its players are told wherever they are (user, 2026-10-03).
        $this->cupNotices->seriesOpened($tournament, $series, ['challenger' => $a->memberIds(), 'challenged' => $b->memberIds()]);

        return $series;
    }

    /**
     * Whether a player of one side was, at the pairing, in the clan of a
     * player of the other (or of the other side's lineup).
     *
     * @param  array<string, string>  $clans  pubkey => clan address at the pairing
     * @param  array{0: User, 1: User}|null  $players
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     */
    private static function sharesClan(array $clans, ?array $players, array $lineups): bool
    {
        $sideClans = [];

        foreach ([0, 1] as $index) {
            $pubkeys = $players !== null ? [$players[$index]->pubkey] : array_map(fn (LineupSeat $seat): string => $seat->user->pubkey, $lineups[$index]?->activeSeats() ?? []);
            $sideClans[$index] = array_filter([$lineups[$index]?->clan->address(), ...array_map(fn (string $pubkey): ?string => $clans[$pubkey] ?? null, $pubkeys)]);
        }

        return array_intersect($sideClans[0], $sideClans[1]) !== [];
    }

    /**
     * The gate of a rated director-mode series: open ladder, trust ranks,
     * both lineups, the clan owners at or above the minimum and enough
     * eligible players on each side. Null = casual.
     */
    /**
     * The two players of an RL 1v1 pairing (NIP rev. 7.1, a player ladder):
     * a solo entry's one player, or a clan 1v1 lineup's one regular player
     * among those entered (captain or player seat, not a substitute). Both
     * kinds of side can meet. Null in a team mode, for a mix team, or when a
     * side has no single such player.
     *
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     * @return array{0: User, 1: User}|null
     */
    private function singlePlayers(Tournament $tournament, TournamentParticipant $a, TournamentParticipant $b, array $lineups): ?array
    {
        if ($tournament->teamSize() !== 1 || $a->isMixTeam() || $b->isMixTeam()) {
            return null;
        }

        $players = [];

        foreach ([[$a, $lineups[0]], [$b, $lineups[1]]] as [$participant, $lineup]) {
            $ids = $lineup === null
                ? $participant->memberIds()
                : array_values(array_map(fn (LineupSeat $seat): int => $seat->user_id, array_filter(
                    $lineup->activeSeats(),
                    fn (LineupSeat $seat): bool => $seat->role !== LineupRole::Substitute && in_array($seat->user_id, $participant->memberIds(), true),
                )));
            $user = count($ids) === 1 ? User::query()->find($ids[0]) : null;

            if ($user === null) {
                return null;
            }

            $players[] = $user;
        }

        return [$players[0], $players[1]];
    }

    /**
     * The gate of a rated 1v1 series: like a rated chess game, both players
     * at or above the minimum on the frozen ladder while it is open, each
     * side pinned with its one eligible player (their seat role on a lineup
     * side). Null = casual.
     *
     * @param  array{0: Lineup|null, 1: Lineup|null}  $lineups
     */
    private function playersPin(Tournament $tournament, User $a, User $b, array $lineups): ?GatePin
    {
        $pin = $this->chessPin($tournament, $a, $b);
        $entry = fn (User $user, ?Lineup $lineup): array => [['user_id' => $user->id, 'pubkey' => $user->pubkey, 'name' => $user->displayName(),
            'role' => $lineup?->activeSeatOf($user)?->role->value ?? 'player']];

        return $pin?->withSides(['challenger' => $entry($a, $lineups[0]), 'challenged' => $entry($b, $lineups[1])]);
    }

    private function seriesPin(Tournament $tournament, ?Lineup $a, ?Lineup $b): ?GatePin
    {
        if ($a === null || $b === null || $tournament->openLadder() === null || ! $this->gate->isAvailable()) {
            return null;
        }

        // Only the players each entry fielded at sign-up, never a blocked one (Tournament::entryPlayersOf).
        $entry = [$a->id => $tournament->entryPlayersOf($a->id) ?? [], $b->id => $tournament->entryPlayersOf($b->id) ?? []];
        $seats = fn (Lineup $lineup): array => array_values(array_filter($lineup->activeSeats(),
            fn (LineupSeat $seat): bool => in_array($seat->user_id, $entry[$lineup->id], true)));
        $players = array_values(array_unique(array_map(fn (LineupSeat $seat): string => $seat->user->pubkey, [...$seats($a), ...$seats($b)])));
        $pin = $this->gate->pin($players, [$a->clan->owner_pubkey, $b->clan->owner_pubkey]);

        foreach ($pin->gatekeepers as $gatekeeper) {
            if (! $pin->isEligible($gatekeeper)) {
                return null;
            }
        }

        if (RatedTrustGate::sidesRefusal($pin, $a, $b) !== null) {
            return null;
        }

        $entries = fn (Lineup $lineup): array => array_values(array_map(
            fn (LineupSeat $seat): array => ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'role' => $seat->role->value],
            array_filter($seats($lineup), fn (LineupSeat $seat): bool => $pin->isEligible($seat->user->pubkey)),
        ));

        return $pin->withSides(['challenger' => $entries($a), 'challenged' => $entries($b)]);
    }

    /**
     * @return array<string, string>
     */
    private function clans(?Lineup $a, ?Lineup $b): array
    {
        $clans = [];

        foreach ([$a, $b] as $lineup) {
            foreach ($lineup === null ? [] : $lineup->activeSeats() as $seat) {
                $clans[$seat->user->pubkey] = $lineup->clan->address();
            }
        }

        return $clans;
    }

    private function lineup(TournamentParticipant $participant): ?Lineup
    {
        return $participant->lineup_id === null ? null : Lineup::query()->with(['clan', 'seats.user.clanMember'])->find($participant->lineup_id);
    }

    /**
     * The best-of of this match: the final best-of for the last round of a
     * knockout, the tournament's otherwise, kept to what the mode allows.
     *
     * @param  list<int>  $allowed
     */
    private function bestOf(Tournament $tournament, TournamentMatch $match, array $allowed): int
    {
        $options = $tournament->formatOptions();
        $wanted = TournamentRunner::isFinal($match) ? $options->finalBestOf : $options->bestOf;

        if (in_array($wanted, $allowed, true) || $allowed === []) {
            return $wanted;
        }

        usort($allowed, fn (int $x, int $y): int => abs($x - $wanted) <=> abs($y - $wanted) ?: $x <=> $y);

        return $allowed[0];
    }

    private static function tag(TournamentParticipant $participant): string
    {
        return $participant->isMixTeam() ? 'MIX' : mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $participant->name) ?: 'P', 0, 4));
    }
}

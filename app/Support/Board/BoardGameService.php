<?php

namespace App\Support\Board;

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Events\BoardGameStarted;
use App\Events\BoardGameUpdated;
use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;
use App\Jobs\CheckBoardClock;
use App\Jobs\NotifyBoardMove;
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\BoardMove;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Notifications\BoardNotifications;
use App\Support\Pong\PongMatches;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\CasualMatches;
use App\Support\Series\Ladders;
use App\Support\Tournaments\CupMatchNow;
use App\Support\Tournaments\TournamentRunner;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a live board game other than chess goes through here (plan
 * "Mühle und Dame", P2), and the server is the only judge: turn, legality and
 * clock are checked against the stored game and the game's rules
 * (BoardRules), never against what a browser believes.
 *
 * Built next to App\Support\Chess\ChessGameService and on its proven pattern,
 * not on its code, so chess stays untouched: each action runs in a
 * transaction on the row locked for update, bumps the game's `version`, and
 * broadcasts the new state once committed. Before any action the clock is
 * checked, so a move that arrives after the flag fell is refused and the game
 * ends on time instead. A move names the ply it expects to make; a client that
 * is behind (a retry, a second tab, a double click) is refused instead of
 * guessed at, and the unique (game, ply) index refuses a second move for the
 * same ply even if two requests got that far.
 *
 * Clock (as chess, blitz 5+3 by default): no clock runs until both sides made
 * their first move; the side to move has `esports.board_games.first_move_seconds`
 * for it or the game is aborted. From the second move of each side on,
 * thinking time is taken off the mover's clock and the increment added after
 * the move. A flag loses: the board games know no "too little to win".
 *
 * League (P5): one live game at a time across games — a player in a live
 * chess game or a running casual 1v1 starts no board game, and a board game
 * that starts takes both players out of every queue (board, chess, casual
 * 1v1) and withdraws their open board invites (LiveGameGuard keeps chess
 * from starting a game for a player in a live board game). A finished game
 * rates its two players on the casual ladder of its board game, in the
 * transaction that ends it (RatingService::applyBoardGame()); a tournament
 * game reports to TournamentRunner once committed. A tournament game cannot
 * be aborted by its players; its first-move window is the tournament's
 * check-in, and a Black who misses its first move after White's loses by
 * forfeit (a White who misses it aborts the game, and the tournament
 * restarts it or applies the double no-show rule).
 *
 * Rated (P6): a game the rated queue paired, or a rated tournament's game,
 * comes with the trust gate the league pinned at the pairing (RatedBoard)
 * and is pinned to the ladder open then; it rates the rated ladder of its
 * board game while that ladder is still open and is attested in the live
 * season (SeasonChains::attestBoardGame()), in the transaction that ends
 * it. Without a gate the game is casual, as before.
 *
 * Correspondence (P8, mode `correspondence`): as daily chess, one move per
 * day on the same tables and rules. No clock runs; the side to move has one
 * day (`initial_ms`, from the mode's PGN TimeControl `1/86400`) and
 * `deadline_ms` is "now plus one day" after every move. A missed deadline
 * before both first moves aborts the game, later it loses on time, exactly
 * like a flag, through the same delayed CheckBoardClock and the
 * `board:check-clocks` sweep. A correspondence game is no live game: a
 * player plays any number of them next to one live game, and starting one
 * takes nobody out of a queue. Its players are told of the opponent's move
 * and of the end (BoardNotifications); `board:daily-reminders` reminds them
 * before a deadline. Every game ends by the rules on their own: both rule
 * sets draw after a run of quiet moves, and progress is finite.
 *
 * Boards of a Blockli team match (NIP rev. 9.25, "Start and board forfeit")
 * follow the rules of a chess team match board (ChessGameService): they start
 * for their two players whatever else they do, the first-move window is
 * `esports.team_matches.first_move_seconds` from the agreed start (at least
 * `late_start_floor_seconds` from a late actual start), a side that misses it
 * loses the board by forfeit (unrated; attested with the team result,
 * App\Support\Chess\ChessTeamMatches::settle()), both sides: the board is
 * void. Its players cannot abort it, and every end of a board asks its team
 * match for the result.
 *
 * Not yet here (later phases of the plan): a record of the game.
 */
final class BoardGameService
{
    public function __construct(private GameRegistry $games, private BoardNotifications $notifications) {}

    /* ---------- Start --------------------------------------------------------------------------------------- */

    /**
     * A rated game comes with the trust gate the league pinned at the pairing
     * (RatedBoard, P6); without one the game is casual.
     *
     * Without a mode the game plays correspondence where it has it, else its
     * lobby mode (BoardGame::lobbyMode()).
     *
     * A board of a Blockli team match (`$teamMatch`, `$board`) starts for its
     * two players whatever else they do (ChessTeamMatches::startBoard()), as
     * a chess board does: neither their own reservation for this team match
     * nor another live game refuses it; a player held elsewhere simply misses
     * the first move. It is rated only while the ladder its challenge pinned
     * is still open, and casual otherwise (fail closed).
     *
     * @throws BoardRuleViolation for a game that is no switched-on board game,
     *                            a mode without a clock, or a player already in a live game
     */
    public function start(string $slug, User $white, User $black, ?string $mode = null, ?int $tournamentMatchId = null, ?int $tournamentGame = null, ?int $firstMoveSeconds = null, ?GatePin $ratedGate = null, ?SeriesMatch $teamMatch = null, ?int $board = null): BoardGame
    {
        if ($white->is($black)) {
            throw new BoardRuleViolation('same_player');
        }

        $definition = $this->definition($slug);
        // Without a mode: correspondence where the game has it (Blockli since its blitz and rapid, 2026-10-11), else its live mode.
        $mode ??= $definition->mode(BoardGame::CORRESPONDENCE) !== null ? BoardGame::CORRESPONDENCE : $definition->lobbyMode();
        [$initialMs, $incrementMs] = $this->timeControl($definition, $mode);
        $daily = $mode === BoardGame::CORRESPONDENCE;
        $rules = $definition->rules();
        // A dealt game (Blockspace) starts from a deal of its own.
        $start = $rules instanceof DealtGame ? $rules->deal($mode) : $rules->start();
        // A rated game is pinned to the ladder open when it starts (a tournament's frozen ladder), and
        // counts only while that ladder is still open (NIP rule 16): never on a later season's. No ladder, no rating.
        $ladder = $ratedGate === null ? null : ($tournamentMatchId === null
            ? Ladders::address($slug, $mode)
            : TournamentMatch::query()->with('tournament')->find($tournamentMatchId)?->tournament->openLadderFor($slug));
        $ratedGate = $ladder === null ? null : $ratedGate;
        $clans = $ratedGate === null ? null : RatedBoard::clans($white, $black);
        $firstMoveAt = null;

        if ($teamMatch !== null) {
            // A board is rated on the ladder its challenge pinned, while that ladder is still the open one;
            // otherwise casual, never rated without a ladder (as ChessGameService::start()).
            $ladder = $ratedGate !== null && $teamMatch->rated && $teamMatch->ladder_address !== null && $teamMatch->ladder_address === Ladders::address($slug, $mode)
                ? $teamMatch->ladder_address : null;
            $ratedGate = $ladder === null ? null : $ratedGate;
            // Each player counts for the clan of the accept (NIP rev. 9.25, as rev. 9.22), never the clan of today.
            $clans = $ratedGate === null ? null : array_intersect_key((array) $teamMatch->clans_at_accept, array_flip([$white->pubkey, $black->pubkey]));
            $firstMoveSeconds = ChessTeamMatches::firstMoveSeconds();
            $firstMoveAt = $teamMatch->start_at?->getTimestampMs();
        }

        $game = DB::transaction(function () use ($slug, $mode, $white, $black, $initialMs, $incrementMs, $rules, $start, $tournamentMatchId, $tournamentGame, $firstMoveSeconds, $ratedGate, $ladder, $daily, $teamMatch, $board, $clans, $firstMoveAt): BoardGame {
            // A correspondence game (P8) is no live game: it neither waits for one to end nor ends a search. Nor
            // does a team match board wait: it starts for its players whatever else they play (as chess).
            foreach ($daily || $teamMatch !== null ? [] : [$white, $black] as $player) {
                if ($this->activeGameOf($player) !== null) {
                    throw new BoardRuleViolation('already_playing', "{$player->id} already plays a live board game.");
                }

                // One live game at a time across games (P5): live chess, a running casual 1v1 and a Proof of Pong match
                // (plan "Proof of Pong", P4) count too.
                if (app(ChessGameService::class)->activeGameOf($player) !== null || CasualMatches::runningMatchOf($player) !== null || PongMatches::runningMatchOf($player) !== null) {
                    throw new BoardRuleViolation('playing_elsewhere', "{$player->id} already plays a live game of another kind.");
                }
            }

            // The casual lock (user, 2026-10-03): no casual game, live or by correspondence, while a player's cup match in a running round is open.
            foreach ($tournamentMatchId === null && $teamMatch === null ? [$white, $black] : [] as $player) {
                if (CupMatchNow::lockOf($player) !== null) {
                    throw new BoardRuleViolation(CupMatchNow::LOCKED, __('Your cup match comes first.'));
                }

                // From the lineup lock a team match's players start no other game (plan "Schach Rapid und Clan", P4).
                if (ChessTeamMatches::reservationOf($player) !== null) {
                    throw new BoardRuleViolation(ChessTeamMatches::RESERVED, __('Your clan match comes first.'));
                }
            }

            $now = $this->nowMs();
            $players = [$white->id, $black->id];
            $firstMoveMs = $firstMoveSeconds !== null ? $firstMoveSeconds * 1000 : ($daily ? $initialMs : $this->firstMoveMs());
            // A board's window runs from the agreed start, also when the tick comes late, but never ends sooner than
            // `late_start_floor_seconds` after the actual start (as a chess board, CEO decision 2026-10-05).
            $deadline = $firstMoveAt !== null
                ? max($firstMoveAt + $firstMoveMs, $now + ChessTeamMatches::lateStartFloorSeconds() * 1000)
                : $now + $firstMoveMs;

            // One intent at a time: both stop searching anywhere, and their open board invites are withdrawn.
            if (! $daily) {
                BoardQueueEntry::query()->whereIn('user_id', $players)->delete();
                ChessQueueEntry::query()->whereIn('user_id', $players)->delete();
                SeriesQueueEntry::query()->whereIn('user_id', $players)->delete();
                BoardInvite::query()->where('status', BoardInviteStatus::Pending)
                    ->where(fn ($query) => $query->whereIn('inviter_id', $players)->orWhereIn('invitee_id', $players))
                    ->update(['status' => BoardInviteStatus::Withdrawn, 'updated_at' => now()]);
            }

            return BoardGame::query()->create([
                'game' => $slug,
                'mode' => $mode,
                'white_id' => $white->id,
                'black_id' => $black->id,
                'status' => BoardGameStatus::Active,
                'position' => $rules->serialize($start),
                'turn' => $rules->turn($start),
                'ply' => 0,
                'initial_ms' => $initialMs,
                'increment_ms' => $incrementMs,
                'white_ms' => $initialMs,
                'black_ms' => $initialMs,
                'turn_started_ms' => $now,
                'deadline_ms' => $deadline,
                'first_move_seconds' => $firstMoveSeconds,
                'tournament_match_id' => $tournamentMatchId,
                'tournament_game' => $tournamentGame,
                'rated' => $ratedGate !== null,
                'ladder_address' => $ladder,
                'gate_at_accept' => $ratedGate?->toArray(),
                'clans_at_accept' => $clans,
                'series_match_id' => $teamMatch?->id,
                'board' => $teamMatch === null ? null : $board,
            ]);
        });

        // A correspondence game does not pull anyone off the page they are on.
        if (! $daily) {
            Broadcasts::send(new BoardGameStarted($game->id, route('board.show', $game), [$white->id, $black->id]));
        }

        $this->scheduleClockCheck($game);

        return $game;
    }

    /**
     * The live board game this player is in, if any. Correspondence games do not count.
     */
    public function activeGameOf(User $user): ?BoardGame
    {
        return BoardGame::query()
            ->live()
            ->where('status', BoardGameStatus::Active)
            ->playedBy($user)
            ->latest('id')
            ->first();
    }

    /* ---------- Moves --------------------------------------------------------------------------------------- */

    /**
     * Play one move in the game's own encoding (BoardRules::legalMoves()).
     * `expectedPly` is the ply the client believes it is making (1 = White's
     * first move); a mismatch is refused, so a move sent twice is played once.
     *
     * @throws BoardRuleViolation
     */
    public function move(BoardGame $game, User $user, string $move, ?int $expectedPly = null): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user, $move, $expectedPly): void {
            $color = $this->playerColor($game, $user);

            if ($game->turn !== $color) {
                throw new BoardRuleViolation('not_your_turn');
            }

            if ($expectedPly !== null && $expectedPly !== $game->ply + 1) {
                throw new BoardRuleViolation('out_of_sync');
            }

            $rules = $this->definition($game->game)->rules();
            $position = $rules->deserialize($game->position);

            if (! in_array($move, $rules->legalMoves($position), true)) {
                throw new BoardRuleViolation('illegal_move', "{$move} is illegal in {$this->publicPosition($game, $rules)}.");
            }

            $notation = $rules->notation($position, $move);
            $next = $rules->apply($position, $move);
            $serialized = $rules->serialize($next);

            $spent = max(0, $now - $game->turn_started_ms);
            $clockKey = $color === 'w' ? 'white_ms' : 'black_ms';
            $clock = $game->{$clockKey};

            if ($game->isCorrespondence()) {
                // No clock runs in correspondence: every move has the same day.
                $clock = $game->initial_ms;
            } elseif ($game->clocksRunning()) {
                $clock = $clock - $spent + $game->increment_ms;
            }

            $ply = $game->ply + 1;

            BoardMove::query()->create([
                'board_game_id' => $game->id,
                'ply' => $ply,
                'move' => $move,
                'notation' => $notation,
                'position' => $serialized,
                'spent_ms' => $spent,
                'clock_ms' => $clock,
            ]);

            $game->forceFill([
                $clockKey => $clock,
                'position' => $serialized,
                'turn' => $rules->turn($next),
                'ply' => $ply,
                'turn_started_ms' => $now,
                // An offer stands until the player it was made to moves (as in chess).
                'draw_offer' => $game->draw_offer === $color ? $color : null,
            ]);

            $game->deadline_ms = match (true) {
                $game->isCorrespondence() => $now + $game->initial_ms,
                $game->clocksRunning() => $now + ($game->turn === 'w' ? $game->white_ms : $game->black_ms),
                default => $now + $this->firstMoveMs($game),
            };

            $history = [$this->startOf($game, $rules), ...$game->moves()->pluck('position')->all()];
            $outcome = $rules->outcome($next, array_values(array_map(strval(...), $history)));

            if ($outcome !== null) {
                $this->end($game, BoardGameStatus::Finished, $outcome['result'], $outcome['reason'], $now);
            }
        });
    }

    /* ---------- Take back ----------------------------------------------------------------------------------- */

    /**
     * The player who just moved takes the move back (a misclick; DerCaddy and the players, 2026-10-15: "21 Sekunden
     * nach dem Move kann man zurücknehmen, wenn der Gegner noch nicht gespielt hat", no accept step): within
     * `esports.board_games.take_back_seconds` of the move and only while the opponent has not answered it. The game
     * is as it was before the move: the position, the turn, the move gone from the record. So are the clocks: the
     * mover's clock and the start of their turn come back, so the time since the move counts as their thinking time
     * and the opponent's clock loses nothing; in correspondence the mover's day runs on from where it stood. A draw
     * offer the move let lapse stays lapsed. The window never outlasts the mover's own time (takeBackUntil()), so a
     * take-back never flags. A move that ended the game cannot be taken back: the game is over.
     *
     * @throws BoardRuleViolation `take_back_closed` without an own last move to take back, or once its time is up
     */
    public function takeBack(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);
            $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
            $until = $last === null ? null : $this->takeBackUntil($game, $last);

            $rules = $this->definition($game->game)->rules();

            // Only the side that made the last move, and only in time. The side to move does not tell: in Blockspace a
            // side moves twice in a row, so the last mover can be to move again, and the other side never may take it back.
            if ($last === null || $until === null || $this->lastMover($game, $rules) !== $color || $now >= $until) {
                throw new BoardRuleViolation('take_back_closed');
            }

            $before = $this->positionBeforeLast($game, $rules);
            $started = $game->turn_started_ms - $last->spent_ms;
            $clockKey = $color === 'w' ? 'white_ms' : 'black_ms';
            // The move took its thinking time off and added the increment only once both first moves were made (move()).
            $clock = ! $game->isCorrespondence() && $game->ply > 2 ? $last->clock_ms + $last->spent_ms - $game->increment_ms : $game->{$clockKey};

            $last->delete();
            $game->forceFill([
                $clockKey => $clock,
                'position' => $before,
                'turn' => $rules->turn($rules->deserialize($before)),
                'ply' => $game->ply - 1,
                'turn_started_ms' => $started,
            ]);

            $game->deadline_ms = match (true) {
                $game->isCorrespondence() => $started + $game->initial_ms,
                $game->clocksRunning() => $started + $clock,
                default => $started + $this->firstMoveMs($game),
            };
        });
    }

    /**
     * The position before the game's last move.
     *
     * @param  BoardRules<mixed>  $rules
     */
    private function positionBeforeLast(BoardGame $game, BoardRules $rules): string
    {
        return $game->ply > 1 ? (string) $game->moves()->where('ply', $game->ply - 1)->value('position') : $this->startOf($game, $rules);
    }

    /**
     * The side that made the last move: the one to move before it.
     *
     * @param  BoardRules<mixed>  $rules
     * @return 'w'|'b'
     */
    private function lastMover(BoardGame $game, BoardRules $rules): string
    {
        return $rules->turn($rules->deserialize($this->positionBeforeLast($game, $rules)));
    }

    /**
     * The game's position as anyone may know it: what a dealt game hides stays in the game.
     *
     * @param  BoardRules<mixed>  $rules
     */
    public function publicPosition(BoardGame $game, BoardRules $rules): string
    {
        return $rules instanceof DealtGame ? $rules->serialize($rules->withoutHidden($rules->deserialize($game->position))) : $game->position;
    }

    /**
     * The game's start position as text: every game's own for a dealt game, the rules' one for all others.
     *
     * @param  BoardRules<mixed>  $rules
     */
    public function startOf(BoardGame $game, BoardRules $rules): string
    {
        return $rules->serialize($rules instanceof DealtGame ? $rules->startOf($rules->deserialize($game->position)) : $rules->start());
    }

    /**
     * Until when (server ms) the side that made the last move may take it back, or null: not before a move, not after
     * the game, not with `take_back_seconds` at 0. The window ends that long after the move, or earlier where the
     * mover's own time would have run out by then on the clock as a take-back puts it back. Never on a team match
     * board, as chess boards have none (NIP rev. 9.25).
     */
    public function takeBackUntil(BoardGame $game, ?BoardMove $last = null): ?int
    {
        $window = (int) config('esports.board_games.take_back_seconds', 0) * 1000;

        if ($window <= 0 || ! $game->isActive() || $game->ply === 0 || $game->series_match_id !== null) {
            return null;
        }

        $last ??= $game->moves()->where('ply', $game->ply)->first();

        if ($last === null) {
            return null;
        }

        // A move that showed something hidden stands: a take-back would be a free look at what comes next.
        $rules = $this->rulesOf($game);

        if ($rules instanceof DealtGame && $rules->revealsOnMove($rules->deserialize($this->positionBeforeLast($game, $rules)))) {
            return null;
        }

        // The opponent's turn started with the move; the mover's had started its thinking time before it.
        $moved = $game->turn_started_ms;
        $timeUp = match (true) {
            $game->isCorrespondence() => $moved - $last->spent_ms + $game->initial_ms,
            $game->ply > 2 => $moved + $last->clock_ms - $game->increment_ms,
            default => $moved - $last->spent_ms + $this->firstMoveMs($game),
        };

        return min($moved + $window, $timeUp);
    }

    /* ---------- Resign, draw, abort ------------------------------------------------------------------------- */

    /**
     * @throws BoardRuleViolation
     */
    public function resign(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);
            $this->end($game, BoardGameStatus::Finished, $color === 'w' ? '0-1' : '1-0', BoardEndReason::Resignation->value, $now);
        });
    }

    /**
     * Offer a draw; if the opponent already offered one, this accepts it.
     *
     * @throws BoardRuleViolation
     */
    public function offerDraw(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);

            if ($game->draw_offer === $this->opponent($color)) {
                $this->end($game, BoardGameStatus::Finished, '1/2-1/2', BoardEndReason::Agreement->value, $now);

                return;
            }

            $game->draw_offer = $color;
        });
    }

    /**
     * @throws BoardRuleViolation
     */
    public function acceptDraw(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            if ($game->draw_offer !== $this->opponent($this->playerColor($game, $user))) {
                throw new BoardRuleViolation('no_draw_offer');
            }

            $this->end($game, BoardGameStatus::Finished, '1/2-1/2', BoardEndReason::Agreement->value, $now);
        });
    }

    /**
     * @throws BoardRuleViolation
     */
    public function declineDraw(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game) use ($user): void {
            if ($game->draw_offer !== $this->opponent($this->playerColor($game, $user))) {
                throw new BoardRuleViolation('no_draw_offer');
            }

            $game->draw_offer = null;
        });
    }

    /**
     * Either player may abort until both have made their first move.
     *
     * @throws BoardRuleViolation
     */
    public function abort(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            $this->playerColor($game, $user);

            if ($game->clocksRunning()) {
                throw new BoardRuleViolation('too_late_to_abort');
            }

            // A tournament game or a team match board is the league's to abort (a missed first move), never a player's.
            if ($game->hasEnvelope()) {
                throw new BoardRuleViolation('tournament_game');
            }

            $this->end($game, BoardGameStatus::Aborted, null, BoardEndReason::Aborted->value, $now);
        });
    }

    /**
     * A player of a live tournament game forfeits it (their account is being
     * deleted, as ChessGameService::forfeit()): the opponent wins by forfeit,
     * unrated, and the match hears of it like any other result.
     *
     * @throws BoardRuleViolation for a game of no tournament, or one that is over already
     */
    public function forfeit(BoardGame $game, User $user): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);

            if ($game->tournament_match_id === null) {
                throw new BoardRuleViolation('not_a_tournament_game');
            }

            $this->end($game, BoardGameStatus::Finished, $color === 'w' ? '0-1' : '1-0', BoardEndReason::Forfeit->value, $now);
        });
    }

    /**
     * The league voids a live tournament game (TournamentControl: a result
     * set by an organizer or admin, a restart, a call-off): it ends unrated,
     * and the match no longer counts it.
     *
     * @throws BoardRuleViolation for a game of no tournament, or one that is over already
     */
    public function void(BoardGame $game): BoardGame
    {
        return $this->change($game, function (BoardGame $game, int $now): void {
            if ($game->tournament_match_id === null) {
                throw new BoardRuleViolation('not_a_tournament_game');
            }

            $this->end($game, BoardGameStatus::Aborted, null, BoardEndReason::Voided->value, $now);
        });
    }

    /* ---------- Clock --------------------------------------------------------------------------------------- */

    /**
     * End the game if the side to move ran out of time (or, before both first
     * moves, did not move in time). Safe to call any time, from anywhere: the
     * queued check, the scheduled sweep and a client whose clock shows zero
     * all land here, and only the server's clock decides.
     */
    public function checkClock(BoardGame $game): BoardGame
    {
        $ended = DB::transaction(function () use ($game): ?BoardGame {
            $locked = $this->lock($game);

            return $this->expire($locked, $this->nowMs()) ? $locked : null;
        });

        if ($ended !== null) {
            $this->announce($ended);
            $this->notify($ended, moved: false);

            return $ended;
        }

        return $game->refresh();
    }

    /**
     * Time left on each clock at `nowMs`; the side to move's clock runs only
     * while the game is live and both first moves are made.
     *
     * @return array{w: int, b: int}
     */
    public function clocks(BoardGame $game, int $nowMs): array
    {
        $clocks = ['w' => $game->white_ms, 'b' => $game->black_ms];

        // Correspondence (P8): the side to move has what is left of its day, the other side a full one.
        if ($game->isCorrespondence()) {
            if ($game->isActive() && $game->deadline_ms !== null) {
                $clocks[$game->turn] = max(0, $game->deadline_ms - $nowMs);
            }

            return $clocks;
        }

        if ($game->isActive() && $game->clocksRunning()) {
            $clocks[$game->turn] = max(0, $clocks[$game->turn] - max(0, $nowMs - $game->turn_started_ms));
        }

        return $clocks;
    }

    /* ---------- State for clients --------------------------------------------------------------------------- */

    /**
     * Everything a board needs to show this game right now: the pieces and
     * the legal moves with the points to click for each (the board knows no
     * rules of its own), for a race (RaceStanding) who would be ahead, and
     * for a game counted by area (AreaCount, Go) the count as it stands.
     * The full move list is included for page loads and reconnects; pushes
     * leave it out and carry `lastMove` instead.
     *
     * A board of stones (StoneBoard, Go) is written short, as a push of
     * 19 x 19 with one object per stone and every legal point is refused by
     * Reverb (measured: 11 kB at the start, up to 29 kB): `pieces` is the
     * stones as text (StoneBoard::stones()), `legal` stays empty and `illegal`
     * names the empty points the side to move may not play (ko, suicide; every
     * other empty point and `pass` are legal), `count.owners` is one
     * character per point (`w`, `b` or `.`), and a move carries the stones it
     * took (`taken`) and its side instead of the pieces after it. The board
     * script reads both forms (boardGame.js unpack()).
     *
     * @return array<string, mixed>
     */
    public function snapshot(BoardGame $game, bool $withMoves = true): array
    {
        $now = $this->nowMs();
        $rules = $this->rulesOf($game);
        $position = $rules?->deserialize($game->position);
        $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
        $view = $rules?->view($position);
        // A board with its own input (Blockspace) proposes its move and the server judges it: it gets no legal moves, nor
        // the pieces twice in a push. Up to 279 moves would not fit into a Reverb message of 10 kB, the stones twice hardly.
        $ownInput = ($view['input'] ?? null) === 'pieces';
        $legal = $rules !== null && $game->isActive() && ! $ownInput ? $rules->legalMoves($position) : [];
        // A board of stones is written short (see above); its last move never carries the pieces.
        $stones = $rules instanceof StoneBoard && ($view['input'] ?? null) === 'stones' ? $rules->stones($game->position) : null;
        $ids = $stones === null ? [] : array_column($view['points'], 'id');
        $lastMove = match (true) {
            $last === null => null,
            $stones !== null => $this->stoneMove($last, $rules, null, $stones, $ids),
            default => $this->moveState($last, $rules),
        };

        if ($ownInput && ! $withMoves && $lastMove !== null) {
            unset($lastMove['pieces']);
        }

        $count = $rules instanceof AreaCount ? $rules->areaCount($position) : null;

        if ($stones !== null && $count !== null) {
            $owners = array_fill_keys($count['owners']['w'], 'w') + array_fill_keys($count['owners']['b'], 'b');
            $count['owners'] = implode('', array_map(fn (string $id): string => $owners[$id] ?? '.', $ids));
        }

        $state = [
            'id' => $game->id,
            'game' => $game->game,
            'version' => $game->version,
            'status' => $game->status->value,
            'result' => $game->result,
            'reason' => $game->end_reason,
            'ply' => $game->ply,
            'turn' => $game->turn,
            'daily' => $game->isCorrespondence(),
            'clock' => [
                ...$this->clocks($game, $now),
                // In correspondence the side to move's day runs from the first move on.
                'running' => $game->isActive() && ($game->clocksRunning() || $game->isCorrespondence()) ? $game->turn : null,
                'serverNow' => $now,
            ],
            'deadline' => $game->isActive() ? $game->deadline_ms : null,
            'firstMoveDeadline' => $game->isActive() && ! $game->clocksRunning() && ! $game->isCorrespondence() ? $game->deadline_ms : null,
            'drawOffer' => $game->draw_offer,
            // Until when the side that just moved may take the move back (server ms), null when it may not.
            'takeBackUntil' => $last === null ? null : $this->takeBackUntil($game, $last),
            // Which side that is: the board offers the take-back to it alone.
            'takeBackBy' => $last === null || $rules === null ? null : $this->lastMover($game, $rules),
            'pieces' => $stones ?? $view['pieces'] ?? [],
            // Who would be ahead in a race (Blockli): steps to the goal and blocks left a side (DerCaddy, 2026-10-09).
            'standing' => $rules instanceof RaceStanding ? $rules->standing($position) : null,
            // What each side would have if the game were counted now (Go, DerCaddy 2026-10-27: play it out, then count).
            'count' => $count,
            'legal' => $stones !== null ? [] : array_map(fn (string $move): array => ['move' => $move, 'path' => $rules?->path($move) ?? []], $legal),
            'lastMove' => $lastMove,
        ];

        if ($stones !== null) {
            $playable = array_fill_keys($legal, true);
            // The move without a point: the pass, null where the side to move has none.
            $state['pass'] = array_find($legal, fn (string $move): bool => $rules->path($move) === []);
            $state['illegal'] = $game->isActive()
                ? array_values(array_filter($ids, fn (string $id, int $point): bool => $stones[$point] === '.' && ! isset($playable[$id]), ARRAY_FILTER_USE_BOTH))
                : [];
        }

        if ($withMoves) {
            $state['moves'] = $stones !== null
                ? $this->stoneMoves($game, $rules, $ids)
                : $game->moves()->get()->map(fn (BoardMove $move) => $this->moveState($move, $rules))->all();
        }

        return $state;
    }

    /**
     * The board's fixed drawing (lines, cells, clickable points) of this
     * game, for the page; pushes carry only the pieces.
     *
     * @return array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, cells: list<array{x: int, y: int, size: int}>, points: list<array{id: string, x: int, y: int}>, input?: 'blocks'|'pieces'|'stones', repetitions?: int}|null
     */
    public function layout(BoardGame $game): ?array
    {
        $rules = $this->rulesOf($game);

        if ($rules === null) {
            return null;
        }

        $view = $rules->view($rules->deserialize($game->position));
        unset($view['pieces']);

        return $view;
    }

    /**
     * One move for the board, with the pieces after it, so the board can
     * step back through the game (P8, browsing like chess P55) without rules
     * of its own.
     *
     * @param  BoardRules<mixed>|null  $rules
     * @return array{ply: int, move: string, notation: string, path: list<string>, spent: int, at: int|null, pieces: array<string, mixed>}
     */
    private function moveState(BoardMove $move, ?BoardRules $rules): array
    {
        return [
            'ply' => $move->ply,
            'move' => $move->move,
            'notation' => $move->notation,
            'path' => $rules?->path($move->move) ?? [],
            'spent' => $move->spent_ms,
            'at' => $move->created_at?->getTimestampMs(),
            'pieces' => $rules === null ? [] : $rules->view($rules->deserialize($move->position))['pieces'],
        ];
    }

    /**
     * The record of a board of stones (StoneBoard): every move with the stones it took off the board and the side that
     * made it, read from the stored texts alone. The board builds the stones of an earlier position from them
     * (boardGame.js piecesAt()); the pieces after every move, as other boards carry them, came to 1 MB for a go game of
     * 250 moves on 19 x 19.
     *
     * @param  BoardRules<mixed>&StoneBoard  $rules
     * @param  list<string>  $ids  the points in the order of the stones' text
     * @return list<array{ply: int, move: string, notation: string, path: list<string>, spent: int, at: int|null, side: 'w'|'b'|null, taken: list<string>}>
     */
    private function stoneMoves(BoardGame $game, BoardRules $rules, array $ids): array
    {
        $before = $rules->stones($this->startOf($game, $rules));
        $moves = [];

        foreach ($game->moves()->get() as $move) {
            $after = $rules->stones($move->position);
            $moves[] = $this->stoneMove($move, $rules, $before, $after, $ids);
            $before = $after;
        }

        return $moves;
    }

    /**
     * One move of a board of stones: `side` is the side whose stone it set (null for a pass), `taken` the points whose
     * stones it took off; without the stones before it (a push's last move) nothing taken is named, the push carries
     * the stones as they stand.
     *
     * @param  BoardRules<mixed>  $rules
     * @param  list<string>  $ids
     * @return array{ply: int, move: string, notation: string, path: list<string>, spent: int, at: int|null, side: 'w'|'b'|null, taken: list<string>}
     */
    private function stoneMove(BoardMove $move, BoardRules $rules, ?string $before, string $after, array $ids): array
    {
        $path = $rules->path($move->move);
        $at = $path === [] ? false : array_search($path[0], $ids, true);
        $taken = [];

        if ($before !== null && strlen($before) === strlen($after)) {
            foreach ($ids as $point => $id) {
                if ($before[$point] !== '.' && $after[$point] === '.') {
                    $taken[] = $id;
                }
            }
        }

        return [
            'ply' => $move->ply,
            'move' => $move->move,
            'notation' => $move->notation,
            'path' => $path,
            'spent' => $move->spent_ms,
            'at' => $move->created_at?->getTimestampMs(),
            'side' => $at === false ? null : match ($after[$at] ?? '.') {
                'x' => 'w',
                'o' => 'b',
                default => null,
            },
            'taken' => $taken,
        ];
    }

    /* ---------- Internals ----------------------------------------------------------------------------------- */

    /**
     * Lock, check the clock, apply one change to a live game, save, and
     * broadcast after commit. A game that just ran out of time is saved as
     * ended and the change is refused.
     *
     * @param  Closure(BoardGame, int): void  $change
     *
     * @throws BoardRuleViolation
     */
    private function change(BoardGame $game, Closure $change): BoardGame
    {
        $over = false;
        $changed = false;
        $plyBefore = null;

        $game = DB::transaction(function () use ($game, $change, &$over, &$changed, &$plyBefore): BoardGame {
            $locked = $this->lock($game);
            $now = $this->nowMs();

            if ($this->expire($locked, $now)) {
                [$over, $changed] = [true, true];

                return $locked;
            }

            if (! $locked->isActive()) {
                $over = true;

                return $locked;
            }

            $plyBefore = $locked->ply;
            $change($locked, $now);
            $locked->version++;
            $locked->save();
            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->announce($game);
            $this->notify($game, moved: $plyBefore !== null && $game->ply > $plyBefore);
        }

        if ($over) {
            throw new BoardRuleViolation('game_over');
        }

        if ($game->isActive()) {
            $this->scheduleClockCheck($game);
        }

        return $game;
    }

    private function lock(BoardGame $game): BoardGame
    {
        return BoardGame::query()->lockForUpdate()->findOrFail($game->id);
    }

    /**
     * Ends a live game whose deadline has passed; true if it did. Needs no
     * rules: a missed first move aborts, a flag loses.
     */
    private function expire(BoardGame $game, int $now): bool
    {
        if (! $game->isActive() || $game->deadline_ms === null || $now < $game->deadline_ms) {
            return false;
        }

        if (! $game->clocksRunning() && $game->series_match_id !== null) {
            // A team match board (as a chess one): a side that missed its first move loses the board by forfeit; before
            // any move a Black who never opened the board missed it too, and both missed: the board is void.
            $missed = $this->missedFirstMove($game);

            count($missed) === 1
                ? $this->end($game, BoardGameStatus::Finished, $missed[0] === 'w' ? '0-1' : '1-0', BoardEndReason::Forfeit->value, $game->deadline_ms)
                : $this->end($game, BoardGameStatus::Aborted, null, BoardEndReason::Aborted->value, $game->deadline_ms);
        } elseif (! $game->clocksRunning() && $game->tournament_match_id !== null && $game->ply === 1) {
            // A tournament game whose Black missed the first move after White's: Black loses by forfeit.
            $this->end($game, BoardGameStatus::Finished, '1-0', BoardEndReason::Forfeit->value, $game->deadline_ms);
        } elseif (! $game->clocksRunning()) {
            $this->end($game, BoardGameStatus::Aborted, null, BoardEndReason::Aborted->value, $game->deadline_ms);
        } else {
            $this->end($game, BoardGameStatus::Finished, $game->turn === 'w' ? '0-1' : '1-0', BoardEndReason::Timeout->value, $game->deadline_ms);
        }

        $game->version++;
        $game->save();

        return true;
    }

    /**
     * The sides of a team match board that missed their first move when the
     * first-move deadline passed: the side to move, and before any move a
     * Black who never opened the board (as ChessGameService).
     *
     * @return list<'w'|'b'>
     */
    private function missedFirstMove(BoardGame $game): array
    {
        $missed = [$game->turn];

        if ($game->ply === 0 && $game->black_seen_at === null) {
            $missed[] = 'b';
        }

        return array_values(array_unique($missed));
    }

    /**
     * The player opened a board of a team match: the first visit is kept, so
     * a Black who never opened a board nobody moved on missed the first move
     * too (as ChessGameService::markPresent()).
     */
    public function markSeen(BoardGame $game, User $user): void
    {
        $color = $game->colorOf($user);

        if ($color === null || $game->series_match_id === null || ! $game->isActive()) {
            return;
        }

        $seen = $color === 'w' ? 'white_seen_at' : 'black_seen_at';
        BoardGame::query()->whereKey($game->id)->whereNull($seen)->update([$seen => now()]);
    }

    /**
     * Stops the clocks where they stand at `at` and closes the game.
     *
     * @param  '1-0'|'0-1'|'1/2-1/2'|null  $result
     */
    private function end(BoardGame $game, BoardGameStatus $status, ?string $result, string $reason, int $at): void
    {
        $clocks = $this->clocks($game, $at);

        $game->forceFill([
            'white_ms' => $clocks['w'],
            'black_ms' => $clocks['b'],
            'status' => $status,
            'result' => $result,
            'end_reason' => $reason,
            'deadline_ms' => null,
            'draw_offer' => null,
            'ended_at' => now(),
        ]);

        // In the transaction that ends the game: its rating change and, for a rated game in a live
        // season, its league attestation commit with the result or not at all. A forfeit rates nothing.
        if ($status === BoardGameStatus::Finished) {
            if ($reason !== BoardEndReason::Forfeit->value) {
                app(RatingService::class)->applyBoardGame($game);
            }

            // A team match board's forfeit is attested with its team result (ChessTeamMatches::settle()), as chess:
            // if the same side showed up at no board, the whole team match is one forfeit and no board is attested.
            if ($game->series_match_id === null || $reason !== BoardEndReason::Forfeit->value) {
                app(SeasonChains::class)->attestBoardGame($game);
            }
        }

        // A team match board asks its team match for the result; a failure there is reported and never undoes the
        // board, the tick settles later (as ChessGameService::moveBracket()).
        if ($game->series_match_id !== null) {
            $matchId = $game->series_match_id;
            DB::afterCommit(fn () => rescue(fn () => app(ChessTeamMatches::class)->settleById($matchId)));
        }

        if ($game->tournament_match_id !== null) {
            $id = $game->id;
            DB::afterCommit(fn () => $status === BoardGameStatus::Aborted
                ? app(TournamentRunner::class)->boardGameAborted($id)
                : app(TournamentRunner::class)->boardGameFinished($id));
        }
    }

    private function announce(BoardGame $game): void
    {
        Broadcasts::send(new BoardGameUpdated($game->id, $this->snapshot($game, withMoves: false)));
    }

    /**
     * Correspondence games notify (P8, as daily chess): the player to move
     * after a move, once it can no longer be taken back (NotifyBoardMove),
     * both players when the game ends. A live game's players are at the board.
     */
    private function notify(BoardGame $game, bool $moved): void
    {
        if (! $game->isCorrespondence()) {
            return;
        }

        if (! $game->isActive()) {
            $this->notifications->gameOver($game);
        } elseif ($moved) {
            // Once the take-back window has closed, so a move taken back again never reaches the opponent's phone.
            $wait = (int) config('esports.board_games.take_back_seconds', 0);
            $wait > 0
                ? NotifyBoardMove::dispatch($game->id, (int) $game->moves()->where('ply', $game->ply)->value('id'))->delay(now()->addSeconds($wait + 1))
                : $this->notifications->yourMove($game);
        }
    }

    private function scheduleClockCheck(BoardGame $game): void
    {
        if ($game->deadline_ms === null) {
            return;
        }

        // Whole seconds, rounded up plus one: a queue's delay is second-precise,
        // and a check that runs before the deadline does nothing.
        $seconds = (int) ceil(max(0, $game->deadline_ms - $this->nowMs()) / 1000) + 1;

        CheckBoardClock::dispatch($game->id)->delay(now()->addSeconds($seconds));
    }

    /**
     * The switched-on board game of this slug.
     *
     * @throws BoardRuleViolation for a slug that is no board game in the registry (unknown or switched off)
     */
    private function definition(string $slug): BoardGameDefinition
    {
        $game = $this->games->find($slug);

        return $game instanceof BoardGameDefinition ? $game : throw new BoardRuleViolation('unknown_game', "{$slug} is no board game that is switched on.");
    }

    /**
     * The rules of this game, or null once its board game is switched off
     * (its page is gone then, and a flag still falls without rules).
     *
     * @return BoardRules<mixed>|null
     */
    public function rulesOf(BoardGame $game): ?BoardRules
    {
        $definition = $this->games->find($game->game);

        return $definition instanceof BoardGameDefinition ? $definition->rules() : null;
    }

    /**
     * @return 'w'|'b'
     *
     * @throws BoardRuleViolation for anyone who does not play this game
     */
    private function playerColor(BoardGame $game, User $user): string
    {
        return $game->colorOf($user) ?? throw new BoardRuleViolation('not_a_player');
    }

    /**
     * @param  'w'|'b'  $color
     * @return 'w'|'b'
     */
    private function opponent(string $color): string
    {
        return $color === 'w' ? 'b' : 'w';
    }

    /**
     * @return array{0: int, 1: int} initial and increment in milliseconds, from the mode's PGN TimeControl
     *                               (`300+3`), or `1/86400` in correspondence: one move per 86400 s, no increment
     *
     * @throws BoardRuleViolation for a mode without such a clock
     */
    private function timeControl(BoardGameDefinition $definition, string $mode): array
    {
        $timeControl = $definition->mode($mode)->timeControl ?? '';

        if (preg_match('/^(\d+)\+(\d+)$/', $timeControl, $parts) === 1) {
            return [(int) $parts[1] * 1000, (int) $parts[2] * 1000];
        }

        // Correspondence (P8): one move per so many seconds (`1/86400`), no increment.
        if ($mode === BoardGame::CORRESPONDENCE && preg_match('#^1/(\d+)$#', $timeControl, $parts) === 1) {
            return [(int) $parts[1] * 1000, 0];
        }

        throw new BoardRuleViolation('unsupported_mode', "{$definition->slug()} mode {$mode} has no supported time control.");
    }

    /**
     * The first-move window: a tournament game's own (its check-in, pinned
     * at the start), else `esports.board_games.first_move_seconds`.
     */
    private function firstMoveMs(?BoardGame $game = null): int
    {
        return ($game->first_move_seconds ?? (int) config('esports.board_games.first_move_seconds')) * 1000;
    }

    private function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }
}

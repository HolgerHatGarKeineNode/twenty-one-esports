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
use App\Models\BoardGame;
use App\Models\BoardInvite;
use App\Models\BoardMove;
use App\Models\BoardQueueEntry;
use App\Models\ChessQueueEntry;
use App\Models\SeriesQueueEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessGameService;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\GatePin;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\CasualMatches;
use App\Support\Series\Ladders;
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
 * Not yet here (later phases of the plan): a record of the game.
 */
final class BoardGameService
{
    public function __construct(private GameRegistry $games) {}

    /* ---------- Start --------------------------------------------------------------------------------------- */

    /**
     * A rated game comes with the trust gate the league pinned at the pairing
     * (RatedBoard, P6); without one the game is casual.
     *
     * @throws BoardRuleViolation for a game that is no switched-on board game,
     *                            a mode without a clock, or a player already in a live game
     */
    public function start(string $slug, User $white, User $black, string $mode = 'blitz', ?int $tournamentMatchId = null, ?int $tournamentGame = null, ?int $firstMoveSeconds = null, ?GatePin $ratedGate = null): BoardGame
    {
        if ($white->is($black)) {
            throw new BoardRuleViolation('same_player');
        }

        $definition = $this->definition($slug);
        [$initialMs, $incrementMs] = $this->timeControl($definition, $mode);
        $rules = $definition->rules();
        $start = $rules->start();
        // A rated game is pinned to the ladder open when it starts (a tournament's frozen ladder), and
        // counts only while that ladder is still open (NIP rule 16): never on a later season's. No ladder, no rating.
        $ladder = $ratedGate === null ? null : ($tournamentMatchId === null
            ? Ladders::address($slug, $mode)
            : TournamentMatch::query()->with('tournament')->find($tournamentMatchId)?->tournament->openLadder());
        $ratedGate = $ladder === null ? null : $ratedGate;

        $game = DB::transaction(function () use ($slug, $mode, $white, $black, $initialMs, $incrementMs, $rules, $start, $tournamentMatchId, $tournamentGame, $firstMoveSeconds, $ratedGate, $ladder): BoardGame {
            foreach ([$white, $black] as $player) {
                if ($this->activeGameOf($player) !== null) {
                    throw new BoardRuleViolation('already_playing', "{$player->id} already plays a live board game.");
                }

                // One live game at a time across games (P5): live chess and a running casual 1v1 count too.
                if (app(ChessGameService::class)->activeGameOf($player) !== null || CasualMatches::runningMatchOf($player) !== null) {
                    throw new BoardRuleViolation('playing_elsewhere', "{$player->id} already plays a live game of another kind.");
                }
            }

            $now = $this->nowMs();
            $players = [$white->id, $black->id];

            // One intent at a time: both stop searching anywhere, and their open board invites are withdrawn.
            BoardQueueEntry::query()->whereIn('user_id', $players)->delete();
            ChessQueueEntry::query()->whereIn('user_id', $players)->delete();
            SeriesQueueEntry::query()->whereIn('user_id', $players)->delete();
            BoardInvite::query()->where('status', BoardInviteStatus::Pending)
                ->where(fn ($query) => $query->whereIn('inviter_id', $players)->orWhereIn('invitee_id', $players))
                ->update(['status' => BoardInviteStatus::Withdrawn, 'updated_at' => now()]);

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
                'deadline_ms' => $now + ($firstMoveSeconds !== null ? $firstMoveSeconds * 1000 : $this->firstMoveMs()),
                'first_move_seconds' => $firstMoveSeconds,
                'tournament_match_id' => $tournamentMatchId,
                'tournament_game' => $tournamentGame,
                'rated' => $ratedGate !== null,
                'ladder_address' => $ladder,
                'gate_at_accept' => $ratedGate?->toArray(),
                'clans_at_accept' => $ratedGate === null ? null : RatedBoard::clans($white, $black),
            ]);
        });

        Broadcasts::send(new BoardGameStarted($game->id, route('board.show', $game), [$white->id, $black->id]));
        $this->scheduleClockCheck($game);

        return $game;
    }

    /**
     * The live board game this player is in, if any.
     */
    public function activeGameOf(User $user): ?BoardGame
    {
        return BoardGame::query()
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
                throw new BoardRuleViolation('illegal_move', "{$move} is illegal in {$game->position}.");
            }

            $notation = $rules->notation($position, $move);
            $next = $rules->apply($position, $move);
            $serialized = $rules->serialize($next);

            $spent = max(0, $now - $game->turn_started_ms);
            $clockKey = $color === 'w' ? 'white_ms' : 'black_ms';
            $clock = $game->{$clockKey};

            if ($game->clocksRunning()) {
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

            $game->deadline_ms = $game->clocksRunning()
                ? $now + ($game->turn === 'w' ? $game->white_ms : $game->black_ms)
                : $now + $this->firstMoveMs($game);

            $history = [$rules->serialize($rules->start()), ...$game->moves()->pluck('position')->all()];
            $outcome = $rules->outcome($next, array_values(array_map(strval(...), $history)));

            if ($outcome !== null) {
                $this->end($game, BoardGameStatus::Finished, $outcome['result'], $outcome['reason'], $now);
            }
        });
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

            // A tournament game is the league's to abort (a missed first move), never a player's.
            if ($game->tournament_match_id !== null) {
                throw new BoardRuleViolation('tournament_game');
            }

            $this->end($game, BoardGameStatus::Aborted, null, BoardEndReason::Aborted->value, $now);
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

        if ($game->isActive() && $game->clocksRunning()) {
            $clocks[$game->turn] = max(0, $clocks[$game->turn] - max(0, $nowMs - $game->turn_started_ms));
        }

        return $clocks;
    }

    /* ---------- State for clients --------------------------------------------------------------------------- */

    /**
     * Everything a board needs to show this game right now: the pieces and
     * the legal moves with the points to click for each (the board knows no
     * rules of its own). The full move list is included for page loads and
     * reconnects; pushes leave it out and carry `lastMove` instead.
     *
     * @return array<string, mixed>
     */
    public function snapshot(BoardGame $game, bool $withMoves = true): array
    {
        $now = $this->nowMs();
        $rules = $this->rulesOf($game);
        $position = $rules?->deserialize($game->position);
        $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;
        $legal = $rules !== null && $game->isActive() ? $rules->legalMoves($position) : [];

        $state = [
            'id' => $game->id,
            'game' => $game->game,
            'version' => $game->version,
            'status' => $game->status->value,
            'result' => $game->result,
            'reason' => $game->end_reason,
            'ply' => $game->ply,
            'turn' => $game->turn,
            'clock' => [
                ...$this->clocks($game, $now),
                'running' => $game->isActive() && $game->clocksRunning() ? $game->turn : null,
                'serverNow' => $now,
            ],
            'deadline' => $game->isActive() ? $game->deadline_ms : null,
            'firstMoveDeadline' => $game->isActive() && ! $game->clocksRunning() ? $game->deadline_ms : null,
            'drawOffer' => $game->draw_offer,
            'pieces' => $rules === null ? [] : $rules->view($position)['pieces'],
            'legal' => array_map(fn (string $move): array => ['move' => $move, 'path' => $rules?->path($move) ?? []], $legal),
            'lastMove' => $last === null ? null : $this->moveState($last, $rules),
        ];

        if ($withMoves) {
            $state['moves'] = $game->moves()->get()->map(fn (BoardMove $move) => $this->moveState($move, $rules))->all();
        }

        return $state;
    }

    /**
     * The board's fixed drawing (lines, cells, clickable points) of this
     * game, for the page; pushes carry only the pieces.
     *
     * @return array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, cells: list<array{x: int, y: int, size: int}>, points: list<array{id: string, x: int, y: int}>}|null
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
     * @param  BoardRules<mixed>|null  $rules
     * @return array{ply: int, move: string, notation: string, path: list<string>, spent: int, at: int|null}
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

        $game = DB::transaction(function () use ($game, $change, &$over, &$changed): BoardGame {
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

            $change($locked, $now);
            $locked->version++;
            $locked->save();
            $changed = true;

            return $locked;
        });

        if ($changed) {
            $this->announce($game);
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

        if (! $game->clocksRunning() && $game->tournament_match_id !== null && $game->ply === 1) {
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

            app(SeasonChains::class)->attestBoardGame($game);
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
     * @return array{0: int, 1: int} initial and increment in milliseconds, from the mode's PGN TimeControl (`300+3`)
     *
     * @throws BoardRuleViolation for a mode without such a clock
     */
    private function timeControl(BoardGameDefinition $definition, string $mode): array
    {
        $timeControl = $definition->mode($mode)->timeControl ?? '';

        if (preg_match('/^(\d+)\+(\d+)$/', $timeControl, $parts) === 1) {
            return [(int) $parts[1] * 1000, (int) $parts[2] * 1000];
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

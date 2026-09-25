<?php

namespace App\Support\Chess;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Events\ChessGameStarted;
use App\Events\ChessGameUpdated;
use App\Events\ChessInviteChanged;
use App\Games\GameRegistry;
use App\Jobs\CheckChessClock;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessMove;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a live chess game goes through here, and the server is the
 * only judge: legality, turn and clock are checked against the stored game,
 * never against what a browser believes.
 *
 * Each action runs in a transaction on the row locked for update, bumps the
 * game's `version`, and broadcasts the new state once committed. Before any
 * action the clock is checked, so a move that arrives after the flag fell is
 * refused and the game ends on time instead.
 *
 * Clock rules (lichess-style, ChessOverlays/ChessStates): no clock runs until
 * both sides made their first move; the side to move has
 * `first_move_seconds` for it or the game is aborted. From the second move
 * of each side on, thinking time is taken off the mover's clock and the
 * increment added after the move.
 *
 * Daily games (mode `correspondence`, P5b) share the table and the rules:
 * there is no running clock, the side to move has one day (`initial_ms`,
 * from the PGN TimeControl `1/86400`) and `deadline_ms` is simply "now plus
 * one day" after every move. A missed deadline before both first moves
 * aborts the game, later it loses on time, exactly like a blitz flag. A
 * player is in at most one live (blitz) game, but in any number of daily ones.
 */
final class ChessGameService
{
    public function __construct(private GameRegistry $games) {}

    /* ---------- Start --------------------------------------------------------------------------------------- */

    /**
     * @throws ChessRuleViolation when either player already plays a live game
     */
    public function start(User $white, User $black, string $mode = 'blitz', ?ChessGame $rematchOf = null): ChessGame
    {
        [$initialMs, $incrementMs] = $this->timeControl($mode);
        $daily = $mode === ChessGame::CORRESPONDENCE;

        $game = DB::transaction(function () use ($white, $black, $mode, $rematchOf, $initialMs, $incrementMs, $daily): ChessGame {
            foreach ($daily ? [] : [$white, $black] as $player) {
                if ($this->activeGameOf($player) !== null) {
                    throw new ChessRuleViolation('already_playing', "{$player->id} already plays a live game.");
                }
            }

            $now = $this->nowMs();

            $game = ChessGame::query()->create([
                'mode' => $mode,
                'rated' => false,
                'white_id' => $white->id,
                'black_id' => $black->id,
                'status' => ChessGameStatus::Active,
                'fen' => ChessGame::START_FEN,
                'ply' => 0,
                'initial_ms' => $initialMs,
                'increment_ms' => $incrementMs,
                'white_ms' => $initialMs,
                'black_ms' => $initialMs,
                'turn_started_ms' => $now,
                'deadline_ms' => $now + ($daily ? $initialMs : $this->firstMoveMs()),
                'rematch_of_id' => $rematchOf?->id,
            ]);

            // Freeze the PGN tag pairs now (names can change, signed notes cannot).
            $game->forceFill(['pgn_headers' => ChessPgn::headersFor($game)])->save();

            if ($daily) {
                return $game;
            }

            // One live game at a time (P5d): a player who starts one (queue,
            // invite, rematch) stops searching, and their other open invites,
            // sent and received, are withdrawn. The other side's lobby hears it.
            ChessQueueEntry::query()->whereIn('user_id', [$white->id, $black->id])->delete();

            $open = ChessInvite::query()
                ->where('status', ChessInviteStatus::Pending)
                ->where('mode', '!=', ChessGame::CORRESPONDENCE)
                ->where('expires_at', '>', now())
                ->where(fn ($query) => $query
                    ->whereIn('inviter_id', [$white->id, $black->id])
                    ->orWhereIn('invitee_id', [$white->id, $black->id]))
                ->lockForUpdate()
                ->get();

            foreach ($open as $invite) {
                $invite->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
                Broadcasts::send(new ChessInviteChanged($invite->id, $invite->status->value, $invite->inviter_id, $invite->invitee_id));
            }

            if ($rematchOf !== null) {
                $rematchOf->forceFill(['rematch_id' => $game->id, 'rematch_offer' => null, 'version' => $rematchOf->version + 1])->save();
            }

            return $game;
        });

        if ($rematchOf !== null) {
            $this->announce($rematchOf->refresh());
        }

        // A daily game does not pull anyone off the page they are on.
        if (! $daily) {
            Broadcasts::send(new ChessGameStarted($game->id, route('games.show', $game), [$white->id, $black->id]));
            $this->scheduleClockCheck($game);
        }

        return $game;
    }

    /**
     * The live (blitz) game this player is in, if any. Daily games do not count.
     */
    public function activeGameOf(User $user): ?ChessGame
    {
        return ChessGame::query()
            ->live()
            ->where('status', ChessGameStatus::Active)
            ->playedBy($user)
            ->latest('id')
            ->first();
    }

    /* ---------- Moves --------------------------------------------------------------------------------------- */

    /**
     * Play one move in UCI (`e2e4`, `e7e8q`). `expectedPly` is the ply the
     * client believes it is making (1 = White's first move); a mismatch means
     * the client is behind and is refused instead of guessed at.
     *
     * `record` runs inside the same transaction once the move is applied (and
     * the game ended, if it did): a daily move checks and stores the player's
     * signed note there, and anything it throws undoes the move.
     *
     * @param  (Closure(ChessGame, ChessMove): void)|null  $record
     *
     * @throws ChessRuleViolation
     */
    public function move(ChessGame $game, User $user, string $uci, ?int $expectedPly = null, ?Closure $record = null): ChessGame
    {
        return $this->change($game, function (ChessGame $game, int $now) use ($user, $uci, $expectedPly, $record): void {
            $color = $this->playerColor($game, $user);

            if ($game->turn() !== $color) {
                throw new ChessRuleViolation('not_your_turn');
            }

            if ($expectedPly !== null && $expectedPly !== $game->ply + 1) {
                throw new ChessRuleViolation('out_of_sync');
            }

            $played = $game->moves()->get(['uci', 'fen']);
            $ucis = array_values($played->map(fn (ChessMove $move): string => $move->uci)->all());
            $fens = array_values($played->map(fn (ChessMove $move): string => $move->fen)->all());
            $chess = ChessRules::replay($ucis, $game->startFen());
            $move = ChessRules::play($chess, $uci);

            if ($move === null) {
                throw new ChessRuleViolation('illegal_move', "{$uci} is illegal in {$game->fen}.");
            }

            $spent = max(0, $now - $game->turn_started_ms);
            $clockKey = $color === 'w' ? 'white_ms' : 'black_ms';
            $clock = $game->{$clockKey};

            if ($game->isCorrespondence()) {
                // What was left of the day; the next move gets a fresh one.
                $clock = max(0, (int) $game->deadline_ms - $now);
            } elseif ($game->clocksRunning()) {
                $clock = $clock - $spent + $game->increment_ms;
            }

            $fen = $chess->fen();
            $ply = $game->ply + 1;

            $stored = ChessMove::query()->create([
                'chess_game_id' => $game->id,
                'ply' => $ply,
                'uci' => $uci,
                'san' => (string) $move->san,
                'fen' => $fen,
                'spent_ms' => $spent,
                'clock_ms' => $clock,
            ]);

            $game->forceFill([
                $clockKey => $game->isCorrespondence() ? $game->initial_ms : $clock,
                'fen' => $fen,
                'ply' => $ply,
                'turn_started_ms' => $now,
                // An offer stands until the player it was made to moves (ChessOverlays).
                'draw_offer' => $game->draw_offer === $color ? $color : null,
            ]);

            $game->deadline_ms = match (true) {
                $game->isCorrespondence() => $now + $game->initial_ms,
                $game->clocksRunning() => $now + ($game->turn() === 'w' ? $game->white_ms : $game->black_ms),
                default => $now + $this->firstMoveMs(),
            };

            $outcome = ChessRules::outcome($chess, [$game->startFen(), ...$fens, $fen]);

            if ($outcome !== null) {
                $this->finish($game, $outcome[0], $outcome[1], $now);
            }

            if ($record !== null) {
                $record($game, $stored);
            }
        });
    }

    /**
     * Where a move would lead, without playing it: the SAN, whether it ends
     * the game, and the resulting PGN. A daily move is signed on this text
     * before the server plays it.
     *
     * @return array{san: string, fen: string, result: string, reason: ChessEndReason|null, pgn: string}
     *
     * @throws ChessRuleViolation
     */
    public function preview(ChessGame $game, User $user, string $uci, int $expectedPly): array
    {
        $game->refresh();
        $color = $this->playerColor($game, $user);

        if (! $game->isActive()) {
            throw new ChessRuleViolation('game_over');
        }

        if ($game->turn() !== $color) {
            throw new ChessRuleViolation('not_your_turn');
        }

        if ($expectedPly !== $game->ply + 1) {
            throw new ChessRuleViolation('out_of_sync');
        }

        $played = $game->moves()->get(['uci', 'san', 'fen']);
        $chess = ChessRules::replay(array_values($played->pluck('uci')->all()), $game->startFen());
        $move = ChessRules::play($chess, $uci);

        if ($move === null) {
            throw new ChessRuleViolation('illegal_move', "{$uci} is illegal in {$game->fen}.");
        }

        $fen = $chess->fen();
        $outcome = ChessRules::outcome($chess, [$game->startFen(), ...array_values($played->pluck('fen')->all()), $fen]);
        $sans = [...array_values($played->pluck('san')->all()), (string) $move->san];
        $result = $outcome[0] ?? '*';
        $reason = $outcome[1] ?? null;

        return ['san' => (string) $move->san, 'fen' => $fen, 'result' => $result, 'reason' => $reason, 'pgn' => ChessPgn::build($game, $sans, $result, $reason)];
    }

    /* ---------- Resign, draw, abort ------------------------------------------------------------------------- */

    /**
     * @throws ChessRuleViolation
     */
    public function resign(ChessGame $game, User $user): ChessGame
    {
        return $this->change($game, function (ChessGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);
            $this->finish($game, $color === 'w' ? '0-1' : '1-0', ChessEndReason::Resignation, $now);
        });
    }

    /**
     * Offer a draw; if the opponent already offered one, this accepts it.
     *
     * @throws ChessRuleViolation
     */
    public function offerDraw(ChessGame $game, User $user): ChessGame
    {
        return $this->change($game, function (ChessGame $game, int $now) use ($user): void {
            $color = $this->playerColor($game, $user);

            if ($game->draw_offer === $this->opponent($color)) {
                $this->finish($game, '1/2-1/2', ChessEndReason::Agreement, $now);

                return;
            }

            $game->draw_offer = $color;
        });
    }

    /**
     * @throws ChessRuleViolation
     */
    public function acceptDraw(ChessGame $game, User $user): ChessGame
    {
        return $this->change($game, function (ChessGame $game, int $now) use ($user): void {
            if ($game->draw_offer !== $this->opponent($this->playerColor($game, $user))) {
                throw new ChessRuleViolation('no_draw_offer');
            }

            $this->finish($game, '1/2-1/2', ChessEndReason::Agreement, $now);
        });
    }

    /**
     * @throws ChessRuleViolation
     */
    public function declineDraw(ChessGame $game, User $user): ChessGame
    {
        return $this->change($game, function (ChessGame $game) use ($user): void {
            if ($game->draw_offer !== $this->opponent($this->playerColor($game, $user))) {
                throw new ChessRuleViolation('no_draw_offer');
            }

            $game->draw_offer = null;
        });
    }

    /**
     * Either player may abort until both have made their first move.
     *
     * @throws ChessRuleViolation
     */
    public function abort(ChessGame $game, User $user): ChessGame
    {
        return $this->change($game, function (ChessGame $game, int $now) use ($user): void {
            $this->playerColor($game, $user);

            if ($game->clocksRunning()) {
                throw new ChessRuleViolation('too_late_to_abort');
            }

            $this->end($game, ChessGameStatus::Aborted, null, ChessEndReason::Aborted, $now);
        });
    }

    /* ---------- Opponent disconnected (ChessOverlays) ------------------------------------------------------- */

    /**
     * A player's page saw the opponent leave the game's presence channel.
     * The server asks the websocket server itself and, if the opponent is
     * really gone, starts the claim timer (once; a later report keeps it).
     */
    public function reportGone(ChessGame $game, User $reporter, PresenceLookup $presence): ChessGame
    {
        $color = $this->playerColor($game, $reporter);
        $opponentColor = $this->opponent($color);
        $absent = $game->isActive() && ! $game->isCorrespondence() ? $presence->absent($game, $game->player($opponentColor)) : null;
        $column = $opponentColor === 'w' ? 'white_gone_ms' : 'black_gone_ms';

        return DB::transaction(function () use ($game, $absent, $column): ChessGame {
            $locked = $this->lock($game);

            if ($absent === true && $locked->{$column} === null) {
                $locked->forceFill([$column => $this->nowMs()])->save();
            } elseif ($absent === false && $locked->{$column} !== null) {
                $locked->forceFill([$column => null])->save();
            }

            return $locked;
        });
    }

    /**
     * The player is here (page load, reconnect, any action): their own
     * "gone" mark is cleared, so an old report cannot be claimed against them.
     */
    public function markPresent(ChessGame $game, User $user): void
    {
        $column = match ($game->colorOf($user)) {
            'w' => 'white_gone_ms',
            'b' => 'black_gone_ms',
            default => null,
        };

        if ($column !== null) {
            ChessGame::query()->whereKey($game->id)->whereNotNull($column)->update([$column => null]);
        }
    }

    /**
     * Claim the win after the opponent stayed disconnected for the claim
     * timeout (esports.chess.disconnect_claim_seconds). The server decides
     * from its own record and asks the websocket server again; if it cannot
     * tell, there is no claim (fail closed).
     *
     * @throws ChessRuleViolation
     */
    public function claimWin(ChessGame $game, User $user, PresenceLookup $presence): ChessGame
    {
        $color = $this->playerColor($game, $user);
        $opponentColor = $this->opponent($color);
        $absent = $presence->absent($game, $game->player($opponentColor));

        return $this->change($game, function (ChessGame $game, int $now) use ($color, $opponentColor, $absent): void {
            if ($game->isCorrespondence() || ! $game->clocksRunning()) {
                throw new ChessRuleViolation('no_claim');
            }

            $gone = $opponentColor === 'w' ? $game->white_gone_ms : $game->black_gone_ms;
            $wait = (int) config('esports.chess.disconnect_claim_seconds') * 1000;

            if ($absent !== true || $gone === null || $now - $gone < $wait) {
                throw new ChessRuleViolation('no_claim');
            }

            $result = ChessRules::hasMoreThanKing($game->fen, $color) ? ($color === 'w' ? '1-0' : '0-1') : '1/2-1/2';
            $this->finish($game, $result, ChessEndReason::Abandoned, $now);
        });
    }

    /* ---------- Clock ----------------------------------------------------------------------------------------- */

    /**
     * End the game if the side to move ran out of time (or, before both first
     * moves, did not move in time). Safe to call any time, from anywhere:
     * the queued check, the scheduled sweep and a client whose clock shows
     * zero all land here, and only the server's clock decides.
     */
    public function checkClock(ChessGame $game): ChessGame
    {
        $ended = DB::transaction(function () use ($game): ?ChessGame {
            $locked = $this->lock($game);

            return $this->expire($locked, $this->nowMs()) ? $locked : null;
        });

        if ($ended !== null) {
            $this->announce($ended);
            $this->notifyPlayers($ended, ended: true);

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
    public function clocks(ChessGame $game, int $nowMs): array
    {
        $clocks = ['w' => $game->white_ms, 'b' => $game->black_ms];

        if ($game->isCorrespondence()) {
            if ($game->isActive() && $game->deadline_ms !== null) {
                $clocks[$game->turn()] = max(0, $game->deadline_ms - $nowMs);
            }

            return $clocks;
        }

        if ($game->isActive() && $game->clocksRunning()) {
            $turn = $game->turn();
            $clocks[$turn] = max(0, $clocks[$turn] - max(0, $nowMs - $game->turn_started_ms));
        }

        return $clocks;
    }

    /* ---------- Rematch ------------------------------------------------------------------------------------- */

    /**
     * Offer a rematch after a finished game; if the opponent already offered
     * one, this accepts it and starts the new game with colours swapped.
     *
     * @throws ChessRuleViolation
     */
    public function offerRematch(ChessGame $game, User $user): ?ChessGame
    {
        $color = $this->playerColor($game, $user);

        if ($game->rematch_offer === $this->opponent($color)) {
            return $this->acceptRematch($game, $user);
        }

        $this->changeFinished($game, function (ChessGame $game) use ($color): void {
            $game->rematch_offer = $color;
        });

        return null;
    }

    /**
     * @throws ChessRuleViolation
     */
    public function acceptRematch(ChessGame $game, User $user): ChessGame
    {
        $game->refresh();
        $color = $this->playerColor($game, $user);

        if ($game->status !== ChessGameStatus::Finished || $game->rematch_id !== null) {
            throw new ChessRuleViolation('no_rematch');
        }

        if ($game->rematch_offer !== $this->opponent($color)) {
            throw new ChessRuleViolation('no_rematch_offer');
        }

        return $this->start($game->black, $game->white, $game->mode, $game);
    }

    /**
     * @throws ChessRuleViolation
     */
    public function declineRematch(ChessGame $game, User $user): void
    {
        $color = $this->playerColor($game, $user);

        $this->changeFinished($game, function (ChessGame $game) use ($color): void {
            if ($game->rematch_offer !== $this->opponent($color)) {
                throw new ChessRuleViolation('no_rematch_offer');
            }

            $game->rematch_offer = null;
        });
    }

    /* ---------- State for clients --------------------------------------------------------------------------- */

    /**
     * Everything a board needs to show this game right now. The full move
     * list is included for page loads and reconnects; pushes leave it out and
     * carry `lastMove` instead.
     *
     * @return array<string, mixed>
     */
    public function snapshot(ChessGame $game, bool $withMoves = true): array
    {
        $now = $this->nowMs();
        $last = $game->ply > 0 ? $game->moves()->where('ply', $game->ply)->first() : null;

        $state = [
            'id' => $game->id,
            'version' => $game->version,
            'status' => $game->status->value,
            'result' => $game->result,
            'reason' => $game->end_reason?->value,
            'fen' => $game->fen,
            'ply' => $game->ply,
            'turn' => $game->turn(),
            'clock' => [
                ...$this->clocks($game, $now),
                'running' => $game->isActive() && $game->clocksRunning() ? $game->turn() : null,
                'serverNow' => $now,
            ],
            'mode' => $game->mode,
            'deadline' => $game->isActive() ? $game->deadline_ms : null,
            'firstMoveDeadline' => $game->isActive() && ! $game->clocksRunning() && ! $game->isCorrespondence() ? $game->deadline_ms : null,
            'recorded' => $game->record_event_id !== null,
            'drawOffer' => $game->draw_offer,
            'rematchOffer' => $game->rematch_offer,
            'rematchUrl' => $game->rematch_id !== null ? route('games.show', $game->rematch_id) : null,
            'lastMove' => $last === null ? null : $this->moveState($last),
        ];

        if ($withMoves) {
            $state['moves'] = $game->moves()->with('nostrEvent:id,event_id')->get()->map(fn (ChessMove $move) => $this->moveState($move))->all();
        }

        return $state;
    }

    /**
     * @return array{ply: int, uci: string, san: string, spent: int, at: int|null, event: string|null}
     */
    private function moveState(ChessMove $move): array
    {
        return [
            'ply' => $move->ply,
            'uci' => $move->uci,
            'san' => $move->san,
            'spent' => $move->spent_ms,
            'at' => $move->created_at?->getTimestampMs(),
            'event' => $move->nostrEvent?->event_id,
        ];
    }

    /* ---------- Internals ----------------------------------------------------------------------------------- */

    /**
     * Lock, check the clock, apply one change to a live game, save, and
     * broadcast after commit. A game that just ran out of time is saved as
     * ended and the change is refused.
     *
     * @param  Closure(ChessGame, int): void  $change
     *
     * @throws ChessRuleViolation
     */
    private function change(ChessGame $game, Closure $change): ChessGame
    {
        $over = false;
        $changed = false;
        $plyBefore = 0;
        $wasActive = false;

        $game = DB::transaction(function () use ($game, $change, &$over, &$changed, &$plyBefore, &$wasActive): ChessGame {
            $locked = $this->lock($game);
            $now = $this->nowMs();
            $plyBefore = $locked->ply;
            $wasActive = $locked->isActive();

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
            $this->notifyPlayers($game, ended: $wasActive && ! $game->isActive(), moved: $game->ply > $plyBefore);
        }

        if ($over) {
            throw new ChessRuleViolation('game_over');
        }

        if ($game->isActive()) {
            $this->scheduleClockCheck($game);
        }

        return $game;
    }

    /**
     * Same as change(), for the rematch fields of a finished game.
     *
     * @param  Closure(ChessGame): void  $change
     *
     * @throws ChessRuleViolation
     */
    private function changeFinished(ChessGame $game, Closure $change): void
    {
        $game = DB::transaction(function () use ($game, $change): ChessGame {
            $locked = $this->lock($game);

            if ($locked->status !== ChessGameStatus::Finished || $locked->rematch_id !== null) {
                throw new ChessRuleViolation('no_rematch');
            }

            $change($locked);
            $locked->version++;
            $locked->save();

            return $locked;
        });

        $this->announce($game);
    }

    private function lock(ChessGame $game): ChessGame
    {
        return ChessGame::query()->lockForUpdate()->findOrFail($game->id);
    }

    /**
     * Ends a live game whose deadline has passed; true if it did.
     */
    private function expire(ChessGame $game, int $now): bool
    {
        if (! $game->isActive() || $game->deadline_ms === null || $now < $game->deadline_ms) {
            return false;
        }

        if (! $game->clocksRunning()) {
            $this->end($game, ChessGameStatus::Aborted, null, ChessEndReason::Aborted, $game->deadline_ms);
        } else {
            $flagged = $game->turn();
            $winner = $this->opponent($flagged);
            $result = ChessRules::hasMoreThanKing($game->fen, $winner) ? ($winner === 'w' ? '1-0' : '0-1') : '1/2-1/2';
            $this->finish($game, $result, ChessEndReason::Timeout, $game->deadline_ms);
        }

        $game->version++;
        $game->save();

        return true;
    }

    /**
     * @param  '1-0'|'0-1'|'1/2-1/2'  $result
     */
    private function finish(ChessGame $game, string $result, ChessEndReason $reason, int $at): void
    {
        $this->end($game, ChessGameStatus::Finished, $result, $reason, $at);
    }

    /**
     * Stops the clocks where they stand at `at` and closes the game.
     */
    private function end(ChessGame $game, ChessGameStatus $status, ?string $result, ChessEndReason $reason, int $at): void
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
    }

    private function announce(ChessGame $game): void
    {
        Broadcasts::send(new ChessGameUpdated($game->id, $this->snapshot($game, withMoves: false)));
    }

    /**
     * Daily games notify (P5b): the player to move after a move, both players
     * when the game ends. Live games only when they end (P5c, in the app): a
     * player whose tab is in the background still hears that it is over.
     */
    private function notifyPlayers(ChessGame $game, bool $ended = false, bool $moved = false): void
    {
        if (! $ended && (! $moved || ! $game->isCorrespondence())) {
            return;
        }

        DB::afterCommit(function () use ($game, $ended): void {
            $notifications = app(ChessNotifications::class);

            if ($ended) {
                $notifications->gameOver($game);
            } else {
                $notifications->yourMove($game);
            }
        });
    }

    private function scheduleClockCheck(ChessGame $game): void
    {
        // Daily deadlines are a day away: the scheduled sweep (chess:check-clocks) catches them.
        if ($game->deadline_ms === null || $game->isCorrespondence()) {
            return;
        }

        // Whole seconds, rounded up plus one: a queue's delay is second-precise,
        // and a check that runs before the deadline does nothing.
        $seconds = (int) ceil(max(0, $game->deadline_ms - $this->nowMs()) / 1000) + 1;

        CheckChessClock::dispatch($game->id)->delay(now()->addSeconds($seconds));
    }

    /**
     * @return 'w'|'b'
     *
     * @throws ChessRuleViolation for anyone who does not play this game
     */
    private function playerColor(ChessGame $game, User $user): string
    {
        return $game->colorOf($user) ?? throw new ChessRuleViolation('not_a_player');
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
     * @return array{0: int, 1: int} initial and increment in milliseconds, from the mode's PGN TimeControl:
     *                               `300+3` (blitz), or `1/86400` (daily: one move per 86400 s, no increment)
     */
    private function timeControl(string $mode): array
    {
        $timeControl = $this->games->mode('chess', $mode)->timeControl ?? '';

        if (preg_match('/^(\d+)\+(\d+)$/', $timeControl, $parts) === 1) {
            return [(int) $parts[1] * 1000, (int) $parts[2] * 1000];
        }

        if ($mode === ChessGame::CORRESPONDENCE && preg_match('#^1/(\d+)$#', $timeControl, $parts) === 1) {
            return [(int) $parts[1] * 1000, 0];
        }

        throw new ChessRuleViolation('unsupported_mode', "Chess mode {$mode} has no supported time control.");
    }

    private function firstMoveMs(): int
    {
        return (int) config('esports.chess.first_move_seconds') * 1000;
    }

    private function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }
}

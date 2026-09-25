<?php

namespace App\Support\Chess;

use App\Enums\ChessGameStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\DB;

/**
 * NIP-64 game notes (kind 64), signed by the players, never by the league
 * (NIP "Game Record": "signed by one of its two players"; the league key
 * signs only attestations, and a casual game gets none).
 *
 * - Daily moves: every move is a note by the player who moves, with the
 *   whole game so far (result `*`) and an `e` to the previous move's note.
 *   The move that ends the game (mate, a draw by rule) carries the result
 *   and is at once the game's final record.
 * - Final record: a note with the finished game (result and termination),
 *   offered to both players when the game ends; the first valid one the
 *   league accepts counts, a second is refused.
 *
 * Casual games are not part of any season: no `e` to a challenge, no ladder
 * `a` (NIP "Rest": "Casual correspondence games may be published as plain
 * NIP-64 notes without an `e` to a challenge and without a ladder `a`").
 *
 * The league builds each note's template from its own record of the game;
 * the browser signs it; the signed note must equal the template
 * (SignedEventGate) and is published through the RelayPublisher.
 */
final class GameRecords
{
    public function __construct(private ChessGameService $games, private SignedEventGate $gate) {}

    /**
     * The template of the next daily move, and what the move is.
     *
     * @return array{template: array{kind: int, tags: list<list<string>>, content: string, created_at: int}, san: string, result: string, check: bool}
     *
     * @throws ChessRuleViolation
     */
    public function prepareMove(ChessGame $game, User $user, string $uci, int $ply): array
    {
        if (! $game->isCorrespondence()) {
            throw new ChessRuleViolation('not_daily');
        }

        $preview = $this->games->preview($game, $user, $uci, $ply);

        return [
            'template' => [...$this->template($game, $preview['pgn'], $this->previousNote($game, $ply)), 'created_at' => now()->getTimestamp()],
            'san' => $preview['san'],
            'result' => $preview['result'],
            'check' => str_contains($preview['san'], '+') || str_contains($preview['san'], '#'),
        ];
    }

    /**
     * Play a daily move with the player's signed note of it. The note is
     * checked inside the move's transaction: an invalid note plays nothing.
     *
     * @throws ChessRuleViolation
     * @throws RejectedEvent
     */
    public function playSigned(ChessGame $game, User $user, string $uci, int $ply, mixed $signed): ChessGame
    {
        if (! $game->isCorrespondence()) {
            throw new ChessRuleViolation('not_daily');
        }

        return $this->games->move($game, $user, $uci, $ply, function (ChessGame $game, ChessMove $move) use ($user, $signed): void {
            $previous = $this->previousNote($game, $move->ply);
            $game->load('moves');

            $event = $this->gate->check($signed, $this->template($game, ChessPgn::of($game), $previous), $user);

            // NIP "Game Record": a move is not signed before the move it follows.
            if ($previous !== null && $event->createdAt < $previous->signed_at) {
                throw new RejectedEvent('created_at_before_previous_move');
            }

            $stored = NostrEvent::fromSigned($event);
            $move->forceFill(['nostr_event_id' => $stored->id])->save();

            if (! $game->isActive()) {
                $game->record_event_id = $stored->id;
            }

            PublishNostrEvent::dispatch($stored);
        });
    }

    /**
     * The final record's template, or null when there is nothing to sign:
     * the game runs, was aborted, or already has its record.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}|null
     */
    public function finalTemplate(ChessGame $game): ?array
    {
        if ($game->status !== ChessGameStatus::Finished || $game->record_event_id !== null) {
            return null;
        }

        $game->loadMissing('moves');
        $last = $game->isCorrespondence() ? $game->moves->last()?->nostrEvent : null;

        return [...$this->template($game, ChessPgn::of($game), $last), 'created_at' => now()->getTimestamp()];
    }

    /**
     * Store and publish a player's final record, if it is the first valid one.
     *
     * @throws ChessRuleViolation
     * @throws RejectedEvent
     */
    public function submitFinal(ChessGame $game, User $user, mixed $signed): NostrEvent
    {
        if ($game->colorOf($user) === null) {
            throw new ChessRuleViolation('not_a_player');
        }

        return DB::transaction(function () use ($game, $user, $signed): NostrEvent {
            $locked = ChessGame::query()->lockForUpdate()->findOrFail($game->id);
            $template = $this->finalTemplate($locked);

            if ($template === null) {
                throw new ChessRuleViolation($locked->record_event_id !== null ? 'already_recorded' : 'not_finished');
            }

            $stored = NostrEvent::fromSigned($this->gate->check($signed, $template, $user));
            $locked->forceFill(['record_event_id' => $stored->id, 'version' => $locked->version + 1])->save();

            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string}
     */
    private function template(ChessGame $game, string $pgn, ?NostrEvent $previous): array
    {
        $headers = ChessPgn::headersFor($game);

        $tags = [
            ['p', $game->white->pubkey, '', 'white'],
            ['p', $game->black->pubkey, '', 'black'],
        ];

        if ($previous !== null) {
            $tags[] = ['e', $previous->event_id];
        }

        $result = preg_match('/\[Result "([^"]*)"\]/', $pgn, $match) === 1 ? $match[1] : '*';

        $tags[] = ['alt', $game->isCorrespondence()
            ? "Daily chess game {$game->number()}: {$headers['White']} vs {$headers['Black']}, {$result} (NIP-64 PGN)"
            : "Chess game {$game->number()}: {$headers['White']} vs {$headers['Black']}, {$result} (NIP-64 PGN)"];

        return ['kind' => EsportsEventRules::GAME_RECORD_KIND, 'tags' => $tags, 'content' => $pgn];
    }

    /**
     * The signed note of the move before `ply` (none before the first move).
     */
    private function previousNote(ChessGame $game, int $ply): ?NostrEvent
    {
        return $ply <= 1 ? null : ChessMove::query()
            ->where('chess_game_id', $game->id)
            ->where('ply', $ply - 1)
            ->first()?->nostrEvent;
    }
}

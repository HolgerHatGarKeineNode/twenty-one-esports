<?php

namespace App\Support\Chess;

use App\Enums\ChessGameStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEventGate;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Support\Facades\DB;

/**
 * NIP-64 game notes (kind 64), NIP rev. 9.4 ("Game Record"):
 *
 * - No move is an event. Blitz and daily moves alike run over the league
 *   server, which checks turn, legality and deadline ({@see ChessGameService}).
 * - The league's record: when a RATED game ends with a result, the league
 *   key signs ONE note with the whole game (PGN, result, termination) inside
 *   the transaction that ends it, so its attestation (`2154`) can reference it
 *   by `e`. A game gets it once (`record_event_id`); a game without a move
 *   gets none. Casual games get none: the league key is the league's own
 *   public profile, and a note per casual game would bury it (user decision,
 *   2026-09-28).
 * - A player's post: optional, by button after the game ("Post this game to
 *   my profile"), never automatic. The same PGN as a kind-64 note signed by
 *   the player, quoting the league's record with `q` (NIP-18: a quote, not a
 *   reply) when there is one; a casual game's post stands alone. At most one
 *   per player and game.
 */
final class GameRecords
{
    public function __construct(private SignedEventGate $gate) {}

    /**
     * Sign and queue the league's record of a rated game that just ended.
     * Call inside the transaction that ends it, before the attestation. Null
     * for a casual game, when there is nothing to record (no move, no result,
     * a deleted player) or no league key is configured; the game's result
     * stands either way.
     */
    public function recordFinished(ChessGame $game): ?NostrEvent
    {
        if (! $game->rated || $game->status !== ChessGameStatus::Finished || $game->record_event_id !== null || $game->ply === 0
            || ! in_array($game->result, ['1-0', '0-1', '1/2-1/2'], true) || $game->white_id === null || $game->black_id === null) {
            return null;
        }

        $league = LeagueKey::fromConfig();

        if ($league === null) {
            return null;
        }

        // Fresh: the move that ended the game was stored after any earlier load of the relation.
        $game->load('moves');
        $pgn = ChessPgn::of($game);
        $tags = $this->playerTags($game);

        if ($game->ladder_address !== null) {
            $tags[] = ['a', $game->ladder_address, ''];
        }

        $tags[] = ['alt', $this->alt($game, 'Chess game record')];

        $event = $league->publish(EsportsEventRules::GAME_RECORD_KIND, $tags, $pgn, now()->getTimestamp());
        $game->record_event_id = $event->id;
        $game->setRelation('recordEvent', $event);

        return $event;
    }

    /**
     * The note a player would post: exactly what the preview shows and the
     * signer signs.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws ChessRuleViolation not_a_player, not_finished, already_posted
     */
    public function postTemplate(ChessGame $game, User $user): array
    {
        $color = $game->colorOf($user) ?? throw new ChessRuleViolation('not_a_player');

        if ($game->status !== ChessGameStatus::Finished || $game->ply === 0 || $game->white_id === null || $game->black_id === null) {
            throw new ChessRuleViolation('not_finished');
        }

        if ($this->postOf($game, $color) !== null) {
            throw new ChessRuleViolation('already_posted');
        }

        $record = $game->recordEvent;

        if ($record === null) {
            $game->load('moves');
        }

        $tags = $this->playerTags($game);

        if ($record !== null) {
            $tags[] = ['q', $record->event_id, '', $record->pubkey];
        }

        $tags[] = ['alt', $this->alt($game, 'Chess game')];

        return [
            'kind' => EsportsEventRules::GAME_RECORD_KIND,
            'tags' => $tags,
            'content' => $record !== null ? (string) ($record->payload()['content'] ?? '') : ChessPgn::of($game),
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * Store and publish a player's post of the game, once per player.
     *
     * @throws ChessRuleViolation
     * @throws RejectedEvent
     */
    public function submitPost(ChessGame $game, User $user, mixed $signed): NostrEvent
    {
        return DB::transaction(function () use ($game, $user, $signed): NostrEvent {
            $locked = ChessGame::query()->lockForUpdate()->findOrFail($game->id);
            $color = $locked->colorOf($user) ?? throw new ChessRuleViolation('not_a_player');
            $event = $this->gate->check($signed, $this->postTemplate($locked, $user), $user);

            $stored = NostrEvent::fromSigned($event);
            $locked->forceFill([($color === 'w' ? 'white' : 'black').'_post_event_id' => $stored->id])->save();

            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /**
     * The player's post of this game, if they made one.
     *
     * @param  'w'|'b'  $color
     */
    public function postOf(ChessGame $game, string $color): ?int
    {
        return $color === 'w' ? $game->white_post_event_id : $game->black_post_event_id;
    }

    /**
     * @return list<list<string>>
     */
    private function playerTags(ChessGame $game): array
    {
        return [
            ['p', $game->white->pubkey, '', 'white'],
            ['p', $game->black->pubkey, '', 'black'],
        ];
    }

    private function alt(ChessGame $game, string $what): string
    {
        $headers = ChessPgn::headersFor($game);
        $mode = ChessModes::word($game->mode);

        return "{$what} {$game->number()} ({$mode}): {$headers['White']} vs {$headers['Black']}, {$game->result} (NIP-64 PGN)";
    }
}

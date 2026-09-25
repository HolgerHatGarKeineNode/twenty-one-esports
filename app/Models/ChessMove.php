<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One half-move of a chess game, as the server accepted it.
 *
 * @property int $id
 * @property int $chess_game_id
 * @property int $ply 1 = White's first move
 * @property string $uci e.g. `e2e4`, `e7e8q`
 * @property string $san e.g. `Nf3`, `Qh8#`
 * @property string $fen position after the move
 * @property int $spent_ms thinking time of this move
 * @property int $clock_ms mover's time left after the move, increment included (daily: time that was left of the move's 24 h)
 * @property int|null $nostr_event_id the player's signed kind-64 note of a daily move
 * @property Carbon|null $created_at
 * @property-read ChessGame $game
 * @property-read NostrEvent|null $nostrEvent
 */
#[Fillable(['chess_game_id', 'ply', 'uci', 'san', 'fen', 'spent_ms', 'clock_ms', 'nostr_event_id'])]
class ChessMove extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'ply' => 'integer',
            'spent_ms' => 'integer',
            'clock_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChessGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(ChessGame::class, 'chess_game_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }
}

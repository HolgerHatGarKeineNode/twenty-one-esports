<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move of a board game other than chess, as the server accepted it.
 *
 * @property int $id
 * @property int $board_game_id
 * @property int $ply 1 = White's first move
 * @property string $move in the game's own encoding (App\Support\Board\BoardRules)
 * @property string $notation as players write it
 * @property string $position position after the move, as the rules serialize it
 * @property int $spent_ms thinking time of this move
 * @property int $clock_ms mover's time left after the move, increment included
 * @property Carbon|null $created_at
 * @property-read BoardGame $game
 */
#[Fillable(['board_game_id', 'ply', 'move', 'notation', 'position', 'spent_ms', 'clock_ms'])]
class BoardMove extends Model
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
     * @return BelongsTo<BoardGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(BoardGame::class, 'board_game_id');
    }
}

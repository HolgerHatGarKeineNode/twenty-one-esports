<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A taken seat at a Hyperbitcoinization lobby table (App\Models\HyperTable): a player or a bot, the faction
 * once chosen (unique per table; null = drawn at the start), and for a rematch whether the player said yes.
 *
 * @property int $id
 * @property int $hyper_table_id
 * @property int $seat
 * @property int|null $user_id null for a bot
 * @property bool $bot
 * @property string|null $faction
 * @property bool $ready
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read HyperTable $table
 * @property-read User|null $user
 */
#[Fillable(['hyper_table_id', 'seat', 'user_id', 'bot', 'faction', 'ready'])]
class HyperTableSeat extends Model
{
    protected function casts(): array
    {
        return [
            'seat' => 'integer',
            'bot' => 'boolean',
            'ready' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<HyperTable, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(HyperTable::class, 'hyper_table_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

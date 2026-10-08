<?php

namespace App\Models;

use Database\Factories\HyperSeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One seat of a Hyperbitcoinization match: a player or a bot, its faction (unique per match) and, once the
 * match is over, its place and the sats it collected (loot). `seat` is the index in the rules core.
 *
 * A player's seat stays theirs when a bot takes it over (`takeover`): after they left (`left`), after
 * `esports.hyper.takeover_timeouts` timed-out turns in a row (`timeouts`), or after leaving a rated match
 * (`forfeit`). `bot` is true for a bot from the start and for a taken-over seat.
 *
 * @property int $id
 * @property int $hyper_match_id
 * @property int $seat
 * @property int|null $user_id null for a bot seat, or once the player deleted the account
 * @property string $faction
 * @property bool $bot
 * @property int|null $team
 * @property int|null $place 1 = winner, set when the seat is out or the match is over
 * @property float $loot
 * @property int $timeouts timed-out turns in a row
 * @property string|null $takeover left|timeouts|forfeit
 * @property Carbon|null $left_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read HyperMatch $match
 * @property-read User|null $user
 */
#[Fillable(['hyper_match_id', 'seat', 'user_id', 'faction', 'bot', 'team', 'place', 'loot', 'timeouts', 'takeover', 'left_at'])]
class HyperSeat extends Model
{
    /** @use HasFactory<HyperSeatFactory> */
    use HasFactory;

    public const TAKEOVER_LEFT = 'left';

    public const TAKEOVER_TIMEOUTS = 'timeouts';

    public const TAKEOVER_FORFEIT = 'forfeit';

    protected function casts(): array
    {
        return [
            'seat' => 'integer',
            'bot' => 'boolean',
            'team' => 'integer',
            'place' => 'integer',
            'loot' => 'float',
            'timeouts' => 'integer',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HyperMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(HyperMatch::class, 'hyper_match_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

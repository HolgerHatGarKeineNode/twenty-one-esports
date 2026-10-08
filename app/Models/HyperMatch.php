<?php

namespace App\Models;

use App\Enums\HyperEndReason;
use App\Enums\HyperMatchStatus;
use Database\Factories\HyperMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Hyperbitcoinization match (plan "Hyperbitcoinization", P2): 2 to 6 seats (hyper_seats), the rules
 * core's state after the last action, and the action log (hyper_actions) that replays it from the seed.
 * App\Support\Hyper\HyperMatches is the only writer. URLs and channel names use the `ulid`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $mode `live` (P2) or `correspondence` (P3)
 * @property HyperMatchStatus $status
 * @property int $seed 32-bit unsigned seed of the rules core
 * @property int $round_limit 0 = none
 * @property bool $rated
 * @property array<string, mixed> $state HyperGame::toArray() after the last action
 * @property int $ply number of actions played
 * @property int|null $current_seat the seat to move, null once over
 * @property int|null $turn_started_ms Unix ms the current turn began
 * @property int|null $deadline_ms Unix ms the current turn runs out, null once over
 * @property int|null $winner_seat
 * @property HyperEndReason|null $end_reason
 * @property int|null $created_by
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, HyperSeat> $seats
 * @property-read Collection<int, HyperAction> $actions
 * @property-read User|null $creator
 */
#[Fillable(['mode', 'status', 'seed', 'round_limit', 'rated', 'state', 'ply', 'current_seat', 'turn_started_ms', 'deadline_ms', 'winner_seat', 'end_reason', 'created_by', 'ended_at'])]
class HyperMatch extends Model
{
    /** @use HasFactory<HyperMatchFactory> */
    use HasFactory;

    use HasUlids;

    public const LIVE = 'live';

    public const CORRESPONDENCE = 'correspondence';

    protected function casts(): array
    {
        return [
            'status' => HyperMatchStatus::class,
            'end_reason' => HyperEndReason::class,
            'seed' => 'integer',
            'round_limit' => 'integer',
            'rated' => 'boolean',
            'state' => 'array',
            'ply' => 'integer',
            'current_seat' => 'integer',
            'turn_started_ms' => 'integer',
            'deadline_ms' => 'integer',
            'winner_seat' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return HasMany<HyperSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(HyperSeat::class)->orderBy('seat');
    }

    /**
     * @return HasMany<HyperAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(HyperAction::class)->orderBy('ply');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === HyperMatchStatus::Active;
    }

    /**
     * The seat this user plays, null for everyone else (spectators, guests).
     */
    public function seatOf(?User $user): ?HyperSeat
    {
        if ($user === null) {
            return null;
        }

        return $this->seats->first(fn (HyperSeat $seat): bool => $seat->user_id !== null && (int) $seat->user_id === (int) $user->id);
    }
}

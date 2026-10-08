<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A table in the Hyperbitcoinization lobby (plan "Hyperbitcoinization", P3): 2 to 6 seats waiting for players
 * and bots, then one match (`hyper_match_id`). App\Support\Hyper\HyperLobby is the only writer. A free seat
 * has no row in `hyper_table_seats`. A rematch is a table with `rematch_of` (the match it follows), whose
 * seats are the old lineup and start once every player said yes (`ready`). URLs use the `ulid`.
 *
 * @property int $id
 * @property string $ulid
 * @property string $mode HyperMatch::LIVE or ::CORRESPONDENCE
 * @property int $seats 2 to 6
 * @property int $round_limit 0 = none
 * @property list<int|null>|null $team_clans a clan table (P4): the clan of side 0 and side 1 (seats alternate), null for a table of single players
 * @property string $status open|started|cancelled
 * @property int|null $created_by
 * @property int|null $hyper_match_id the match once started
 * @property int|null $rematch_of
 * @property Carbon|null $fill_at when bots take a live table's free seats
 * @property Carbon|null $started_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, HyperTableSeat> $takenSeats
 * @property-read User|null $creator
 * @property-read HyperMatch|null $match
 */
#[Fillable(['mode', 'seats', 'round_limit', 'team_clans', 'status', 'created_by', 'hyper_match_id', 'rematch_of', 'fill_at', 'started_at'])]
class HyperTable extends Model
{
    use HasUlids;

    public const OPEN = 'open';

    public const STARTED = 'started';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'round_limit' => 'integer',
            'team_clans' => 'array',
            'fill_at' => 'datetime',
            'started_at' => 'datetime',
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
     * @return HasMany<HyperTableSeat, $this>
     */
    public function takenSeats(): HasMany
    {
        return $this->hasMany(HyperTableSeat::class)->orderBy('seat');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<HyperMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(HyperMatch::class, 'hyper_match_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function seatOf(?User $user): ?HyperTableSeat
    {
        if ($user === null) {
            return null;
        }

        return $this->takenSeats->first(fn (HyperTableSeat $seat): bool => $seat->user_id !== null && (int) $seat->user_id === (int) $user->id);
    }

    /**
     * A clan table (P4): two sides seated alternately, each side one clan.
     */
    public function isTeamTable(): bool
    {
        return $this->team_clans !== null;
    }

    /**
     * The side of a seat at a clan table: 0 for seats 0, 2, 4; 1 for seats 1, 3, 5.
     */
    public static function sideOf(int $seat): int
    {
        return $seat % 2;
    }

    public function freeSeats(): int
    {
        return max(0, $this->seats - $this->takenSeats->count());
    }
}

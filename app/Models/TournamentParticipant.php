<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a tournament: a player (chess, RL 1v1), a lineup (RL 2v2/3v3)
 * or a drawn mix team (P8b). `rating` is the Elo it was seeded with (a mix
 * team has no Elo: it keeps the start value and is seeded after the lineups
 * in draw order, `draw_position`), `seed` and `group` are set when the bracket is generated.
 * `members` are its players: the player, the lineup's fielded players, or the
 * mix team drawn from the solo pool.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property int|null $lineup_id
 * @property string $name
 * @property int $rating
 * @property int|null $seed
 * @property int|null $group
 * @property list<int>|null $members user ids
 * @property int|null $tournament_signup_id
 * @property int|null $draw_position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $user
 * @property-read Lineup|null $lineup
 */
#[Fillable(['tournament_id', 'user_id', 'lineup_id', 'name', 'rating', 'seed', 'group', 'members', 'tournament_signup_id', 'draw_position'])]
class TournamentParticipant extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'seed' => 'integer', 'group' => 'integer', 'members' => 'array', 'draw_position' => 'integer'];
    }

    public function isMixTeam(): bool
    {
        return $this->draw_position !== null;
    }

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return $this->members === null ? ($this->user_id === null ? [] : [$this->user_id]) : array_map(intval(...), $this->members);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}

<?php

namespace App\Models;

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use Database\Factories\PongMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A live Proof of Pong match between two players (plan "Proof of Pong", P2). Side 0 (`left_id`) plays left, or at the
 * bottom on a phone held upright, side 1 (`right_id`) right. The referee's state of the rally in play is `state.ref`
 * (App\Support\Pong\PongReferee), the players' presence and the rematch offers are the rest of `state`; `log` keeps
 * every serve, hit and goal. App\Support\Pong\PongMatches is the only writer. URLs and channel names use the `ulid`.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $left_id
 * @property int|null $right_id
 * @property int $seed 32-bit unsigned seed of the rallies and meme events
 * @property PongMatchStatus $status
 * @property int $score_left
 * @property int $score_right
 * @property array<string, mixed> $state
 * @property list<array<int|string, mixed>> $log
 * @property bool $rated whether its result moves the players' Proof of Pong Elo (live player against player)
 * @property int|null $winner_id
 * @property PongEndReason|null $end_reason
 * @property int|null $rematch_of_id
 * @property int|null $left_rating_before
 * @property int|null $left_rating_after
 * @property int|null $right_rating_before
 * @property int|null $right_rating_after
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $left
 * @property-read User|null $right
 * @property-read User|null $winner
 */
#[Fillable(['left_id', 'right_id', 'seed', 'status', 'score_left', 'score_right', 'state', 'log', 'rated', 'winner_id', 'end_reason', 'rematch_of_id', 'left_rating_before', 'left_rating_after', 'right_rating_before', 'right_rating_after', 'started_at', 'ended_at'])]
class PongMatch extends Model
{
    /** @use HasFactory<PongMatchFactory> */
    use HasFactory;

    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => PongMatchStatus::class,
            'end_reason' => PongEndReason::class,
            'seed' => 'integer',
            'score_left' => 'integer',
            'score_right' => 'integer',
            'state' => 'array',
            'log' => 'array',
            'rated' => 'boolean',
            'left_rating_before' => 'integer',
            'left_rating_after' => 'integer',
            'right_rating_before' => 'integer',
            'right_rating_after' => 'integer',
            'started_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function left(): BelongsTo
    {
        return $this->belongsTo(User::class, 'left_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function right(): BelongsTo
    {
        return $this->belongsTo(User::class, 'right_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    /** The side this player plays (0 left, 1 right), or null for anybody else. */
    public function sideOf(?User $user): ?int
    {
        return match (true) {
            $user === null => null,
            $user->id === $this->left_id => 0,
            $user->id === $this->right_id => 1,
            default => null,
        };
    }

    /** The player of side `$side`. */
    public function player(int $side): ?User
    {
        return $side === 0 ? $this->left : $this->right;
    }

    /**
     * @return array{int, int}
     */
    public function score(): array
    {
        return [$this->score_left, $this->score_right];
    }

    public function isOver(): bool
    {
        return in_array($this->status, [PongMatchStatus::Finished, PongMatchStatus::Aborted], true);
    }

    /**
     * Waiting for its players or in play.
     *
     * @param  Builder<PongMatch>  $query
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $query->whereIn('status', [PongMatchStatus::Waiting, PongMatchStatus::Active]);
    }

    /**
     * Matches this user plays, either side.
     *
     * @param  Builder<PongMatch>  $query
     */
    #[Scope]
    protected function playedBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('left_id', $user->id)->orWhere('right_id', $user->id));
    }
}

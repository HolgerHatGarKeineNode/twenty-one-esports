<?php

namespace App\Models;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A special tournament (P8), created by an admin or an organizer an admin
 * unlocked. Its matches count for Elo like every other match, but never mine
 * season-chain blocks: the chain belongs to the season, a tournament has its
 * own prize pot (P9).
 *
 * `capacity` is the number of participants the format was chosen for,
 * `time_window` the time the organizer has, in the game's unit (minutes, or
 * days for daily chess), `stations` the boards or stations on site (null =
 * online), `times` the organizer's own planning values (game, setup, break).
 *
 * @property int $id
 * @property string $name
 * @property string $game
 * @property string $mode
 * @property TournamentFormat $format
 * @property array<string, mixed> $options see {@see FormatOptions}
 * @property int $capacity
 * @property Carbon $starts_at
 * @property int $time_window
 * @property bool $on_site
 * @property int|null $stations
 * @property array{game?: float, setup?: float, break?: float}|null $times
 * @property TournamentResultsMode $results_mode
 * @property TournamentStatus $status
 * @property string|null $seed
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, User> $directors
 * @property-read Collection<int, TournamentParticipant> $participants
 * @property-read Collection<int, TournamentStage> $stages
 * @property-read Collection<int, TournamentMatch> $matches
 */
#[Fillable(['name', 'game', 'mode', 'format', 'options', 'capacity', 'starts_at', 'time_window', 'on_site', 'stations', 'times', 'results_mode', 'status', 'seed', 'created_by_id'])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'format' => TournamentFormat::class,
            'options' => 'array',
            'capacity' => 'integer',
            'starts_at' => 'datetime',
            'time_window' => 'integer',
            'on_site' => 'boolean',
            'stations' => 'integer',
            'times' => 'array',
            'results_mode' => TournamentResultsMode::class,
            'status' => TournamentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The named tournament directors (the creator directs without being named).
     *
     * @return BelongsToMany<User, $this>
     */
    public function directors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tournament_directors')->withPivot('added_by_id')->withTimestamps();
    }

    /**
     * @return HasMany<TournamentParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    /**
     * @return HasMany<TournamentStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(TournamentStage::class)->orderBy('number');
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }

    /**
     * The planning values of the game and mode, with the organizer's own times.
     */
    public function profile(): GameProfile
    {
        $times = $this->times ?? [];

        return GameProfile::for($this->game, $this->mode)->withTimes($times['game'] ?? null, $times['setup'] ?? null, $times['break'] ?? null);
    }

    public function formatOptions(): FormatOptions
    {
        return FormatOptions::fromArray($this->options, $this->profile());
    }

    /**
     * Planned duration in the game's unit, as the chooser estimated it.
     */
    public function plannedDuration(): float
    {
        $estimator = new Estimator;
        $options = $this->formatOptions();
        $profile = $this->profile();

        return $estimator->duration($this->format, $estimator->structure($this->format, $this->capacity, $options), $profile, $options, $profile->isDaily() ? null : $this->stations)->total;
    }

    /**
     * The creator and the named directors enter results in director mode (P8b).
     */
    public function isDirectedBy(User $user): bool
    {
        return $this->created_by_id === $user->id
            || $this->directors()->whereKey($user->id)->exists();
    }

    /**
     * A draft is seen by its creator, its directors and admins only.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->status !== TournamentStatus::Draft) {
            return true;
        }

        return $user !== null && ($user->isAdmin() || $this->isDirectedBy($user));
    }
}

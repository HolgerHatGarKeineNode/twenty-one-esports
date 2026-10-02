<?php

namespace App\Models;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Games\ScoreMetric;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\ServerIngest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One value a score source saw for a player on a course (plan "AoE2 und
 * Trackmania", P4): a manual submission, a director's entry, a poller's or a
 * server's record. Rows are never overwritten: a later, worse or better
 * record is a new row, so the best inside a window stays readable after the
 * source replaced it (App\Support\Scores\ScoreRuns reads the best).
 *
 * - `source`: `manual` (a player submitted it with a proof URL; counts once
 *   an admin verified it), `director` (entered or corrected by a director or
 *   admin with `note` as the reason; counts at once and overrides the other
 *   sources for that player in its tournament; `value` null = no valid value),
 *   or the key of a poller or server source (verified when read).
 * - `account_id`: the player's private game account id a server reported; with
 *   `user_id` null the account belongs to no player yet (pending, never shown).
 *   Hidden from every serialization. Such a run of an id nobody stored and
 *   nobody confirmed is pruned once it is older than
 *   `esports.score_games.prune_days` (round-4 F6: a public server's strangers).
 *
 * @property int $id
 * @property int|null $tournament_id
 * @property int|null $user_id
 * @property string $game
 * @property string $mode
 * @property string $course
 * @property int|null $value
 * @property string $unit
 * @property string $source
 * @property Carbon $achieved_at
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_id
 * @property Carbon|null $rejected_at
 * @property string|null $note
 * @property string|null $proof_url
 * @property array<string, mixed>|null $raw
 * @property string|null $account_id
 * @property int|null $score_server_id
 * @property string|null $external_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $verifiedBy
 * @property-read Tournament|null $tournament
 */
#[Fillable(['tournament_id', 'user_id', 'game', 'mode', 'course', 'value', 'unit', 'source', 'achieved_at', 'verified_at', 'verified_by_id',
    'rejected_at', 'note', 'proof_url', 'raw', 'account_id', 'score_server_id', 'external_id'])]
#[Hidden(['account_id', 'raw'])]
class ScoreRun extends Model
{
    use MassPrunable;

    public const MANUAL = 'manual';

    public const DIRECTOR = 'director';

    /**
     * The runs of an id that belongs to nobody: pending, of a registered
     * score game (an unregistered game's runs are kept, nothing is known
     * about its ids), no player has the id in their gamer tags (with or
     * without a stored-at row, as ScoreAccounts::blockingFor() reads it),
     * nobody confirmed it, older than `prune_days`, and outside the window
     * of every running or open leaderboard on its course (round-5 H: a
     * window can be longer than `prune_days`).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $games = app(GameRegistry::class)->scores();
        $query = static::query()->whereNull('user_id')->whereNotNull('account_id')->whereIn('game', array_keys($games))
            ->where('achieved_at', '<', now()->subDays(max(1, (int) config('esports.score_games.prune_days', 30))))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('score_account_tags as t')->whereColumn('t.game', 'score_runs.game')->whereColumn('t.account_id', 'score_runs.account_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('score_account_claims as c')->whereColumn('c.game', 'score_runs.game')->whereColumn('c.account_id', 'score_runs.account_id'));

        foreach ($games as $slug => $game) {
            $service = $game->accountService();

            if ($service !== null) {
                $query->whereNot(fn ($query) => $query->where('game', $slug)
                    ->whereExists(fn ($users) => $users->selectRaw('1')->from('users')->whereColumn("users.gamer_tags->{$service}", 'score_runs.account_id')));
            }
        }

        $open = Tournament::query()->whereIn('game', array_keys($games))->whereIn('status', [TournamentStatus::Running, TournamentStatus::Signup, TournamentStatus::Drawing])->get();

        foreach ($open as $tournament) {
            $window = ScoreWindow::of($tournament);
            $query->whereNot(fn ($query) => $query->where(['game' => $tournament->game, 'mode' => $tournament->mode, 'course' => (string) $tournament->score_course])
                ->where('achieved_at', '>=', $window->start)->where('achieved_at', '<', $window->end));
        }

        return $query;
    }

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'achieved_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->verified_at === null && $this->rejected_at === null;
    }

    public function metric(): ?ScoreMetric
    {
        $game = app(GameRegistry::class)->find($this->game);
        $mode = $game?->mode($this->mode);

        return $game instanceof ScoreGame && $mode !== null ? $game->metric($mode) : null;
    }

    /**
     * The value for people, in its metric; `—` for a director's "no valid value".
     */
    public function formatted(): string
    {
        if ($this->value === null) {
            return '—';
        }

        return $this->metric()?->format($this->value) ?? (string) $this->value;
    }

    /**
     * Values still waiting for an admin: manual submissions, and the server
     * finishes an adapter held for one tournament (a flagged time that would
     * enter its top places, ServerIngest::review()).
     *
     * @param  Builder<self>  $query
     */
    protected function scopePendingReview(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->where('source', self::MANUAL)
            ->orWhere(fn (Builder $query) => $query->where('source', ServerIngest::SOURCE)->whereNotNull('tournament_id')))
            ->whereNull('verified_at')->whereNull('rejected_at');
    }

    /**
     * A server finish held for an admin (ServerIngest::review()).
     */
    public function isHeld(): bool
    {
        return $this->source === ServerIngest::SOURCE && $this->tournament_id !== null && $this->isPending();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}

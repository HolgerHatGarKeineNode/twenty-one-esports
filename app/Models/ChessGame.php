<?php

namespace App\Models;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Support\Nostr\NostrKeys;
use Database\Factories\ChessGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One live chess game between two players. The server holds the position and
 * the clocks; App\Support\Chess\ChessGameService is the only writer.
 *
 * Clocks: `white_ms` / `black_ms` are the time each side had left when the
 * side to move started thinking (`turn_started_ms`, Unix ms). The side to
 * move's real time left is its value minus the time since then; the other
 * value is exact. Before both first moves no clock runs.
 *
 * @property int $id
 * @property int|null $number league match number, shared with series (MatchNumber)
 * @property string $mode
 * @property bool $rated
 * @property array<string, mixed>|null $gate_at_accept the trust gate pinned when the league paired a rated game (App\Support\SeasonChain\GatePin)
 * @property array<string, string>|null $clans_at_accept pubkey => clan address at the pairing of a rated game
 * @property int|null $white_id null once the player deleted the account
 * @property int|null $black_id null once the player deleted the account
 * @property ChessGameStatus $status
 * @property string|null $result
 * @property ChessEndReason|null $end_reason
 * @property string|null $start_fen null = the normal start position
 * @property string $fen
 * @property int $ply
 * @property int $initial_ms
 * @property int $increment_ms
 * @property int $white_ms
 * @property int $black_ms
 * @property int $turn_started_ms
 * @property int|null $deadline_ms
 * @property 'w'|'b'|null $draw_offer
 * @property 'w'|'b'|null $rematch_offer
 * @property int|null $rematch_of_id
 * @property int|null $rematch_id
 * @property int $version
 * @property array<string, string>|null $pgn_headers
 * @property int|null $record_event_id
 * @property int|null $reminded_ply
 * @property int|null $tournament_match_id the tournament match this game plays (P8b)
 * @property int|null $white_gone_ms
 * @property int|null $black_gone_ms
 * @property 'dm'|'push'|'here'|null $white_notify
 * @property 'dm'|'push'|'here'|null $black_notify
 * @property bool $white_remind
 * @property bool $black_remind
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $white
 * @property-read User $black
 * @property-read Collection<int, ChessMove> $moves
 * @property-read ChessGame|null $rematch
 * @property-read NostrEvent|null $recordEvent
 * @property-read TournamentMatch|null $tournamentMatch
 */
#[Fillable(['number', 'mode', 'rated', 'gate_at_accept', 'clans_at_accept', 'white_id', 'black_id', 'status', 'result', 'end_reason', 'start_fen', 'fen', 'ply', 'initial_ms', 'increment_ms',
    'white_ms', 'black_ms', 'turn_started_ms', 'deadline_ms', 'draw_offer', 'rematch_offer', 'rematch_of_id', 'rematch_id', 'version', 'ended_at',
    'pgn_headers', 'record_event_id', 'reminded_ply', 'white_gone_ms', 'black_gone_ms', 'white_notify', 'black_notify', 'white_remind', 'black_remind', 'tournament_match_id'])]
class ChessGame extends Model
{
    /** @use HasFactory<ChessGameFactory> */
    use HasFactory;

    public const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    /** The daily mode: one move per day, a deadline per move, no running clock. */
    public const CORRESPONDENCE = 'correspondence';

    /**
     * Every game takes the next league match number when it is created, from
     * the same sequence as Rocket League series (NIP "Terminology": casual
     * games take numbers too), recorded as used by White.
     */
    protected static function booted(): void
    {
        static::creating(function (ChessGame $game): void {
            $game->number ??= MatchNumber::query()->create(['user_id' => $game->white_id, 'used_at' => now()])->id;
        });
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'rated' => 'boolean',
            'gate_at_accept' => 'array',
            'clans_at_accept' => 'array',
            'status' => ChessGameStatus::class,
            'end_reason' => ChessEndReason::class,
            'ply' => 'integer',
            'initial_ms' => 'integer',
            'increment_ms' => 'integer',
            'white_ms' => 'integer',
            'black_ms' => 'integer',
            'turn_started_ms' => 'integer',
            'deadline_ms' => 'integer',
            'version' => 'integer',
            'ended_at' => 'datetime',
            'pgn_headers' => 'array',
            'reminded_ply' => 'integer',
            'white_gone_ms' => 'integer',
            'black_gone_ms' => 'integer',
            'white_remind' => 'boolean',
            'black_remind' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function tournamentMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function white(): BelongsTo
    {
        return $this->belongsTo(User::class, 'white_id')->withDefault(fn (User $user) => self::deletedPlayer($user));
    }

    /**
     * The side of a player whose account is gone (the game stays, security
     * re-check item 4): an unsaved user named "Deleted player", with an
     * all-zero key, so views and the PGN keep working; `exists` is false.
     */
    public static function deletedPlayer(User $user): User
    {
        $pubkey = str_repeat('0', 64);

        return $user->forceFill(['name' => __('Deleted player'), 'pubkey' => $pubkey, 'npub' => NostrKeys::hexToNpub($pubkey)]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function black(): BelongsTo
    {
        return $this->belongsTo(User::class, 'black_id')->withDefault(fn (User $user) => self::deletedPlayer($user));
    }

    /**
     * @return HasMany<ChessMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(ChessMove::class)->orderBy('ply');
    }

    /**
     * @return BelongsTo<ChessGame, $this>
     */
    public function rematch(): BelongsTo
    {
        return $this->belongsTo(ChessGame::class, 'rematch_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function recordEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'record_event_id');
    }

    /**
     * Games played live on the board (blitz): a player is in at most one.
     * Daily games run for weeks next to them.
     *
     * @param  Builder<ChessGame>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('mode', '!=', self::CORRESPONDENCE);
    }

    /**
     * @param  Builder<ChessGame>  $query
     */
    #[Scope]
    protected function daily(Builder $query): void
    {
        $query->where('mode', self::CORRESPONDENCE);
    }

    /**
     * Games this user plays, either colour.
     *
     * @param  Builder<ChessGame>  $query
     */
    #[Scope]
    protected function playedBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('white_id', $user->id)->orWhere('black_id', $user->id));
    }

    public function isCorrespondence(): bool
    {
        return $this->mode === self::CORRESPONDENCE;
    }

    /**
     * @param  'w'|'b'  $color
     */
    public function player(string $color): User
    {
        return $color === 'w' ? $this->white : $this->black;
    }

    public function opponentOf(?User $user): ?User
    {
        return match ($this->colorOf($user)) {
            'w' => $this->black,
            'b' => $this->white,
            default => null,
        };
    }

    public function startFen(): string
    {
        return $this->start_fen ?? self::START_FEN;
    }

    public function isActive(): bool
    {
        return $this->status === ChessGameStatus::Active;
    }

    /**
     * 'w' or 'b' for a player of this game, null for everyone else.
     *
     * @return 'w'|'b'|null
     */
    public function colorOf(?User $user): ?string
    {
        return match ($user?->id) {
            null => null,
            $this->white_id => 'w',
            $this->black_id => 'b',
            default => null,
        };
    }

    /**
     * @return 'w'|'b'
     */
    public function turn(): string
    {
        return explode(' ', $this->fen)[1] === 'b' ? 'b' : 'w';
    }

    /**
     * Both sides have made their first move; from here on the clocks run.
     */
    public function clocksRunning(): bool
    {
        return $this->ply >= 2;
    }

    public function number(): string
    {
        return '#'.$this->number;
    }
}

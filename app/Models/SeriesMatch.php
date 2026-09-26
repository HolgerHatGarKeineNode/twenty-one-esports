<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameMode;
use App\Games\GameRegistry;
use Database\Factories\SeriesMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A series between two lineups (Rocket League), from the challenge to the
 * result. See App\Support\Series\SeriesService for the rules and the NIP
 * mapping. `number` is the league match number (`#402`).
 *
 * The lobby is private to the two lineups: encrypted at rest, hidden from
 * every array/JSON form of the model, and read only by the match room.
 *
 * @property int $id
 * @property int $number
 * @property string $game
 * @property string $mode
 * @property int $best_of
 * @property bool $rated
 * @property int|null $challenger_lineup_id
 * @property int|null $challenged_lineup_id
 * @property string $challenger_name
 * @property string $challenged_name
 * @property string $challenger_tag
 * @property string $challenged_tag
 * @property string $challenger_lineup_address
 * @property string $challenged_lineup_address
 * @property string|null $ladder_address
 * @property int|null $created_by_id
 * @property SeriesStatus $status
 * @property list<int> $proposals unix seconds
 * @property Carbon $respond_by
 * @property Carbon|null $start_at
 * @property string|null $message
 * @property int|null $answered_by_id
 * @property Carbon|null $answered_at
 * @property array<string, string>|null $clans_at_accept pubkey => clan address at the accept
 * @property array<string, mixed>|null $gate_at_accept the trust gate pinned at a rated accept (App\Support\SeasonChain\GatePin)
 * @property array{challenger?: string, challenged?: string}|null $rated_subjects the rated entities pinned at a rated accept (`lineup:<id>`)
 * @property list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>|null $resolved_roster the roster of an admin decision
 * @property string|null $lobby_name
 * @property string|null $lobby_password
 * @property string|null $lobby_region
 * @property int|null $lobby_updated_by_id
 * @property list<array{challenger: int|null, challenged: int|null, winner: string|null}>|null $live_games
 * @property array{challenger?: list<int>, challenged?: list<int>}|null $rosters user ids per side
 * @property string|null $noshow_side the side that showed up and reported the other missing
 * @property Carbon|null $noshow_reported_at
 * @property Carbon|null $new_report_requested_at
 * @property list<array{winner: string, challenger: int|null, challenged: int|null}>|null $result_games
 * @property string|null $winner challenger|challenged|none
 * @property SeriesResolution|null $resolution
 * @property string|null $resolution_reason
 * @property int|null $resolved_by_id
 * @property Carbon|null $finished_at
 * @property int|null $challenge_event_id
 * @property int|null $answer_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lineup|null $challengerLineup
 * @property-read Lineup|null $challengedLineup
 * @property-read User|null $createdBy
 * @property-read User|null $answeredBy
 * @property-read User|null $resolvedBy
 * @property-read NostrEvent|null $challengeEvent
 * @property-read NostrEvent|null $answerEvent
 * @property-read SeriesReport|null $latestReport
 */
#[Fillable([
    'number', 'game', 'mode', 'best_of', 'rated',
    'challenger_lineup_id', 'challenged_lineup_id', 'challenger_name', 'challenged_name', 'challenger_tag', 'challenged_tag',
    'challenger_lineup_address', 'challenged_lineup_address', 'ladder_address', 'created_by_id',
    'status', 'proposals', 'respond_by', 'start_at', 'message', 'answered_by_id', 'answered_at', 'clans_at_accept', 'gate_at_accept', 'rated_subjects', 'resolved_roster',
    'lobby_name', 'lobby_password', 'lobby_region', 'lobby_updated_by_id',
    'live_games', 'rosters', 'noshow_side', 'noshow_reported_at', 'new_report_requested_at',
    'result_games', 'winner', 'resolution', 'resolution_reason', 'resolved_by_id', 'finished_at',
    'challenge_event_id', 'answer_event_id',
])]
#[Hidden(['lobby_name', 'lobby_password'])]
class SeriesMatch extends Model
{
    /** @use HasFactory<SeriesMatchFactory> */
    use HasFactory;

    public const SIDES = ['challenger', 'challenged'];

    /**
     * The roster the result counts: an admin decision's own, otherwise the
     * latest report's (NIP "League Attestation"), empty without either.
     *
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    public function countedRoster(): array
    {
        return $this->resolved_roster ?? ($this->latestReport instanceof SeriesReport ? $this->latestReport->roster : []);
    }

    protected function casts(): array
    {
        return [
            'rated' => 'boolean',
            'status' => SeriesStatus::class,
            'proposals' => 'array',
            'respond_by' => 'datetime',
            'start_at' => 'datetime',
            'answered_at' => 'datetime',
            'clans_at_accept' => 'array',
            'gate_at_accept' => 'array',
            'rated_subjects' => 'array',
            'resolved_roster' => 'array',
            'lobby_name' => 'encrypted',
            'lobby_password' => 'encrypted',
            'live_games' => 'array',
            'rosters' => 'array',
            'noshow_reported_at' => 'datetime',
            'new_report_requested_at' => 'datetime',
            'result_games' => 'array',
            'resolution' => SeriesResolution::class,
            'finished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function challengerLineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class, 'challenger_lineup_id');
    }

    /**
     * @return BelongsTo<Lineup, $this>
     */
    public function challengedLineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class, 'challenged_lineup_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function challengeEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'challenge_event_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function answerEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'answer_event_id');
    }

    /**
     * @return HasMany<SeriesReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(SeriesReport::class)->orderBy('id');
    }

    /**
     * @return HasOne<SeriesReport, $this>
     */
    public function latestReport(): HasOne
    {
        return $this->hasOne(SeriesReport::class)->ofMany('id', 'max');
    }

    /**
     * @return HasMany<DisputeEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class)->orderBy('id');
    }

    public function gameMode(): GameMode
    {
        return app(GameRegistry::class)->mode($this->game, $this->mode)
            ?? throw new \LogicException("Match {$this->number} has an unknown mode.");
    }

    public function label(): string
    {
        return '#'.$this->number;
    }

    public function lineup(string $side): ?Lineup
    {
        return $side === 'challenger' ? $this->challengerLineup : $this->challengedLineup;
    }

    public function sideName(string $side): string
    {
        return $side === 'challenger' ? $this->challenger_name : $this->challenged_name;
    }

    public function sideTag(string $side): string
    {
        return $side === 'challenger' ? $this->challenger_tag : $this->challenged_tag;
    }

    public static function otherSide(string $side): string
    {
        return $side === 'challenger' ? 'challenged' : 'challenger';
    }

    /**
     * The side whose lineup this user is an acting captain of (NIP
     * "Terminology"): still a member of the lineup's clan, and the lineup's
     * author (the clan owner) or seated as its captain.
     */
    public function captainSideOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach (self::SIDES as $side) {
            if ($this->lineup($side)?->isActingCaptain($user) === true) {
                return $side;
            }
        }

        return null;
    }

    /**
     * The side this user plays for: an active (accepted, still in the clan)
     * seat in one of the two lineups. Only these users see the lobby.
     */
    public function participantSideOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach (self::SIDES as $side) {
            if ($this->lineup($side)?->activeSeatOf($user) !== null) {
                return $side;
            }
        }

        return $this->captainSideOf($user);
    }

    /**
     * Games won per side in a list of games.
     *
     * @param  list<array<string, mixed>>|null  $games
     * @return array{challenger: int, challenged: int}
     */
    public static function seriesScore(?array $games): array
    {
        $score = ['challenger' => 0, 'challenged' => 0];

        foreach ($games ?? [] as $game) {
            $winner = $game['winner'] ?? null;

            if ($winner === 'challenger' || $winner === 'challenged') {
                $score[$winner]++;
            }
        }

        return $score;
    }

    /**
     * The games that count right now: the result once there is one, else the
     * latest report, else the live sheet (provisional).
     *
     * @return list<array<string, mixed>>
     */
    public function currentGames(): array
    {
        if ($this->status->hasResult()) {
            return $this->result_games ?? [];
        }

        $report = $this->latestReport;

        if ($report !== null && $report->status !== ReportStatus::Superseded) {
            return $report->games;
        }

        return array_values(array_filter($this->live_games ?? [], fn (array $game) => ($game['winner'] ?? null) !== null));
    }

    /**
     * The status group of the lists (Matches.dc.html "Status" tabs).
     *
     * @return 'waiting'|'scheduled'|'live'|'to_confirm'|'disputed'|'done'|'closed'
     */
    public function listStatus(): string
    {
        return match ($this->status) {
            SeriesStatus::Open => 'waiting',
            SeriesStatus::Accepted => $this->start_at !== null && $this->start_at->isFuture() ? 'scheduled' : 'live',
            SeriesStatus::Reported => 'to_confirm',
            SeriesStatus::Disputed => 'disputed',
            SeriesStatus::Confirmed, SeriesStatus::Resolved => 'done',
            default => 'closed',
        };
    }
}

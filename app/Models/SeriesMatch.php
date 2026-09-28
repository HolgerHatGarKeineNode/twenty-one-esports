<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonInterface;
use Database\Factories\SeriesMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property int|null $tournament_match_id the tournament match this series plays (P8b)
 * @property int $tournament_attempt 1, or the replay number after an admin voided the series before (P18)
 * @property array{challenger?: list<int>, challenged?: list<int>}|null $sides a roster side's players (mix team, RL 1v1 player): no lineup
 * @property array{noshow_minutes: int, report_hours?: int, report_minutes?: int, response_minutes: int, pauses?: list<array{0: int, 1: int}>}|null $deadlines a players-mode tournament's deadlines, pinned at the pairing (P18; `report_minutes` on the round clock; `pauses`: the tournament's pauses while the series ran, unix seconds from and to, {@see pausedAfter()}); null = none run by the league
 * @property Carbon|null $overdue_at when the league moved it to the admin queue: nobody reported by the report deadline (P18)
 * @property string|null $origin a casual 1v1 without a clan (P23): `queue` or `invite`; null for every other series
 * @property string|null $host_side the side that opens the game lobby (casual 1v1), drawn at the pairing
 * @property Carbon|null $ready_by the ready check of a casual 1v1 ends then
 * @property Carbon|null $ready_at_challenger
 * @property Carbon|null $ready_at_challenged
 * @property Carbon|null $lobby_shared_at the host shared the lobby in the match chat (only the flag, never the lobby)
 * @property Carbon|null $joined_at the guest joined the host's lobby
 * @property Carbon|null $noshow_contested_at the accused side answered a casual no-show claim
 * @property Carbon|null $lobby_seen_at the guest's client opened a valid lobby or account card from the host (P23 S2; only the flag)
 * @property Carbon|null $host_swapped_at the host handed the host seat to the guest before sharing; the lobby deadline runs from then
 * @property Carbon|null $reminded_at a scheduled casual 1v1 (P23 S4): the start reminder went out
 * @property Carbon|null $checkin_opened_at a scheduled casual 1v1: "check-in is open" went out
 * @property array{ready_seconds?: int, lobby_minutes?: int, join_minutes?: int, contest_minutes?: int, report_minutes?: int, confirm_minutes?: int, checkin_before_minutes?: int, checkin_after_minutes?: int, scheduled_at?: int, queue?: array<string, array{platform: string, crossplay: bool}>}|null $casual the casual deadlines pinned at the pairing (a scheduled match: at the accept, with its agreed start), and each side's queue choice
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
 * @property-read TournamentMatch|null $tournamentMatch
 */
#[Fillable([
    'number', 'game', 'mode', 'best_of', 'rated',
    'challenger_lineup_id', 'challenged_lineup_id', 'challenger_name', 'challenged_name', 'challenger_tag', 'challenged_tag',
    'challenger_lineup_address', 'challenged_lineup_address', 'ladder_address', 'created_by_id',
    'status', 'proposals', 'respond_by', 'start_at', 'message', 'answered_by_id', 'answered_at', 'clans_at_accept', 'gate_at_accept', 'rated_subjects', 'resolved_roster',
    'lobby_name', 'lobby_password', 'lobby_region', 'lobby_updated_by_id',
    'live_games', 'rosters', 'noshow_side', 'noshow_reported_at', 'new_report_requested_at',
    'result_games', 'winner', 'resolution', 'resolution_reason', 'resolved_by_id', 'finished_at',
    'challenge_event_id', 'answer_event_id', 'tournament_match_id', 'tournament_attempt', 'sides',
    'deadlines', 'overdue_at',
    'origin', 'host_side', 'ready_by', 'ready_at_challenger', 'ready_at_challenged', 'lobby_shared_at', 'joined_at', 'noshow_contested_at', 'casual',
    'lobby_seen_at', 'host_swapped_at', 'reminded_at', 'checkin_opened_at',
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

    /**
     * `series_match_players` mirrors `sides` (one row per player and side),
     * so the series of a player is an index lookup (OpenMatches). Written
     * with the series, in its transaction; ids without a users row are
     * skipped rather than failing the series.
     */
    protected static function booted(): void
    {
        static::created(fn (SeriesMatch $match) => $match->syncSidePlayers());
        static::updated(function (SeriesMatch $match): void {
            if ($match->wasChanged('sides')) {
                $match->syncSidePlayers();
            }
        });
    }

    public function syncSidePlayers(): void
    {
        DB::table('series_match_players')->where('series_match_id', $this->id)->delete();

        foreach (self::SIDES as $side) {
            $ids = array_values(array_unique(array_map(intval(...), $this->sides[$side] ?? [])));

            if ($ids !== []) {
                DB::table('series_match_players')->insertUsing(['series_match_id', 'user_id', 'side'],
                    DB::table('users')->whereIn('id', $ids)->selectRaw('?, id, ?', [$this->id, $side]));
            }
        }
    }

    protected function casts(): array
    {
        return [
            'rated' => 'boolean',
            'tournament_attempt' => 'integer',
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
            'sides' => 'array',
            'deadlines' => 'array',
            'overdue_at' => 'datetime',
            'ready_by' => 'datetime',
            'ready_at_challenger' => 'datetime',
            'ready_at_challenged' => 'datetime',
            'lobby_shared_at' => 'datetime',
            'joined_at' => 'datetime',
            'noshow_contested_at' => 'datetime',
            'casual' => 'array',
            'lobby_seen_at' => 'datetime',
            'host_swapped_at' => 'datetime',
            'reminded_at' => 'datetime',
            'checkin_opened_at' => 'datetime',
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
     * @return BelongsTo<TournamentMatch, $this>
     */
    public function tournamentMatch(): BelongsTo
    {
        return $this->belongsTo(TournamentMatch::class);
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
     * The admins' open cases (the disputes queue): a disputed series, a
     * reported no-show (not a casual 1v1's claim, P23), a report nobody confirmed or disputed for
     * `esports.tournaments.unanswered_report_hours` (P18), and a tournament
     * series nobody reported by its report deadline (P18, `overdue_at`).
     *
     * @param  Builder<SeriesMatch>  $query
     */
    #[Scope]
    protected function openCase(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('status', SeriesStatus::Disputed)
            // A casual 1v1's no-show claim (P23) is the players' own: contested or forfeited by the clock, never an admin's case.
            ->orWhere(fn (Builder $query) => $query->where('status', SeriesStatus::Accepted)->whereNotNull('noshow_reported_at')->whereNull('origin'))
            ->orWhere(fn (Builder $query) => $query->where('status', SeriesStatus::Accepted)->whereNotNull('overdue_at'))
            ->orWhere(fn (Builder $query) => $query->where('status', SeriesStatus::Reported)
                ->whereHas('latestReport', fn (Builder $report) => $report->where('created_at', '<=', self::unansweredSince()))));
    }

    /** Reports made before this are unanswered for too long. */
    public static function unansweredSince(): CarbonInterface
    {
        return now()->subHours((int) config('esports.tournaments.unanswered_report_hours', 2));
    }

    /** A report nobody answered for `unanswered_report_hours` (P18). */
    public function isUnansweredReport(): bool
    {
        return $this->status === SeriesStatus::Reported
            && $this->latestReport?->created_at !== null
            && $this->latestReport->created_at->lte(self::unansweredSince());
    }

    /* ---------- Tournament deadlines (P18, slice 2) ------------------------------------------------------------ */

    /**
     * How long after the start a captain may report a no-show: the pinned
     * tournament value, else `esports.series.noshow_minutes`.
     */
    public function noshowMinutes(): int
    {
        return (int) ($this->deadlines['noshow_minutes'] ?? config('esports.series.noshow_minutes', 15));
    }

    /** How long the other side has to answer a report or a reported no-show; null without league deadlines. */
    public function responseMinutes(): ?int
    {
        return $this->deadlines === null ? null : (int) $this->deadlines['response_minutes'];
    }

    /** When a series nobody reported joins the admin queue; null without league deadlines. Round clock: minutes after the start. */
    public function reportDueAt(): ?CarbonInterface
    {
        if ($this->deadlines === null || $this->start_at === null) {
            return null;
        }

        $due = isset($this->deadlines['report_minutes'])
            ? $this->start_at->copy()->addMinutes((int) $this->deadlines['report_minutes'])
            : $this->start_at->copy()->addHours((int) ($this->deadlines['report_hours'] ?? config('esports.tournaments.report_hours', 2)));

        return $due->addSeconds($this->pausedAfter($this->start_at));
    }

    /** When a reported no-show the other side did not answer becomes a forfeit; null without one. */
    public function noshowForfeitAt(): ?CarbonInterface
    {
        return $this->deadlines === null || $this->noshow_reported_at === null ? null
            : $this->noshow_reported_at->copy()->addMinutes((int) $this->responseMinutes())->addSeconds($this->pausedAfter($this->noshow_reported_at));
    }

    /**
     * Seconds the tournament was paused after `$since` (P18,
     * TournamentControl): a deadline that runs from `$since` moves back by
     * as much, so the time of a pause never counts against a side.
     */
    public function pausedAfter(CarbonInterface $since): int
    {
        $seconds = 0;

        foreach ($this->deadlines['pauses'] ?? [] as [$from, $to]) {
            $seconds += max(0, (int) $to - max($since->getTimestamp(), (int) $from));
        }

        return $seconds;
    }

    /** When the open report is confirmed by the league; null without one. */
    public function responseDueAt(): ?CarbonInterface
    {
        $report = $this->latestReport;

        return $this->deadlines === null || $this->status !== SeriesStatus::Reported || $report === null || $report->status !== ReportStatus::Open || $report->created_at === null
            ? null
            : $report->created_at->copy()->addMinutes((int) $this->responseMinutes())->addSeconds($this->pausedAfter($report->created_at));
    }

    /**
     * The next deadline the league acts on (the countdown of P18 slice 4 and
     * the TV of P19), and the side it runs against:
     *
     * - `report`: nobody reported yet; either side may (`side` null). At the
     *   deadline the series joins the admin queue;
     * - `noshow`: a no-show was reported; the reported side has to enter a
     *   game or report, else it loses by forfeit;
     * - `response`: a result was reported; the other side has to answer,
     *   else the league confirms it.
     *
     * Null when none runs: no league deadlines (a ladder series, a director
     * tournament), a decided series, or one waiting for an admin.
     *
     * @return array{kind: 'report'|'noshow'|'response', at: CarbonInterface, side: 'challenger'|'challenged'|null}|null
     */
    public function nextDeadline(): ?array
    {
        if ($this->deadlines === null) {
            return null;
        }

        if ($this->status === SeriesStatus::Accepted && $this->noshow_reported_at !== null) {
            $at = $this->noshowForfeitAt();
            $side = $this->noshow_side === 'challenger' ? 'challenged' : 'challenger';

            return $at === null ? null : ['kind' => 'noshow', 'at' => $at, 'side' => $side];
        }

        if ($this->status === SeriesStatus::Accepted && $this->overdue_at === null) {
            $at = $this->reportDueAt();

            return $at === null ? null : ['kind' => 'report', 'at' => $at, 'side' => null];
        }

        $at = $this->responseDueAt();
        $report = $this->latestReport;

        return $at === null || $report === null ? null
            : ['kind' => 'response', 'at' => $at, 'side' => $report->side === 'challenger' ? 'challenged' : 'challenger'];
    }

    /* ---------- Casual 1v1 (P23, App\Support\Series\CasualMatches) -------------------------------------------- */

    public const ORIGIN_QUEUE = 'queue';

    public const ORIGIN_INVITE = 'invite';

    /** A scheduled casual 1v1 (P23 S4, App\Support\Series\CasualChallenges). */
    public const ORIGIN_CHALLENGE = 'challenge';

    /**
     * A casual cup's series match (P25 S3): started by the league for its
     * agreed time (or the cup's auto slot), then the scheduled casual flow:
     * check-in, lobby, report. Its no-shows never lock the casual queue.
     */
    public const ORIGIN_CUP = 'cup';

    /** The origins that wait for an agreed start and a check-in. */
    public const SCHEDULED_ORIGINS = [self::ORIGIN_CHALLENGE, self::ORIGIN_CUP];

    /** A casual 1v1 without a clan, from the queue, a direct invite or a scheduled challenge. */
    public function isCasualPairing(): bool
    {
        return $this->origin !== null;
    }

    /**
     * A casual deadline in the unit its key names: the value pinned at the
     * pairing, else `esports.casual.<key>` as in force now (LeagueSettings).
     */
    public function casualSetting(string $key): int
    {
        return (int) ($this->casual[$key] ?? LeagueSettings::get('esports.casual.'.$key));
    }

    public function readyAt(string $side): ?CarbonInterface
    {
        return $side === 'challenger' ? $this->ready_at_challenger : $this->ready_at_challenged;
    }

    /** Still in the ready check: paired, not started. */
    public function awaitsReady(): bool
    {
        return $this->isCasualPairing() && $this->status === SeriesStatus::Accepted && $this->start_at === null;
    }

    /**
     * A scheduled casual 1v1 (P23 S4): a challenge with 1 to 3 suggested
     * times; accepted, it waits for both players to check in. Until then
     * `start_at` is the agreed time; once both checked in it is the moment
     * the second one did, and the casual deadlines run from there as after
     * a ready check. `ready_by` is the close of the check-in window, so the
     * chat's expiration anchor (casualChatExpiresFrom()) is fixed from the
     * accept on.
     */
    public function isScheduledPairing(): bool
    {
        return in_array($this->origin, self::SCHEDULED_ORIGINS, true);
    }

    /** The agreed start of a scheduled casual 1v1, pinned at the accept; null before. */
    public function scheduledAt(): ?CarbonInterface
    {
        $at = $this->casual['scheduled_at'] ?? null;

        return is_int($at) ? now()->setTimestamp($at) : null;
    }

    public function checkInOpensAt(): ?CarbonInterface
    {
        return $this->scheduledAt()?->copy()->subMinutes($this->casualSetting('checkin_before_minutes'));
    }

    /** The check-in window closes then (`ready_by`): a side not in by then forfeits. */
    public function checkInClosesAt(): ?CarbonInterface
    {
        return $this->isScheduledPairing() ? $this->ready_by : null;
    }

    public function bothCheckedIn(): bool
    {
        return $this->ready_at_challenger !== null && $this->ready_at_challenged !== null;
    }

    /** Accepted and waiting for both players to check in. */
    public function awaitsCheckIn(): bool
    {
        return $this->isScheduledPairing() && $this->status === SeriesStatus::Accepted && ! $this->bothCheckedIn();
    }

    /**
     * The casual flow runs: past the ready check (or both checked in), not
     * reported yet. Lobby, join, no-show and the score belong to this phase.
     */
    public function casualUnderWay(): bool
    {
        return $this->isCasualPairing() && $this->status === SeriesStatus::Accepted && $this->start_at !== null
            && (! $this->isScheduledPairing() || $this->bothCheckedIn());
    }

    /**
     * The host shares the lobby by then; afterwards the guest may claim a
     * no-show. A swapped host (CasualMatches::swapHost()) gets the
     * full time again, from the swap.
     */
    public function casualLobbyDueAt(): ?CarbonInterface
    {
        return ($this->host_swapped_at ?? $this->start_at)?->copy()->addMinutes($this->casualSetting('lobby_minutes'));
    }

    /**
     * `A + D` of the NIP's "Expiration" (Lobby and account cards): the
     * latest regular end of a casual 1v1, the anchor of the NIP-40
     * `expiration` on every message of its room. `A` is the close of the
     * ready check (`ready_by`; a scheduled match: its start plus the
     * 10-minute check-in), `D` the pinned deadlines along the longest path
     * without a dispute: lobby, join, report, confirm. Both are fixed at the
     * pairing, so both clients get the same value for the whole match; a
     * host swap does not move it. Null for every other series.
     */
    public function casualChatExpiresFrom(): ?CarbonInterface
    {
        if (! $this->isCasualPairing()) {
            return null;
        }

        $anchor = $this->ready_by ?? $this->start_at?->copy()->addMinutes(10);
        $minutes = $this->casualSetting('lobby_minutes') + $this->casualSetting('join_minutes') + $this->casualSetting('report_minutes') + $this->casualSetting('confirm_minutes');

        return $anchor?->copy()->addMinutes($minutes);
    }

    /** The guest joins by then; afterwards the host may claim a no-show. */
    public function casualJoinDueAt(): ?CarbonInterface
    {
        return $this->lobby_shared_at?->copy()->addMinutes($this->casualSetting('join_minutes'));
    }

    /** The accused side contests a no-show claim by then, else it is a forfeit. */
    public function casualContestDueAt(): ?CarbonInterface
    {
        return $this->noshow_reported_at?->copy()->addMinutes($this->casualSetting('contest_minutes'));
    }

    /** Nobody reported by then: the match is void. Null for a scheduled match before both checked in. */
    public function casualReportDueAt(): ?CarbonInterface
    {
        if ($this->isScheduledPairing() && ! $this->bothCheckedIn()) {
            return null;
        }

        return $this->start_at?->copy()->addMinutes($this->casualSetting('report_minutes'));
    }

    /** The open report is confirmed by the league then; null without one. */
    public function casualConfirmDueAt(): ?CarbonInterface
    {
        $report = $this->latestReport;

        return $this->status !== SeriesStatus::Reported || $report === null || $report->status !== ReportStatus::Open || $report->created_at === null
            ? null
            : $report->created_at->copy()->addMinutes($this->casualSetting('confirm_minutes'));
    }

    /**
     * The next casual deadline, for the countdown of the room (slice S3),
     * and the side it runs against (null: either or both):
     *
     * - `ready`: both press Ready, else the match is void;
     * - `checkin`: a scheduled match (P23 S4); both check in by then, else
     *   the missing side forfeits (both missing: void);
     * - `contest`: a no-show was claimed; the accused side contests or loses;
     * - `lobby`: the host shares the lobby, else the guest may claim a no-show;
     * - `join`: the guest joins, else the host may claim a no-show;
     * - `report`: someone reports the result, else the match is void;
     * - `confirm`: the other side answers the report, else the league confirms it.
     *
     * @return array{kind: 'ready'|'checkin'|'contest'|'lobby'|'join'|'report'|'confirm', at: CarbonInterface, side: 'challenger'|'challenged'|null}|null
     */
    public function casualNextDeadline(): ?array
    {
        if (! $this->isCasualPairing()) {
            return null;
        }

        if ($this->status === SeriesStatus::Reported) {
            $at = $this->casualConfirmDueAt();
            $side = $this->latestReport?->side;

            return $at === null || $side === null ? null : ['kind' => 'confirm', 'at' => $at, 'side' => $side === 'challenger' ? 'challenged' : 'challenger'];
        }

        if ($this->status !== SeriesStatus::Accepted) {
            return null;
        }

        if ($this->start_at === null) {
            return $this->ready_by === null ? null : ['kind' => 'ready', 'at' => $this->ready_by, 'side' => null];
        }

        if ($this->awaitsCheckIn()) {
            return $this->ready_by === null ? null : ['kind' => 'checkin', 'at' => $this->ready_by, 'side' => null];
        }

        $host = $this->host_side === 'challenged' ? 'challenged' : 'challenger';
        $guest = $host === 'challenger' ? 'challenged' : 'challenger';

        if ($this->noshow_reported_at !== null && ($at = $this->casualContestDueAt()) !== null) {
            return ['kind' => 'contest', 'at' => $at, 'side' => $this->noshow_side === 'challenger' ? 'challenged' : 'challenger'];
        }

        $lobbyDue = $this->casualLobbyDueAt();

        if ($this->lobby_shared_at === null && $lobbyDue !== null && $lobbyDue->isFuture()) {
            return ['kind' => 'lobby', 'at' => $lobbyDue, 'side' => $host];
        }

        $joinDue = $this->casualJoinDueAt();

        if ($this->joined_at === null && $joinDue !== null && $joinDue->isFuture()) {
            return ['kind' => 'join', 'at' => $joinDue, 'side' => $guest];
        }

        $at = $this->casualReportDueAt();

        return $at === null ? null : ['kind' => 'report', 'at' => $at, 'side' => null];
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

    /**
     * The clan behind a side, for its logo. Null for a side without a clan
     * lineup (a mix team); lists eager-load `challengerLineup.clan` and
     * `challengedLineup.clan` so this stays one query per list.
     */
    public function sideClan(string $side): ?Clan
    {
        return $this->lineup($side)?->clan;
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

        // A player blocked from the tournament acts for no side of its series, not even as the clan's captain or owner.
        if ($this->tournament_match_id !== null && ($this->tournamentMatch?->tournament?->bans()->where('user_id', $user->id)->exists() ?? false)) {
            return null;
        }

        foreach (self::SIDES as $side) {
            if ($this->lineup($side)?->isActingCaptain($user) === true || $this->isRosterSideMember($side, $user)) {
                return $side;
            }
        }

        return null;
    }

    /**
     * A roster side (a tournament's mix team or RL 1v1 player, P8b) has no
     * lineup and no captain: any of its players acts for it (NIP "Result
     * Report": "any roster player of a roster side").
     */
    public function isRosterSideMember(string $side, User $user): bool
    {
        return in_array($user->id, $this->rosterSide($side), true);
    }

    /**
     * @return list<int> the user ids of a roster side, empty for a lineup side
     */
    public function rosterSide(string $side): array
    {
        return array_map(intval(...), $this->sides[$side] ?? []);
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

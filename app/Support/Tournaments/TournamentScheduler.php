<?php

namespace App\Support\Tournaments;

use App\Enums\SeriesStatus;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\SeriesMatch;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The tournament clock (P18, slice 2): one scheduled run a minute
 * (`tournaments:tick`, routes/console.php) that moves every tournament on
 * and applies the deadlines that are due.
 *
 * 0. The league's casual cups (P25, CasualCups): open the next cup of each
 *    enabled game, settle cup sign-ups, open cup rounds and decide what is
 *    past a round's deadline.
 * 1. Sign-ups, draws and brackets: TournamentDraws::advanceDue() (closes
 *    sign-ups, draws from the Bitcoin block, syncs running tournaments and
 *    starts their ready matches). `tournaments:advance` runs only this step.
 * 2. A reported no-show the other side did not answer: forfeit (unrated).
 * 3. A series nobody reported by its report deadline: the admin queue.
 * 4. A report the other side did not answer: confirmed by the league (unrated).
 *
 * Steps 2 to 4 skip a paused tournament (P18, TournamentControl).
 *
 * The chess check-in (a missed first move) needs nothing here: the game's own
 * deadline ends it (CheckChessClock, and `chess:check-clocks` every ten
 * seconds), with the tournament's window pinned on the game.
 *
 * Every transition happens once: each is a conditional update on the
 * series' own state (SeriesService::markOverdue(), forfeitNoShow(),
 * autoConfirm()), never a flag in memory, so a second or concurrent run
 * changes nothing. One series that fails is reported and the others go on.
 *
 * The run ends by writing its heartbeat; {@see health()} reads it for the
 * admin pages. A heartbeat older than five minutes means the scheduler is
 * not running, and nothing in any tournament moves.
 */
final class TournamentScheduler
{
    public const HEARTBEAT = 'tournaments:tick:last-run-at';

    public const STALE_AFTER_SECONDS = 300;

    public function __construct(private TournamentDraws $draws, private SeriesService $series, private CasualCups $cups) {}

    /**
     * @return array{cups: array{opened: int, extended: int, evenings: int, cancelled: int, rounds: int, decided: int}, closed: int, drawn: int, forfeited: int, overdue: int, confirmed: int}
     */
    public function tick(): array
    {
        $cups = $this->cups->tick();
        $done = ['cups' => $cups, ...$this->draws->advanceDue()];

        $done['forfeited'] = $this->each($this->timed()->where('status', SeriesStatus::Accepted)->whereNotNull('noshow_reported_at'),
            fn (SeriesMatch $match): bool => $this->series->forfeitNoShow($match));
        $done['overdue'] = $this->each($this->timed()->where('status', SeriesStatus::Accepted)->whereNull('noshow_reported_at')->whereNull('overdue_at'),
            fn (SeriesMatch $match): bool => $this->series->markOverdue($match));
        $done['confirmed'] = $this->each($this->timed()->where('status', SeriesStatus::Reported),
            fn (SeriesMatch $match): bool => $this->series->autoConfirm($match));

        Cache::forever(self::HEARTBEAT, now()->getTimestamp());

        return $done;
    }

    /**
     * The scheduler's heartbeat, read-only: when the last tick finished and
     * whether that is too long ago. No heartbeat at all (never ran, cache
     * cleared) counts as stale, and so does a cache that cannot be read: the
     * page that shows this warning must never fail on the very fault it warns
     * about.
     *
     * @return array{last_run_at: CarbonImmutable|null, stale: bool}
     */
    public static function health(): array
    {
        try {
            $stamp = Cache::get(self::HEARTBEAT);
        } catch (Throwable $e) {
            report($e);
            $stamp = null;
        }

        // Redis stores a number unserialized and hands it back as a string.
        $stamp = is_numeric($stamp) ? (int) $stamp : null;
        $lastRunAt = $stamp === null ? null : CarbonImmutable::createFromTimestamp($stamp);

        return [
            'last_run_at' => $lastRunAt,
            'stale' => $stamp === null || now()->getTimestamp() - $stamp > self::STALE_AFTER_SECONDS,
        ];
    }

    /**
     * Series of running players-mode tournaments with league deadlines. A
     * paused tournament's are left alone (P18): its deadlines move by the
     * pause once it is resumed (SeriesMatch::pausedAfter()).
     *
     * @return Builder<SeriesMatch>
     */
    private function timed(): Builder
    {
        return SeriesMatch::query()->whereNotNull('deadlines')
            ->whereHas('tournamentMatch.tournament', fn ($query) => $query->where('status', TournamentStatus::Running)
                ->where('results_mode', TournamentResultsMode::Players)->whereNull('paused_at'));
    }

    /**
     * @param  Builder<SeriesMatch>  $query
     * @param  Closure(SeriesMatch): bool  $step
     */
    private function each(Builder $query, Closure $step): int
    {
        $count = 0;

        foreach ($query->with('latestReport')->orderBy('id')->get() as $match) {
            try {
                $count += $step($match) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $count;
    }
}

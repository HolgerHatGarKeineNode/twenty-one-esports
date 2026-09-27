<?php

namespace App\Support\Series;

use App\Enums\Platform;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The casual 1v1 clock (P23): one scheduled run a minute (`casual:tick`,
 * routes/console.php) applies the deadlines that are due, each once:
 *
 * 1. a ready check that ran out: void (not a no-show); the ready player of
 *    a queue match goes back to the front of the queue;
 * 2. a no-show claim the accused side did not contest: forfeit to the
 *    claimer (counts towards the queue lock);
 * 3. a started match nobody reported by the report deadline: void;
 * 4. a report the other side did not answer: confirmed by the league.
 *
 * Every decision goes through SeriesService::leagueClose() with the
 * deadline re-checked inside its transaction, and the update only takes
 * the series in the state it was read in: a player who acted a moment
 * earlier wins, and a second or concurrent run changes nothing. One series
 * that fails is reported and the others go on.
 */
final class CasualScheduler
{
    public function __construct(private SeriesService $series, private CasualQueue $queue) {}

    /**
     * @return array{unready: int, forfeited: int, unreported: int, confirmed: int}
     */
    public function tick(): array
    {
        return [
            'unready' => $this->each($this->casual()->where('status', SeriesStatus::Accepted)->whereNull('start_at')->where('ready_by', '<=', now()),
                fn (SeriesMatch $match): bool => $this->voidUnready($match)),
            'forfeited' => $this->each($this->casual()->where('status', SeriesStatus::Accepted)->whereNotNull('noshow_reported_at'),
                fn (SeriesMatch $match): bool => $this->forfeitNoShow($match)),
            'unreported' => $this->each($this->casual()->where('status', SeriesStatus::Accepted)->whereNotNull('start_at')->whereNull('noshow_reported_at'),
                fn (SeriesMatch $match): bool => $this->voidUnreported($match)),
            'confirmed' => $this->each($this->casual()->where('status', SeriesStatus::Reported),
                fn (SeriesMatch $match): bool => $this->autoConfirm($match)),
        ];
    }

    public function voidUnready(SeriesMatch $match): bool
    {
        $due = fn (SeriesMatch $locked): bool => $locked->awaitsReady() && ($locked->ready_by?->isPast() ?? false);

        if (! $due($match)) {
            return false;
        }

        $voided = $this->series->leagueClose($match, ['resolution' => SeriesResolution::Void, 'winner' => 'none', 'games' => null],
            'Ready check missed; the match did not start.', null, $due);

        if ($voided && $match->origin === SeriesMatch::ORIGIN_QUEUE) {
            $this->requeueReadyPlayer($match->refresh());
        }

        return $voided;
    }

    public function forfeitNoShow(SeriesMatch $match): bool
    {
        $claimer = $match->noshow_side;
        $due = fn (SeriesMatch $locked): bool => $locked->status === SeriesStatus::Accepted && $locked->noshow_side === $claimer
            && ($locked->casualContestDueAt()?->isPast() ?? false);

        if (! in_array($claimer, SeriesMatch::SIDES, true) || ! $due($match)) {
            return false;
        }

        return $this->series->leagueClose($match, ['resolution' => SeriesResolution::Forfeit, 'winner' => $claimer, 'games' => null],
            'No-show claimed; the other side did not contest within '.$match->casualSetting('contest_minutes').' minutes.', null, $due);
    }

    public function voidUnreported(SeriesMatch $match): bool
    {
        $due = fn (SeriesMatch $locked): bool => $locked->status === SeriesStatus::Accepted && $locked->noshow_reported_at === null
            && ($locked->casualReportDueAt()?->isPast() ?? false);

        if (! $due($match)) {
            return false;
        }

        return $this->series->leagueClose($match, ['resolution' => SeriesResolution::Void, 'winner' => 'none', 'games' => null],
            'No result reported within '.$match->casualSetting('report_minutes').' minutes of the start.', null, $due);
    }

    public function autoConfirm(SeriesMatch $match): bool
    {
        $report = $match->latestReport;
        $due = fn (SeriesMatch $locked): bool => $locked->latestReport?->id === $report?->id && ($locked->casualConfirmDueAt()?->isPast() ?? false);

        if ($report === null || ! $due($match)) {
            return false;
        }

        $wins = $report->score();

        return $this->series->leagueClose($match, [
            'resolution' => SeriesResolution::Admin,
            'winner' => $wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged',
            'games' => $report->games,
        ], 'Report not answered within '.$match->casualSetting('confirm_minutes').' minutes; confirmed by the league.', null, $due);
    }

    /**
     * The one side that pressed Ready goes back to the front of the queue;
     * the side that missed it does not. Nobody when both missed it.
     */
    private function requeueReadyPlayer(SeriesMatch $match): void
    {
        $ready = array_values(array_filter(SeriesMatch::SIDES, fn (string $side): bool => $match->readyAt($side) !== null));

        if (count($ready) !== 1) {
            return;
        }

        $choice = $match->casual['queue'][$ready[0]] ?? null;
        $user = User::query()->find($match->rosterSide($ready[0])[0] ?? null);
        $platform = Platform::tryFrom((string) ($choice['platform'] ?? ''));

        if ($user !== null && $platform !== null) {
            $this->queue->requeueAtFront($user, $match->game, $platform, (bool) ($choice['crossplay'] ?? false));
        }
    }

    /**
     * @return Builder<SeriesMatch>
     */
    private function casual(): Builder
    {
        return SeriesMatch::query()->whereNotNull('origin');
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

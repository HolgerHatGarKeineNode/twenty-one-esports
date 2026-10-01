<?php

namespace App\Support\Scores;

use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Games\ScoreMetric;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The stored values of the score games and the leaderboard they make (plan
 * "AoE2 und Trackmania", P4).
 *
 * Snapshot rule: a run is stored once and never overwritten. A source that
 * only knows a player's current best (Trackmania's personal best) is read
 * again and again; every best it showed inside the window stays a row, so a
 * later record, better or not, set after the window never hides the best
 * set inside it. A run outside the window is never stored for it, and the
 * leaderboard reads only runs inside [start, end) anyway.
 *
 * The leaderboard of a tournament, per entry: the latest director entry for
 * that player in this tournament if there is one (it overrides every source;
 * a director entry without a value takes the entry off the leaderboard), else
 * the best verified run of any source on the tournament's course inside the
 * window. Best first by the metric; a tie goes to the earlier `achieved_at`,
 * then to the seed. Entries without a value, and disqualified ones, follow
 * without a place.
 */
final class ScoreRuns
{
    public function __construct(private GameRegistry $games) {}

    public function gameOf(Tournament $tournament): ScoreGame
    {
        $game = $this->games->find($tournament->game);

        if (! $game instanceof ScoreGame || $game->mode($tournament->mode) === null) {
            throw new InvalidArgumentException("Tournament {$tournament->id} is no score game leaderboard.");
        }

        return $game;
    }

    public function courseOf(Tournament $tournament): ?ScoreCourse
    {
        $game = $this->gameOf($tournament);
        $mode = $game->mode($tournament->mode);

        return $tournament->score_course === null || $mode === null ? null : new ScoreCourse($game, $mode, $tournament->score_course);
    }

    public function metricOf(Tournament $tournament): ScoreMetric
    {
        $game = $this->gameOf($tournament);

        return $game->metric($game->mode($tournament->mode) ?? throw new InvalidArgumentException("Unknown mode [{$tournament->mode}]."));
    }

    /**
     * Store what an automatic source read (verified as read), once: the same
     * record read again is no new row. Null when it lies outside the window.
     */
    public function store(ScoreRecord $record, int $userId, ScoreCourse $course, ScoreWindow $window, ?string $accountId = null): ?ScoreRun
    {
        if (! $window->contains($record->achievedAt)) {
            return null;
        }

        $attributes = [
            'source' => $record->source,
            'user_id' => $userId,
            'game' => $course->game->slug(),
            'mode' => $course->mode->slug,
            'course' => $course->id,
            'achieved_at' => $record->achievedAt,
            'value' => $record->value,
        ];

        $existing = ScoreRun::query()->where($attributes)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return ScoreRun::query()->create($attributes + [
                'unit' => $course->metric()->unit,
                'verified_at' => now(),
                'proof_url' => $record->proofUrl,
                'raw' => $record->raw,
                'account_id' => $accountId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ScoreRun::query()->where($attributes)->first();
        }
    }

    /**
     * The leaderboard of a score tournament, best first.
     *
     * @return list<ScoreStanding>
     */
    public function standings(Tournament $tournament): array
    {
        $course = $this->courseOf($tournament);
        $window = ScoreWindow::of($tournament);
        $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)
            ->orderByRaw('seed is null')->orderBy('seed')->orderBy('id')->get();
        $userIds = $participants->pluck('user_id')->filter()->all();

        $director = ScoreRun::query()->where(['tournament_id' => $tournament->id, 'source' => ScoreRun::DIRECTOR])
            ->whereIn('user_id', $userIds)->orderBy('id')->get()->keyBy('user_id');

        $runs = $course === null ? collect() : ScoreRun::query()
            ->where(['game' => $tournament->game, 'mode' => $tournament->mode, 'course' => $course->id])
            ->whereIn('user_id', $userIds)
            ->where('source', '!=', ScoreRun::DIRECTOR)
            ->whereNotNull('verified_at')->whereNull('rejected_at')->whereNotNull('value')
            ->where(fn ($query) => $query->whereNull('tournament_id')->orWhere('tournament_id', $tournament->id))
            ->where('achieved_at', '>=', $window->start)->where('achieved_at', '<', $window->end)
            ->orderBy('id')->get()->groupBy('user_id');

        $metric = $this->metricOf($tournament);
        $placed = [];
        $unplaced = [];

        foreach ($participants as $order => $participant) {
            $run = $director->get($participant->user_id);

            if ($run === null) {
                $run = null;

                foreach ($runs->get($participant->user_id, collect()) as $candidate) {
                    $sign = $run === null ? -1 : $metric->compare((int) $candidate->value, (int) $run->value);

                    if ($sign < 0 || ($sign === 0 && $candidate->achieved_at->lessThan($run->achieved_at))) {
                        $run = $candidate;
                    }
                }
            }

            // A disqualified entry keeps no place (P18: an organizer or admin took it out).
            if ($run === null || $run->value === null || $participant->isDisqualified()) {
                $unplaced[] = new ScoreStanding($participant, null, null, null, $run?->source, null, $run?->id);

                continue;
            }

            $placed[] = [$order, $run, $participant];
        }

        usort($placed, fn (array $a, array $b): int => $metric->compare((int) $a[1]->value, (int) $b[1]->value)
            ?: $a[1]->achieved_at->getTimestamp() <=> $b[1]->achieved_at->getTimestamp()
            ?: $a[0] <=> $b[0]);

        $rows = [];

        foreach ($placed as $index => [, $run, $participant]) {
            $rows[] = new ScoreStanding($participant, $index + 1, (int) $run->value, CarbonImmutable::instance($run->achieved_at), $run->source, $run->proof_url, $run->id);
        }

        return [...$rows, ...$unplaced];
    }
}

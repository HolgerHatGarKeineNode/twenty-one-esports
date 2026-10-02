<?php

namespace App\Support\Tmnf;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;

/**
 * The look at a TMNF finish before it counts (plan "Trackmania und
 * Restposten", P2), Blockfill's cheat hints for a server the league does not
 * replay: the server timed the finish, but a time far below the track's
 * author time is either a world-class drive or a modified client.
 *
 * - Flagged: faster than the track's author time minus
 *   `esports.tmnf.outlier_margin_ms` (the configured author time, else the
 *   one the server reported with the finish; a track without one flags nothing).
 * - Held: a flagged finish that would enter the top `esports.tmnf.outlier_top`
 *   of its running week waits for an admin (/admin/scores), as a manual
 *   submission does; it counts once approved.
 * - Everything else counts at once; a flagged finish below those places
 *   keeps its hint for admins only.
 */
final class TmnfOutliers
{
    public function __construct(private TmnfWeeks $weeks, private ScoreRuns $runs) {}

    /**
     * @return array{hint: array<string, mixed>, hold: Tournament|null}|null
     */
    public function assess(string $course, int $value, CarbonImmutable $achievedAt, int $userId, ?int $authorMs): ?array
    {
        // The configured author time first: the league's own record of the track, whatever a finish carries.
        $configured = TmnfWeeks::track($course)['author_ms'] ?? 0;
        $author = $configured > 0 ? $configured : max(0, $authorMs ?? 0);
        $margin = max(0, (int) config('esports.tmnf.outlier_margin_ms', 1500));

        if ($author <= 0 || $value >= $author - $margin) {
            return null;
        }

        $hint = ['kind' => 'below_author_time', 'author_ms' => $author, 'margin_ms' => $margin, 'threshold_ms' => $author - $margin];
        $week = $this->week($course, $achievedAt);

        return ['hint' => $hint, 'hold' => $week !== null && $this->wouldEnterTop($week, $value, $userId) ? $week : null];
    }

    /**
     * The running week of the finish's track and moment, if it is open.
     */
    private function week(string $course, CarbonImmutable $achievedAt): ?Tournament
    {
        $start = BlockfillWeeks::startOf($achievedAt);
        $week = $start->equalTo(BlockfillWeeks::startOf(now())) ? $this->weeks->open() : $this->weeks->find($start);

        return $week !== null && $week->status === TournamentStatus::Running && $week->score_course === $course && ScoreWindow::of($week)->contains($achievedAt) ? $week : null;
    }

    /**
     * Whether the value would rank among the top places: fewer than that many
     * other players hold a time as fast or faster (an equal time set earlier
     * ranks first).
     */
    private function wouldEnterTop(Tournament $week, int $value, int $userId): bool
    {
        $top = max(1, (int) config('esports.tmnf.outlier_top', 10));
        $ahead = array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null
            && $row->participant->user_id !== $userId && $row->value <= $value);

        return count($ahead) < $top;
    }
}

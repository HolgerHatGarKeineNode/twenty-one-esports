<?php

namespace App\Games;

use App\Games\Contracts\Game;
use App\Support\Scores\Contracts\ScoreSource;
use App\Support\Scores\ServerIngest;

/**
 * A highscore or time attack game (plan "AoE2 und Trackmania", P4): every
 * player tries alone, as often as they like, for the best value on one course
 * (a track, a level, a fixed game mode) inside a submission window, and the
 * league reads that best from a source (App\Support\Scores\Contracts\ScoreSource:
 * a manual submission an admin approves, an API poller, our own dedicated
 * server). No pairing, no lobby, no casual queue and no Elo series ladder:
 * its tournaments are leaderboards (TournamentFormat::Leaderboard) and its
 * ladder sums points per place (App\Support\Scores\ScorePoints).
 *
 * Game-agnostic: a concrete game names its modes, the metric of each
 * (ScoreMetric: unit and direction), what its course id looks like and, if
 * the league reads a game account, the private service key under which the
 * player's account id is stored (users.gamer_tags, never shown in public).
 *
 * The window: a record counts for a tournament when its `achieved_at` lies in
 * [starts_at, starts_at + time_window), start included, end excluded; the
 * best value inside wins, a tie goes to the earlier `achieved_at`. A record
 * set later never replaces the best one inside the window (App\Support\Scores\ScoreRuns).
 *
 * Registered through `config('esports.score_games')`, never through
 * `esports.games`, so nothing of it shows while its switch is off. Like every
 * game class it stays free of framework helpers.
 */
abstract class ScoreGame implements Game
{
    final public function kind(): GameKind
    {
        return GameKind::Score;
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    /**
     * What a mode measures: unit and direction.
     */
    abstract public function metric(GameMode $mode): ScoreMetric;

    /**
     * What the course is called on pages ("Track", "Level", "Mode"), in English; views translate it.
     */
    public function courseLabel(): string
    {
        return 'Course';
    }

    /**
     * Whether a course id has the shape this game uses (a map UID, a level
     * number, a fixed mode name). The default takes 1 to 64 letters, digits,
     * dots, dashes and underscores.
     */
    public function isCourse(string $course): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $course) === 1;
    }

    /**
     * The key under which a player's account id in this game is stored
     * privately (users.gamer_tags), for the sources that read by account; null
     * when the league reads no account (manual submissions only).
     */
    public function accountService(): ?string
    {
        return null;
    }

    /**
     * The automatic sources the league reads this game from (API pollers,
     * our own servers' records), asked for every entry at every snapshot.
     * Manual submissions are always there on top ({@see acceptsManual()}).
     *
     * @return list<class-string<ScoreSource>>
     */
    public function sources(): array
    {
        return [];
    }

    /**
     * The adapter that reads what our own dedicated server of this game
     * reports (App\Support\Scores\ServerIngest); null when the league runs
     * no server for it, and the ingest endpoint refuses the game.
     *
     * @return class-string<ServerIngest>|null
     */
    public function serverIngest(): ?string
    {
        return null;
    }

    /**
     * Whether players may submit a value with a proof URL for an admin to check.
     */
    public function acceptsManual(): bool
    {
        return true;
    }

    /**
     * The window a tournament of this game runs by default, in minutes (a week).
     */
    public function defaultWindowMinutes(): int
    {
        return 7 * 24 * 60;
    }

    /**
     * The game's own points per place for its ladder, best place first; null
     * takes the league table (`esports.score_games.points`).
     *
     * @return list<int>|null
     */
    public function pointsTable(): ?array
    {
        return null;
    }

    public function resultSchema(GameMode $mode): array
    {
        $metric = $this->metric($mode);

        return [
            'course' => 'string, the course id',
            'value' => "integer in {$metric->unit}, {$metric->better} is better",
            'achieved_at' => 'unix time the value was set',
        ];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        $errors = [];

        if (! is_string($result['course'] ?? null) || ! $this->isCourse($result['course'])) {
            $errors[] = 'course';
        }

        if (! is_int($result['value'] ?? null) || $result['value'] < 0) {
            $errors[] = 'value';
        }

        if (! is_int($result['achieved_at'] ?? null) || $result['achieved_at'] <= 0) {
            $errors[] = 'achieved_at';
        }

        return $errors;
    }
}

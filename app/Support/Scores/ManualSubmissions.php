<?php

namespace App\Support\Scores;

use App\Enums\TournamentStatus;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\TournamentRuleViolation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A player's own value with a proof URL (plan "AoE2 und Trackmania", P4):
 * submitted from the tournament's scores page, counted once an admin
 * verified it (App\Support\Scores\Sources\ManualScoreSource), never while it
 * waits or after it was rejected.
 *
 * Taken while the tournament runs, from the window's start until
 * `esports.score_games.manual.grace_minutes` after its end, from an entry of
 * the tournament only, with `achieved_at` inside the window and not in the
 * future, an https proof URL, and at most `submissions_per_day` a day.
 */
final class ManualSubmissions
{
    public function __construct(private ScoreRuns $runs) {}

    /**
     * @throws TournamentRuleViolation
     */
    public function submit(Tournament $tournament, User $user, string $value, CarbonInterface $achievedAt, string $proofUrl): ScoreRun
    {
        $game = $this->runs->gameOf($tournament);
        $course = $this->runs->courseOf($tournament);
        $window = ScoreWindow::of($tournament);

        if (! $game->acceptsManual()) {
            throw new TournamentRuleViolation('manual_off', __('This game takes no submissions: its values are read automatically.'));
        }

        if ($tournament->status !== TournamentStatus::Running || $course === null || ! $window->hasStarted() || now()->greaterThanOrEqualTo(self::closesAt($tournament))) {
            throw new TournamentRuleViolation('closed', __('Submissions are closed for this leaderboard.'));
        }

        if (! $tournament->participants()->where('user_id', $user->id)->exists()) {
            throw new TournamentRuleViolation('not_entered', __('Only players in this leaderboard can submit a value.'));
        }

        $parsed = $course->metric()->parse($value);

        if ($parsed === null || $parsed <= 0) {
            throw new TournamentRuleViolation('value', $course->metric()->unit === 'ms'
                ? __('Enter the time as m:ss.mmm, for example 1:23.456.')
                : __('Enter the score as a whole number.'));
        }

        if (! $window->contains($achievedAt) || $achievedAt->isFuture()) {
            throw new TournamentRuleViolation('achieved_at', __('The value must be set inside the window, and not in the future.'));
        }

        if (! self::isProofUrl($proofUrl)) {
            throw new TournamentRuleViolation('proof_url', __('Add a link that proves it (https): a replay, a leaderboard page or a screenshot.'));
        }

        $today = ScoreRun::query()->where(['source' => ScoreRun::MANUAL, 'user_id' => $user->id])->where('created_at', '>=', now()->subDay())->count();

        if ($today >= max(1, (int) config('esports.score_games.manual.submissions_per_day', 20))) {
            throw new TournamentRuleViolation('limit', __('That is enough submissions for today. Try again tomorrow.'));
        }

        try {
            return ScoreRun::query()->create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'game' => $tournament->game,
                'mode' => $tournament->mode,
                'course' => $course->id,
                'value' => $parsed,
                'unit' => $course->metric()->unit,
                'source' => ScoreRun::MANUAL,
                'achieved_at' => $achievedAt,
                'proof_url' => trim($proofUrl),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new TournamentRuleViolation('duplicate', __('You submitted this value already.'));
        }
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function approve(ScoreRun $run, User $admin): void
    {
        $this->review($run, $admin);
        $this->decide($run, ['verified_at' => now(), 'verified_by_id' => $admin->id]);
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function reject(ScoreRun $run, User $admin, string $note): void
    {
        $this->review($run, $admin);
        $note = trim($note);

        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 500 characters: the player sees it.'));
        }

        $this->decide($run, ['rejected_at' => now(), 'verified_by_id' => $admin->id, 'note' => $note]);
    }

    /**
     * One decision per submission: the second of two admins deciding at once finds it decided.
     *
     * @param  array<string, mixed>  $decision
     *
     * @throws TournamentRuleViolation
     */
    private function decide(ScoreRun $run, array $decision): void
    {
        $changed = ScoreRun::query()->whereKey($run->id)->whereNull('verified_at')->whereNull('rejected_at')->update($decision);

        if ($changed === 0) {
            throw new TournamentRuleViolation('reviewed', __('This submission was reviewed already.'));
        }

        $run->refresh();
    }

    /**
     * Until when a leaderboard takes submissions: its window's end plus
     * `esports.score_games.manual.grace_minutes`.
     */
    public static function closesAt(Tournament $tournament): CarbonImmutable
    {
        return ScoreWindow::of($tournament)->end->addMinutes(max(0, (int) config('esports.score_games.manual.grace_minutes', 60)));
    }

    public static function isProofUrl(string $url): bool
    {
        $url = trim($url);

        return mb_strlen($url) <= 500 && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function review(ScoreRun $run, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin reviews submissions.'));
        }

        if ($run->source !== ScoreRun::MANUAL || ! $run->isPending()) {
            throw new TournamentRuleViolation('reviewed', __('This submission was reviewed already.'));
        }

        if ($run->user_id === $admin->id) {
            throw new TournamentRuleViolation('interested', __('This is your own submission, so another admin has to review it.'));
        }

        // Security gate F1: no admin reviews a submission of a leaderboard they have a stake in.
        if ($run->tournament !== null && ScoreLeaderboards::interested($run->tournament, $admin)) {
            throw new TournamentRuleViolation('interested', ScoreLeaderboards::interestMessage());
        }
    }
}

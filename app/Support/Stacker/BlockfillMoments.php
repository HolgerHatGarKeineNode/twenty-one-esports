<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use Carbon\CarbonImmutable;

/**
 * A player's proud moments in Blockfill: what a verified run of theirs
 * stands for, so it can be shared (its share card, its moment page
 * `/scores/blockfill/moment/{run}` and its share post).
 *
 * - `final`: the run holds the player's place on a finished week (any place:
 *   the top 3 or "my place");
 * - `first`: when it was handed in, no faster or equal verified run of that
 *   week existed: a new first place of the week;
 * - `pb`: when it was handed in, no faster or equal verified run of the
 *   player existed: a personal best;
 * - `place`: the run holds the player's place on the running week, a place
 *   so far (it may still change until Monday).
 *
 * The kind is the first of these that is true. Only a verified run is a
 * moment (a pending, rejected or practice run never is); a verified run
 * that is none of these (a week best that was neither a record nor holds
 * the place any more) is none. Nothing is shared and no moment page exists
 * while Blockfill is not registered (`esports.blockfill.enabled` off); only
 * the card of a moment stays ({@see card()}), so a note posted before keeps
 * its picture.
 */
final class BlockfillMoments
{
    public function __construct(private BlockfillWeeks $weeks, private ScoreRuns $runs) {}

    /**
     * A verified run that is a moment, with its owner loaded; null otherwise,
     * and while Blockfill is switched off.
     */
    public function run(int|string $id): ?StackerRun
    {
        return $this->weeks->game() === null ? null : $this->card($id);
    }

    /**
     * The run behind a moment's share card: a verified run that is a moment,
     * also while Blockfill is switched off (the card a posted note shows;
     * without the game its week place is not read, so only a personal best
     * or a first place is drawn then).
     */
    public function card(int|string $id): ?StackerRun
    {
        if (! ctype_digit((string) $id) || strlen((string) $id) > 18) {
            return null;
        }

        $run = StackerRun::query()->with('user')->find((int) $id);

        // Its player is always there: a deleted account takes its runs with it (cascade).
        if ($run === null || $run->status !== StackerRunStatus::Verified || $run->ticks === null || $run->submitted_at === null || $run->week === null) {
            return null;
        }

        return $this->of($run) === null ? null : $run;
    }

    /**
     * The run's moment, if the player can share it: only their own.
     */
    public function ownedBy(User $user, int|string $id): ?StackerRun
    {
        $run = $this->run($id);

        return $run !== null && $run->user_id === $user->id ? $run : null;
    }

    /**
     * The id of the viewer's run that holds their place on a Blockfill week,
     * when it is a moment: their own row's share button. Null for a guest,
     * another game's leaderboard, or nothing to share.
     */
    public function shareableOn(?User $viewer, Tournament $week): ?string
    {
        if ($viewer === null || ! $week->isBlockfillWeek() || $this->weeks->game() === null) {
            return null;
        }

        $run = $this->counted($viewer->id, $week->starts_at->copy()->setTimezone(BlockfillWeeks::TIMEZONE)->toDateString());

        return $run !== null && $this->ownedBy($viewer, $run->id) !== null ? (string) $run->id : null;
    }

    /**
     * What a verified run stands for; null when it is no moment.
     *
     * @return array{kind: 'final'|'first'|'pb'|'place', place: int|null, final: bool, pb: bool, first: bool, week: string}|null
     */
    public function of(StackerRun $run): ?array
    {
        if ($run->status !== StackerRunStatus::Verified || $run->ticks === null || $run->submitted_at === null || $run->week === null) {
            return null;
        }

        // Only times on the same rules (engine id, BlockfillRules) compare: a 20-line time beats no 60-line one.
        $earlier = fn () => StackerRun::query()->where('status', StackerRunStatus::Verified)->where('engine', $run->engine)
            // Handed in before it: earlier, or in the same millisecond with a lower id.
            ->where(fn ($before) => $before->where('submitted_at', '<', $run->submitted_at->format('Y-m-d H:i:s.v'))
                ->orWhere(fn ($same) => $same->where('submitted_at', $run->submitted_at->format('Y-m-d H:i:s.v'))->where('id', '<', $run->id)))
            ->where('ticks', '<=', $run->ticks);

        $pb = ! $earlier()->where('user_id', $run->user_id)->exists();
        $first = ! $earlier()->where('week', $run->week)->exists();
        [$place, $final] = $this->placeOf($run);

        $kind = match (true) {
            $place !== null && $final => 'final',
            $first => 'first',
            $pb => 'pb',
            $place !== null => 'place',
            default => null,
        };

        return $kind === null ? null : ['kind' => $kind, 'place' => $place, 'final' => $final, 'pb' => $pb, 'first' => $first, 'week' => $run->week];
    }

    /**
     * What the moment is, in the current language: "Final place 2 of the
     * week", "New first place of the week", "New personal best", "Place 3
     * this week so far". Never "#1" (a hashtag in a note).
     */
    public static function headline(string $kind, ?int $place): string
    {
        return match ($kind) {
            'final' => __('Final place :place of the week', ['place' => (int) $place]),
            'first' => __('New first place of the week'),
            'pb' => __('New personal best'),
            default => __('Place :place this week so far', ['place' => (int) $place]),
        };
    }

    /** "Blockfill Week 41, 2026" of the week starting on `$monday` (Y-m-d, Berlin), in the current language. */
    public static function weekTitle(string $monday): string
    {
        $local = CarbonImmutable::parse($monday, BlockfillWeeks::TIMEZONE);

        return __('Blockfill Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]);
    }

    /** The verified time, as the leaderboard shows it: "1:02.350". */
    public static function time(int $ticks): string
    {
        return ScoreMetric::time()->format(Blockfill::milliseconds($ticks));
    }

    /**
     * The verified run of the player that counts for this week's place (the
     * fastest, a tie to the earlier submission), as the replay source reads it.
     */
    public function counted(int $userId, string $week): ?StackerRun
    {
        return StackerRun::query()->where(['user_id' => $userId, 'week' => $week, 'status' => StackerRunStatus::Verified])
            ->whereNotNull('ticks')->whereNotNull('submitted_at')
            ->orderBy('ticks')->orderBy('submitted_at')->orderBy('id')->first();
    }

    /**
     * The place the run holds on its week, and whether the week has ended;
     * no place when the run is not the one that counts for the player.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function placeOf(StackerRun $run): array
    {
        // The leaderboard is read through the registered game: switched off, no place.
        $week = $this->weeks->game() === null ? null : $this->weeks->find(BlockfillWeeks::startOf($run->submitted_at));

        if ($week === null || $this->counted($run->user_id, (string) $run->week)?->id !== $run->id) {
            return [null, false];
        }

        $final = $week->status === TournamentStatus::Finished;

        if (! $final && $week->status !== TournamentStatus::Running) {
            return [null, false];
        }

        $row = collect($this->runs->standings($week))->first(fn (ScoreStanding $row): bool => $row->participant->user_id === $run->user_id);

        // The board shows this very time: a director's entry or another value is not this run's place.
        if (! $row instanceof ScoreStanding || $row->place === null || $row->value !== Blockfill::milliseconds((int) $run->ticks)) {
            return [null, false];
        }

        return [$row->place, $final];
    }
}

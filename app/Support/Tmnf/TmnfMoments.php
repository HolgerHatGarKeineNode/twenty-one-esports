<?php

namespace App\Support\Tmnf;

use App\Enums\TournamentStatus;
use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ServerIngest;
use App\Support\Stacker\BlockfillWeeks;
use Illuminate\Database\Eloquent\Builder;

/**
 * A player's proud moments in TMNF (plan "Trackmania und Restposten", P2),
 * as Blockfill's (App\Support\Stacker\BlockfillMoments): a finish of theirs
 * on our server that counts for a week, to share (its share card and post).
 *
 * - `final`: the finish holds the player's place on a finished week;
 * - `first`: when it came in, no faster or equal counted finish of that
 *   week's track inside the week existed: a new first place;
 * - `pb`: when it came in, no faster or equal counted finish of the player on
 *   that track existed: a personal best;
 * - `place`: the finish holds the player's place on the running week.
 *
 * Only a counted finish (verified, not held, not rejected) of a linked
 * player inside a TMNF week is a moment. The card names the player by their
 * league name only, never by a TMNF login.
 */
final class TmnfMoments
{
    public function __construct(private TmnfWeeks $weeks, private ScoreRuns $runs) {}

    /**
     * A counted finish that is a moment; null otherwise, and while TMNF is switched off.
     */
    public function run(int|string $id): ?ScoreRun
    {
        return $this->weeks->game() === null ? null : $this->card($id);
    }

    /**
     * The finish behind a moment's share card. Its place is read from the
     * week's board, so off it is none (the card route exists only while TMNF is on).
     */
    public function card(int|string $id): ?ScoreRun
    {
        if (! ctype_digit((string) $id) || strlen((string) $id) > 18) {
            return null;
        }

        $run = ScoreRun::query()->with('user')->find((int) $id);

        return $run !== null && $this->counts($run) && $this->of($run) !== null ? $run : null;
    }

    public function ownedBy(User $user, int|string $id): ?ScoreRun
    {
        $run = $this->run($id);

        return $run !== null && $run->user_id === $user->id ? $run : null;
    }

    /**
     * The id of the viewer's finish that holds their place on a TMNF week, when it is a moment.
     */
    public function shareableOn(?User $viewer, Tournament $week): ?string
    {
        if ($viewer === null || ! $week->isTmnfWeek() || $this->weeks->game() === null) {
            return null;
        }

        $row = collect($this->runs->standings($week))->first(fn (ScoreStanding $row): bool => $row->participant->user_id === $viewer->id && $row->runId !== null);

        return $row === null || $this->ownedBy($viewer, (int) $row->runId) === null ? null : (string) $row->runId;
    }

    /**
     * What a counted finish stands for; null when it is no moment.
     *
     * @return array{kind: 'final'|'first'|'pb'|'place', place: int|null, final: bool, pb: bool, first: bool, week: string, track: string}|null
     */
    public function of(ScoreRun $run): ?array
    {
        if (! $this->counts($run)) {
            return null;
        }

        $monday = BlockfillWeeks::startOf($run->achieved_at);
        $earlier = fn () => $this->counted()->where('course', $run->course)->where('value', '<=', (int) $run->value)
            ->where(fn ($before) => $before->where('achieved_at', '<', $run->achieved_at)
                ->orWhere(fn ($same) => $same->where('achieved_at', $run->achieved_at)->where('id', '<', $run->id)));

        $pb = ! $earlier()->where('user_id', $run->user_id)->exists();
        $first = ! $earlier()->where('achieved_at', '>=', $monday)->exists();
        [$place, $final] = $this->placeOf($run);

        $kind = match (true) {
            $place !== null && $final => 'final',
            $first && $place !== null => 'first',
            $pb && $place !== null => 'pb',
            $place !== null => 'place',
            default => null,
        };

        return $kind === null ? null : ['kind' => $kind, 'place' => $place, 'final' => $final, 'pb' => $pb, 'first' => $first,
            'week' => $monday->setTimezone(BlockfillWeeks::TIMEZONE)->toDateString(), 'track' => TmnfWeeks::track($run->course)['name'] ?? $run->course];
    }

    private function counts(ScoreRun $run): bool
    {
        return $run->game === TrackmaniaNationsForever::SLUG && $run->source === ServerIngest::SOURCE && $run->user_id !== null
            && $run->verified_at !== null && $run->rejected_at === null && $run->value !== null;
    }

    /**
     * @return Builder<ScoreRun>
     */
    private function counted(): Builder
    {
        return ScoreRun::query()->where(['game' => TrackmaniaNationsForever::SLUG, 'source' => ServerIngest::SOURCE])
            ->whereNotNull('user_id')->whereNotNull('verified_at')->whereNull('rejected_at')->whereNotNull('value');
    }

    /**
     * The place the finish holds on its week (the board shows this very
     * run), and whether the week has ended; no place otherwise.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function placeOf(ScoreRun $run): array
    {
        $week = $this->weeks->game() === null ? null : $this->weeks->find(BlockfillWeeks::startOf($run->achieved_at));

        if ($week === null || $week->score_course !== $run->course || ! in_array($week->status, [TournamentStatus::Running, TournamentStatus::Finished], true)) {
            return [null, false];
        }

        $row = collect($this->runs->standings($week))->first(fn (ScoreStanding $row): bool => $row->participant->user_id === $run->user_id);

        if (! $row instanceof ScoreStanding || $row->place === null || $row->runId !== $run->id) {
            return [null, false];
        }

        return [$row->place, $week->status === TournamentStatus::Finished];
    }
}

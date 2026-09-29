<?php

namespace App\Support\Rating;

use App\Enums\LineupRole;
use App\Enums\SeriesResolution;
use App\Enums\TournamentStatus;
use App\Jobs\SyncRankBadges;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Engagement\Placements;
use App\Support\Engagement\ResultEngagement;
use App\Support\FairPlay\FairPlay;
use App\Support\SeasonChain\GatePin;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Applies a finished result to the ratings (P7b), inside the transaction that
 * writes the result, so a result and its rating change commit together or
 * not at all.
 *
 * - A chess game rates its two players, White as the challenger (NIP
 *   "Rating": either colour may be the challenger, rounding is symmetric).
 * - A Rocket League series rates the two lineups, once per series; `void`
 *   and a series with a deleted lineup rate nothing. A tournament series
 *   between two single players (RL 1v1 entries, P8b) rates the two players;
 *   a mix team (a roster side of several players) is never rated.
 * - A board game other than chess (plan "Mühle und Dame", P5) rates its two
 *   players, White as the challenger, on the ladder of that board game: the
 *   rating row names the board game, so chess ratings never move. A rated
 *   board game (P6) goes to its season ladder as a rated chess game does,
 *   a casual one to its casual ladder.
 * - A rated game or series goes to the season ladder, but only while that
 *   ladder is open ({@see Ladders}); before Block 0 there is none and a rated
 *   result moves nothing (fail closed). A rated series counts only on the
 *   ladder its challenge named while that ladder is still open, never on a
 *   later season's. A rated result reads only the trust
 *   gate pinned at its accept ({@see GatePin}), never live trust facts: an
 *   unfollow or a lower rank after the accept changes nothing (NIP "Nothing
 *   after the accept undoes the gate"). Without a pin, or with a roster that
 *   lists a player not eligible at the accept (condition 3), it moves
 *   nothing. A casual one goes to the permanent casual
 *   ladder. Both are capped per pairing and UTC day
 *   (`season.rating.daily_pair_limit`, `season.casual.daily_pair_limit`).
 *   Casual never touches a rated row.
 *
 * Idempotent: a result that already has rating changes (reverted ones
 * included) is skipped, and the unique (rating, source, source id, revision)
 * index refuses a second write even if two requests race past that check.
 *
 * A correction of a rated result ({@see correct()}, {@see revert()}) takes
 * its changes back and rates the corrected outcome, delta only.
 */
final class RatingService
{
    /**
     * @return bool whether any rating moved
     */
    public function applyChessGame(ChessGame $game): bool
    {
        $score = match ($game->result) {
            '1-0' => 1.0,
            '0-1' => 0.0,
            '1/2-1/2' => 0.5,
            default => null,
        };

        if ($score === null || self::inCalledOffTournament($game->tournament_match_id)) {
            return false;
        }

        // Two accounts of one person (P41): their results are void and rate nothing.
        if (FairPlay::samePerson(array_filter([$game->white_id]), array_filter([$game->black_id]))) {
            return false;
        }

        if ($game->rated && ! $this->pinAdmits(GatePin::fromArray($game->gate_at_accept), [$game->white->pubkey, $game->black->pubkey])) {
            return false;
        }

        // Only on the ladder pinned at the start, while it is still the open one; a game without a
        // pinned ladder is never rated (fail closed), a game finished after its season closed neither.
        if ($game->rated && $game->ladder_address !== Ladders::address('chess', $game->mode)) {
            return false;
        }

        return $this->apply(
            (bool) $game->rated, 'chess', $game->mode,
            ['subject' => 'user:'.$game->white_id, 'user_id' => $game->white_id],
            ['subject' => 'user:'.$game->black_id, 'user_id' => $game->black_id],
            $score, RatingChange::CHESS, $game->id, $game->number,
        );
    }

    /**
     * A finished game of a board game other than chess, on the ladder of its
     * own board game and mode (plan "Mühle und Dame", P5): casual, or rated
     * (P6) under the same gates as a rated chess game.
     *
     * @return bool whether any rating moved
     */
    public function applyBoardGame(BoardGame $game): bool
    {
        $score = match ($game->result) {
            '1-0' => 1.0,
            '0-1' => 0.0,
            '1/2-1/2' => 0.5,
            default => null,
        };

        if ($score === null || $game->white_id === null || $game->black_id === null || self::inCalledOffTournament($game->tournament_match_id)) {
            return false;
        }

        // Two accounts of one person (P41): their results are void and rate nothing.
        if (FairPlay::samePerson([$game->white_id], [$game->black_id])) {
            return false;
        }

        if ($game->rated && ($game->white === null || $game->black === null || ! $this->pinAdmits(GatePin::fromArray($game->gate_at_accept), [$game->white->pubkey, $game->black->pubkey]))) {
            return false;
        }

        // Only on the ladder pinned at the start, while it is still the open one (as chess, fail closed).
        if ($game->rated && ($game->ladder_address === null || $game->ladder_address !== Ladders::address($game->game, $game->mode))) {
            return false;
        }

        return $this->apply(
            $game->rated, $game->game, $game->mode,
            ['subject' => 'user:'.$game->white_id, 'user_id' => $game->white_id],
            ['subject' => 'user:'.$game->black_id, 'user_id' => $game->black_id],
            $score, RatingChange::BOARD, $game->id, $game->number,
        );
    }

    /**
     * @return bool whether any rating moved
     */
    public function applySeries(SeriesMatch $match): bool
    {
        if (! $match->status->hasResult() || $match->resolution === SeriesResolution::Void || ! in_array($match->winner, SeriesMatch::SIDES, true)
            || self::inCalledOffTournament($match->tournament_match_id)) {
            return false;
        }

        $subjects = self::seriesSubjects($match);

        if ($subjects === null) {
            return false;
        }

        // Two accounts of one person on opposite sides (P41): their results are void and rate nothing.
        $sides = FairPlay::seriesSides($match);

        if (FairPlay::samePerson($sides['challenger'], $sides['challenged'])) {
            return false;
        }

        if ($match->rated && ! $this->pinAdmits(GatePin::fromArray($match->gate_at_accept), $this->rosterOf($match))) {
            return false;
        }

        // A rated series counts only on the ladder its challenge named (a tournament's frozen
        // ladder), and only while that ladder is still the open one: a result reached after it
        // closed belongs to no season and moves nothing, never a later season's ladder (NIP
        // "Rest": events after `ends` are never attested; "Tournaments": never on another ladder).
        if ($match->rated && $match->ladder_address !== Ladders::address($match->game, $match->mode)) {
            return false;
        }

        return $this->apply(
            (bool) $match->rated, $match->game, $match->mode,
            self::entity($subjects['challenger'], $match->challenger_lineup_id),
            self::entity($subjects['challenged'], $match->challenged_lineup_id),
            $match->winner === 'challenger' ? 1.0 : 0.0, RatingChange::SERIES, $match->id, $match->number,
        );
    }

    /**
     * What a correction of this rated result to `$score` (the challenger's,
     * White's in chess and a board game; null for a forfeit or void) would do to the Elo,
     * without writing anything; see {@see correct()}. Deltas challenger (or
     * White) first. Null when the correction moves no Elo: the result moved
     * no rated Elo that still counts, or its outcome stays the same.
     *
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    public function correction(SeriesMatch|ChessGame|BoardGame $result, ?float $score): ?array
    {
        $changes = $this->correctable($result);

        return $changes === null ? null : $this->effect($changes[0], $changes[1], $score);
    }

    /**
     * Correct a rated result that already moved the Elo (P18, the tournament
     * control): take its rating changes back and, unless `$score` is null (a
     * forfeit or void, which moves no Elo), rate the corrected outcome.
     *
     * The rule is **delta only**, not a replay: the old deltas are
     * subtracted from the ratings as they stand now, and the corrected
     * deltas, computed from the ratings and result counts the result was
     * first rated with, are added now. Later results of the same entities
     * keep the deltas they were rated and attested with; without later
     * results the outcome is exactly the one a correct first rating would
     * have given. The old rows stay as the audit trail (`reverted_at`), the
     * corrected ones are the next `revision` and keep the result's time.
     *
     * Only the season ladder that is still open is corrected: casual Elo, a
     * result of a closed season and one whose changes no longer count are
     * left as they are (null). Idempotent: a second call with the same
     * outcome finds nothing to change (null). Rank badges and an unshown
     * placement reveal follow the corrected ratings. Weekly quest credits
     * do not move: they are keyed by the result, not its revision, so the
     * corrected side earns what it has not earned yet and a credit already
     * given stays (quests are decoration, {@see ResultEngagement}).
     *
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null the effect, as {@see correction()}
     */
    public function correct(SeriesMatch|ChessGame|BoardGame $result, ?float $score): ?array
    {
        $done = DB::transaction(function () use ($result, $score): ?array {
            $changes = $this->correctable($result);

            if ($changes === null) {
                return null;
            }

            // The rating rows in id order (as apply() locks them), then the changes again under the lock.
            $locked = Rating::query()->whereKey([$changes[0]->rating_id, $changes[1]->rating_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $changes = $this->correctable($result, lock: true);
            $effect = $changes === null ? null : $this->effect($changes[0], $changes[1], $score);

            if ($changes === null || $effect === null) {
                return null;
            }

            [$c, $d] = [$locked[$changes[0]->rating_id], $locked[$changes[1]->rating_id]];
            $revision = 1 + (int) RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)
                ->where('source', $changes[0]->source)->where('source_id', $changes[0]->source_id)->max('revision');

            foreach ([[$changes[0], $c], [$changes[1], $d]] as [$change, $rating]) {
                $change->forceFill(['reverted_at' => now()])->save();
                $this->count($rating, $rating->rating - $change->delta, $change->score, -1);
            }

            $applied = [];

            if ($effect['applied'] !== null) {
                $applied[] = $this->rerecord($changes[0], $c, $d, $score ?? 0.0, $effect['applied'][0], $revision);
                $applied[] = $this->rerecord($changes[1], $d, $c, 1.0 - ($score ?? 0.0), $effect['applied'][1], $revision);
            }

            foreach ([[$changes[0], $c], [$changes[1], $d]] as [$change, $rating]) {
                app(Placements::class)->corrected($change, $rating);
            }

            if ($applied !== []) {
                DB::afterCommit(fn () => app(ResultEngagement::class)->handle($applied[0], $applied[1]));
            }

            return ['effect' => $effect, 'game' => $c->game, 'mode' => $c->mode, 'subjects' => [$c->subject, $d->subject]];
            // Called on its own, a transient lock retries; inside a caller's transaction the caller's attempts apply.
        }, 3);

        if ($done === null) {
            return null;
        }

        SyncRankBadges::dispatch($done['game'], $done['mode'], $done['subjects']);

        return $done['effect'];
    }

    /**
     * Take a rated result's Elo back without rating it anew (a void): the
     * same as {@see correct()} with no score.
     *
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    public function revert(SeriesMatch|ChessGame|BoardGame $result): ?array
    {
        return $this->correct($result, null);
    }

    /**
     * The two live changes of a rated result, challenger (White) first, if a
     * correction may move them: both on the rated ladder of the season that
     * is open now. Null otherwise (fail closed: nothing is corrected).
     *
     * @return array{0: RatingChange, 1: RatingChange}|null
     */
    private function correctable(SeriesMatch|ChessGame|BoardGame $result, bool $lock = false): ?array
    {
        $query = RatingChange::query()->with('rating')
            ->where('source', match (true) {
                $result instanceof ChessGame => RatingChange::CHESS,
                $result instanceof BoardGame => RatingChange::BOARD,
                default => RatingChange::SERIES,
            })
            ->where('source_id', $result->id)->orderBy('id');
        $changes = ($lock ? $query->lockForUpdate() : $query)->get();

        if ($changes->count() !== 2) {
            return null;
        }

        foreach ($changes as $change) {
            $rating = $change->rating;

            if ($rating->pool !== Rating::RATED || $rating->season !== Ladders::season() || ! Ladders::isOpen($rating->game, $rating->mode)) {
                return null;
            }
        }

        return [$changes[0], $changes[1]];
    }

    /**
     * @return array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null
     */
    private function effect(RatingChange $challenger, RatingChange $challenged, ?float $score): ?array
    {
        if ($score !== null && ! in_array($score, [0.0, 0.5, 1.0], true)) {
            throw new InvalidArgumentException('The score is 1, 0.5 or 0.');
        }

        if ($score === $challenger->score) {
            return null;
        }

        $applied = null;

        if ($score !== null) {
            // As the result would have been rated first: the ratings and counts before it.
            $rated = EloRating::fromConfig('rating')->rate($challenger->before, $challenged->before, $score, $challenger->results_before, $challenged->results_before);
            $applied = [$rated['challenger_delta'], $rated['challenged_delta']];
        }

        return ['reverted' => [$challenger->delta, $challenged->delta], 'applied' => $applied];
    }

    /**
     * The corrected change of one side, on the rating as it stands after the
     * revert; the result's own time and number stay.
     */
    private function rerecord(RatingChange $reverted, Rating $rating, Rating $opponent, float $score, int $delta, int $revision): RatingChange
    {
        $change = new RatingChange;
        $change->forceFill([
            'rating_id' => $rating->id,
            'opponent_rating_id' => $opponent->id,
            'source' => $reverted->source,
            'source_id' => $reverted->source_id,
            'match_number' => $reverted->match_number,
            'score' => $score,
            'before' => $rating->rating,
            'after' => $rating->rating + $delta,
            'delta' => $delta,
            'results_before' => $rating->results,
            'revision' => $revision,
            'created_at' => $reverted->created_at,
        ])->save();

        $this->count($rating, $rating->rating + $delta, $score, 1);

        return $change;
    }

    /**
     * Set a rating and add (+1) or take back (-1) one result with this
     * score in its counters.
     */
    private function count(Rating $rating, int $value, float $score, int $step): void
    {
        $rating->forceFill([
            'rating' => $value,
            'results' => max(0, $rating->results + $step),
            'wins' => max(0, $rating->wins + ($score === 1.0 ? $step : 0)),
            'draws' => max(0, $rating->draws + ($score === 0.5 ? $step : 0)),
            'losses' => max(0, $rating->losses + ($score === 0.0 ? $step : 0)),
        ])->save();
    }

    /**
     * Nothing of a called-off tournament is rated any more (P18,
     * TournamentControl::abort()), whatever still ends after the call-off.
     */
    private static function inCalledOffTournament(?int $tournamentMatchId): bool
    {
        return $tournamentMatchId !== null && TournamentMatch::query()->whereKey($tournamentMatchId)
            ->whereHas('tournament', fn ($query) => $query->where('status', TournamentStatus::Cancelled))->exists();
    }

    /**
     * The two rated entities of a series, or null (nothing to rate):
     *
     * - on a player ladder (Rocket League 1v1, NIP rev. 7.1) each side's one
     *   roster player, whether the side is a lineup or a player: `user:<id>`,
     *   so a player has one rating however they entered;
     * - else the entities a rated accept pinned (a lineup gone since cannot
     *   take the loss away, security gate F3);
     * - else both lineups. A mix team (a roster side of several players) has
     *   no rating.
     *
     * @return array{challenger: string, challenged: string}|null
     */
    public static function seriesSubjects(SeriesMatch $match): ?array
    {
        if ($match->gameMode()->rates === 'player') {
            return self::playerSubjects($match);
        }

        $pinned = $match->rated ? $match->rated_subjects : null;

        if (isset($pinned['challenger'], $pinned['challenged'])) {
            return ['challenger' => (string) $pinned['challenger'], 'challenged' => (string) $pinned['challenged']];
        }

        if ($match->challenger_lineup_id !== null && $match->challenged_lineup_id !== null) {
            return ['challenger' => 'lineup:'.$match->challenger_lineup_id, 'challenged' => 'lineup:'.$match->challenged_lineup_id];
        }

        return null;
    }

    /**
     * The one player of each 1v1 side: who the counted roster names for it,
     * else the pinned subject, else the player of a roster side, else the one
     * regular player the accept pinned for the side, else the lineup's one
     * regular (captain or player) seat now. Null when a side has none
     * or more than one (fail closed: unrated). A deleted account keeps its
     * subject, so the winner is still rated (security re-check F2).
     *
     * @return array{challenger: string, challenged: string}|null
     */
    private static function playerSubjects(SeriesMatch $match): ?array
    {
        $subjects = [];
        $roster = $match->countedRoster();

        foreach (SeriesMatch::SIDES as $side) {
            $played = array_values(array_unique(array_column(array_filter($roster, fn (array $entry): bool => $entry['side'] === $side), 'user_id')));
            $pinned = $match->rated_subjects[$side] ?? null;
            // The side as a rated accept pinned it (security gate F3 class): a deleted account or an
            // emptied lineup after the accept cannot take a forfeit's result away.
            $pinnedRegulars = array_values(array_filter(GatePin::fromArray($match->gate_at_accept)?->sides[$side] ?? [], fn (array $entry): bool => $entry['role'] !== LineupRole::Substitute->value));
            $regulars = array_values(array_filter($match->lineup($side)?->activeSeats() ?? [], fn ($seat): bool => $seat->role !== LineupRole::Substitute));

            $userId = match (true) {
                $roster !== [] => count($played) === 1 ? (int) $played[0] : null,
                is_string($pinned) && str_starts_with($pinned, 'user:') => (int) substr($pinned, 5),
                count($match->rosterSide($side)) === 1 => $match->rosterSide($side)[0],
                count($pinnedRegulars) === 1 => (int) $pinnedRegulars[0]['user_id'],
                count($regulars) === 1 => $regulars[0]->user_id,
                default => null,
            };

            if ($userId === null) {
                return null;
            }

            $subjects[$side] = 'user:'.$userId;
        }

        return $subjects['challenger'] === $subjects['challenged'] ? null : $subjects;
    }

    /**
     * A rating entity: a player subject carries its user, a lineup subject
     * the lineup as the series still has it (null once deleted: the pinned
     * subject rates on, security gate F3).
     *
     * @return array{subject: string, user_id?: int|null, lineup_id?: int|null}
     */
    private static function entity(string $subject, ?int $lineupId): array
    {
        if (str_starts_with($subject, 'user:')) {
            $userId = (int) substr($subject, 5);

            // A deleted account keeps its rating row by subject, without the user.
            return ['subject' => $subject, 'user_id' => User::query()->whereKey($userId)->exists() ? $userId : null];
        }

        return ['subject' => $subject, 'lineup_id' => $lineupId];
    }

    /**
     * A rated result counts if the accept pinned its gate and every rated
     * player was eligible then (NIP "Trust gate", condition 3).
     *
     * @param  list<string>  $players
     */
    private function pinAdmits(?GatePin $pin, array $players): bool
    {
        if ($pin === null) {
            return false;
        }

        foreach ($players as $player) {
            if (! $pin->isEligible($player)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The roster of the counted report; empty for a result without a report
     * (a no-show forfeit), which the pin alone admits.
     *
     * @return list<string>
     */
    private function rosterOf(SeriesMatch $match): array
    {
        return array_map(fn (array $entry): string => $entry['pubkey'], $match->countedRoster());
    }

    /**
     * @param  array{subject: string, user_id?: int|null, lineup_id?: int|null}  $challenger
     * @param  array{subject: string, user_id?: int|null, lineup_id?: int|null}  $challenged
     */
    private function apply(bool $rated, string $game, string $mode, array $challenger, array $challenged, float $score, string $source, int $sourceId, ?int $number): bool
    {
        if ($rated && ! Ladders::isOpen($game, $mode)) {
            return false;
        }

        $pool = $rated ? Rating::RATED : Rating::CASUAL;
        $season = $rated ? (string) Ladders::season() : '';
        $engine = EloRating::fromConfig($rated ? 'rating' : 'casual');

        $moved = DB::transaction(function () use ($pool, $season, $game, $mode, $challenger, $challenged, $score, $source, $sourceId, $number, $engine): bool {
            // Reverted rows count too: a corrected result is never rated again from here, only by correct().
            if (RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->where('source', $source)->where('source_id', $sourceId)->exists()) {
                return false;
            }

            $ids = [];

            foreach ([$challenger, $challenged] as $entity) {
                $ids[] = $this->ensure($pool, $season, $game, $mode, $entity, $engine->start);
            }

            // Lock in id order, so two results of the same pairing cannot deadlock.
            $locked = Rating::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $c = $locked[$ids[0]];
            $d = $locked[$ids[1]];

            if ($this->pairCapReached($pool, $c, $d)) {
                return false;
            }

            $rated = $engine->rate($c->rating, $d->rating, $score, $c->results, $d->results);

            $challengerChange = $this->record($c, $d, $score, $rated['challenger'], $rated['challenger_delta'], $source, $sourceId, $number);
            $challengedChange = $this->record($d, $c, 1.0 - $score, $rated['challenged'], $rated['challenged_delta'], $source, $sourceId, $number);

            // Placement reveal and quests (P10), once the result is committed; never part of it.
            DB::afterCommit(fn () => app(ResultEngagement::class)->handle($challengerChange, $challengedChange));

            return true;
        });

        // Rank badges come from rated results only (NIP "Rank badges"); casual has no tiers.
        if ($moved && $pool === Rating::RATED) {
            SyncRankBadges::dispatch($game, $mode, [$challenger['subject'], $challenged['subject']]);
        }

        return $moved;
    }

    /**
     * The id of the entity's rating row, created at the start rating if new.
     * insertOrIgnore keeps a concurrent create from aborting the transaction.
     *
     * @param  array{subject: string, user_id?: int|null, lineup_id?: int|null}  $entity
     */
    private function ensure(string $pool, string $season, string $game, string $mode, array $entity, int $start): int
    {
        $key = ['pool' => $pool, 'season' => $season, 'game' => $game, 'mode' => $mode, 'subject' => $entity['subject']];

        Rating::query()->insertOrIgnore([$key + [
            'user_id' => $entity['user_id'] ?? null,
            'lineup_id' => $entity['lineup_id'] ?? null,
            'rating' => $start,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return (int) Rating::query()->where($key)->value('id');
    }

    /**
     * Farming guard: this pairing already moved the rating of this pool
     * `daily_pair_limit` times today (UTC).
     */
    private function pairCapReached(string $pool, Rating $a, Rating $b): bool
    {
        $limit = $pool === Rating::RATED ? RatingSettings::inForce()['rating']['daily_pair_limit'] : config('season.casual.daily_pair_limit');

        if ($limit === null) {
            return false;
        }

        $today = RatingChange::query()
            ->where('rating_id', $a->id)
            ->where('opponent_rating_id', $b->id)
            ->where('created_at', '>=', now()->utc()->startOfDay())
            ->count();

        return $today >= (int) $limit;
    }

    private function record(Rating $rating, Rating $opponent, float $score, int $after, int $delta, string $source, int $sourceId, ?int $number): RatingChange
    {
        $change = RatingChange::query()->create([
            'rating_id' => $rating->id,
            'opponent_rating_id' => $opponent->id,
            'source' => $source,
            'source_id' => $sourceId,
            'match_number' => $number,
            'score' => $score,
            'before' => $rating->rating,
            'after' => $after,
            'delta' => $delta,
            'results_before' => $rating->results,
        ]);

        $rating->forceFill([
            'rating' => $after,
            'results' => $rating->results + 1,
            'wins' => $rating->wins + ($score === 1.0 ? 1 : 0),
            'draws' => $rating->draws + ($score === 0.5 ? 1 : 0),
            'losses' => $rating->losses + ($score === 0.0 ? 1 : 0),
        ])->save();

        return $change;
    }
}

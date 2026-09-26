<?php

namespace App\Support\Rating;

use App\Enums\LineupRole;
use App\Enums\SeriesResolution;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Engagement\ResultEngagement;
use App\Support\SeasonChain\GatePin;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\DB;

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
 * - A rated game or series goes to the season ladder, but only while that
 *   ladder is open ({@see Ladders}); before Block 0 there is none and a rated
 *   result moves nothing (fail closed). A rated result reads only the trust
 *   gate pinned at its accept ({@see GatePin}), never live trust facts: an
 *   unfollow or a lower rank after the accept changes nothing (NIP "Nothing
 *   after the accept undoes the gate"). Without a pin, or with a roster that
 *   lists a player not eligible at the accept (condition 3), it moves
 *   nothing. A casual one goes to the permanent casual
 *   ladder. Both are capped per pairing and UTC day
 *   (`season.rating.daily_pair_limit`, `season.casual.daily_pair_limit`).
 *   Casual never touches a rated row.
 *
 * Idempotent: a result that already has rating changes is skipped, and the
 * unique (rating, source, source id) index refuses a second write even if
 * two requests race past that check.
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

        if ($score === null) {
            return false;
        }

        if ($game->rated && ! $this->pinAdmits(GatePin::fromArray($game->gate_at_accept), [$game->white->pubkey, $game->black->pubkey])) {
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
     * @return bool whether any rating moved
     */
    public function applySeries(SeriesMatch $match): bool
    {
        if (! $match->status->hasResult() || $match->resolution === SeriesResolution::Void || ! in_array($match->winner, SeriesMatch::SIDES, true)) {
            return false;
        }

        $subjects = self::seriesSubjects($match);

        if ($subjects === null) {
            return false;
        }

        if ($match->rated && ! $this->pinAdmits(GatePin::fromArray($match->gate_at_accept), $this->rosterOf($match))) {
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

        return DB::transaction(function () use ($pool, $season, $game, $mode, $challenger, $challenged, $score, $source, $sourceId, $number, $engine): bool {
            if (RatingChange::query()->where('source', $source)->where('source_id', $sourceId)->exists()) {
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
        $limit = config($pool === Rating::RATED ? 'season.rating.daily_pair_limit' : 'season.casual.daily_pair_limit');

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

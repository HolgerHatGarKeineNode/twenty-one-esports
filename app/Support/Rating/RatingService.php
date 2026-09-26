<?php

namespace App\Support\Rating;

use App\Enums\SeriesResolution;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
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

        // A rated series rates the entities pinned at its accept, so a lineup gone since
        // (security gate F3) cannot take the loss away; casual needs both lineups.
        $subjects = $match->rated ? $match->rated_subjects : null;

        if (! isset($subjects['challenger'], $subjects['challenged'])) {
            $subjects = self::seriesSubjects($match);

            if ($subjects === null) {
                return false;
            }
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
     * The two rated entities of a casual series: its lineups, or its two
     * single players (both sides a roster side of one player, in a mode of
     * one player per side); null otherwise (a mix team, a deleted lineup).
     *
     * @return array{challenger: string, challenged: string}|null
     */
    public static function seriesSubjects(SeriesMatch $match): ?array
    {
        if ($match->challenger_lineup_id !== null && $match->challenged_lineup_id !== null) {
            return ['challenger' => 'lineup:'.$match->challenger_lineup_id, 'challenged' => 'lineup:'.$match->challenged_lineup_id];
        }

        [$a, $b] = [$match->rosterSide('challenger'), $match->rosterSide('challenged')];

        if (count($a) === 1 && count($b) === 1 && $match->challenger_lineup_id === null && $match->challenged_lineup_id === null && $match->gameMode()->teamSize === 1) {
            return ['challenger' => 'user:'.$a[0], 'challenged' => 'user:'.$b[0]];
        }

        return null;
    }

    /**
     * A rating entity: a player subject carries its user, a lineup subject
     * the lineup as the series still has it (null once deleted: the pinned
     * subject rates on, security gate F3).
     *
     * @return array{subject: string, user_id?: int, lineup_id?: int|null}
     */
    private static function entity(string $subject, ?int $lineupId): array
    {
        return str_starts_with($subject, 'user:')
            ? ['subject' => $subject, 'user_id' => (int) substr($subject, 5)]
            : ['subject' => $subject, 'lineup_id' => $lineupId];
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
     * @param  array{subject: string, user_id?: int, lineup_id?: int|null}  $challenger
     * @param  array{subject: string, user_id?: int, lineup_id?: int|null}  $challenged
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

            $this->record($c, $d, $score, $rated['challenger'], $rated['challenger_delta'], $source, $sourceId, $number);
            $this->record($d, $c, 1.0 - $score, $rated['challenged'], $rated['challenged_delta'], $source, $sourceId, $number);

            return true;
        });
    }

    /**
     * The id of the entity's rating row, created at the start rating if new.
     * insertOrIgnore keeps a concurrent create from aborting the transaction.
     *
     * @param  array{subject: string, user_id?: int, lineup_id?: int|null}  $entity
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

    private function record(Rating $rating, Rating $opponent, float $score, int $after, int $delta, string $source, int $sourceId, ?int $number): void
    {
        RatingChange::query()->create([
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
    }
}

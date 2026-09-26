<?php

namespace App\Support\Rating;

use App\Enums\SeriesResolution;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
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
 *   and a series with a deleted lineup rate nothing.
 * - A rated game or series goes to the season ladder, but only while that
 *   ladder is open ({@see Ladders}); before Block 0 there is none and a rated
 *   result moves nothing (fail closed). A casual one goes to the permanent
 *   casual ladder, capped per pairing and UTC day
 *   (`season.casual.daily_pair_limit`). Casual never touches a rated row.
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

        if ($match->challenger_lineup_id === null || $match->challenged_lineup_id === null) {
            return false;
        }

        return $this->apply(
            (bool) $match->rated, $match->game, $match->mode,
            ['subject' => 'lineup:'.$match->challenger_lineup_id, 'lineup_id' => $match->challenger_lineup_id],
            ['subject' => 'lineup:'.$match->challenged_lineup_id, 'lineup_id' => $match->challenged_lineup_id],
            $match->winner === 'challenger' ? 1.0 : 0.0, RatingChange::SERIES, $match->id, $match->number,
        );
    }

    /**
     * @param  array{subject: string, user_id?: int, lineup_id?: int}  $challenger
     * @param  array{subject: string, user_id?: int, lineup_id?: int}  $challenged
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

            if ($pool === Rating::CASUAL && $this->pairCapReached($c, $d)) {
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
     * @param  array{subject: string, user_id?: int, lineup_id?: int}  $entity
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
     * Casual farming guard: this pairing already moved the casual rating
     * `daily_pair_limit` times today (UTC).
     */
    private function pairCapReached(Rating $a, Rating $b): bool
    {
        $limit = config('season.casual.daily_pair_limit');

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

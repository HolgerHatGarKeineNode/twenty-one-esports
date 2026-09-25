<?php

namespace App\Support\Chess;

use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A transaction over rows two players race for (an invite both answer, two
 * queue entries that pair). Under Postgres two such transactions can
 * deadlock; the database then aborts one of them. That one is run again
 * (DB::transaction's own retry), and if it loses every attempt the player
 * gets a rule violation (`lost_race`) the page shows as a message, not a
 * 500. Any other failure is thrown unchanged.
 *
 * Only the outermost transaction can retry: inside another one, a deadlock
 * aborts the whole outer transaction, so it surfaces here as `lost_race`
 * after one attempt.
 */
final class ChessTransaction
{
    public const ATTEMPTS = 3;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws ChessRuleViolation
     */
    public static function run(Closure $callback): mixed
    {
        try {
            return DB::transaction(fn () => $callback(), self::ATTEMPTS);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            report($exception);

            throw new ChessRuleViolation('lost_race', $exception->getMessage());
        }
    }
}

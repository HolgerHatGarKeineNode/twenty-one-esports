<?php

namespace App\Support\Rating;

use App\Models\ChessGame;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\Ladders;

/**
 * Read side of the ratings for the pages: which ladder a game shows, and the
 * values of players and lineups there, looked up in one query per list.
 * An entity without a row stands at the start rating with no results.
 *
 * `rated` reads the open season ladder and is empty before Block 0; `casual`
 * reads the permanent casual ladder.
 */
final class Ratings
{
    /**
     * @return 'rated'|'casual'
     */
    public static function pool(bool $rated): string
    {
        return $rated ? Rating::RATED : Rating::CASUAL;
    }

    /**
     * The season of a pool, null for a rated pool while no ladder is open.
     */
    public static function season(string $pool, string $game, string $mode): ?string
    {
        if ($pool === Rating::CASUAL) {
            return '';
        }

        return Ladders::isOpen($game, $mode) ? (string) config('esports.ladder.season') : null;
    }

    /**
     * @param  iterable<int|null>  $userIds
     * @return array<int, array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}>
     */
    public static function forUsers(iterable $userIds, string $game, string $mode, string $pool): array
    {
        return self::lookup('user', $userIds, $game, $mode, $pool);
    }

    /**
     * @param  iterable<int|null>  $lineupIds
     * @return array<int, array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}>
     */
    public static function forLineups(iterable $lineupIds, string $game, string $mode, string $pool): array
    {
        return self::lookup('lineup', $lineupIds, $game, $mode, $pool);
    }

    /**
     * @return array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}
     */
    public static function forUser(?int $userId, string $game, string $mode, string $pool): array
    {
        return self::forUsers([$userId], $game, $mode, $pool)[(int) $userId] ?? self::summary(null, $pool);
    }

    /**
     * @return array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}
     */
    public static function summary(?Rating $rating, string $pool): array
    {
        $key = $pool === Rating::CASUAL ? 'casual' : 'rating';

        // No row yet: the start rating with no results.
        [$value, $results, $wins, $draws, $losses] = $rating === null
            ? [(int) config("season.{$key}.start"), 0, 0, 0, 0]
            : [$rating->rating, $rating->results, $rating->wins, $rating->draws, $rating->losses];
        $provisional = $results < (int) config("season.{$key}.provisional");

        return [
            'rating' => $value,
            'results' => $results,
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $losses,
            'provisional' => $provisional,
            'tier' => $pool === Rating::RATED ? RankTiers::fromConfig()->tierFor($value, $results) : null,
            'pool' => $pool,
        ];
    }

    /**
     * Splits a tier token into the rank-badge props: `diamond-2` →
     * ['tier' => 'diamond', 'level' => 2]; `provisional` stays as it is.
     *
     * @return array{tier: string, level: int}
     */
    public static function badge(?string $token): array
    {
        if ($token === null || ! preg_match('/^(.+)-([123])$/', $token, $parts)) {
            return ['tier' => RankTiers::Provisional, 'level' => 1];
        }

        return ['tier' => $parts[1], 'level' => (int) $parts[2]];
    }

    /**
     * The ladder a player's headline rating comes from: the rated ladder once
     * it is open, the casual one before Block 0.
     *
     * @return array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}
     */
    public static function headline(?int $userId, string $game, string $mode): array
    {
        return self::forUser($userId, $game, $mode, self::pool(Ladders::isOpen($game, $mode)));
    }

    /**
     * The rating chips of a player (PlayerHeader.dc.html, the player card):
     * chess blitz always, plus each Rocket League lineup the player has a
     * seat in, every one from its headline ladder (casual before Block 0).
     *
     * @return list<array{label: string, rating: array<string, mixed>}>
     */
    public static function chipsFor(User $user): array
    {
        $chips = [['label' => __('Chess blitz'), 'rating' => self::headline($user->id, 'chess', 'blitz')]];

        $seats = LineupSeat::query()->where('user_id', $user->id)->whereNotNull('accepted_at')
            ->whereHas('lineup', fn ($query) => $query->where('game', 'rocket-league'))
            ->with('lineup')->get()->sortBy(fn (LineupSeat $seat) => $seat->lineup->mode);

        foreach ($seats as $seat) {
            $mode = $seat->lineup->mode;
            $pool = self::pool(Ladders::isOpen('rocket-league', $mode));
            $chips[] = ['label' => 'RL '.$mode, 'rating' => self::forLineups([$seat->lineup_id], 'rocket-league', $mode, $pool)[$seat->lineup_id]];
        }

        return $chips;
    }

    /**
     * Both players of a chess game in the game's ladder, with this game's
     * change once it moved their rating (`delta` null otherwise).
     *
     * @return array{w: array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string, before: int|null, delta: int|null}, b: array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string, before: int|null, delta: int|null}}
     */
    public static function forChessGame(ChessGame $game): array
    {
        $pool = self::pool((bool) $game->rated);
        $now = self::forUsers([$game->white_id, $game->black_id], 'chess', $game->mode, $pool);
        $changes = self::changesOf(RatingChange::CHESS, $game->id);
        $side = fn (int $userId) => ($now[$userId] ?? self::summary(null, $pool)) + [
            'before' => ($changes['user:'.$userId] ?? null)?->before,
            'delta' => ($changes['user:'.$userId] ?? null)?->delta,
        ];

        return ['w' => $side($game->white_id), 'b' => $side($game->black_id)];
    }

    /**
     * Both lineups of a series: rating now, and before/after/delta once the
     * result moved them. `win` / `loss` is what the challenger's lineup would
     * gain or lose with the ratings as they stand (null once decided).
     *
     * @return array{pool: string, challenger: array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string, before: int|null, delta: int|null}, challenged: array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string, before: int|null, delta: int|null}, win: int|null, loss: int|null, expected: float}
     */
    public static function forSeries(SeriesMatch $match): array
    {
        $pool = self::pool((bool) $match->rated);
        $now = self::forLineups([$match->challenger_lineup_id, $match->challenged_lineup_id], $match->game, $match->mode, $pool);
        $changes = self::changesOf(RatingChange::SERIES, $match->id);
        $side = fn (?int $lineupId) => ($lineupId !== null && isset($now[$lineupId]) ? $now[$lineupId] : self::summary(null, $pool)) + [
            'before' => ($changes['lineup:'.$lineupId] ?? null)?->before,
            'delta' => ($changes['lineup:'.$lineupId] ?? null)?->delta,
        ];

        $c = $side($match->challenger_lineup_id);
        $d = $side($match->challenged_lineup_id);
        $engine = EloRating::fromConfig($pool === Rating::CASUAL ? 'casual' : 'rating');
        $open = $c['delta'] === null && ! $match->status->hasResult();

        return [
            'pool' => $pool,
            'challenger' => $c,
            'challenged' => $d,
            'win' => $open ? $engine->rate($c['rating'], $d['rating'], 1.0, $c['results'], $d['results'])['challenger_delta'] : null,
            'loss' => $open ? $engine->rate($c['rating'], $d['rating'], 0.0, $c['results'], $d['results'])['challenger_delta'] : null,
            'expected' => $engine->expectedScore($c['rating'], $d['rating']),
        ];
    }

    /**
     * @return array<string, RatingChange> subject => change
     */
    private static function changesOf(string $source, int $sourceId): array
    {
        $changes = RatingChange::query()->with('rating:id,subject')->where('source', $source)->where('source_id', $sourceId)->get();

        return $changes->mapWithKeys(fn (RatingChange $change) => [$change->rating->subject => $change])->all();
    }

    /**
     * @param  'user'|'lineup'  $kind
     * @param  iterable<int|null>  $ids
     * @return array<int, array{rating: int, results: int, wins: int, draws: int, losses: int, provisional: bool, tier: string|null, pool: string}>
     */
    private static function lookup(string $kind, iterable $ids, string $game, string $mode, string $pool): array
    {
        $ids = array_values(array_unique(array_filter(is_array($ids) ? $ids : iterator_to_array($ids, false))));
        $season = self::season($pool, $game, $mode);
        $rows = $season === null || $ids === [] ? collect() : Rating::query()
            ->where(['pool' => $pool, 'season' => $season, 'game' => $game, 'mode' => $mode])
            ->whereIn('subject', array_map(fn (int $id): string => $kind.':'.$id, $ids))
            ->get()
            ->keyBy('subject');

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = self::summary($rows->get($kind.':'.$id), $pool);
        }

        return $out;
    }
}

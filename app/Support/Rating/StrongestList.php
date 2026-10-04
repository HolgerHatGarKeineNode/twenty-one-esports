<?php

namespace App\Support\Rating;

use App\Games\GameRegistry;
use App\Models\User;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Cache;

/**
 * The cross-game "Strongest" list (P40): every player with a Global Rating
 * in the live season (docs/nips/esports.md "Global Rating"), strongest
 * first. A player below the minimum weight has no Global Rating and is not
 * ranked; the list only counts them.
 *
 * The ranking comes from LadderBoard::globalBreakdown() and is cached under
 * LadderBoard::ratingStamp(), so it moves with the next rating change. A
 * render costs a fixed number of queries whatever the season's size: the
 * live season, the breakdown on a cache miss, and the listed players with
 * their clans (the stamp is a cache read).
 *
 * Without a live season (before Block 0, between seasons) there is nothing
 * to rank: `season` is null and the list is empty.
 */
final class StrongestList
{
    /** Players the page lists at most. */
    public const LIMIT = 100;

    /** Players the home tile shows. */
    public const HOME = 5;

    public function __construct(public readonly ?string $season) {}

    public static function current(): self
    {
        return new self(Ladders::season());
    }

    /**
     * The top `$limit` players and how many more are on their way (a weight
     * above zero but below the minimum). Order: Global Rating, then more
     * rated results, then the older account; places count every row.
     *
     * @return array{rows: list<array{place: int, user: User, rating: int, weight: int, games: array<string, int>}>, ranked: int, waiting: int}
     */
    public function top(int $limit): array
    {
        $ranking = $this->ranking();
        $listed = array_slice($ranking['ranked'], 0, $limit);

        if ($listed === []) {
            return ['rows' => [], 'ranked' => 0, 'waiting' => $ranking['waiting']];
        }

        $users = User::query()->whereKey(array_column($listed, 'user'))->with('clanMember.clan')->get()->keyBy('id');
        $rows = [];

        foreach ($listed as $entry) {
            $user = $users->get($entry['user']);

            if ($user instanceof User) {
                $rows[] = ['place' => count($rows) + 1, 'user' => $user, 'rating' => $entry['rating'], 'weight' => $entry['weight'], 'games' => $entry['games']];
            }
        }

        return ['rows' => $rows, 'ranked' => count($ranking['ranked']), 'waiting' => $ranking['waiting']];
    }

    /**
     * Every ranked player of the season in list order (ids only, cacheable),
     * and the number still below the minimum weight. The per-game weights
     * follow the registry's order, so a row's segments always line up the
     * same way.
     *
     * @return array{ranked: list<array{user: int, rating: int, weight: int, games: array<string, int>}>, waiting: int}
     */
    public function ranking(): array
    {
        $season = $this->season;

        if ($season === null) {
            return ['ranked' => [], 'waiting' => 0];
        }

        /** @var array{ranked: list<array{user: int, rating: int, weight: int, games: array<string, int>}>, waiting: int} */
        return Cache::remember('strongest:'.$season.':'.LadderBoard::ratingStamp(), now()->addMinutes(10), function () use ($season): array {
            $order = array_flip(array_keys(app(GameRegistry::class)->all()));
            $ranked = [];
            $waiting = 0;

            foreach (LadderBoard::globalBreakdown($season) as $userId => $player) {
                if ($player['rating'] === null) {
                    $waiting++;

                    continue;
                }

                $games = $player['games'];
                uksort($games, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
                $ranked[] = ['user' => (int) $userId, 'rating' => $player['rating'], 'weight' => $player['weight'], 'games' => $games];
            }

            usort($ranked, fn (array $a, array $b): int => [$b['rating'], $b['weight'], $a['user']] <=> [$a['rating'], $a['weight'], $b['user']]);

            return ['ranked' => $ranked, 'waiting' => $waiting];
        });
    }
}

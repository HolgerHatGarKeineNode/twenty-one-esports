<?php

namespace App\Support\Clans;

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Engagement\ClanHashrate;
use App\Support\Rating\ClanRating;
use App\Support\Rating\Ratings;
use App\Support\Series\Ladders;
use Illuminate\Support\Collection;

/**
 * The clan numbers of the pages, from the league's own records only
 * (replaces the sample figures of ClanStatsPreview, which the styleguide
 * keeps). Before Block 0 there is no live season: rated numbers are empty
 * (no Clan Rating, no Hashrate, no rank) and the pages say so.
 *
 * - Clan Rating (NIP "Terminology"): the average of the three best rated
 *   chess blitz ratings of the clan's members in the live season.
 * - Hashrate: {@see ClanHashrate}, for the season and the last 7 days.
 * - A lineup's Elo: its rated ladder once it is open, the casual one before
 *   (as the player chips do); rank among the rows of that ladder.
 * - Block Height (NIP "Block Height"): rated results a player took part in,
 *   over all seasons.
 *
 * One instance per page: the hashrate of all clans is computed once.
 */
final class ClanStats
{
    private readonly ?string $season;

    /** @var array<int, array{rating: int|null, top: list<int>}>|null */
    private ?array $ratings = null;

    public function __construct(private readonly ClanHashrate $hashrate)
    {
        $this->season = Ladders::season();
    }

    public function seasonLive(): bool
    {
        return $this->season !== null;
    }

    /**
     * @return array{rating: int|null, top: list<int>}
     */
    public function clanRating(Clan $clan): array
    {
        return $this->allRatings()[$clan->id] ?? ['rating' => null, 'top' => []];
    }

    /**
     * The members' rated blitz ratings of the live season, best first.
     *
     * @return Collection<int, array{user: User, rating: int, tier: string|null}>
     */
    public function topPlayers(Clan $clan, int $limit = 3): Collection
    {
        if ($this->season === null) {
            return collect();
        }

        return Rating::query()
            ->with('user')
            ->where(['pool' => Rating::RATED, 'season' => $this->season, 'game' => 'chess', 'mode' => 'blitz'])
            ->whereIn('user_id', ClanMember::query()->where('clan_id', $clan->id)->select('user_id'))
            ->orderByDesc('rating')->orderBy('id')
            ->limit($limit)
            ->get()
            ->flatMap(fn (Rating $rating): array => $rating->user instanceof User
                ? [['user' => $rating->user, 'rating' => $rating->rating, 'tier' => Ratings::summary($rating, Rating::RATED)['tier']]]
                : [])
            ->values();
    }

    /**
     * @return array{season: int, seasonBonus: int, week: int, weekBonus: int}
     */
    public function hashrate(Clan $clan): array
    {
        $season = $this->breakdown(false)[$clan->address()] ?? null;
        $week = $this->breakdown(true)[$clan->address()] ?? null;

        return ['season' => $season['points'] ?? 0, 'seasonBonus' => $season['bonus'] ?? 0, 'week' => $week['points'] ?? 0, 'weekBonus' => $week['bonus'] ?? 0];
    }

    /**
     * The Rocket League part (series results and team-win bonus) of the
     * clan's hashrate in a window.
     */
    public function seriesHashrate(Clan $clan, bool $week): int
    {
        return $this->breakdown($week)[$clan->address()]['series'] ?? 0;
    }

    /**
     * Team wins in a window (series won by a clan lineup).
     */
    public function teamWins(Clan $clan, bool $week): int
    {
        return $this->breakdown($week)[$clan->address()]['teamWins'] ?? 0;
    }

    /**
     * Hashrate points of each player (pubkey) for the clan in a window.
     *
     * @return array<string, int>
     */
    public function playerHashrate(Clan $clan, bool $week): array
    {
        return $this->breakdown($week)[$clan->address()]['players'] ?? [];
    }

    /**
     * Rank of the clan by Clan Rating among the rated clans, and by Hashrate
     * among the clans with points; null where it has none.
     *
     * @return array{rating: int|null, ratedClans: int, season: int|null, week: int|null, clans: int}
     */
    public function ranks(Clan $clan): array
    {
        $ratings = array_filter(array_map(fn (array $row): ?int => $row['rating'], $this->allRatings()), fn (?int $rating): bool => $rating !== null);
        arsort($ratings);
        $season = $this->forWindow(false);
        $week = $this->forWindow(true);
        $position = fn (array $list, int|string $key): ?int => ($index = array_search($key, array_keys($list), true)) === false ? null : $index + 1;

        return [
            'rating' => $position($ratings, $clan->id),
            'ratedClans' => count($ratings),
            'season' => $position($season, $clan->address()),
            'week' => $position($week, $clan->address()),
            'clans' => count($season),
        ];
    }

    /**
     * Clan address => points in a window, highest first, only clans with points.
     *
     * @return array<string, int>
     */
    public function forWindow(bool $week): array
    {
        $points = array_filter(array_map(fn (array $row): int => $row['points'], $this->breakdown($week)));
        arsort($points);

        return $points;
    }

    /**
     * A lineup's Elo on its headline ladder: rated once open, casual before.
     *
     * @return array{elo: int, series: int, rank: int, of: int, tier: string, level: int}
     */
    public function lineup(Lineup $lineup): array
    {
        $pool = Ratings::pool(Ladders::isOpen($lineup->game, $lineup->mode));
        $season = Ratings::season($pool, $lineup->game, $lineup->mode) ?? '';
        $ladder = Rating::query()->where(['pool' => $pool, 'season' => $season, 'game' => $lineup->game, 'mode' => $lineup->mode]);
        $row = (clone $ladder)->where('subject', 'lineup:'.$lineup->id)->first();
        $summary = Ratings::summary($row, $pool);
        $badge = Ratings::badge($summary['tier']);

        return [
            'elo' => $summary['rating'],
            'series' => $summary['results'],
            'rank' => $row === null ? 0 : (clone $ladder)->where('rating', '>', $row->rating)->count() + 1,
            'of' => (clone $ladder)->count(),
            'tier' => $badge['tier'],
            'level' => $badge['level'],
        ];
    }

    /**
     * The clan's Rocket League series record (ClanShow.dc.html): series,
     * wins and team goals in the last 30 days and in total (decided series
     * only), the Elo line of its 3v3 lineup on its headline ladder, and the
     * latest series with a result or waiting for one. Empty for a clan
     * without series.
     *
     * @return array{stats: list<array{0: string, 1: int, 2: int}>, line: list<int>, matches: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>}|null
     */
    public function record(Clan $clan, int $limit = 8): ?array
    {
        $lineups = Lineup::query()->where('clan_id', $clan->id)->get()->keyBy('id');
        $ids = $lineups->keys()->all();

        $matches = SeriesMatch::query()
            ->where(fn ($query) => $query->whereIn('challenger_lineup_id', $ids)->orWhereIn('challenged_lineup_id', $ids))
            ->whereIn('status', [SeriesStatus::Reported, SeriesStatus::Disputed, SeriesStatus::Confirmed, SeriesStatus::Resolved])
            ->with('latestReport')
            ->orderByDesc('number')
            ->get();

        if ($ids === [] || $matches->isEmpty()) {
            return null;
        }

        $sideOf = fn (SeriesMatch $match): string => in_array($match->challenger_lineup_id, $ids, true) ? 'challenger' : 'challenged';
        $decided = $matches->filter(fn (SeriesMatch $match): bool => $match->status->hasResult() && in_array($match->winner, SeriesMatch::SIDES, true));
        $recent = $decided->filter(fn (SeriesMatch $match): bool => ($match->finished_at ?? $match->updated_at)?->gte(now()->subDays(30)) === true);
        $count = fn (Collection $set): array => [
            $set->count(),
            $set->filter(fn (SeriesMatch $match): bool => $match->winner === $sideOf($match))->count(),
            (int) $set->sum(fn (SeriesMatch $match): int => array_sum(array_map(fn (array $game): int => (int) ($game[$sideOf($match)] ?? 0), $match->result_games ?? []))),
        ];
        [$recentSeries, $recentWins, $recentGoals] = $count($recent);
        [$series, $wins, $goals] = $count($decided);

        $deltas = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('rating_changes.source', RatingChange::SERIES)
            ->whereIn('rating_changes.source_id', $matches->take($limit)->pluck('id'))
            ->whereIn('ratings.lineup_id', $ids)
            ->pluck('rating_changes.delta', 'rating_changes.source_id');

        $rows = $matches->take($limit)->map(function (SeriesMatch $match) use ($sideOf, $deltas): array {
            $side = $sideOf($match);
            $other = SeriesMatch::otherSide($side);
            $score = $match->status->hasResult() ? SeriesMatch::seriesScore($match->result_games) : ($match->latestReport?->score() ?? ['challenger' => 0, 'challenged' => 0]);
            $result = ! $match->status->hasResult() ? 'wait' : ($match->winner === $side ? 'win' : 'loss');
            $delta = $deltas[$match->id] ?? null;

            return [
                $match->label(),
                $match->sideTag($other),
                $match->sideName($other),
                $score[$side].' : '.$score[$other],
                $match->mode.' · BO'.$match->best_of,
                $result,
                $delta === null ? (string) ($result === 'wait' ? __('pending') : __('no Elo')) : ($delta >= 0 ? '+'.$delta : '−'.abs((int) $delta)),
                ($match->finished_at ?? $match->updated_at)?->diffForHumans() ?? '',
            ];
        })->all();

        $line = [];
        $lead = $lineups->firstWhere('mode', '3v3');

        if ($lead instanceof Lineup) {
            $pool = Ratings::pool(Ladders::isOpen($lead->game, $lead->mode));
            $changes = RatingChange::query()
                ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
                ->where(['ratings.pool' => $pool, 'ratings.season' => Ratings::season($pool, $lead->game, $lead->mode) ?? '', 'ratings.subject' => 'lineup:'.$lead->id])
                ->orderBy('rating_changes.id')
                ->get(['rating_changes.before', 'rating_changes.after']);

            if ($changes->isNotEmpty()) {
                $line = [(int) $changes->first()->before, ...$changes->pluck('after')->map(fn ($after): int => (int) $after)->all()];
            }
        }

        return [
            'stats' => [['Series', $recentSeries, $series], ['Wins', $recentWins, $wins], ['Goals (team)', $recentGoals, $goals]],
            'line' => array_values($line),
            'matches' => array_values($rows),
        ];
    }

    /**
     * Block Height per player: rated results they took part in, all seasons.
     * A chess game counts for its player's rating row; a lineup series once
     * for each player of its counted roster on that side.
     *
     * @param  iterable<int>  $userIds
     * @return array<int, int>
     */
    public static function blockHeights(iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $heights = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $heights;
        }

        $own = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::RATED)
            ->whereIn('ratings.user_id', $ids)
            ->selectRaw('ratings.user_id as user_id, count(*) as results')
            ->groupBy('ratings.user_id')
            ->pluck('results', 'user_id');

        foreach ($own as $userId => $count) {
            $heights[(int) $userId] += (int) $count;
        }

        $lineupSeries = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::RATED)
            ->where('rating_changes.source', RatingChange::SERIES)
            ->whereNotNull('ratings.lineup_id')
            ->distinct()
            ->pluck('rating_changes.source_id');

        foreach (SeriesMatch::query()->with('latestReport')->whereKey($lineupSeries)->get() as $match) {
            foreach (array_unique(array_map(fn (array $entry): int => (int) $entry['user_id'], $match->countedRoster())) as $userId) {
                if (array_key_exists($userId, $heights)) {
                    $heights[$userId]++;
                }
            }
        }

        return $heights;
    }

    /**
     * @return array<int, array{rating: int|null, top: list<int>}>
     */
    private function allRatings(): array
    {
        if ($this->ratings !== null) {
            return $this->ratings;
        }

        if ($this->season === null) {
            return $this->ratings = [];
        }

        $rows = Rating::query()
            ->join('clan_members', 'clan_members.user_id', '=', 'ratings.user_id')
            ->where(['ratings.pool' => Rating::RATED, 'ratings.season' => $this->season, 'ratings.game' => 'chess', 'ratings.mode' => 'blitz'])
            ->orderByDesc('ratings.rating')
            ->get(['clan_members.clan_id', 'ratings.rating']);

        $engine = ClanRating::fromConfig();
        $this->ratings = [];

        foreach ($rows->groupBy('clan_id') as $clanId => $group) {
            $all = array_values($group->pluck('rating')->map(fn ($rating): int => (int) $rating)->all());
            $this->ratings[(int) $clanId] = ['rating' => $engine->rating($all), 'top' => array_slice($all, 0, $engine->top)];
        }

        return $this->ratings;
    }

    /**
     * @return array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>
     */
    private function breakdown(bool $week): array
    {
        if ($this->season === null) {
            return [];
        }

        // Rounded to the minute, so both reads of a page share the memo.
        return $this->hashrate->breakdown($this->season, $week ? now()->subDays(7)->startOfMinute() : null);
    }
}

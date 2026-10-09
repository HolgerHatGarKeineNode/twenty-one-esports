<?php

namespace App\Support\Hyper;

use App\Models\HyperMatch;
use App\Models\HyperRating;
use App\Models\HyperRatingChange;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\Rating\EloRating;
use App\Support\Rating\NipMath;
use App\Support\SeasonChain\Seasons;
use Illuminate\Support\Collection;

/**
 * Hyperbitcoinization in the season (plan "Hyperbitcoinization", P5, Ansatz 11; user 2026-10-08: "FFA nach
 * Platzierung, Clan/Meetup und 1v1 mit Elo; nur Partien ohne Bots").
 *
 * - **Rated**: a match whose every seat is a player at the start, begun while a chain season is live
 *   ({@see seasonFor()}); it counts in that season only (`hyper_matches.season`). Before Block 0 and between
 *   seasons every match is casual, as chess is.
 * - **Kinds**: a clan match is `team`, a match of two seats a `duel` (1v1), every other a free-for-all (`ffa`).
 * - **Free-for-all**: points by place ({@see points()}, `esports.hyper.season_points`, 3 to 6 seats), stored on
 *   the seat at the end, summed per player over the season ({@see ffaStandings()}).
 * - **1v1 and team**: Elo with the season's rating values (EloRating, `season.rating`): a side's rating is the
 *   average of its members', the expected score is the side's, and each member moves by their own k-factor
 *   (provisional while they have fewer results). One row per season, kind and player (hyper_ratings).
 * - **Forfeit**: a player who leaves a rated match or lets `takeover_timeouts` turns run out in a row forfeits
 *   (HyperMatches): a bot plays the seat on for the others, the player takes the last place ({@see placeForfeits()}),
 *   scores 0 points in a free-for-all and loses their Elo (score 0) whatever their side did.
 *
 * {@see record()} runs inside the transaction that ends the match, so a result and its season entry commit
 * together; it is idempotent (a seat's points are written once, a rating change is unique per rating and match).
 */
final class HyperSeason
{
    public const FFA = 'ffa';

    public const KINDS = [self::FFA, HyperRating::DUEL, HyperRating::TEAM];

    /** The season a match begun now counts in: the live chain season's slug, null outside a season. */
    public static function seasonFor(): ?string
    {
        return Seasons::live()?->slug;
    }

    public static function kindOf(HyperMatch $match): string
    {
        return match (true) {
            $match->isTeamMatch() => HyperRating::TEAM,
            $match->seats->count() === 2 => HyperRating::DUEL,
            default => self::FFA,
        };
    }

    /**
     * The points of place `$place` (1 = best) in a rated free-for-all of `$seats` seats; 0 beyond the table.
     */
    public static function points(int $seats, int $place): int
    {
        $table = array_values(array_map(intval(...), (array) config("esports.hyper.season_points.{$seats}", [])));

        return $place >= 1 ? ($table[$place - 1] ?? 0) : 0;
    }

    public static function forfeited(HyperSeat $seat): bool
    {
        return $seat->takeover === HyperSeat::TAKEOVER_FORFEIT;
    }

    /**
     * The places of a finished rated match with its forfeits last: every seat that played on keeps its order (a
     * shared place stays shared, the numbers close up), then the forfeits, the latest forfeit before the earlier
     * ones. Changes the seats in memory; the caller saves them.
     */
    public static function placeForfeits(HyperMatch $match): void
    {
        $seats = $match->seats;
        // A seat without a place (none should be left at the end) goes behind the placed ones, never ahead.
        $kept = $seats->reject(self::forfeited(...))->sortBy([fn (HyperSeat $a, HyperSeat $b): int => ($a->place ?? PHP_INT_MAX) <=> ($b->place ?? PHP_INT_MAX), ['seat', 'asc']])->values();
        $forfeits = $seats->filter(self::forfeited(...))
            ->sortBy([fn (HyperSeat $a, HyperSeat $b): int => ($b->left_at?->getTimestamp() ?? 0) <=> ($a->left_at?->getTimestamp() ?? 0), ['seat', 'asc']])->values();
        $previous = null;
        $rank = 0;

        foreach ($kept as $index => $seat) {
            if ($seat->place !== $previous) {
                $rank = $index + 1;
                $previous = $seat->place;
            }

            $seat->place = $rank;
        }

        foreach ($forfeits as $index => $seat) {
            $seat->place = $kept->count() + $index + 1;
        }
    }

    /**
     * The season entry of a finished rated match: points for a free-for-all, Elo for a 1v1 or a team match. Call
     * inside the transaction that ends it, after the places are stored. Nothing for a casual match.
     */
    public function record(HyperMatch $match): void
    {
        if (! $match->rated || $match->season === null) {
            return;
        }

        $match->loadMissing('seats');

        if (self::kindOf($match) === self::FFA) {
            foreach ($match->seats as $seat) {
                if ($seat->user_id !== null && $seat->points === null && $seat->place !== null) {
                    $seat->forceFill(['points' => self::forfeited($seat) ? 0 : self::points($match->seats->count(), $seat->place)])->save();
                }
            }

            return;
        }

        $this->rate($match, self::kindOf($match));
    }

    /**
     * Elo of a 1v1 or team match: the two sides (a team match's `team`, else seat 0 and seat 1), the side holding
     * place 1 won.
     */
    private function rate(HyperMatch $match, string $kind): void
    {
        $sides = $match->seats->filter(fn (HyperSeat $seat): bool => $seat->user_id !== null)
            ->groupBy(fn (HyperSeat $seat): int => $kind === HyperRating::TEAM ? (int) $seat->team : $seat->seat);

        if ($sides->count() !== 2) {
            return;
        }

        $engine = EloRating::fromConfig('rating');
        $season = (string) $match->season;
        $userIds = $sides->flatten()->pluck('user_id')->map(intval(...))->all();

        foreach ($userIds as $userId) {
            HyperRating::query()->insertOrIgnore([[
                'season' => $season, 'kind' => $kind, 'user_id' => $userId, 'rating' => $engine->start,
                'results' => 0, 'wins' => 0, 'losses' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]]);
        }

        // Lock in id order, so two matches of the same players cannot deadlock.
        $ratings = HyperRating::query()->where(['season' => $season, 'kind' => $kind])->whereIn('user_id', $userIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');

        if (HyperRatingChange::query()->where('hyper_match_id', $match->id)->whereIn('hyper_rating_id', $ratings->modelKeys())->exists()) {
            return;
        }

        [$first, $second] = $sides->values()->all();
        $average = fn (Collection $side): float => $side->avg(fn (HyperSeat $seat): int => $ratings[$seat->user_id]->rating);
        $won = fn (Collection $side): bool => $side->contains(fn (HyperSeat $seat): bool => $seat->place === 1);
        $expected = [$engine->expectedScore((int) round($average($first)), (int) round($average($second)))];
        $expected[] = 1.0 - $expected[0];

        foreach ([$first, $second] as $index => $side) {
            $sideScore = $won($side) ? 1.0 : 0.0;

            foreach ($side as $seat) {
                $rating = $ratings[$seat->user_id];
                $score = self::forfeited($seat) ? 0.0 : $sideScore;
                $delta = NipMath::round($engine->kFactor($rating->results) * ($score - $expected[$index]));

                HyperRatingChange::query()->create([
                    'hyper_rating_id' => $rating->id,
                    'hyper_match_id' => $match->id,
                    'score' => $score,
                    'before' => $rating->rating,
                    'after' => $rating->rating + $delta,
                    'delta' => $delta,
                    'results_before' => $rating->results,
                    'created_at' => now(),
                ]);

                $rating->forceFill([
                    'rating' => $rating->rating + $delta,
                    'results' => $rating->results + 1,
                    'wins' => $rating->wins + ($score === 1.0 ? 1 : 0),
                    'losses' => $rating->losses + ($score === 1.0 ? 0 : 1),
                ])->save();
            }
        }
    }

    /* ---------- Standings ---------------------------------------------------------------------------------- */

    /**
     * The free-for-all table of a season: points per player, then wins, then fewer matches, then first to reach
     * it. Every rated free-for-all match the player sat in counts, a forfeit with 0 points.
     *
     * @return list<array{rank: int, user: User, points: int, matches: int, wins: int}>
     */
    public function ffaStandings(string $season, int $limit = 200): array
    {
        $rows = HyperSeat::query()->toBase()
            ->join('hyper_matches', 'hyper_matches.id', '=', 'hyper_seats.hyper_match_id')
            ->where('hyper_matches.season', $season)->where('hyper_matches.rated', true)
            ->whereNotNull('hyper_seats.points')->whereNotNull('hyper_seats.user_id')
            ->groupBy('hyper_seats.user_id')
            ->selectRaw('hyper_seats.user_id, sum(hyper_seats.points) as points, count(*) as matches, sum(case when hyper_seats.place = 1 then 1 else 0 end) as wins, max(hyper_matches.ended_at) as last_at')
            ->orderByDesc('points')->orderByDesc('wins')->orderBy('matches')->orderBy('last_at')->orderBy('hyper_seats.user_id')
            ->limit($limit)->get();
        $users = User::query()->whereKey($rows->pluck('user_id')->all())->get()->keyBy('id');
        $standings = [];

        foreach ($rows as $row) {
            $user = $users->get((int) $row->user_id);

            if ($user instanceof User) {
                $standings[] = ['rank' => count($standings) + 1, 'user' => $user, 'points' => (int) $row->points, 'matches' => (int) $row->matches, 'wins' => (int) $row->wins];
            }
        }

        return $standings;
    }

    /**
     * The Elo table of a season and kind (`duel` or `team`), best first.
     *
     * @return Collection<int, HyperRating>
     */
    public function eloStandings(string $season, string $kind, int $limit = 200): Collection
    {
        return HyperRating::query()->where(['season' => $season, 'kind' => $kind])->where('results', '>', 0)
            ->with('user')->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit($limit)->get();
    }

    /**
     * One player's season in short, for the profile and the ladder: FFA points, and the 1v1 and team Elo.
     *
     * @return array{ffa: int|null, duel: int|null, team: int|null}
     */
    public function summaryOf(User $user, string $season): array
    {
        $points = HyperSeat::query()->where('user_id', $user->id)->whereNotNull('points')
            ->whereHas('match', fn ($query) => $query->where('season', $season)->where('rated', true))->sum('points');
        $elo = HyperRating::query()->where(['season' => $season, 'user_id' => $user->id])->where('results', '>', 0)->pluck('rating', 'kind');
        $played = HyperSeat::query()->where('user_id', $user->id)->whereNotNull('points')
            ->whereHas('match', fn ($query) => $query->where('season', $season)->where('rated', true))->exists();

        return [
            'ffa' => $played ? (int) $points : null,
            'duel' => isset($elo[HyperRating::DUEL]) ? (int) $elo[HyperRating::DUEL] : null,
            'team' => isset($elo[HyperRating::TEAM]) ? (int) $elo[HyperRating::TEAM] : null,
        ];
    }
}

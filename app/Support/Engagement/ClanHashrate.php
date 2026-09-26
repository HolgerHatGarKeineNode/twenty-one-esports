<?php

namespace App\Support\Engagement;

use App\Models\Clan;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Support\Rating\ClanRating;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clan hashrate of a season from the league's own records (docs/nips/
 * esports.md, "Clan hashrate"):
 *
 * 1. every rated player with a clan at the accept (`clans_at_accept`, the
 *    `clan` row of the attestation) earns that clan the points of their own
 *    result: a chess game gives its two players the game result, a Rocket
 *    League series gives each player of the counted roster the series
 *    result;
 * 2. a series won by a lineup adds the team-win bonus once to the lineup's
 *    clan;
 * 3. only rated results count: results that moved a rating of the season's
 *    rated ladders. Casual games, void series and players without a clan
 *    earn nothing.
 *
 * "Last 7 days" is the same over the results recorded since then (from a
 * whole minute), counted in the same pass as the season. Chess
 * team matches over boards do not exist yet, so there is no chess team-win
 * bonus to count.
 *
 * Cost (security gate P10, Low): the whole season is read, so a breakdown
 * is computed in one pass for both windows and cached for
 * {@see CACHE_SECONDS} per season, the rows are read without model
 * hydration, and one instance serves a request (AppServiceProvider). A
 * result shows in the numbers within a minute.
 */
final class ClanHashrate
{
    public const CACHE_SECONDS = 60;

    /** Rows per whereIn, below every driver's bound-parameter limit. */
    private const CHUNK = 1000;

    /** @var array<string, array{season: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>, week: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>}> */
    private array $memo = [];

    /**
     * @return array<string, int> clan address => hashrate points
     */
    public function forSeason(string $season, bool $week = false): array
    {
        return array_map(fn (array $clan): int => $clan['points'], $this->breakdown($season, $week));
    }

    /**
     * Per clan address: the points, the part of them from team wins, the
     * number of team wins, the part from Rocket League series, and the
     * points each player (pubkey) earned; for the season or its last 7 days.
     *
     * @return array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>
     */
    public function breakdown(string $season, bool $week = false): array
    {
        $key = 'clan-hashrate:'.$season;
        // The window starts on a whole minute, so one cached pass serves the whole minute.
        $since = now()->subDays(7)->startOfMinute();

        $this->memo[$key] ??= Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->compute($season, $since));

        return $this->memo[$key][$week ? 'week' : 'season'];
    }

    /**
     * @return array{season: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>, week: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>}
     */
    private function compute(string $season, CarbonInterface $since): array
    {
        // Each rated result once, with the time it was recorded.
        $sources = RatingChange::query()
            ->toBase()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::RATED)
            ->where('ratings.season', $season)
            ->groupBy('rating_changes.source', 'rating_changes.source_id')
            ->selectRaw('rating_changes.source as source, rating_changes.source_id as source_id, max(rating_changes.created_at) as recorded_at')
            ->get();
        $recent = $sources->filter(fn (object $row): bool => $row->recorded_at !== null && CarbonImmutable::parse($row->recorded_at)->gte($since))
            ->map(fn (object $row): string => $row->source.':'.$row->source_id)->flip();

        $weights = ClanRating::fromConfig();
        $points = [$weights->winPoints, $weights->drawPoints, $weights->lossPoints];
        /** @var array{season: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>, week: array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>} $windows */
        $windows = ['season' => [], 'week' => []];
        $add = function (?string $address, ?string $pubkey, int $slot, bool $series, bool $isRecent) use (&$windows, $points, $weights): void {
            if ($address === null || $address === '') {
                return;
            }

            foreach ($isRecent ? ['season', 'week'] : ['season'] as $window) {
                $row = $windows[$window][$address] ?? ['points' => 0, 'bonus' => 0, 'teamWins' => 0, 'series' => 0, 'players' => []];

                if ($pubkey === null) {
                    $row['points'] += $weights->teamWinBonus;
                    $row['bonus'] += $weights->teamWinBonus;
                    $row['teamWins']++;
                    $row['series'] += $weights->teamWinBonus;
                } else {
                    $row['points'] += $points[$slot];
                    $row['series'] += $series ? $points[$slot] : 0;
                    $row['players'][$pubkey] = ($row['players'][$pubkey] ?? 0) + $points[$slot];
                }

                $windows[$window][$address] = $row;
            }
        };

        foreach ($sources->where('source', RatingChange::CHESS)->pluck('source_id')->chunk(self::CHUNK) as $ids) {
            $games = DB::table('chess_games')->whereIn('id', $ids->all())->get(['id', 'white_id', 'black_id', 'result', 'clans_at_accept']);
            $pubkeys = DB::table('users')->whereIn('id', $games->pluck('white_id')->merge($games->pluck('black_id'))->filter()->unique()->values()->all())->pluck('pubkey', 'id');

            foreach ($games as $game) {
                $atAccept = self::json($game->clans_at_accept);
                [$white, $black] = match ($game->result) {
                    '1-0' => [0, 2],
                    '0-1' => [2, 0],
                    default => [1, 1],
                };

                foreach ([[$game->white_id, $white], [$game->black_id, $black]] as [$userId, $slot]) {
                    $pubkey = $userId === null ? null : ($pubkeys[$userId] ?? null);

                    if (is_string($pubkey)) {
                        $add($atAccept[$pubkey] ?? null, $pubkey, $slot, false, isset($recent['chess:'.$game->id]));
                    }
                }
            }
        }

        foreach ($sources->where('source', RatingChange::SERIES)->pluck('source_id')->chunk(self::CHUNK) as $ids) {
            $matches = DB::table('series_matches')->whereIn('id', $ids->all())
                ->get(['id', 'winner', 'resolved_roster', 'clans_at_accept', 'challenger_lineup_id', 'challenged_lineup_id']);
            // The roster of the latest report, for series without an admin decision (SeriesMatch::countedRoster()).
            $reports = DB::table('series_reports')
                ->whereIn('id', DB::table('series_reports')->whereIn('series_match_id', $ids->all())->groupBy('series_match_id')->selectRaw('max(id)'))
                ->pluck('roster', 'series_match_id');
            $lineupClans = DB::table('lineups')->join('clans', 'clans.id', '=', 'lineups.clan_id')
                ->whereIn('lineups.id', $matches->pluck('challenger_lineup_id')->merge($matches->pluck('challenged_lineup_id'))->filter()->unique()->values()->all())
                ->get(['lineups.id', 'clans.owner_pubkey', 'clans.slug'])
                ->mapWithKeys(fn (object $row): array => [$row->id => Clan::KIND.':'.$row->owner_pubkey.':'.$row->slug]);

            foreach ($matches as $match) {
                $atAccept = self::json($match->clans_at_accept);
                $isRecent = isset($recent['series:'.$match->id]);
                $roster = $match->resolved_roster !== null ? self::json($match->resolved_roster) : self::json($reports[$match->id] ?? null);

                foreach ($roster as $entry) {
                    $add($atAccept[$entry['pubkey']] ?? null, $entry['pubkey'], $entry['side'] === $match->winner ? 0 : 2, true, $isRecent);
                }

                if (in_array($match->winner, SeriesMatch::SIDES, true)) {
                    $lineupId = $match->winner === 'challenger' ? $match->challenger_lineup_id : $match->challenged_lineup_id;
                    $add($lineupId === null ? null : ($lineupClans[$lineupId] ?? null), null, 0, true, $isRecent);
                }
            }
        }

        return $windows;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The city ranking of a season; empty while no season is live.
     *
     * @return list<array{city: string, hashrate: int, clans: int}>
     */
    public function cities(?string $season): array
    {
        if ($season === null) {
            return [];
        }

        $hashrate = $this->forSeason($season);
        $clans = Clan::query()->whereNotNull('meetup_city')->where('meetup_city', '!=', '')->get(['id', 'owner_pubkey', 'slug', 'meetup_city']);

        return CityRanking::rank($clans->map(fn (Clan $clan): array => ['city' => $clan->meetup_city, 'hashrate' => $hashrate[$clan->address()] ?? 0]));
    }
}

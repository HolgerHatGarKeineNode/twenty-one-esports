<?php

namespace App\Support\Engagement;

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Support\Rating\ClanRating;
use Carbon\CarbonInterface;

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
 * "Last 7 days" is the same over the results recorded since then. Chess
 * team matches over boards do not exist yet, so there is no chess team-win
 * bonus to count.
 */
final class ClanHashrate
{
    /** @var array<string, array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>> */
    private array $memo = [];

    /**
     * @return array<string, int> clan address => hashrate points
     */
    public function forSeason(string $season, ?CarbonInterface $since = null): array
    {
        return array_map(fn (array $clan): int => $clan['points'], $this->breakdown($season, $since));
    }

    /**
     * Per clan address: the points, the part of them from team wins, the
     * number of team wins, the part from Rocket League series, and the
     * points each player (pubkey) earned.
     *
     * @return array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>
     */
    public function breakdown(string $season, ?CarbonInterface $since = null): array
    {
        $key = $season.'|'.($since?->getTimestamp() ?? '');

        return $this->memo[$key] ??= $this->compute($season, $since);
    }

    /**
     * @return array<string, array{points: int, bonus: int, teamWins: int, series: int, players: array<string, int>}>
     */
    private function compute(string $season, ?CarbonInterface $since): array
    {
        $sources = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::RATED)
            ->where('ratings.season', $season)
            ->when($since !== null, fn ($query) => $query->where('rating_changes.created_at', '>=', $since))
            ->distinct()
            ->get(['rating_changes.source', 'rating_changes.source_id']);

        $weights = ClanRating::fromConfig();
        $points = [$weights->winPoints, $weights->drawPoints, $weights->lossPoints];
        $clans = [];
        $add = function (?string $address, ?string $pubkey, int $slot, bool $series = false) use (&$clans, $points, $weights): void {
            if ($address === null || $address === '') {
                return;
            }

            $clans[$address] ??= ['points' => 0, 'bonus' => 0, 'teamWins' => 0, 'series' => 0, 'players' => []];

            if ($pubkey === null) {
                $clans[$address]['points'] += $weights->teamWinBonus;
                $clans[$address]['bonus'] += $weights->teamWinBonus;
                $clans[$address]['teamWins']++;
                $clans[$address]['series'] += $weights->teamWinBonus;

                return;
            }

            $clans[$address]['points'] += $points[$slot];
            $clans[$address]['series'] += $series ? $points[$slot] : 0;
            $clans[$address]['players'][$pubkey] = ($clans[$address]['players'][$pubkey] ?? 0) + $points[$slot];
        };

        $chessIds = $sources->where('source', RatingChange::CHESS)->pluck('source_id')->all();

        foreach (ChessGame::query()->with(['white:id,pubkey', 'black:id,pubkey'])->whereKey($chessIds)->get() as $game) {
            $atAccept = $game->clans_at_accept ?? [];
            [$white, $black] = match ($game->result) {
                '1-0' => [0, 2],
                '0-1' => [2, 0],
                default => [1, 1],
            };

            $add($atAccept[$game->white->pubkey] ?? null, $game->white->pubkey, $white);
            $add($atAccept[$game->black->pubkey] ?? null, $game->black->pubkey, $black);
        }

        $seriesIds = $sources->where('source', RatingChange::SERIES)->pluck('source_id')->all();

        foreach (SeriesMatch::query()->with(['latestReport', 'challengerLineup.clan', 'challengedLineup.clan'])->whereKey($seriesIds)->get() as $match) {
            $atAccept = $match->clans_at_accept ?? [];

            foreach ($match->countedRoster() as $entry) {
                $add($atAccept[$entry['pubkey']] ?? null, $entry['pubkey'], $entry['side'] === $match->winner ? 0 : 2, true);
            }

            if (in_array($match->winner, SeriesMatch::SIDES, true)) {
                $add($match->lineup($match->winner)?->clan?->address(), null, 0);
            }
        }

        return $clans;
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

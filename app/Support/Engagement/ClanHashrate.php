<?php

namespace App\Support\Engagement;

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Support\Rating\ClanRating;

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
 * Chess team matches over boards do not exist yet, so there is no chess
 * team-win bonus to count.
 */
final class ClanHashrate
{
    /**
     * @return array<string, int> clan address => hashrate points
     */
    public function forSeason(string $season): array
    {
        $sources = RatingChange::query()
            ->join('ratings', 'ratings.id', '=', 'rating_changes.rating_id')
            ->where('ratings.pool', Rating::RATED)
            ->where('ratings.season', $season)
            ->distinct()
            ->get(['rating_changes.source', 'rating_changes.source_id']);

        /** @var array<string, array{0: int, 1: int, 2: int, 3: int}> $counts address => [wins, draws, losses, team wins] */
        $counts = [];
        $add = function (?string $address, int $slot) use (&$counts): void {
            if ($address !== null && $address !== '') {
                $counts[$address] ??= [0, 0, 0, 0];
                $counts[$address][$slot]++;
            }
        };

        $chessIds = $sources->where('source', RatingChange::CHESS)->pluck('source_id')->all();

        foreach (ChessGame::query()->with(['white:id,pubkey', 'black:id,pubkey'])->whereKey($chessIds)->get() as $game) {
            $clans = $game->clans_at_accept ?? [];
            [$white, $black] = match ($game->result) {
                '1-0' => [0, 2],
                '0-1' => [2, 0],
                default => [1, 1],
            };

            $add($clans[$game->white->pubkey] ?? null, $white);
            $add($clans[$game->black->pubkey] ?? null, $black);
        }

        $seriesIds = $sources->where('source', RatingChange::SERIES)->pluck('source_id')->all();

        foreach (SeriesMatch::query()->with(['latestReport', 'challengerLineup.clan', 'challengedLineup.clan'])->whereKey($seriesIds)->get() as $match) {
            $clans = $match->clans_at_accept ?? [];

            foreach ($match->countedRoster() as $entry) {
                $add($clans[$entry['pubkey']] ?? null, $entry['side'] === $match->winner ? 0 : 2);
            }

            if (in_array($match->winner, SeriesMatch::SIDES, true)) {
                $add($match->lineup($match->winner)?->clan?->address(), 3);
            }
        }

        $points = ClanRating::fromConfig();

        return array_map(fn (array $count): int => $points->hashrate(...$count), $counts);
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

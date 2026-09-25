<?php

namespace App\Support\Rating;

/**
 * Clan values derived for display (docs/nips/esports.md, "Terminology" and
 * "Clan hashrate"): the clan rating is the average of the top three player
 * ratings of the clan's members in one chess mode, rounded as in Rating; the
 * hashrate adds the ladder's `hashrate` points of every rated result of the
 * clan's players plus a bonus per team win.
 */
final class ClanRating
{
    public function __construct(
        public readonly int $top = 3,
        public readonly int $winPoints = 3,
        public readonly int $drawPoints = 2,
        public readonly int $lossPoints = 1,
        public readonly int $teamWinBonus = 5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('season.clan_rating_top'),
            (int) config('season.hashrate.win'),
            (int) config('season.hashrate.draw'),
            (int) config('season.hashrate.loss'),
            (int) config('season.hashrate.team_win_bonus'),
        );
    }

    /**
     * Null while the clan has fewer rated members than the top count, as in
     * the sample ledger.
     *
     * @param  list<int>  $memberRatings
     */
    public function rating(array $memberRatings): ?int
    {
        if (count($memberRatings) < $this->top) {
            return null;
        }

        rsort($memberRatings);

        return NipMath::round(array_sum(array_slice($memberRatings, 0, $this->top)) / $this->top);
    }

    /**
     * Hashrate points of a clan: its players' rated results (a series counts
     * once per roster player, a chess game or board for its player) plus the
     * bonus per team win (a series won by a lineup, a chess team match won on
     * board points).
     */
    public function hashrate(int $wins, int $draws, int $losses, int $teamWins): int
    {
        return $wins * $this->winPoints + $draws * $this->drawPoints + $losses * $this->lossPoints
            + $teamWins * $this->teamWinBonus;
    }
}

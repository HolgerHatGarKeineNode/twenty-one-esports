<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;

/**
 * The AdminSeason estimator (SEASON-CHAIN.md, "Admin: season settings with
 * estimator", decision 12): from a forecast of valid blocks per week per
 * game and mode it simulates the rest of the season in steps of one day,
 * with the share cap per game and era, and reports when 50 / 75 / 87.5 / 94 %
 * of the supply are mined, what is mined at the season end, the payout per
 * week, the rewarded wins the caps allow, and warnings.
 *
 * The arithmetic follows the ledger generator's estimator
 * (ledger-src/chain.py, `estimate`) step by step in IEEE 754 doubles, so the
 * figures match it exactly; figures it rounds are rounded half to even, as
 * Python's round() does.
 */
final class Estimator
{
    private const int Week = 604800;

    /**
     * @param  list<float>  $milestones  shares of the supply
     */
    public function __construct(
        public readonly int $windowDays = 28,
        public readonly array $milestones = [0.5, 0.75, 0.875, 0.94],
        public readonly int $minWeeks = 8,
        public readonly int $maxWeeks = 26,
    ) {}

    public static function fromConfig(): self
    {
        /** @var list<float> $milestones */
        $milestones = config('season.estimator.milestones');

        return new self(
            (int) config('season.estimator.window_days'),
            $milestones,
            (int) config('season.estimator.min_weeks'),
            (int) config('season.estimator.max_weeks'),
        );
    }

    /**
     * Forecast from the live chain: blocks per week over the window before
     * $now, per `<game>/<mode>`; milestones already passed are the real week.
     *
     * @return array<string, mixed>
     */
    public function fromChain(BlockChain $chain, CarbonImmutable $now): array
    {
        $season = $chain->season;
        $from = $now->subDays($this->windowDays);
        $counts = [];

        foreach ($chain->blocks() as $block) {
            $at = $block->candidate->attestedAt;
            if ($at->gt($from) && $at->lte($now)) {
                $key = $block->candidate->weightKey;
                $counts[$key] ??= ['game' => $block->candidate->game, 'blocks' => 0, 'winners' => 0];
                $counts[$key]['blocks']++;
                $counts[$key]['winners'] += count($block->candidate->winners);
            }
        }

        $weeks = $this->windowDays / 7;
        $streams = [];
        foreach ($counts as $key => $count) {
            $streams[] = [
                'weight_key' => $key,
                'game' => $count['game'],
                'winners' => $count['winners'] / $count['blocks'],
                'per_week' => $count['blocks'] / $weeks,
            ];
        }

        $passed = [];
        $running = 0;
        foreach ($chain->blocks() as $block) {
            $running += $block->reward;
            foreach ($this->milestones as $milestone) {
                $label = (string) $milestone;
                if (! isset($passed[$label]) && $running >= $milestone * $season->supply) {
                    $passed[$label] = $this->weeksSince($season, $block->candidate->attestedAt);
                }
            }
        }

        return $this->forecast($season, $now, $streams, $chain->mined(), $chain->minedByGameAndEra(), $passed);
    }

    /**
     * @param  list<array{weight_key: string, game: string, winners: float|int, per_week: float|int}>  $streams  forecast per game and mode
     * @param  array<string, array<int, int|float>>  $minedByGameAndEra  game => era => sats mined so far
     * @param  array<numeric-string, float|int>  $passedMilestones  milestone => real week, for milestones already reached
     * @return array{start_week: float|int, forecast_per_week: array<string, float|int>, milestone_weeks: array<numeric-string, float|int|null>, end_mined: int, end_mined_percent: float, per_era: list<array{era: int, budget: int, mined: array<string, int>}>, payout_per_week: list<int>, wins_per_era: list<array<string, int>>, warnings: list<string>}
     */
    public function forecast(SeasonParameters $season, CarbonImmutable $start, array $streams, int $mined, array $minedByGameAndEra, array $passedMilestones = []): array
    {
        $parameters = $season->inForceAt($start);
        $eras = $season->eras();
        $eraWeeks = $season->halvingSeconds / self::Week;
        $games = array_values(array_unique(array_merge(array_column($streams, 'game'), array_keys($parameters->shares))));
        sort($games);
        usort($streams, fn (array $a, array $b): int => $a['weight_key'] <=> $b['weight_key']);

        $blockReward = fn (array $stream, int $era): float|int => $season->rewardPerPlayer($parameters->weightFor($stream['weight_key']), $era) * $stream['winners'];
        $cap = fn (string $game, int $era): int => $season->shareCap($parameters->shareFor($game), $era);

        $minedTotal = $mined;
        $byGameAndEra = $minedByGameAndEra;
        $hits = [];
        $step = 1 / 7;
        $week = $this->weeksSince($season, $start);
        $startWeek = $week;

        while ($week < $eras * $eraWeeks - 1e-9) {
            $era = (int) floor($week / $eraWeeks) + 1;

            foreach ($games as $game) {
                $want = 0;
                foreach ($streams as $stream) {
                    if ($stream['game'] === $game) {
                        $want += $stream['per_week'] * $blockReward($stream, $era);
                    }
                }
                $want *= $step;
                $room = $cap($game, $era) - ($byGameAndEra[$game][$era] ?? 0);
                $got = max(0, min($want, $room));
                $byGameAndEra[$game][$era] = ($byGameAndEra[$game][$era] ?? 0) + $got;
                $minedTotal += $got;
            }

            $week += $step;

            foreach ($this->milestones as $milestone) {
                $label = (string) $milestone;
                if (! isset($hits[$label]) && $minedTotal >= $milestone * $season->supply) {
                    $hits[$label] = $week;
                }
            }
        }

        $milestoneWeeks = [];
        foreach ($this->milestones as $milestone) {
            $label = (string) $milestone;
            $milestoneWeeks[$label] = $passedMilestones[$label] ?? $hits[$label] ?? null;
        }

        $perEra = [];
        $payoutPerWeek = [];
        $winsPerEra = [];
        for ($era = 1; $era <= $eras; $era++) {
            $minedInEra = [];
            foreach ($games as $game) {
                $minedInEra[$game] = self::roundHalfEven($byGameAndEra[$game][$era] ?? 0);
            }
            $perEra[] = ['era' => $era, 'budget' => $season->eraBudget($era), 'mined' => $minedInEra];

            $payout = 0;
            $wins = [];
            foreach ($streams as $stream) {
                $reward = $blockReward($stream, $era);
                $payout += $stream['per_week'] * $reward;
                $wins[$stream['weight_key']] = $reward > 0 ? (int) floor($cap($stream['game'], $era) / $reward) : 0;
            }
            $payoutPerWeek[] = self::roundHalfEven($payout);
            $winsPerEra[] = $wins;
        }

        $seasonWeeks = ($season->endsAt->getTimestamp() - $season->genesisAt->getTimestamp()) / self::Week;
        $highest = $this->milestones === [] ? null : (string) max($this->milestones);
        $warnings = [];
        if ($seasonWeeks < $this->minWeeks) {
            $warnings[] = 'season-shorter-than-min-weeks';
        }
        if ($seasonWeeks > $this->maxWeeks) {
            $warnings[] = 'season-longer-than-max-weeks';
        }
        if ($highest !== null && isset($hits[$highest]) && $hits[$highest] < $this->minWeeks) {
            $warnings[] = 'supply-mined-before-min-weeks';
        }
        if ($minedTotal < 0.5 * $season->supply) {
            $warnings[] = 'most-of-the-supply-stays-unmined';
        }

        $endMined = self::roundHalfEven($minedTotal);

        return [
            'start_week' => $startWeek,
            'forecast_per_week' => array_column($streams, 'per_week', 'weight_key'),
            'milestone_weeks' => $milestoneWeeks,
            'end_mined' => $endMined,
            'end_mined_percent' => round($endMined / $season->supply * 100, 1),
            'per_era' => $perEra,
            'payout_per_week' => $payoutPerWeek,
            'wins_per_era' => $winsPerEra,
            'warnings' => $warnings,
        ];
    }

    private function weeksSince(SeasonParameters $season, CarbonImmutable $at): float|int
    {
        return ($at->getTimestamp() - $season->genesisAt->getTimestamp()) / self::Week;
    }

    private static function roundHalfEven(float|int $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_EVEN);
    }
}

<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The parameters of one chain season: the Season Genesis (`2156`, Block 0 at
 * T0) and the public log of Parameter Changes (`2158`). Eras, rewards and era
 * budgets follow from them in integers (docs/nips/esports.md, "Eras and
 * rewards"):
 *
 *   era n          = floor((t - T0) / halving) + 1
 *   reward/player  = floor(subsidy * w_milli / (1000 * 2^(n - 1)))
 *   budget B_n     = floor(supply / 2^n)
 *   share cap      = floor(B_n * share / 100)
 */
final class SeasonParameters
{
    /** @var list<ParameterChange> in the order they apply: effective, then creation */
    private array $changes = [];

    public function __construct(
        public readonly string $season,
        public readonly CarbonImmutable $genesisAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $supply,
        public readonly int $subsidy,
        public readonly int $halvingSeconds,
        public readonly ConsensusParameters $genesis,
        public readonly int $claimSeconds = 90 * 86400,
    ) {
        if ($supply < 1 || $subsidy < 1 || $halvingSeconds < 1 || $endsAt->lte($genesisAt)) {
            throw new InvalidArgumentException('A season needs a supply, a subsidy, a halving interval and an end after Block 0.');
        }
    }

    /** The Pre-Season defaults of config/season.php for a Block 0 at $genesisAt. */
    public static function fromConfig(string $season, CarbonImmutable $genesisAt): self
    {
        /** @var array{supply: int, subsidy: int, weights: array<string, int>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, halving_seconds: int, eras: int, claim_seconds: int} $chain */
        $chain = config('season.chain');

        return new self(
            $season,
            $genesisAt,
            $genesisAt->addSeconds($chain['eras'] * $chain['halving_seconds']),
            $chain['supply'],
            $chain['subsidy'],
            $chain['halving_seconds'],
            new ConsensusParameters(
                $chain['weights'],
                $chain['shares'],
                $chain['daily'],
                $chain['pairlimit'][0],
                $chain['pairlimit'][1],
                $chain['subtree'],
                $chain['moves'],
            ),
            $chain['claim_seconds'],
        );
    }

    /**
     * Log a change. Only BlockChain::changeParameters() may add one to a
     * running chain, because only it knows the latest attestation.
     */
    public function addChange(ParameterChange $change): void
    {
        if ($change->effectiveAt->lt($this->genesisAt) || $change->effectiveAt->gte($this->endsAt)) {
            throw new ChainViolation('A parameter change takes effect inside the season.');
        }

        $this->changes[] = $change;
        usort($this->changes, fn (ParameterChange $a, ParameterChange $b): int => [$a->effectiveAt->getTimestamp(), $a->createdAt->getTimestamp()]
            <=> [$b->effectiveAt->getTimestamp(), $b->createdAt->getTimestamp()]);
    }

    /** @return list<ParameterChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** The genesis, changed by every change whose `effective` is not later than $at. */
    public function inForceAt(CarbonImmutable $at): ConsensusParameters
    {
        $parameters = $this->genesis;

        foreach ($this->changes as $change) {
            if ($change->effectiveAt->lte($at)) {
                $parameters = $parameters->with($change);
            }
        }

        return $parameters;
    }

    /** T0 <= t < ends: the attestation belongs to the season. */
    public function contains(CarbonImmutable $at): bool
    {
        return $at->gte($this->genesisAt) && $at->lt($this->endsAt);
    }

    /** Era n, counted from 1, of an attestation inside the season. */
    public function eraAt(CarbonImmutable $at): int
    {
        return intdiv($at->getTimestamp() - $this->genesisAt->getTimestamp(), $this->halvingSeconds) + 1;
    }

    /** Number of eras until `ends` (the last one may be cut short). */
    public function eras(): int
    {
        return (int) ceil(($this->endsAt->getTimestamp() - $this->genesisAt->getTimestamp()) / $this->halvingSeconds);
    }

    public function eraStart(int $era): CarbonImmutable
    {
        return $this->genesisAt->addSeconds(($era - 1) * $this->halvingSeconds);
    }

    public function eraBudget(int $era): int
    {
        return $this->supply >> $era;
    }

    public function rewardPerPlayer(int $weightMilli, int $era): int
    {
        return intdiv($this->subsidy * $weightMilli, 1000 << ($era - 1));
    }

    public function shareCap(int $sharePercent, int $era): int
    {
        return intdiv($this->eraBudget($era) * $sharePercent, 100);
    }
}

<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The parameters of one chain season: the Season Genesis (`2156`, Block 0 at
 * T0) and the public log of Parameter Changes (`2158`). Pots, eras and
 * rewards follow from them in integers (`season-chain-v2`: the halving is by
 * the amount a share key mined, never by the calendar):
 *
 *   pot_k          = floor(supply * share_k / 100)        share of the genesis
 *   bound_k(n)     = pot_k - floor(pot_k / 2^n)           n = 1..eras
 *   era of a block = smallest n with m < bound_k(n)       m: sats of every
 *                    earlier block of key k; none up to `eras`: k is closed
 *   reward/player  = floor(subsidy * w_milli / (1000 * 2^(n - 1)))
 *
 * The shares are the genesis's and no change alters them. What the rounding
 * loses and the percent no key has belong to no pot: the rest of the season
 * is supply - mined.
 */
final class SeasonParameters
{
    /** The most eras a season can have: every shift below stays far inside an integer. */
    public const MAX_ERAS = 16;

    /** @var list<ParameterChange> in the order they apply: effective, then creation */
    private array $changes = [];

    public function __construct(
        public readonly string $season,
        public readonly CarbonImmutable $genesisAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $supply,
        public readonly int $subsidy,
        public readonly int $eras,
        public readonly ConsensusParameters $genesis,
        public readonly int $claimSeconds = 90 * 86400,
    ) {
        if ($supply < 1 || $subsidy < 1 || $eras < 1 || $eras > self::MAX_ERAS || $endsAt->lte($genesisAt)) {
            throw new InvalidArgumentException('A season needs a supply, a subsidy, 1 to '.self::MAX_ERAS.' eras and an end after Block 0.');
        }
    }

    /**
     * The parameters of a chain draft (ChainDraft) for a Block 0 at
     * $genesisAt, ending after the draft's length or at $endsAt.
     *
     * @param  array{supply: int, subsidy: int, weights: array<string, int>, groups: array<string, list<string>>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, weeks: int, eras: int, claim_days: int}  $chain
     */
    public static function fromDraft(array $chain, string $season, CarbonImmutable $genesisAt, ?CarbonImmutable $endsAt = null): self
    {
        return new self(
            $season,
            $genesisAt,
            $endsAt ?? $genesisAt->addSeconds($chain['weeks'] * 604800),
            $chain['supply'],
            $chain['subsidy'],
            $chain['eras'],
            new ConsensusParameters(
                $chain['weights'],
                $chain['shares'],
                $chain['daily'],
                $chain['pairlimit'][0],
                $chain['pairlimit'][1],
                $chain['subtree'],
                $chain['moves'],
                $chain['groups'],
            ),
            $chain['claim_days'] * 86400,
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

    /** The eras of every share key: after the last one the key is closed. */
    public function eras(): int
    {
        return $this->eras;
    }

    /**
     * The most a share key mines in the season: its genesis share of the
     * supply, rounded down. A key without a share has no pot and never mines.
     */
    public function pot(string $shareKey): int
    {
        return intdiv($this->supply * max(0, $this->genesis->shares[$shareKey] ?? 0), 100);
    }

    /** What a share key has mined when its era $era ends: pot - floor(pot / 2^era). */
    public function bound(string $shareKey, int $era): int
    {
        $pot = $this->pot($shareKey);

        return $era < 1 ? 0 : $pot - ($pot >> $era);
    }

    /** The sats of era $era of a share key: from the bound before it up to its own. */
    public function eraSize(string $shareKey, int $era): int
    {
        return $this->bound($shareKey, $era) - $this->bound($shareKey, $era - 1);
    }

    /**
     * The era of the next block of a share key that mined $mined sats so
     * far (voided blocks included): the first whose bound lies above them,
     * or null when the key is closed.
     */
    public function eraOf(string $shareKey, int $mined): ?int
    {
        for ($era = 1; $era <= $this->eras; $era++) {
            if ($mined < $this->bound($shareKey, $era)) {
                return $era;
            }
        }

        return null;
    }

    public function rewardPerPlayer(int $weightMilli, int $era): int
    {
        return intdiv($this->subsidy * $weightMilli, 1000 << ($era - 1));
    }
}

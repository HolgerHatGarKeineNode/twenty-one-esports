<?php

namespace App\Support\SeasonChain;

use Carbon\CarbonImmutable;

/**
 * A Parameter Change (`2158`): in force for every attestation from
 * `effective` on, never earlier than its own creation. Supply, subsidy, eras,
 * `ends` and `claim` cannot change during a season, so they are not here.
 */
final class ParameterChange
{
    /**
     * @param  array<string, int>  $weights  `<game>/<mode>` => thousandths; 0 stops a game from mining
     * @param  array<string, int>  $shares  game => percent
     * @param  array<string, int>  $daily  game => blocks per winning player and UTC day
     * @param  array{0: int, 1: int}|null  $pairLimit  [per UTC day, per season]
     */
    public function __construct(
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $effectiveAt,
        public readonly string $by,
        public readonly string $reason,
        public readonly array $weights = [],
        public readonly array $shares = [],
        public readonly array $daily = [],
        public readonly ?array $pairLimit = null,
        public readonly ?int $subtree = null,
        public readonly ?int $moves = null,
    ) {
        if ($effectiveAt->lt($createdAt)) {
            throw new ChainViolation('A parameter change is never retroactive: effective lies before its creation.');
        }
    }
}

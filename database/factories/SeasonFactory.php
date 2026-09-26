<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A released season with the Pre-Season defaults of config/season.php, live
 * from an hour ago. Only for tests: a real season is written by
 * App\Support\SeasonChain\SeasonChains::release() from a signed genesis.
 *
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{supply: int, subsidy: int, weights: array<string, int>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int, halving_seconds: int, eras: int, claim_seconds: int} $chain */
        $chain = config('season.chain');
        $genesisAt = now()->subHour()->startOfSecond();

        return [
            'slug' => 'pre-season',
            'league_pubkey' => bin2hex(random_bytes(32)),
            'supply' => $chain['supply'],
            'subsidy' => $chain['subsidy'],
            'halving_seconds' => $chain['halving_seconds'],
            'claim_seconds' => $chain['claim_seconds'],
            'minimum_trust' => (int) config('season.trust_minimum'),
            'parameters' => [
                'weights' => $chain['weights'],
                'shares' => $chain['shares'],
                'daily' => $chain['daily'],
                'pairlimit' => $chain['pairlimit'],
                'subtree' => $chain['subtree'],
                'moves' => $chain['moves'],
            ],
            'genesis_message' => 'Pre-Season: every fair win is a block',
            'digest' => hash('sha256', Str::random()),
            'genesis_at' => $genesisAt,
            'ends_at' => $genesisAt->copy()->addSeconds($chain['eras'] * $chain['halving_seconds']),
            'released_by_pubkey' => bin2hex(random_bytes(32)),
        ];
    }

    /** The season ended an hour ago: the rest between seasons. */
    public function ended(): static
    {
        return $this->state(fn (): array => [
            'genesis_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
        ]);
    }
}

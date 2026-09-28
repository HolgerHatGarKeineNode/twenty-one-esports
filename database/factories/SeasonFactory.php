<?php

namespace Database\Factories;

use App\Models\Season;
use App\Support\SeasonChain\ChainDraft;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A released season with the chain draft defaults of config/season.php, live
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
        $chain = ChainDraft::defaults();
        $genesisAt = now()->subHour()->startOfSecond();

        return [
            'slug' => 'pre-season',
            'league_pubkey' => bin2hex(random_bytes(32)),
            'supply' => $chain['supply'],
            'subsidy' => $chain['subsidy'],
            'halving_seconds' => $chain['halving_days'] * 86400,
            'claim_seconds' => $chain['claim_days'] * 86400,
            'minimum_trust' => $chain['trust_minimum'],
            'parameters' => [
                'weights' => $chain['weights'],
                'groups' => $chain['groups'],
                'shares' => $chain['shares'],
                'daily' => $chain['daily'],
                'pairlimit' => $chain['pairlimit'],
                'subtree' => $chain['subtree'],
                'moves' => $chain['moves'],
            ],
            'genesis_message' => 'Pre-Season: every fair win is a block',
            'digest' => hash('sha256', Str::random()),
            'genesis_at' => $genesisAt,
            'ends_at' => $genesisAt->copy()->addSeconds($chain['weeks'] * 604800),
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

<?php

namespace Database\Factories;

use App\Models\Season;
use App\Support\Hyper\HyperMining;
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
        $chain = self::chain();
        $genesisAt = now()->subHour()->startOfSecond();

        return [
            'slug' => 'pre-season',
            'league_pubkey' => bin2hex(random_bytes(32)),
            'supply' => $chain['supply'],
            'subsidy' => $chain['subsidy'],
            'eras' => $chain['eras'],
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

    /**
     * The chain a test season is released with: the draft's defaults, and
     * Rocket League and both EA Sports FC editions mining as well, at the
     * shares the board gave them on 2026-10-11 (32/36/22 and the board
     * games' 10). They left the defaults (user decision 2026-11-18), but
     * the suite proves the chain with their series, so its seasons keep
     * them; the defaults themselves are tested in ChainDefaultsTest.
     * Hyperbitcoinization, in the defaults since 2026-11-18, has no weight
     * here: a test season in which it mines says so with its parameters.
     *
     * @return array<string, mixed>
     */
    private static function chain(): array
    {
        $chain = ChainDraft::defaults();
        $chain['weights'] = array_filter($chain['weights'], fn (string $key): bool => ! str_starts_with($key, HyperMining::GAME.'/'), ARRAY_FILTER_USE_KEY);
        $series = [...config('season.chain.rocket_league_proposal.weights'), ...config('season.chain.ea_sports_fc_proposal.weights')];
        $first = array_slice($chain['weights'], 0, 2, true);
        $boards = isset($chain['shares']['board-games']);

        $chain['weights'] = [...$first, ...$series, ...array_diff_key($chain['weights'], $first)];
        $chain['shares'] = ['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, ...($boards ? ['board-games' => 10] : [])];
        $chain['daily'] = ['chess' => 5, 'rocket-league' => 5, 'ea-sports-fc' => 5, ...($boards ? ['board-games' => 5] : [])];

        return $chain;
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

<?php

namespace Tests\Support;

/**
 * The chain defaults as they were before 2026-11-18, for one test: Rocket
 * League and both EA Sports FC editions mine from Block 0 next to chess and
 * the board games, at the board's shares of 2026-10-11 (32/36/22/10). They
 * left the defaults (user decision 2026-11-18); the tests of the chain
 * draft's form, refusals and proposals still edit a draft in which a series
 * game and a share group mine.
 */
final class SeriesChain
{
    public static function asDefaults(): void
    {
        $chain = config('season.chain');
        $chess = array_slice($chain['weights'], 0, 2, true);

        config([
            'season.chain.weights' => [...$chess, ...$chain['rocket_league_proposal']['weights'], ...$chain['ea_sports_fc_proposal']['weights'], ...array_diff_key($chain['weights'], $chess)],
            'season.chain.shares' => ['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, 'board-games' => 10],
            'season.chain.daily' => ['chess' => 5, 'rocket-league' => 5, 'ea-sports-fc' => 5, 'board-games' => 5],
        ]);
    }
}

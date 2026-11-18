<?php

/*
 * Property test of the season chain (P7 DoD, `season-chain-v2`): over random
 * game sequences and random parameters, the supply is never exceeded, no
 * share key mines more than its pot, every block is in the era its key's
 * mined amount puts it in, and pays exactly
 * floor(subsidy * weight / (1000 * 2^(era - 1))) per winning player. The
 * parameters are random, not a validated draft: weights that pay nothing in
 * a late era and blocks larger than a small pot are in the mix. Seeded, so a
 * failure names the seed that reproduces it.
 */

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;

test('random game sequences never exceed the supply or a pot, and every block pays the reward of its key\'s era', function (int $seed) {
    mt_srand($seed);

    $t0 = CarbonImmutable::parse('2027-01-01T00:00:00Z');
    $length = mt_rand(1, 8) * mt_rand(1, 14) * 86400;
    $weights = ['chess/blitz' => mt_rand(0, 3000), 'chess/correspondence' => mt_rand(0, 5000), 'rocket-league/3v3' => mt_rand(0, 5000), 'rocket-league/1v1' => mt_rand(0, 2500)];
    // Together above 100 % in some seeds: then the supply is the bound that holds.
    $shares = ['chess' => mt_rand(1, 100), 'rocket-league' => mt_rand(1, 100)];
    $season = new SeasonParameters('pre-season', $t0, $t0->addSeconds($length), mt_rand(10_000, 3_000_000), mt_rand(100, 50_000), mt_rand(1, 8),
        new ConsensusParameters($weights, $shares, ['chess' => mt_rand(1, 10), 'rocket-league' => mt_rand(1, 10)], mt_rand(1, 5), mt_rand(1, 50), 101, mt_rand(1, 40)));
    $chain = new BlockChain($season, new ConsensusRules(50));
    $players = array_map(fn (int $n): string => 'p'.$n, range(1, 12));
    $at = $t0;

    foreach (range(1, 400) as $n) {
        $at = $at->addSeconds(mt_rand(0, intdiv($length, 300)));
        $key = array_rand($weights);
        $game = explode('/', $key)[0];
        shuffle($players);
        $size = $key === 'rocket-league/3v3' ? 3 : 1;
        $winners = array_slice($players, 0, $size);
        $losers = array_slice($players, $size, $size);

        $chain->attest(new Candidate('#'.$n, '#'.$n, $game, $key, $at, mt_rand(1, 10) === 1 ? Resolution::Forfeit : Resolution::Confirmed,
            $game === 'chess' ? mt_rand(5, 60) : null, $winners, $losers, null,
            [implode('+', $winners), implode('+', $losers)], [$winners[0], $losers[0]], true,
            array_fill_keys([...$winners, ...$losers], 100), [], []));
    }

    $sum = 0;
    $perGame = [];

    foreach ($chain->blocks() as $block) {
        $game = $block->candidate->game;
        $weight = $season->inForceAt($block->candidate->attestedAt)->weightFor($block->candidate->weightKey);

        expect($block->era)->toBe($season->eraOf($game, $perGame[$game] ?? 0), "seed {$seed}: block {$block->height}")
            ->and($block->rewardPerPlayer)->toBe(intdiv($season->subsidy * $weight, 1000 << ($block->era - 1)))
            ->and($block->reward)->toBe($block->rewardPerPlayer * count($block->candidate->winners));

        $sum += $block->reward;
        $perGame[$game] = ($perGame[$game] ?? 0) + $block->reward;
    }

    expect($chain->mined())->toBe($sum)
        ->and($chain->mined())->toBeLessThanOrEqual($season->supply)
        ->and($chain->remaining())->toBeGreaterThanOrEqual(0);

    foreach ($perGame as $game => $mined) {
        expect($mined)->toBeLessThanOrEqual($season->pot($game), "seed {$seed}: {$game}");
    }
})->with(array_map(fn (int $seed): array => [$seed], range(1, 40)));

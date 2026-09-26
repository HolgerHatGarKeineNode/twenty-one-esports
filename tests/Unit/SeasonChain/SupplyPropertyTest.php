<?php

/*
 * Property test of the season chain (P7 DoD): over random game sequences and
 * random parameters, the supply is never exceeded, no game mines more than
 * its share cap in an era, and every block pays exactly
 * floor(subsidy * weight / 2^(era - 1)) per winning player. Seeded, so a
 * failure names the seed that reproduces it.
 */

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;

test('random game sequences never exceed the supply or a share cap, and every block pays the era reward', function (int $seed) {
    mt_srand($seed);

    $t0 = CarbonImmutable::parse('2027-01-01T00:00:00Z');
    $halving = mt_rand(1, 14) * 86400;
    $eras = mt_rand(1, 8);
    $weights = ['chess/blitz' => mt_rand(0, 3000), 'chess/correspondence' => mt_rand(0, 5000), 'rocket-league/3v3' => mt_rand(0, 5000), 'rocket-league/1v1' => mt_rand(0, 2500)];
    $shares = ['chess' => mt_rand(1, 100), 'rocket-league' => mt_rand(1, 100)];
    $season = new SeasonParameters('pre-season', $t0, $t0->addSeconds($eras * $halving), mt_rand(10_000, 3_000_000), mt_rand(100, 50_000), $halving,
        new ConsensusParameters($weights, $shares, ['chess' => mt_rand(1, 10), 'rocket-league' => mt_rand(1, 10)], mt_rand(1, 5), mt_rand(1, 50), 101, mt_rand(1, 40)));
    $chain = new BlockChain($season, new ConsensusRules(50));
    $players = array_map(fn (int $n): string => 'p'.$n, range(1, 12));
    $at = $t0;

    foreach (range(1, 400) as $n) {
        $at = $at->addSeconds(mt_rand(0, intdiv($eras * $halving, 300)));
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
    $perGameAndEra = [];

    foreach ($chain->blocks() as $block) {
        $weight = $season->inForceAt($block->candidate->attestedAt)->weightFor($block->candidate->weightKey);
        expect($block->rewardPerPlayer)->toBe(intdiv($season->subsidy * $weight, 1000 << ($block->era - 1)))
            ->and($block->reward)->toBe($block->rewardPerPlayer * count($block->candidate->winners));
        $sum += $block->reward;
        $perGameAndEra[$block->candidate->game][$block->era] = ($perGameAndEra[$block->candidate->game][$block->era] ?? 0) + $block->reward;
    }

    expect($chain->mined())->toBe($sum)
        ->and($chain->mined())->toBeLessThanOrEqual($season->supply)
        ->and($chain->remaining())->toBeGreaterThanOrEqual(0);

    foreach ($perGameAndEra as $game => $eraSums) {
        foreach ($eraSums as $era => $mined) {
            expect($mined)->toBeLessThanOrEqual($season->shareCap($shares[$game], $era), "seed {$seed}: {$game} era {$era}");
        }
    }
})->with(array_map(fn (int $seed): array => [$seed], range(1, 40)));

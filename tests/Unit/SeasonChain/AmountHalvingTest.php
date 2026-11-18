<?php

/*
 * `season-chain-v2`: a share key halves by the amount it mined, never by the
 * calendar. Its pot is floor(supply * share / 100), era n ends once the key
 * mined pot - floor(pot / 2^n), and after the last era the key is closed.
 * The era of a block is read from what the key mined before it; the block
 * pays that era's whole reward and may cross the bound.
 *
 * The numbers of the first tests are the defaults of config/season.php
 * (ChainDefaultsTest holds the config to them): supply 2 100 000, subsidy
 * 2 100, chess 50 %, board games 30 %, Hyperbitcoinization 20 %, 6 eras.
 */

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use App\Support\SeasonChain\Verdict;
use Carbon\CarbonImmutable;

const AH_T0 = '2026-11-20T17:00:00Z';

/** A chain with the default numbers; no pairing, daily or subtree limit is in the way of the amounts. */
function ahChain(int $supply = 2_100_000, int $subsidy = 2_100, array $shares = ['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20], int $eras = 6, int $weeks = 24): BlockChain
{
    $t0 = CarbonImmutable::parse(AH_T0);
    $weights = ['chess/blitz' => 1000, 'chess/rapid' => 1500, 'chess/correspondence' => 2000, 'blockli/blitz' => 1000, 'hyperbitcoinization/live' => 1000];

    return new BlockChain(
        new SeasonParameters('season-1', $t0, $t0->addWeeks($weeks), $supply, $subsidy, $eras,
            new ConsensusParameters($weights, $shares, [], 1000, 100_000, 101, 20, ['board-games' => ['blockli', 'nine-mens-morris', 'checkers']])),
        new ConsensusRules(50),
    );
}

/** The next win of `<game>/<mode>` between two players nobody else is, $at after Block 0 (default: one hour). */
function ahWin(BlockChain $chain, string $key = 'chess/blitz', ?CarbonImmutable $at = null): Verdict
{
    $n = count($chain->attestations()) + 1;

    return $chain->attest(new Candidate('#'.$n, '#'.$n, explode('/', $key)[0], $key, $at ?? CarbonImmutable::parse(AH_T0)->addHour(), Resolution::Confirmed, 34,
        ['w'.$n], ['l'.$n], null, ['w'.$n, 'l'.$n], ['w'.$n, 'l'.$n], true, ['w'.$n => 100, 'l'.$n => 100], [], []));
}

test('with the defaults a chess blitz win pays 2 100 until 525 000 sats of chess are mined, then 1 050, 525, 262, 131 and 65, and then chess is closed', function () {
    $chain = ahChain();
    $firstOfEra = [];
    $rewards = [];
    $mined = 0;

    while (($verdict = ahWin($chain))->mines()) {
        $firstOfEra[$verdict->era] ??= $mined;
        $rewards[$verdict->era][$verdict->rewardPerPlayer] = true;
        $mined += $verdict->reward;
    }

    expect(array_map(array_keys(...), $rewards))->toBe([1 => [2100], 2 => [1050], 3 => [525], 4 => [262], 5 => [131], 6 => [65]])
        // What chess had mined when its first block of each era came: the bound, or the block that crossed it.
        ->and($firstOfEra)->toBe([1 => 0, 2 => 525_000, 3 => 787_500, 4 => 918_750, 5 => 984_512, 6 => 1_017_262])
        ->and([count($chain->blocks()), $chain->mined()])->toBe([1503, 1_033_642])
        ->and([$verdict->rule?->value, $verdict->reason, $verdict->subject, $verdict->reward])->toBe([9, 'key-closed', 'chess', 0])
        ->and($chain->mined())->toBeLessThanOrEqual($chain->season->pot('chess'));
});

test('a year without games changes no reward: the first win still pays 2 100, at the last second of the season too', function () {
    $long = ahChain(weeks: 104);
    $default = ahChain();
    $t0 = CarbonImmutable::parse(AH_T0);

    $afterAYear = ahWin($long, at: $t0->addDays(365));
    $lastSecond = ahWin($default, at: $t0->addWeeks(24)->subSecond());

    expect([$afterAYear->mines(), $afterAYear->era, $afterAYear->rewardPerPlayer])->toBe([true, 1, 2100])
        ->and([$lastSecond->mines(), $lastSecond->era, $lastSecond->rewardPerPlayer])->toBe([true, 1, 2100]);
});

test('the keys halve one by one: chess past its first era leaves the board games and Hyperbitcoinization in era 1', function () {
    $chain = ahChain();

    foreach (range(1, 250) as $n) {
        ahWin($chain);
    }

    $chess = ahWin($chain);
    $board = ahWin($chain, 'blockli/blitz');
    $hyper = ahWin($chain, 'hyperbitcoinization/live');

    expect([$chess->era, $chess->rewardPerPlayer])->toBe([2, 1050])
        ->and([$board->era, $board->rewardPerPlayer])->toBe([1, 2100])
        ->and([$hyper->era, $hyper->rewardPerPlayer])->toBe([1, 2100])
        ->and($chain->minedByGameAndEra())->toBe(['chess' => [1 => 525_000, 2 => 1050], 'board-games' => [1 => 2100], 'hyperbitcoinization' => [1 => 2100]]);
});

test('the block that crosses a bound pays its own era in full, and the next block is in the next era', function () {
    $chain = ahChain();

    foreach (range(1, 249) as $n) {
        ahWin($chain);
    }

    // 522 900 mined, 2 100 below the bound of era 1: a correspondence win (weight 2) crosses it.
    $crossing = ahWin($chain, 'chess/correspondence');
    $next = ahWin($chain);

    expect([$crossing->mines(), $crossing->era, $crossing->reward])->toBe([true, 1, 4200])
        ->and([$next->era, $next->reward])->toBe([2, 1050])
        ->and($chain->minedByGameAndEra()['chess'])->toBe([1 => 527_100, 2 => 1050]);
});

test('the last block of the last era may cross its bound, stays inside the pot, and closes the key for every mode', function () {
    // Chess 100 % of 64 000: the bound of era 6 is 63 000; rapid pays floor(2 100 * 1.5 / 32) = 98 there.
    $chain = ahChain(supply: 64_000, shares: ['chess' => 100, 'board-games' => 0]);
    $season = $chain->season;
    $before = 0;

    while (($verdict = ahWin($chain, 'chess/rapid'))->mines()) {
        $before = $chain->mined() - $verdict->reward;
        $last = $verdict;
    }

    expect([$season->pot('chess'), $season->bound('chess', 6)])->toBe([64_000, 63_000])
        ->and([$last->era, $last->rewardPerPlayer])->toBe([6, 98])
        ->and($before)->toBeLessThan(63_000)
        ->and($chain->mined())->toBeGreaterThanOrEqual(63_000)->toBeLessThanOrEqual(64_000)
        ->and($verdict->reason)->toBe('key-closed')
        ->and(ahWin($chain, 'chess/blitz')->reason)->toBe('key-closed')
        // A key without a pot is closed from the start.
        ->and(ahWin($chain, 'blockli/blitz')->reason)->toBe('key-closed');
});

test('an odd pot rounds every bound down to the key: 501 sats in three eras end at 251, 376 and 439', function () {
    $chain = ahChain(supply: 1003, subsidy: 100, shares: ['chess' => 50], eras: 3);
    $season = $chain->season;
    $paid = [];

    while (($verdict = ahWin($chain))->mines()) {
        $paid[] = [$verdict->era, $verdict->reward];
    }

    expect($season->pot('chess'))->toBe(501)
        ->and(array_map(fn (int $era): int => $season->bound('chess', $era), [1, 2, 3]))->toBe([251, 376, 439])
        ->and(array_map(fn (int $era): int => $season->eraSize('chess', $era), [1, 2, 3]))->toBe([251, 125, 63])
        ->and($paid)->toBe([[1, 100], [1, 100], [1, 100], [2, 50], [2, 50], [3, 25], [3, 25]])
        ->and($chain->mined())->toBe(450)
        ->and($verdict->reason)->toBe('key-closed');
});

test('no block takes a key above its pot or the chain above the supply, even with values no draft would sign', function () {
    // One era of a pot of 1 000 (half the supply) ends at 500; blitz pays 400, correspondence 800.
    $pot = ahChain(supply: 2000, subsidy: 400, shares: ['chess' => 50], eras: 1);
    $verdicts = [ahWin($pot), ahWin($pot, 'chess/correspondence'), ahWin($pot), ahWin($pot)];

    expect(array_map(fn (Verdict $verdict): array => [$verdict->reason, $verdict->reward], $verdicts))
        ->toBe([[null, 400], ['key-closed', 800], [null, 400], ['key-closed', 0]])
        ->and($verdicts[1]->rule?->value)->toBe(9)
        ->and($pot->mined())->toBe(800);

    // Shares of 200 % together: each key has room, the supply has not.
    $supply = ahChain(supply: 1000, subsidy: 400, shares: ['chess' => 100, 'board-games' => 100], eras: 1);
    $verdicts = [ahWin($supply), ahWin($supply, 'blockli/blitz'), ahWin($supply)];

    expect(array_map(fn (Verdict $verdict): ?string => $verdict->reason, $verdicts))->toBe([null, null, 'supply-exhausted'])
        ->and($verdicts[2]->rule?->value)->toBe(0)
        ->and($supply->mined())->toBe(800);
});

test('a voided block keeps its sats in the key: the era does not go back', function () {
    $chain = ahChain(supply: 1003, subsidy: 100, shares: ['chess' => 50], eras: 3);

    foreach (range(1, 3) as $n) {
        ahWin($chain);
    }

    $chain->void(3, 'reciprocity');

    expect(ahWin($chain)->era)->toBe(2);
});

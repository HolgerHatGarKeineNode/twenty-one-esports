<?php

/*
 * Solo blocks (plan "AoE2 und Trackmania", P7; NIP rev. 9.18): the winner of a
 * score window the league opened itself mines one block when the solo rules
 * hold. Each solo rule, violated alone, leaves the window without a block;
 * the versus rules stay as they were (SeasonChainTest).
 */

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;

const SOLO_T0 = '2026-07-06T00:00:00Z';

/** @param  array<string, int>  $daily */
function soloSeason(array $daily = ['blockfill' => 5], int $entrants = 5, int $wins = 3, int $review = 48 * 3600, int $subtree = 90): SeasonParameters
{
    $t0 = CarbonImmutable::parse(SOLO_T0);

    return new SeasonParameters('pre-season', $t0, $t0->addWeeks(24), 2_100_000, 2_100, 28 * 86400,
        new ConsensusParameters(['chess/blitz' => 1000, 'blockfill/40-blocks' => 1000], ['chess' => 40, 'blockfill' => 10], $daily, 1, 3, $subtree, 20,
            soloEntrants: $entrants, soloWins: $wins, soloReview: $review));
}

function soloChain(?SeasonParameters $season = null): BlockChain
{
    return new BlockChain($season ?? soloSeason(), new ConsensusRules(50));
}

/**
 * Alice wins week $week (0 = the first full week after Block 0) against four
 * other trusted entrants of four other clans and no shared anchor, attested
 * an hour after the review of 48 hours; $overrides replace constructor
 * arguments by name, $solo the keys of the solo facts.
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<string, mixed>  $solo
 */
function soloWin(int $week = 0, array $overrides = [], array $solo = []): Candidate
{
    $start = CarbonImmutable::parse(SOLO_T0)->addWeeks($week);
    $end = $start->addWeek();
    $winner = $overrides['winners'][0] ?? 'alice';

    $arguments = array_replace([
        'label' => 'Blockfill Week '.($week + 28), 'match' => 'score:'.($week + 1), 'game' => 'blockfill', 'weightKey' => 'blockfill/40-blocks',
        'attestedAt' => $end->addHours(49), 'resolution' => Resolution::Admin, 'moves' => null,
        'winners' => [$winner], 'losers' => [], 'winningSide' => 'LSR', 'pairing' => [$winner, $winner], 'gatekeepers' => [$winner, $winner], 'gatekeepersConnected' => true,
        'trust' => ['alice' => 100, 'bob' => 80, 'carol' => 90, 'dave' => 70, 'erin' => 60, 'frank' => 75],
        'clans' => ['alice' => 'LSR', 'bob' => 'MMP', 'carol' => 'HDL', 'dave' => 'OPS', 'erin' => null, 'frank' => 'XYZ'],
        'anchors' => ['alice' => ['alice', 100], 'bob' => ['board', 100], 'carol' => ['board', 60], 'dave' => ['carol', 55], 'erin' => null, 'frank' => ['frank', 100]],
    ], $overrides);

    $arguments['solo'] = array_replace([
        'start' => $start->toIso8601ZuluString(),
        'end' => $end->toIso8601ZuluString(),
        'achieved_at' => $start->addDays(3)->toIso8601ZuluString(),
        'source' => 'replay',
        'verified' => true,
        'entrants' => array_values(array_diff(['alice', 'bob', 'carol', 'dave', 'erin'], [$winner])),
    ], $solo);

    return new Candidate(...$arguments);
}

test('a score window with every solo rule met mines exactly one block for its winner, with the reward of one winning player', function () {
    $chain = soloChain();

    $verdict = $chain->attest(soloWin());

    expect([$verdict->mines(), $verdict->era, $verdict->rewardPerPlayer, $verdict->reward])->toBe([true, 1, 2_100, 2_100])
        ->and($chain->blocks())->toHaveCount(1)
        ->and($chain->blocks()[0]->candidate->winners)->toBe(['alice'])
        ->and($chain->blocks()[0]->candidate->losers)->toBe([])
        ->and($chain->blocks()[0]->candidate->isSolo())->toBeTrue();
});

test('the solo facts survive the stored form, and a versus candidate stores no solo key (the ledger fixture stays as it is)', function () {
    $solo = soloWin();
    $stored = $solo->toArray();
    $versus = soloWin()->toArray();
    unset($versus['solo']);

    expect(Candidate::fromArray(json_decode(json_encode($stored), true))->solo)->toBe($solo->solo)
        ->and(Candidate::fromArray(json_decode(json_encode($stored), true))->pairingKey())->toBe($solo->pairingKey())
        ->and(Candidate::fromArray($versus)->toArray())->not->toHaveKey('solo')
        ->and(Candidate::fromArray($versus)->isSolo())->toBeFalse();
});

test('each solo rule, violated alone, leaves the window without a block; the reason is that rule', function (Closure $setup, int $rule, string $reason) {
    [$chain, $candidate] = $setup();

    $verdict = $chain->attest($candidate);

    expect([$verdict->mines(), $verdict->rule?->value, $verdict->reason])->toBe([false, $rule, $reason])
        ->and($chain->blocks())->toHaveCount(count($chain->attestations()) - 1);
})->with([
    'rule 0: the window began before Block 0' => [fn () => [soloChain(), soloWin(-1, ['attestedAt' => CarbonImmutable::parse(SOLO_T0)->addHours(49)])], 0, 'window-outside-season'],
    'rule 1: the winner is below the trust minimum' => [fn () => [soloChain(), soloWin(0, ['trust' => ['alice' => 49, 'bob' => 80, 'carol' => 90, 'dave' => 70, 'erin' => 60]])], 1, 'not-trusted'],
    'rule 1: the winner has no pinned trust rank (fail closed)' => [fn () => [soloChain(), soloWin(0, ['trust' => ['bob' => 80, 'carol' => 90, 'dave' => 70, 'erin' => 60]])], 1, 'not-trusted'],
    'rule 1: fewer trusted entrants than the minimum per window' => [fn () => [soloChain(), soloWin(0, [], ['entrants' => ['bob', 'carol', 'dave']])], 1, 'too-few-entrants'],
    'rule 1: an entrant below the trust minimum does not count' => [fn () => [soloChain(), soloWin(0, ['trust' => ['alice' => 100, 'bob' => 80, 'carol' => 90, 'dave' => 70, 'erin' => 10]])], 1, 'too-few-entrants'],
    'rule 2: the winning value is not verified' => [fn () => [soloChain(), soloWin(0, [], ['verified' => false])], 2, 'unverified'],
    'rule 2: the winning value is a director\'s entry' => [fn () => [soloChain(), soloWin(0, [], ['source' => 'director'])], 2, 'unverified'],
    'rule 2: the winning value was set after the window' => [fn () => [soloChain(), soloWin(0, [], ['achieved_at' => CarbonImmutable::parse(SOLO_T0)->addWeek()->toIso8601ZuluString()])], 2, 'outside-window'],
    'rule 2: attested before the review time was over' => [fn () => [soloChain(), soloWin(0, ['attestedAt' => CarbonImmutable::parse(SOLO_T0)->addWeek()->addHours(47)])], 2, 'not-reviewed'],
    'rule 3: the field is the winner\'s own clan' => [fn () => [soloChain(), soloWin(0, ['clans' => ['alice' => 'LSR', 'bob' => 'LSR', 'carol' => 'HDL', 'dave' => 'OPS', 'erin' => null]])], 3, 'same-clan'],
    'rule 4: the window already mined a block' => [function () {
        $chain = soloChain();
        $chain->attest(soloWin());

        return [$chain, soloWin(0, ['winners' => ['frank'], 'winningSide' => 'XYZ', 'attestedAt' => CarbonImmutable::parse(SOLO_T0)->addWeek()->addHours(50)], ['entrants' => ['bob', 'carol', 'dave', 'erin']])];
    }, 4, 'window-block'],
    'rule 5: the winner reached the daily limit of the game' => [function () {
        $chain = soloChain(soloSeason(daily: ['blockfill' => 1]));
        $chain->attest(soloWin(0, ['game' => 'blockfill', 'match' => 'score:other'], ['start' => CarbonImmutable::parse(SOLO_T0)->addDays(1)->toIso8601ZuluString()]));

        return [$chain, soloWin(0, ['attestedAt' => CarbonImmutable::parse(SOLO_T0)->addWeek()->addHours(50)])];
    }, 5, 'player-daily-limit'],
    'rule 7: the field is the winner\'s own trust circle' => [fn () => [soloChain(), soloWin(0, ['anchors' => ['alice' => ['board', 95], 'bob' => ['board', 100], 'carol' => ['board', 90], 'dave' => ['carol', 55], 'erin' => null]])], 7, 'same-subtree'],
    'rule 8: the winner reached the window wins of the season' => [function () {
        $chain = soloChain(soloSeason(wins: 2));
        $chain->attest(soloWin(0));
        $chain->attest(soloWin(1));

        return [$chain, soloWin(2)];
    }, 8, 'window-wins-limit'],
]);

test('rules 3 and 7 count the field together: clan mates and circle mates both leave it', function () {
    // One clan mate and one circle mate: four left after rule 3, three after rule 7.
    $candidate = soloWin(0, [
        'clans' => ['alice' => 'LSR', 'bob' => 'LSR', 'carol' => 'HDL', 'dave' => 'OPS', 'erin' => null],
        'anchors' => ['alice' => ['board', 95], 'bob' => ['x', 100], 'carol' => ['board', 90], 'dave' => null, 'erin' => null],
    ]);

    expect(soloChain(soloSeason(entrants: 4))->attest($candidate)->rule?->value)->toBe(7)
        ->and(soloChain(soloSeason(entrants: 3))->attest($candidate)->mines())->toBeTrue()
        ->and(soloChain(soloSeason(entrants: 4, subtree: 101))->attest($candidate)->mines())->toBeTrue();
});

test('the window wins limit is per player: another winner mines the next window', function () {
    $chain = soloChain(soloSeason(wins: 1));
    $chain->attest(soloWin(0));

    $bob = $chain->attest(soloWin(1, ['winners' => ['bob'], 'winningSide' => 'MMP']));
    $alice = $chain->attest(soloWin(2));

    expect($bob->mines())->toBeTrue()
        ->and([$alice->rule?->value, $alice->reason])->toBe([8, 'window-wins-limit']);
});

test('the solo parameters default to the NIP values: 5 entrants, 3 window wins, 48 hours of review', function () {
    $parameters = new ConsensusParameters([]);

    expect([$parameters->soloEntrants, $parameters->soloWins, $parameters->soloReview])->toBe([5, 3, 172_800]);
});

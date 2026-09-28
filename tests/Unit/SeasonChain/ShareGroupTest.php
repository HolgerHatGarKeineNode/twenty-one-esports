<?php

/*
 * Share groups (P43, NIP rev. 9.4): both EA Sports FC editions mine, but
 * count as one for the daily limit (rule 5) and the share cap (rule 9), so a
 * player who owns both editions does not mine twice as much.
 */

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\ParameterChange;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;

const SG_T0 = '2026-10-02T17:00:00Z';

/** @param  array<string, list<string>>  $groups */
function sgChain(array $groups, array $shares = ['ea-sports-fc' => 25], array $daily = ['ea-sports-fc' => 2], int $supply = 2_100_000): BlockChain
{
    $t0 = CarbonImmutable::parse(SG_T0);
    $weights = ['ea-sports-fc-26/1v1' => 1000, 'ea-sports-fc-27/1v1' => 1000];

    return new BlockChain(
        new SeasonParameters('pre-season', $t0, $t0->addWeeks(24), $supply, 2_100, 28 * 86400, new ConsensusParameters($weights, $shares, $daily, 10, 100, 101, 20, $groups)),
        new ConsensusRules(50),
    );
}

/** A 1v1 win of alice over $loser in an FC edition, $minutes after Block 0. */
function sgWin(string $game, string $loser, int $minutes): Candidate
{
    return new Candidate('#'.$minutes, '#'.$minutes, $game, $game.'/1v1', CarbonImmutable::parse(SG_T0)->addMinutes($minutes), Resolution::Confirmed, null,
        ['alice'], [$loser], null, ['alice', $loser], ['alice', $loser], true, ['alice' => 100, $loser => 100], [], []);
}

test('a player who owns both FC editions reaches the one FC daily limit once, not once per edition', function () {
    $chain = sgChain(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']]);

    $verdicts = [
        $chain->attest(sgWin('ea-sports-fc-26', 'bob', 10)),
        $chain->attest(sgWin('ea-sports-fc-27', 'carol', 20)),
        $chain->attest(sgWin('ea-sports-fc-27', 'dave', 30)),
        $chain->attest(sgWin('ea-sports-fc-26', 'erin', 40)),
    ];

    expect(array_map(fn ($verdict) => $verdict->mines(), $verdicts))->toBe([true, true, false, false])
        ->and(array_map(fn ($verdict) => $verdict->reason, $verdicts))->toBe([null, null, 'player-daily-limit', 'player-daily-limit'])
        ->and($chain->minedByGameAndEra())->toBe(['ea-sports-fc' => [1 => 4_200]]);
});

test('without the group each edition has its own daily limit: the same four wins mine four blocks (control)', function () {
    $chain = sgChain([], ['ea-sports-fc-26' => 25, 'ea-sports-fc-27' => 25], ['ea-sports-fc-26' => 2, 'ea-sports-fc-27' => 2]);

    foreach ([['ea-sports-fc-26', 'bob', 10], ['ea-sports-fc-27', 'carol', 20], ['ea-sports-fc-27', 'dave', 30], ['ea-sports-fc-26', 'erin', 40]] as [$game, $loser, $minutes]) {
        $chain->attest(sgWin($game, $loser, $minutes));
    }

    expect(count($chain->blocks()))->toBe(4);
});

test('both editions fill one FC share cap per era', function () {
    // Era 1 budget 8 400, FC 25 %: 2 100, one block of 2 100 in total, whichever edition mines it.
    $chain = sgChain(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']], daily: ['ea-sports-fc' => 100], supply: 16_800);

    $first = $chain->attest(sgWin('ea-sports-fc-26', 'bob', 10));
    $second = $chain->attest(sgWin('ea-sports-fc-27', 'carol', 20));

    expect([$first->mines(), $second->mines()])->toBe([true, false])
        ->and($second->reason)->toBe('share-cap')
        ->and($second->subject)->toBe('ea-sports-fc');
});

test('the share key is the group of a game, else the game; a change keeps the groups', function () {
    $parameters = new ConsensusParameters(['chess/blitz' => 1000], ['chess' => 35, 'ea-sports-fc' => 25], ['ea-sports-fc' => 5], groups: ['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']]);

    expect($parameters->shareKey('ea-sports-fc-27'))->toBe('ea-sports-fc')
        ->and($parameters->shareKey('chess'))->toBe('chess')
        ->and($parameters->shareFor('ea-sports-fc-26'))->toBe(25)
        ->and($parameters->dailyLimitFor('ea-sports-fc-26'))->toBe(5)
        ->and($parameters->dailyLimitFor('chess'))->toBeNull()
        ->and($parameters->with(new ParameterChange(CarbonImmutable::now(), CarbonImmutable::now(), 'admin', 'x', daily: ['chess' => 3]))->groups)
        ->toBe(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']]);
});

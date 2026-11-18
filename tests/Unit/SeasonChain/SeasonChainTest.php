<?php

use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ChainViolation;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Estimator;
use App\Support\SeasonChain\ParameterChange;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;

const SC_T0 = '2026-07-03T17:00:00Z';

/**
 * @param  array<string, int>  $shares
 * @param  array<string, int>  $daily
 * @param  array{0: int, 1: int}  $pairLimit
 */
function scSeason(int $supply = 2_100_000, array $shares = ['chess' => 40, 'rocket-league' => 60], array $daily = ['chess' => 5], array $pairLimit = [1, 3], int $subtree = 90, int $weeks = 24, int $eras = 6): SeasonParameters
{
    $t0 = CarbonImmutable::parse(SC_T0);

    return new SeasonParameters('pre-season', $t0, $t0->addWeeks($weeks), $supply, 20_000, $eras,
        new ConsensusParameters(['chess/blitz' => 1000, 'rocket-league/3v3' => 1000], $shares, $daily, $pairLimit[0], $pairLimit[1], $subtree, 20));
}

/** A valid blitz win of alice over bob, attested $hours after Block 0; $overrides replace constructor arguments by name. */
function scWin(float $hours = 2, array $overrides = []): Candidate
{
    $arguments = array_replace([
        'label' => '#1', 'match' => '#1', 'game' => 'chess', 'weightKey' => 'chess/blitz',
        'attestedAt' => CarbonImmutable::parse(SC_T0)->addMinutes((int) round($hours * 60)),
        'resolution' => Resolution::Confirmed, 'moves' => 34, 'winners' => ['alice'], 'losers' => ['bob'], 'winningSide' => 'LSR',
        'pairing' => ['alice', 'bob'], 'gatekeepers' => ['alice', 'bob'], 'gatekeepersConnected' => true,
        'trust' => ['alice' => 100, 'bob' => 80, 'carol' => 90, 'dave' => 70], 'clans' => ['alice' => 'LSR', 'bob' => 'MMP', 'carol' => 'HDL', 'dave' => 'OPS'],
        'anchors' => ['alice' => ['alice', 100], 'bob' => ['board', 100], 'carol' => ['board', 60], 'dave' => ['board', 55]],
    ], $overrides);

    return new Candidate(...$arguments);
}

function scChain(?SeasonParameters $season = null): BlockChain
{
    return new BlockChain($season ?? scSeason(), new ConsensusRules(50));
}

test('a valid win mines block 1 with the era-1 reward; a team win pays every winning player', function () {
    $chain = scChain();

    $solo = $chain->attest(scWin());
    $team = $chain->attest(scWin(3, ['game' => 'rocket-league', 'weightKey' => 'rocket-league/3v3', 'moves' => null,
        'winners' => ['alice', 'carol', 'dave'], 'losers' => ['bob'], 'pairing' => ['LSR/3v3', 'MMP/3v3'], 'clans' => ['alice' => 'LSR', 'carol' => 'LSR', 'dave' => 'LSR', 'bob' => 'MMP']]));

    expect([$solo->mines(), $solo->era, $solo->reward])->toBe([true, 1, 20_000])
        ->and([$team->mines(), $team->rewardPerPlayer, $team->reward])->toBe([true, 20_000, 60_000])
        ->and(array_map(fn ($block) => $block->height, $chain->blocks()))->toBe([1, 2])
        ->and($chain->mined())->toBe(80_000);
});

test('each consensus rule rejects an invalid block, and the first failing rule is the reason', function (Closure $setup, int $rule, string $reason) {
    [$chain, $candidate] = $setup();

    $verdict = $chain->attest($candidate);

    expect([$verdict->mines(), $verdict->rule?->value, $verdict->reason])->toBe([false, $rule, $reason]);
})->with([
    'rule 0: before Block 0' => [fn () => [scChain(), scWin(-1)], 0, 'outside-season'],
    'rule 0: game without a weight' => [fn () => [scChain(), scWin(2, ['weightKey' => 'chess/correspondence'])], 0, 'game-does-not-mine'],
    'rule 0 comes before rule 1' => [fn () => [scChain(), scWin(-1, ['trust' => ['alice' => 10, 'bob' => 80]])], 0, 'outside-season'],
    'rule 1: a player not Trusted' => [fn () => [scChain(), scWin(2, ['trust' => ['alice' => 100, 'bob' => 49]])], 1, 'not-trusted'],
    'rule 1: a missing trust rank fails closed' => [fn () => [scChain(), scWin(2, ['trust' => ['alice' => 100]])], 1, 'not-trusted'],
    'rule 1: not connected' => [fn () => [scChain(), scWin(2, ['gatekeepersConnected' => false])], 1, 'not-connected'],
    'rule 2: forfeit' => [fn () => [scChain(), scWin(2, ['resolution' => Resolution::Forfeit])], 2, 'forfeit'],
    'rule 2: 19 moves' => [fn () => [scChain(), scWin(2, ['moves' => 19])], 2, 'too-few-moves'],
    'rule 2: chess without a move count' => [fn () => [scChain(), scWin(2, ['moves' => null])], 2, 'too-few-moves'],
    'rule 3: same clan' => [fn () => [scChain(), scWin(2, ['clans' => ['alice' => 'LSR', 'bob' => 'LSR']])], 3, 'same-clan'],
    'rule 4: pairing already mined this UTC day' => [function () {
        $chain = scChain();
        $chain->attest(scWin(1));

        return [$chain, scWin(2, ['winners' => ['bob'], 'losers' => ['alice']])];
    }, 4, 'pairing-daily-limit'],
    'rule 5: daily limit of the winner' => [function () {
        $chain = scChain(scSeason(daily: ['chess' => 1]));
        $chain->attest(scWin(1));

        return [$chain, scWin(2, ['losers' => ['carol'], 'pairing' => ['alice', 'carol'], 'gatekeepers' => ['alice', 'carol']])];
    }, 5, 'player-daily-limit'],
    'rule 7: same anchor subtree' => [fn () => [scChain(), scWin(2, ['anchors' => ['alice' => ['board', 95], 'bob' => ['board', 90]]])], 7, 'same-subtree'],
    'rule 8: pairing limit per season' => [function () {
        $chain = scChain(scSeason(pairLimit: [1, 1]));
        $chain->attest(scWin(1));

        return [$chain, scWin(30)];
    }, 8, 'pairing-season-limit'],
    'rule 9: a key that mined its last era is closed' => [function () {
        $chain = scChain(scSeason(supply: 100_000, shares: ['chess' => 40], eras: 1));   // chess pot 40 000, its one era ends at 20 000

        $chain->attest(scWin(1));

        return [$chain, scWin(30)];
    }, 9, 'key-closed'],
    'rule 9: a game without a share has no pot' => [fn () => [scChain(scSeason(shares: ['rocket-league' => 60])), scWin()], 9, 'key-closed'],
]);

test('rule 7 compares whole percents against subtree, and 101 switches it off', function () {
    $sameAnchor = ['anchors' => ['alice' => ['board', 100], 'bob' => ['board', 100]]];

    expect(scChain(scSeason(subtree: 101))->attest(scWin(2, $sameAnchor))->mines())->toBeTrue()
        ->and(scChain()->attest(scWin(2, ['anchors' => ['alice' => ['board', 89], 'bob' => ['board', 100]]]))->mines())->toBeTrue();
});

test('a key halves by what it mined, not by the date, and the reward is fixed at attestation', function () {
    // Chess pot 40 000 of 100 000: era 1 ends at 20 000 mined, era 2 at 30 000.
    $chain = scChain(scSeason(supply: 100_000, shares: ['chess' => 40, 'rocket-league' => 60]));

    $first = $chain->attest(scWin(1));
    $late = $chain->attest(scWin(100 * 24, ['winners' => ['carol'], 'losers' => ['dave'], 'pairing' => ['carol', 'dave'], 'gatekeepers' => ['carol', 'dave']]));

    expect([$first->era, $first->rewardPerPlayer])->toBe([1, 20_000])
        ->and([$late->era, $late->rewardPerPlayer])->toBe([2, 10_000])
        ->and(array_map(fn (int $era): int => $chain->season->bound('chess', $era), range(1, 6)))->toBe([20_000, 30_000, 35_000, 37_500, 38_750, 39_375])
        ->and([$chain->season->pot('chess'), $chain->season->eraSize('chess', 6), $chain->season->eraOf('chess', 39_374), $chain->season->eraOf('chess', 39_375)])->toBe([40_000, 625, 6, null]);
});

test('an attestation older than the previous one is refused: the chain only grows forward', function () {
    $chain = scChain();
    $chain->attest(scWin(30));

    expect(fn () => $chain->attest(scWin(29)))->toThrow(ChainViolation::class);
});

test('a parameter change applies only to attestations from its effective time, never retroactively', function () {
    $chain = scChain(scSeason(daily: ['chess' => 5]));
    $t0 = CarbonImmutable::parse(SC_T0);
    $opponent = fn (string $name, float $hours) => scWin($hours, ['losers' => [$name], 'pairing' => ['alice', $name], 'gatekeepers' => ['alice', $name]]);

    $chain->attest($opponent('bob', 1));
    $chain->changeParameters(new ParameterChange($t0->addHours(2), $t0->addHours(3), 'admin', 'test', daily: ['chess' => 1]));
    $before = $chain->attest($opponent('carol', 2.5));
    $after = $chain->attest($opponent('dave', 3));

    expect($before->mines())->toBeTrue()
        ->and($after->rule?->value)->toBe(5)
        ->and(fn () => new ParameterChange($t0->addHours(5), $t0->addHours(4), 'admin', 'backdated'))->toThrow(ChainViolation::class)
        ->and(fn () => $chain->changeParameters(new ParameterChange($t0->addHours(1), $t0->addHours(3), 'admin', 'behind the tip')))->toThrow(ChainViolation::class);
});

test('a voided block keeps its height and its place in every counter', function () {
    $chain = scChain(scSeason(pairLimit: [1, 1]));
    $chain->attest(scWin(1));
    $chain->void(1, 'reciprocity');

    $next = $chain->attest(scWin(30));

    expect($chain->isVoided(1))->toBeTrue()
        ->and($chain->blocks())->toHaveCount(1)
        ->and($chain->mined())->toBe(20_000)
        ->and($next->rule?->value)->toBe(8)
        ->and(fn () => $chain->void(2, 'no such block'))->toThrow(ChainViolation::class);
});

test('the estimator warns about too short, too long, fast and idle seasons', function (int $weeks, float $perWeek, string $warning) {
    $season = scSeason(shares: ['chess' => 100], weeks: $weeks);
    $streams = [['weight_key' => 'chess/blitz', 'game' => 'chess', 'winners' => 1, 'per_week' => $perWeek]];

    expect((new Estimator)->forecast($season, $season->genesisAt, $streams, 0, [])['warnings'])->toContain($warning);
})->with([
    'shorter than 8 weeks' => [4, 10.0, 'season-shorter-than-min-weeks'],
    'longer than 26 weeks' => [28, 10.0, 'season-longer-than-max-weeks'],
    'mined out before week 8' => [24, 500.0, 'supply-mined-before-min-weeks'],
    'most of the supply unmined' => [24, 0.0, 'most-of-the-supply-stays-unmined'],
]);

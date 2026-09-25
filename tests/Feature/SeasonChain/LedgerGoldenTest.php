<?php

/*
 * Golden test: the PHP season chain against the Pre-Season sample ledger.
 *
 * The fixture is generated from the ledger generator (the oracle), see
 * tests/Fixtures/SeasonChain/export_ledger.py for the command. It holds the
 * inputs of all 336 block candidates in attestation order (listed results and
 * the unlisted blitz games that shape the counters) and the oracle's
 * verdicts, heights, eras, rewards, per-player sums, settlement and estimator.
 */

use App\Support\Rating\ClanRating;
use App\Support\Rating\EloRating;
use App\Support\Rating\GlobalRating;
use App\Support\Rating\RankTiers;
use App\Support\SeasonChain\BlockChain;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\ConsensusRules;
use App\Support\SeasonChain\Estimator;
use App\Support\SeasonChain\ParameterChange;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonParameters;
use App\Support\SeasonChain\Settlement;
use Carbon\CarbonImmutable;

/** @return array<string, mixed> */
function seasonLedger(): array
{
    static $ledger = null;

    return $ledger ??= json_decode((string) file_get_contents(base_path('tests/Fixtures/SeasonChain/pre-season-ledger.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $genesis */
function seasonLedgerParameters(array $genesis, ?CarbonImmutable $genesisAt = null): SeasonParameters
{
    $genesisAt ??= CarbonImmutable::parse($genesis['genesis_at']);

    return new SeasonParameters(
        $genesis['season'] ?? 'example',
        $genesisAt,
        isset($genesis['ends_at']) ? CarbonImmutable::parse($genesis['ends_at']) : $genesisAt->addSeconds($genesis['eras'] * 28 * 86400),
        $genesis['supply'],
        $genesis['subsidy'],
        $genesis['halving_seconds'] ?? 28 * 86400,
        new ConsensusParameters(
            $genesis['weights'],
            $genesis['shares'],
            $genesis['daily'] ?? [],
            $genesis['pairlimit'][0] ?? 1,
            $genesis['pairlimit'][1] ?? 3,
            $genesis['subtree'] ?? 51,
            $genesis['moves'] ?? 20,
        ),
    );
}

/** @param array<string, mixed> $row */
function seasonLedgerCandidate(array $row): Candidate
{
    return new Candidate(
        $row['label'], $row['match'], $row['game'], $row['weight_key'],
        CarbonImmutable::parse($row['attested_at']),
        Resolution::from($row['resolution']),
        $row['moves'], $row['winners'], $row['losers'], $row['winning_side'],
        $row['pairing'], $row['gatekeepers'], $row['gatekeepers_connected'],
        $row['trust'], $row['clans'], $row['anchors'],
    );
}

/**
 * Replays the ledger as a reader does: the genesis and the whole public change
 * log first, then every candidate in attestation order, each judged with the
 * parameters in force at its own attestation.
 */
function seasonLedgerChain(): BlockChain
{
    $ledger = seasonLedger();
    $chain = new BlockChain(seasonLedgerParameters($ledger['genesis']), new ConsensusRules($ledger['genesis']['minimum_trust']));

    foreach ($ledger['changes'] as $change) {
        $chain->changeParameters(new ParameterChange(
            CarbonImmutable::parse($change['created_at']),
            CarbonImmutable::parse($change['effective_at']),
            $change['by'],
            $change['reason'],
            daily: $change['daily'],
        ));
    }

    foreach ($ledger['candidates'] as $row) {
        $chain->attest(seasonLedgerCandidate($row));
    }

    return $chain;
}

test('config/season.php carries the Pre-Season defaults of the ledger', function () {
    $genesis = seasonLedger()['genesis'];
    $season = SeasonParameters::fromConfig('pre-season', CarbonImmutable::parse($genesis['genesis_at']));
    $rating = seasonLedger()['rating'];

    expect([$season->supply, $season->subsidy, $season->halvingSeconds, $season->claimSeconds, $season->endsAt->toIso8601ZuluString(), $season->eras()])
        ->toBe([$genesis['supply'], $genesis['subsidy'], $genesis['halving_seconds'], $genesis['claim_seconds'], $genesis['ends_at'], $genesis['eras']])
        ->and([$season->genesis->weights, $season->genesis->shares, $season->genesis->daily])->toEqual([$genesis['weights'], $genesis['shares'], $genesis['daily']])
        ->and([$season->genesis->pairLimitPerDay, $season->genesis->pairLimitPerSeason, $season->genesis->subtree, $season->genesis->moves])
        ->toBe([...$genesis['pairlimit'], $genesis['subtree'], $genesis['moves']])
        ->and(ConsensusRules::fromConfig()->minimumTrust)->toBe($genesis['minimum_trust'])
        ->and([EloRating::fromConfig()->k, EloRating::fromConfig()->provisionalK, EloRating::fromConfig()->provisional])
        ->toBe([$rating['k'], $rating['provisional_k'], $rating['provisional']])
        ->and(RankTiers::fromConfig()->tierFor(1151, 65))->toBe('platinum-3')
        ->and(ClanRating::fromConfig()->hashrate(1, 1, 1, 1))->toBe(array_sum($rating['hashrate']['points']))
        ->and(GlobalRating::fromConfig()->minimumWeight)->toBe(5)
        ->and(Estimator::fromConfig()->windowDays)->toBe(seasonLedger()['estimator']['window_days']);
});

test('the engine reproduces every verdict, block, era and reward of the ledger', function () {
    $ledger = seasonLedger();
    $chain = seasonLedgerChain();

    $actual = [];
    $expected = [];
    foreach ($chain->attestations() as $i => $attestation) {
        $verdict = $attestation['verdict'];
        $want = $ledger['candidates'][$i]['expected'];
        $candidate = $attestation['candidate'];

        $actual[] = [$candidate->label, $verdict->rule?->value, $attestation['height'], $verdict->era,
            $verdict->mines() ? $verdict->rewardPerPlayer : null, $verdict->mines() ? $verdict->reward : null,
            $attestation['parameters']->dailyLimitFor($candidate->game)];
        $expected[] = [$ledger['candidates'][$i]['label'], $want['rule'], $want['height'], $want['era'],
            $want['reward_per_player'], $want['reward'], $want['daily_limit_in_force']];
    }

    expect($actual)->toHaveCount(336)
        ->and($actual)->toBe($expected)
        ->and($chain->mined())->toBe(1_767_500)
        ->and($chain->mined())->toBe($ledger['totals']['mined'])
        ->and($chain->blocks())->toHaveCount(85)
        ->and($chain->remaining())->toBe($ledger['totals']['remaining']);

    $verdicts = array_count_values(array_map(fn (array $a): string => (string) ($a['verdict']->rule->value ?? 'valid'), $chain->attestations()));
    ksort($verdicts);
    expect($verdicts)->toBe($ledger['totals']['verdicts']);
});

test('per-player sums, fees and the season settlement match the ledger', function () {
    $ledger = seasonLedger();
    $chain = seasonLedgerChain();

    foreach ($ledger['review'] as $void) {
        $chain->void($void['height'], $void['reason']);
    }
    $settlement = Settlement::compute($chain, $ledger['fees']);

    $miners = array_map(fn (array $row): array => ['blocks' => $row['blocks'], 'sats' => $row['subsidy'], 'fees' => $row['fees']], $settlement['players']);
    $payouts = array_map(fn (array $row): int => $row['payout'], $settlement['players']);

    expect($miners)->toEqual($ledger['miners'])
        ->and($payouts)->toEqual($ledger['settlement']['per_player'])
        ->and(array_sum($payouts))->toBe(1_728_920)
        ->and($settlement['reserve']['voided'])->toBe($ledger['settlement']['voided'])
        ->and($settlement['reserve']['fees_without_block'] + $settlement['reserve']['fee_remainders'])->toBe($ledger['fees_to_reserve']);

    $feesPerBlock = [];
    foreach ($ledger['candidates'] as $row) {
        if ($row['expected']['height'] !== null) {
            $feesPerBlock[$row['expected']['height']] = $row['expected']['fees_per_player'];
        }
    }
    expect(array_filter($feesPerBlock))->toEqual($settlement['fees_per_block']);
});

test('the estimator reproduces the ledger forecast: 1 965 937 sats, 93.6 % at the season end', function () {
    $ledger = seasonLedger();
    $want = $ledger['estimator']['pre_season'];

    $forecast = (new Estimator($ledger['estimator']['window_days']))->fromChain(seasonLedgerChain(), CarbonImmutable::parse($ledger['estimator']['now']));

    expect($forecast['end_mined'])->toBe(1_965_937)
        ->and($forecast['end_mined_percent'])->toBe(93.6)
        ->and($forecast['forecast_per_week'])->toEqual($ledger['estimator']['forecast_per_week'])
        ->and(array_filter($forecast['milestone_weeks'], fn ($week): bool => $week !== null))->toBe($want['milestone_weeks'])
        ->and($forecast['per_era'])->toBe(array_map(fn (array $era): array => ['era' => $era['era'], 'budget' => $era['budget'],
            'mined' => ['chess' => $era['chess'], 'rocket-league' => $era['rocket-league']]], $want['per_era']))
        ->and($forecast['payout_per_week'])->toBe($want['payout_per_week'])
        ->and($forecast['wins_per_era'])->toEqual($want['wins_per_era'])
        ->and($forecast['warnings'])->toBe($want['warnings']);

    // The ledger's second parameter set, forecast from week 0 with the same activity.
    $example = $ledger['estimator']['example'];
    $season = seasonLedgerParameters($example['genesis'], CarbonImmutable::parse('2027-01-01T00:00:00Z'));
    $streams = [];
    foreach ($ledger['estimator']['forecast_per_week'] as $key => $perWeek) {
        $streams[] = ['weight_key' => $key, 'game' => explode('/', $key)[0], 'winners' => ['rocket-league/3v3' => 3, 'rocket-league/2v2' => 2][$key] ?? 1, 'per_week' => $perWeek];
    }
    $second = (new Estimator)->forecast($season, $season->genesisAt, $streams, 0, []);

    expect($second['end_mined'])->toBe($example['expected']['end_mined'])
        ->and(array_filter($second['milestone_weeks'], fn ($week): bool => $week !== null))->toBe($example['expected']['milestone_weeks'])
        ->and($second['payout_per_week'])->toBe($example['expected']['payout_per_week'])
        ->and(array_column($second['per_era'], 'mined'))->toBe(array_map(fn (array $era): array => ['chess' => $era['chess'], 'rocket-league' => $era['rocket-league']], $example['expected']['per_era']))
        ->and($second['wins_per_era'])->toEqual($example['expected']['wins_per_era']);
});

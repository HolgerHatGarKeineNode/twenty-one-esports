<?php

/*
 * The estimator on /mining and AdminSeason reads the live chain from the
 * database (season_attestations, replayed by SeasonChains). Fed with the
 * Pre-Season sample ledger, it must give the ledger generator's figures
 * (ledger-src; the forecast rates are what ledger-src/check.py verifies:
 * valid blocks of the last 4 weeks / 4), exactly like the in-memory golden
 * test (LedgerGoldenTest).
 */

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonParameterChange;
use App\Support\SeasonChain\ChainOverview;
use Carbon\CarbonImmutable;

test('the live estimator over the stored ledger chain gives the figures of the ledger generator', function () {
    $ledger = json_decode((string) file_get_contents(base_path('tests/Fixtures/SeasonChain/pre-season-ledger.json')), true, flags: JSON_THROW_ON_ERROR);
    $genesis = $ledger['genesis'];

    $season = Season::factory()->create([
        'slug' => 'pre-season',
        'supply' => $genesis['supply'],
        'subsidy' => $genesis['subsidy'],
        'halving_seconds' => $genesis['halving_seconds'],
        'claim_seconds' => $genesis['claim_seconds'],
        'minimum_trust' => $genesis['minimum_trust'],
        'parameters' => ['weights' => $genesis['weights'], 'shares' => $genesis['shares'], 'daily' => $genesis['daily'],
            'pairlimit' => $genesis['pairlimit'], 'subtree' => $genesis['subtree'], 'moves' => $genesis['moves']],
        'genesis_at' => CarbonImmutable::parse($genesis['genesis_at']),
        'ends_at' => CarbonImmutable::parse($genesis['ends_at']),
    ]);

    foreach ($ledger['changes'] as $change) {
        SeasonParameterChange::query()->create([
            'season_id' => $season->id, 'signed_at' => CarbonImmutable::parse($change['created_at']), 'effective_at' => CarbonImmutable::parse($change['effective_at']),
            'changed_by_pubkey' => str_repeat('a', 64), 'reason' => $change['reason'], 'parameters' => ['daily' => $change['daily']], 'tip_event_id' => str_repeat('b', 64),
        ]);
    }

    foreach ($ledger['candidates'] as $index => $row) {
        [$game, $mode] = explode('/', $row['weight_key'], 2);
        $candidate = array_diff_key($row, ['expected' => true]);

        SeasonAttestation::query()->create([
            'season_id' => $season->id, 'source' => 'ledger', 'source_id' => $index, 'board' => 1,
            'label' => $row['label'], 'game' => $game, 'mode' => $mode, 'ladder_address' => $row['weight_key'],
            'attested_at' => CarbonImmutable::parse($row['attested_at']), 'candidate' => $candidate,
            'height' => $row['expected']['height'], 'rule' => $row['expected']['rule'],
            'reward_per_player' => (int) $row['expected']['reward_per_player'], 'reward' => (int) $row['expected']['reward'],
            'event_id' => hash('sha256', 'ledger-'.$index),
        ]);
    }

    $overview = app(ChainOverview::class)->live($season->refresh(), CarbonImmutable::parse($ledger['estimator']['now']));
    $want = $ledger['estimator']['pre_season'];

    expect($overview['mined'])->toBe(1_767_500)
        ->and($overview['blocks'])->toBe(85)
        ->and($overview['remaining'])->toBe($ledger['totals']['remaining'])
        ->and($overview['estimate']['end_mined'])->toBe(1_965_937)
        ->and($overview['estimate']['end_mined_percent'])->toBe(93.6)
        ->and($overview['estimate']['forecast_per_week'])->toEqual($ledger['estimator']['forecast_per_week'])
        ->and(array_filter($overview['estimate']['milestone_weeks'], fn ($week): bool => $week !== null))->toBe($want['milestone_weeks'])
        ->and($overview['estimate']['payout_per_week'])->toBe($want['payout_per_week'])
        ->and($overview['estimate']['wins_per_era'])->toEqual($want['wins_per_era'])
        ->and($overview['estimate']['warnings'])->toBe($want['warnings'])
        ->and($overview['era'])->toBe(4);
});

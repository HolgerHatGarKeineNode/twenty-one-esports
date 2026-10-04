<?php

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonParameterChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonChains;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The cached chain readout of the read pages (performance plan P2, S3)
|--------------------------------------------------------------------------
|
| Home, /mining and AdminSeason used to replay every attestation of the season
| on each visit. SeasonChains::readout() replays once per state of the chain:
| its key is the newest attestation, the newest parameter change and the
| season row. Each part of the key has a test that changes only that part.
|
*/

/** A won Rocket League 1v1 at `$height` that the replay mines (both players trusted 100). */
function readoutBlock(Season $season, int $height, CarbonImmutable $at): void
{
    [$winner, $loser] = User::factory()->count(2)->create();
    $candidate = new Candidate('#'.(400 + $height), 'series:'.$height, 'rocket-league', 'rocket-league/1v1', $at, Resolution::Confirmed, null,
        [$winner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:1', 'lineup:'.(1 + $height)], [$winner->pubkey, $loser->pubkey], true,
        [$winner->pubkey => 100, $loser->pubkey => 100], [], []);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'series', 'source_id' => $height, 'label' => $candidate->label, 'game' => 'rocket-league', 'mode' => '1v1',
        'ladder_address' => 'rocket-league/1v1', 'attested_at' => $at, 'candidate' => $candidate->toArray(), 'height' => $height, 'era' => 1,
        'reward_per_player' => 20_000, 'reward' => 20_000, 'event_id' => hash('sha256', 'readout-'.$height),
    ]);
}

/** Queries one readout() runs. */
function readoutQueries(Season $season): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(SeasonChains::class)->readout($season);
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

test('the readout carries the replay\'s figures and is served from the cache on the next visit', function () {
    $season = openSeason();
    readoutBlock($season, 1, CarbonImmutable::now()->subHour());
    readoutBlock($season, 2, CarbonImmutable::now()->subMinutes(30));
    $chain = app(SeasonChains::class)->chain($season);

    $readout = app(SeasonChains::class)->readout($season);

    expect($readout['mined'])->toBe($chain->mined())->toBeGreaterThan(0)
        ->and($readout['mined_by_game_and_era'])->toBe($chain->minedByGameAndEra())
        ->and(array_column($readout['blocks'], 'reward'))->toBe(array_column($chain->blockRows(), 'reward'))
        ->and($readout['blocks'][1]['at']->equalTo($chain->blockRows()[1]['at']))->toBeTrue()
        ->and($readout['stored']['times'])->toHaveCount(2)
        ->and($readout['stored']['miner_count'])->toBe(2)
        // The key costs one query, the replay none.
        ->and(readoutQueries($season))->toBe(1);
});

test('a new attestation gives a new readout', function () {
    $season = openSeason();
    readoutBlock($season, 1, CarbonImmutable::now()->subHour());
    $before = app(SeasonChains::class)->readout($season)['mined'];

    readoutBlock($season, 2, CarbonImmutable::now()->subMinutes(30));

    expect(app(SeasonChains::class)->readout($season)['mined'])->toBeGreaterThan($before);
});

test('a parameter change without a new attestation gives a new readout', function () {
    $season = openSeason(['genesis_at' => now()->subDay()->startOfSecond(), 'ends_at' => now()->subDay()->startOfSecond()->addWeeks(24)]);
    readoutBlock($season, 1, CarbonImmutable::now()->subHour());
    expect(app(SeasonChains::class)->readout($season)['mined'])->toBeGreaterThan(0)
        ->and(readoutQueries($season))->toBe(1);

    // A change in force from before the block that stops Rocket League 1v1 from mining. The league never writes
    // one that reaches back (changeParameters() refuses it), so this one goes straight into the table: it shows
    // that the key follows the parameter changes, not only the attestations.
    SeasonParameterChange::query()->create([
        'season_id' => $season->id, 'signed_at' => now()->subHours(2), 'effective_at' => now()->subHours(2),
        'changed_by_pubkey' => str_repeat('a', 64), 'reason' => 'RL pauses.', 'parameters' => ['weights' => ['rocket-league/1v1' => 0]],
        'tip_event_id' => $season->genesisId() ?: str_repeat('0', 64),
    ]);

    expect(readoutQueries($season))->toBeGreaterThan(1)
        ->and(app(SeasonChains::class)->readout($season)['mined'])->toBe(0);
});

test('a rule change through the board shows on /mining at once, with no new result', function () {
    $season = openSeason();
    readoutBlock($season, 1, CarbonImmutable::now()->subHour());
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    expect(app(ChainOverview::class)->live($season)['changes'])->toHaveCount(0);

    $this->travel(1)->minutes();
    app(SeasonChains::class)->changeParameters($admin, ['daily' => ['chess' => 4]], 'Long blitz evenings.', CarbonImmutable::now());
    $this->travel(1)->minutes();
    $live = app(ChainOverview::class)->live($season->refresh());

    expect($live['changes'])->toHaveCount(1)
        ->and($live['in_force']->dailyLimitFor('chess'))->toBe(4)
        ->and($live['mined'])->toBe(app(SeasonChains::class)->chain($season)->mined());
});

test('a change to the season row gives a new readout', function () {
    $season = openSeason();
    readoutBlock($season, 1, CarbonImmutable::now()->subHour());
    expect(app(SeasonChains::class)->readout($season)['mined'])->toBeGreaterThan(0);

    // Both players are trusted 100: a minimum of 200 leaves the win without a block.
    $season->forceFill(['minimum_trust' => 200])->save();

    expect(app(SeasonChains::class)->readout($season)['mined'])->toBe(0);
});

test('/mining lists ten blocks, counts all of them and today\'s, and ranks the miners from the readout', function () {
    $this->travelTo(now()->setTime(12, 0));
    $season = openSeason(['genesis_at' => now()->subDays(2)->startOfSecond(), 'ends_at' => now()->subDays(2)->startOfSecond()->addWeeks(24)]);
    foreach (range(1, 12) as $height) {
        readoutBlock($season, $height, CarbonImmutable::now()->subDay()->addMinutes($height));
    }
    readoutBlock($season, 13, CarbonImmutable::now()->subHours(2));
    readoutBlock($season, 14, CarbonImmutable::now()->subHour());

    $live = app(ChainOverview::class)->live($season);

    expect($live['latest'])->toHaveCount(10)
        ->and($live['latest'][0]['height'])->toBe(14)
        ->and($live['blocks'])->toBe(14)
        ->and($live['blocks_today'])->toBe(2)
        ->and($live['miner_count'])->toBe(14)
        ->and($live['miners'])->toHaveCount(10)
        ->and($live['last_block_at']->getTimestamp())->toBe(CarbonImmutable::now()->subHour()->getTimestamp());
});

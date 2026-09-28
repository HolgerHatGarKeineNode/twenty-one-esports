<?php

/*
 * /mining shows the real chain (P7c, P33): the rest state and the draft
 * before Block 0, the tip, supply chart, blocks, reserve, payouts, review and
 * change log of a live season, and the ended season between seasons.
 */

use App\Enums\IncomingPaymentStatus;
use App\Models\ChessGame;
use App\Models\IncomingPayment;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Prizes\PoolInvoices;
use App\Support\QrCode;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonChains;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('before Block 0 /mining shows the rest state with the countdown and the Pre-Season draft', function () {
    $this->freezeTime();
    config(['esports.preseason.block0_at' => now()->addDay()->toIso8601String()]);

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-state="pre-launch"', false)
        ->assertSee('The chain starts at Block 0')
        ->assertSee('(in 1 d 00:00:00)')
        ->assertSee("2\u{00A0}100\u{00A0}000", false)
        ->assertSee('Rules of the Pre-Season draft')
        ->assertDontSee('Latest blocks');
});

test('/mining is the Season page: title, share title and heading say Season, like the navigation', function () {
    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('<title>Season – TWENTY ONE esports</title>', false)
        ->assertSee('<meta property="og:title" content="Season', false)
        ->assertSeeInOrder(['<h1', 'Season</h1>'], false)
        ->assertDontSee('>Mining</h1>', false);
});

test('in a live season /mining shows mined sats, the blocks with their miners and the change log', function () {
    $season = openSeason();
    $miner = User::factory()->create(['name' => 'satsjaeger']);
    $loser = User::factory()->create();
    $candidate = new Candidate('#412', 'series:1', 'rocket-league', 'rocket-league/1v1', CarbonImmutable::now(), Resolution::Confirmed, null,
        [$miner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:1', 'lineup:2'], [$miner->pubkey, $loser->pubkey], true,
        [$miner->pubkey => 100, $loser->pubkey => 100], [], []);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'series', 'source_id' => 1, 'label' => '#412', 'game' => 'rocket-league', 'mode' => '1v1',
        'ladder_address' => 'x', 'attested_at' => $candidate->attestedAt, 'candidate' => $candidate->toArray(), 'height' => 1, 'era' => 1,
        'reward_per_player' => 20_000, 'reward' => 20_000, 'link_event_id' => $season->genesisId(), 'event_id' => str_repeat('c', 64),
    ]);
    $admin = User::factory()->create(['name' => 'mempoolmax']);
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    $this->travel(1)->minutes();
    app(SeasonChains::class)->changeParameters($admin, ['daily' => ['chess' => 4]], 'Long blitz evenings.', CarbonImmutable::now());

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-state="live"', false)
        ->assertSeeInOrder(['Mined', "20\u{00A0}000"], false)
        ->assertSee('#412')
        ->assertSee('satsjaeger')
        ->assertSee('Long blitz evenings.')
        ->assertSee('by mempoolmax')
        ->assertSee('Chess 4');
});

test('while rated chess is off, /mining and AdminSeason show no chess reward as achievable; switched on, they do', function () {
    $board = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);
    // Casual chess wins of the last weeks must not forecast chess blocks while chess cannot mine.
    ChessGame::factory()->count(3)->finished('1-0')->create(['updated_at' => now()->subHour()]);

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-test="rated-chess-not-open"', false)
        ->assertSee('data-test="reward-not-open"', false)
        ->assertDontSee('Chess blitz win pays')
        ->assertSee('Rocket League 1v1 win pays');
    $this->actingAs($board)->get(route('admin.season'))
        ->assertOk()
        ->assertSee('data-test="rated-chess-not-open"', false)
        ->assertDontSee('Chess blitz 0.');

    config(['esports.chess.rated_queue' => true]);

    $this->get(route('mining'))
        ->assertOk()
        ->assertDontSee('data-test="rated-chess-not-open"', false)
        ->assertDontSee('data-test="reward-not-open"', false)
        ->assertSee('Chess blitz win pays');
    $this->actingAs($board)->get(route('admin.season'))
        ->assertOk()
        ->assertDontSee('data-test="rated-chess-not-open"', false)
        ->assertSee('Chess blitz 0.');
});

test('both chain pages survive a Livewire roundtrip, before Block 0 and live', function () {
    $board = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    Livewire::test('pages::mining')->call('$refresh')->assertOk();
    Livewire::actingAs($board)->test('pages::admin.season')->call('$refresh')->assertOk();

    openSeason();

    Livewire::test('pages::mining')->call('$refresh')->assertOk()->assertSee('data-state="live"', false);
    Livewire::actingAs($board)->test('pages::admin.season')->call('$refresh')->assertOk()->assertSee('Change the chain rules');
});

/** A mined block of `$winner` in `$season`, as SeasonChains stores it. */
function minedBlock(Season $season, int $height, User $winner, CarbonImmutable $at, int $reward = 20_000): void
{
    $loser = User::factory()->create();
    $candidate = new Candidate('#'.(400 + $height), 'series:'.$height, 'rocket-league', 'rocket-league/1v1', $at, Resolution::Confirmed, null,
        [$winner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:1', 'lineup:'.(1 + $height)], [$winner->pubkey, $loser->pubkey], true,
        [$winner->pubkey => 100, $loser->pubkey => 100], [], []);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'series', 'source_id' => $height, 'label' => $candidate->label, 'game' => 'rocket-league', 'mode' => '1v1',
        'ladder_address' => 'rocket-league/1v1', 'attested_at' => $at, 'candidate' => $candidate->toArray(), 'height' => $height, 'era' => 1,
        'reward_per_player' => $reward, 'reward' => $reward, 'event_id' => hash('sha256', 'mined-'.$height),
    ]);
}

/** A settled payment into the league reserve. */
function reserveZap(int $sats, ?User $payer, string $hash): void
{
    (new IncomingPayment)->forceFill(['pot' => IncomingPayment::RESERVE, 'source' => $payer === null ? 'anonymous' : 'zap', 'payer_pubkey' => $payer?->pubkey,
        'amount_sats' => $sats, 'bolt11' => 'lnbc1', 'payment_hash' => hash('sha256', $hash), 'status' => IncomingPaymentStatus::Settled,
        'expires_at' => now()->addHour(), 'settled_at' => now()])->save();
}

test('before Block 0 the Season page shows the reserve, but no supply chart, payouts or review', function () {
    reserveZap(2_100, null, 'pre');

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-state="pre-launch"', false)
        ->assertSee('data-test="mining-reserve"', false)
        ->assertSee('1 zap')
        ->assertSee('anonymous')
        ->assertDontSee('data-test="reserve-unmined"', false)
        ->assertDontSee('data-test="supply-chart"', false)
        ->assertDontSee('data-test="mining-payouts"', false)
        ->assertDontSee('data-test="mining-review"', false);
});

test('in a live season the supply chart plots the mined sats per day with the forecast, and a table holds the same numbers', function () {
    $season = openSeason(['genesis_at' => now()->subDays(3)->startOfSecond(), 'ends_at' => now()->subDays(3)->startOfSecond()->addWeeks(24)]);
    $miner = User::factory()->create(['name' => 'satsjaeger']);
    minedBlock($season, 1, $miner, CarbonImmutable::now()->subDays(2));
    minedBlock($season, 2, $miner, CarbonImmutable::now()->subDays(2)->addMinute());
    minedBlock($season, 3, $miner, CarbonImmutable::now()->subDay());

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-state="live"', false)
        ->assertSee('data-test="supply-chart"', false)
        ->assertSee('Forecast from the last 4 weeks')
        // Two days with blocks: 2 blocks for 40 000, then 1 more for a total of 60 000.
        ->assertSeeInOrder(['data-test="supply-table"', '>2</td>', "40\u{00A0}000", "40\u{00A0}000", '>1</td>', "20\u{00A0}000", "60\u{00A0}000", 'forecast</span>'], false)
        ->assertSee('Era 2')
        ->assertSee('data-test="mining-payouts"', false)
        ->assertSee('the sats wait 90 days for a claim, then go to the reserve')
        ->assertSee("60\u{00A0}000 sats, 1 player", false)
        ->assertSeeInOrder(['data-test="mining-review"', 'opens '], false)
        ->assertSee('Unmined rest at the season end, at the current rate');
});

test('the reserve lists each settled zap with its payer, never a total, so no balance reads as a pot', function () {
    openSeason();
    reserveZap(1_000, User::factory()->create(['name' => 'poolfan']), 'a');
    $this->travel(1)->minutes();
    reserveZap(2_000, null, 'b');
    (new IncomingPayment)->forceFill(['pot' => IncomingPayment::RESERVE, 'source' => 'zap', 'amount_sats' => 50_000, 'bolt11' => 'lnbc1',
        'payment_hash' => hash('sha256', 'open'), 'status' => IncomingPaymentStatus::Pending, 'expires_at' => now()->addHour()])->save();

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('2 zaps')
        ->assertSeeInOrder(['data-test="reserve-zaps"', 'anonymous', "2\u{00A0}000", 'poolfan', "1\u{00A0}000"], false)
        ->assertDontSee("3\u{00A0}000", false)
        ->assertDontSee("50\u{00A0}000", false);
});

test('the reserve card shows a QR code to zap the league reserve, never its address as text, and nothing without one', function (string $state) {
    if ($state === 'live') {
        openSeason();
    }

    // Without the league's LNURL endpoint (no receiving wallet) there is nothing to zap.
    $this->get(route('mining'))->assertOk()->assertSee('data-test="mining-reserve"', false)->assertDontSee('data-test="reserve-zap-qr"', false);

    fakeWallet();
    $qr = QrCode::svg('lightning:'.PoolInvoices::lnurl(), label: __('QR code to zap the league reserve'));

    $html = $this->get(route('mining'))->assertOk()->getContent();

    expect($html)->toContain('data-test="reserve-zap-qr"')
        ->and($html)->toContain($qr)
        ->and(strip_tags($html))->not->toContain(PoolInvoices::address())
        ->and(strip_tags($html))->not->toContain(PoolInvoices::lnurl())
        ->and($html)->not->toContain('lightning:');
})->with(['draft', 'live']);

test('between seasons the page shows the ended season as it closed: no forecast, the unmined rest and the review', function () {
    $season = openSeason(['genesis_at' => now()->subDays(30)->startOfSecond(), 'ends_at' => now()->subHour()->startOfSecond()]);
    minedBlock($season, 1, User::factory()->create(['name' => 'lena.k']), CarbonImmutable::now()->subDays(10));

    $this->get(route('mining'))
        ->assertOk()
        ->assertSee('data-state="between"', false)
        ->assertSee('The chain rests between seasons')
        ->assertSee('as it closed')
        ->assertDontSee('Pre-Season draft')
        ->assertSee('data-test="supply-chart"', false)
        ->assertDontSee('Forecast from the last 4 weeks')
        ->assertSeeInOrder(['Not mined', "2\u{00A0}080\u{00A0}000", 'goes to the league reserve'], false)
        ->assertSee('lena.k')
        ->assertSee('Rules at the season end')
        ->assertSeeInOrder(['data-test="mining-review"', 'mining stopped'], false)
        ->assertDontSee('Block 0 countdown');
});

test('the supply chart has one point per day with blocks, ends at the mined figure and costs no query of its own', function () {
    $season = openSeason(['genesis_at' => now()->subDays(3)->startOfSecond(), 'ends_at' => now()->subDays(3)->startOfSecond()->addWeeks(24)]);
    $players = User::factory()->count(12)->create();
    foreach (range(1, 12) as $height) {
        minedBlock($season, $height, $players[$height - 1], CarbonImmutable::now()->startOfDay()->subDays($height <= 6 ? 2 : 1)->addMinutes($height));
    }
    $chains = app(SeasonChains::class);
    $chain = $chains->chain($season);

    DB::enableQueryLog();
    $curve = app(ChainOverview::class)->supplyCurve($season, $chain, CarbonImmutable::now(), 100_000);
    $queries = count(DB::getQueryLog());

    expect($queries)->toBe(0)
        ->and(array_column($curve['days'], 'blocks'))->toBe([6, 6])
        ->and($curve['days'][1]['total'])->toBe($chain->mined())
        ->and($chain->mined())->toBeGreaterThan(0)
        ->and($curve['forecast'])->toBe(100_000)
        ->and(array_column($curve['halvings'], 'era'))->toBe([2, 3, 4, 5, 6]);
});

test('the Season page survives a Livewire roundtrip between seasons too', function () {
    openSeason(['genesis_at' => now()->subDays(30)->startOfSecond(), 'ends_at' => now()->subHour()->startOfSecond()]);

    Livewire::test('pages::mining')->call('$refresh')->assertOk()->assertSee('data-state="between"', false);
});

test('the supply table of an ended season without blocks does not promise a first block', function () {
    $curve = ['from' => now(), 'to' => now()->addWeeks(24), 'now' => now()->addWeeks(24), 'supply' => 2100000, 'halvings' => [], 'forecast' => null, 'days' => []];

    $ended = view('components.supply-chart', ['curve' => $curve, 'zone' => 'UTC', 'ended' => true])->render();
    $live = view('components.supply-chart', ['curve' => $curve, 'zone' => 'UTC'])->render();

    expect($ended)->toContain('No block was mined this season.')->not->toContain('mines block 1')
        ->and($live)->toContain('mines block 1');
});

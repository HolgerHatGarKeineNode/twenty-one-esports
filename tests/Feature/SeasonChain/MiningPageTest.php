<?php

/*
 * /mining shows the real chain (P7c): the rest state and the draft before
 * Block 0, the tip, supply, blocks and change log of a live season.
 */

use App\Models\ChessGame;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonChains;
use Carbon\CarbonImmutable;
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

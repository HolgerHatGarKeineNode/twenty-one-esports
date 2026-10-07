<?php

/*
 * The board games on the admin season page (plan "Mühle und Dame", P6):
 * every knob of theirs (weight per mode, the share and daily limit of their
 * group) is in the chain draft for Block 0 and in the rule change of a live
 * season, visible and savable. The draft proposes their values and the
 * shrunk shares of the others; nothing moves until the board saves, and the
 * log shows what moved. A running season whose genesis has no board games'
 * group takes them in one by one (share groups are the genesis's).
 */

use App\Models\Season;
use App\Models\SeasonParameterChange;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
    $this->board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
});

test('the chain draft shows the board games as one row with a weight per game, a share and a daily limit, and proposes their values', function () {
    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="draft-row-board-games"', false)
        ->assertSee('data-test="draft-weight-nine-mens-morris-correspondence"', false)
        ->assertSee('data-test="draft-weight-checkers-correspondence"', false)
        ->assertSee('data-test="draft-share-board-games"', false)
        ->assertSee('data-test="draft-daily-board-games"', false)
        ->assertSee('Board games')
        // Not mining yet: empty fields, the other shares as the board decided them, and the proposal next to them.
        ->assertSet('draftWeights.nine-mens-morris/correspondence', '')
        ->assertSet('draftShares', ['chess' => '35', 'rocket-league' => '40', 'ea-sports-fc' => '25', 'board-games' => '', 'age-of-empires-2' => ''])
        ->assertSee('data-test="board-games-proposal"', false)
        ->assertSee('Chess 32 %, Rocket League 36 %, EA Sports FC 22 %');
});

test('the board fills in the proposal and saves it: the board games mine in the draft, chess keeps its weights, and the log shows the shrunk shares', function () {
    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->call('fillBoardGamesProposal')
        ->assertSet('draftWeights.nine-mens-morris/correspondence', '2')
        ->assertSet('draftWeights.checkers/correspondence', '2')
        ->assertSet('draftShares', ['chess' => '32', 'rocket-league' => '36', 'ea-sports-fc' => '22', 'board-games' => '10', 'age-of-empires-2' => ''])
        ->assertSet('draftDaily.board-games', '5')
        // Nothing is saved by filling in.
        ->tap(fn () => expect(ChainDraft::stored())->toBeNull())
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->assertDontSee('data-test="board-games-proposal"', false)
        // Rated board games are off: their rewards are shown as not open.
        ->assertSee('data-test="rated-board-not-open"', false);

    $chain = ChainDraft::stored();
    $changes = SeasonSettingChange::query()->latest('id')->first()->changes;

    expect($chain['weights'])->toMatchArray(['chess/blitz' => 1000, 'chess/correspondence' => 2000, 'nine-mens-morris/correspondence' => 2000, 'checkers/correspondence' => 2000])
        ->and($chain['shares'])->toBe(['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, 'board-games' => 10])
        ->and($chain['daily'])->toMatchArray(['board-games' => 5])
        ->and($chain['groups'])->toHaveKey('board-games')
        ->and($changes)->toMatchArray([
            'chain.shares.chess' => [35, 32],
            'chain.shares.board-games' => [null, 10],
            'chain.weights.nine-mens-morris/correspondence' => [null, 2000],
        ])
        ->and($changes)->not->toHaveKey('chain.weights.chess/blitz');
});

test('the board keeps the board games unmined by leaving their weights empty; a share of 0 is refused, an empty one is not', function () {
    $page = Livewire::actingAs($this->board)->test('pages::admin.season')
        ->set('draftShares.board-games', '0')
        ->call('saveDraft');

    expect($page->get('draftError'))->toContain('from 1 to 100');

    $page->set('draftShares.board-games', '')
        ->set('draftDaily.board-games', '')
        ->call('saveDraft')
        ->assertSet('draftError', '');

    expect(ChainDraft::stored()['weights'])->not->toHaveKey('checkers/correspondence')
        ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 25]);

    // A weight without a share and a daily limit is refused.
    $page->set('draftWeights.checkers/correspondence', '1')->call('saveDraft');
    expect($page->get('draftError'))->toBe('Board games mine, so they need a share and a daily limit.');
});

test('in a live season with the board games\' group the rule change adds them: weights, the group\'s share and daily limit, in one change', function () {
    openSeason();

    expect(Season::query()->sole()->chainParameters()->genesis->groups)->toHaveKey('board-games');

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSet('weights.nine-mens-morris/correspondence', '')
        ->assertSet('weights.checkers/correspondence', '')
        ->assertSet('shares.board-games', '')
        ->assertSet('daily.board-games', '')
        ->set('weights.nine-mens-morris/correspondence', '1.5')
        ->set('weights.checkers/correspondence', '1')
        ->set('shares.chess', '30')
        ->set('shares.board-games', '5')
        ->set('daily.board-games', '3')
        ->set('reason', 'Board games mine from today.')
        ->call('saveChange')
        ->assertSet('changeError', '');

    expect(SeasonParameterChange::query()->sole()->parameters)->toBe([
        'weights' => ['nine-mens-morris/correspondence' => 1500, 'checkers/correspondence' => 1000],
        'shares' => ['chess' => 30, 'board-games' => 5],
        'daily' => ['board-games' => 3],
    ]);
});

test('a running season whose genesis has no board games\' group takes each board game in as its own share key', function () {
    $defaults = ChainDraft::defaults();
    openSeason(['parameters' => [
        'weights' => $defaults['weights'], 'groups' => ['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']],
        'shares' => $defaults['shares'], 'daily' => $defaults['daily'], 'pairlimit' => $defaults['pairlimit'], 'subtree' => $defaults['subtree'], 'moves' => $defaults['moves'],
    ]]);

    $page = Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSet('shares.nine-mens-morris', '')
        ->assertSet('shares.checkers', '');

    expect($page->get('shares'))->not->toHaveKey('board-games');

    $page->set('weights.checkers/correspondence', '1')
        ->set('shares.ea-sports-fc', '20')
        ->set('shares.checkers', '5')
        ->set('daily.checkers', '3')
        ->set('reason', 'Checkers mines from today.')
        ->call('saveChange')
        ->assertSet('changeError', '');

    expect(SeasonParameterChange::query()->sole()->parameters)->toBe([
        'weights' => ['checkers/correspondence' => 1000],
        'shares' => ['ea-sports-fc' => 20, 'checkers' => 5],
        'daily' => ['checkers' => 3],
    ]);
});

test('a rule change that lets the board games mine without their share and daily limit names them in the plural; a single game keeps its sentence', function () {
    openSeason();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->set('weights.checkers/correspondence', '1')
        ->set('reason', 'Board games mine.')
        ->call('saveChange')
        ->assertSet('changeError', 'Board games mine, so they need a share and a daily limit in the same change.');

    $defaults = ChainDraft::defaults();
    Season::query()->delete();
    openSeason(['parameters' => [
        'weights' => $defaults['weights'], 'groups' => ['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']],
        'shares' => $defaults['shares'], 'daily' => $defaults['daily'], 'pairlimit' => $defaults['pairlimit'], 'subtree' => $defaults['subtree'], 'moves' => $defaults['moves'],
    ]]);

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->set('weights.checkers/correspondence', '1')
        ->set('reason', 'Checkers mines.')
        ->call('saveChange')
        ->assertSet('changeError', 'Checkers mines, so it needs a share and a daily limit in the same change.');

    expect(SeasonParameterChange::query()->count())->toBe(0);
});

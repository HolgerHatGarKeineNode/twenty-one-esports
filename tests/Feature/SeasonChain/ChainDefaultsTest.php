<?php

/*
 * The defaults of the chain draft since 2026-11-18 (user decision): chess,
 * the board games and Hyperbitcoinization (NIP rev. 9.26, draft) mine at
 * Block 0, at 50, 30 and 20 %, each only while it is switched on. Rocket
 * League and EA Sports FC are proposals of the admin season page, and so is
 * Hyperbitcoinization for a draft or a live season from before it was
 * switched on. Nothing of a proposal is in force until the board saves it.
 */

use App\Models\NostrEvent;
use App\Models\SeasonParameterChange;
use App\Models\User;
use App\Support\Hyper\HyperMining;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonParameters;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->board = User::factory()->withPubkey((new TestSigner)->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);
});

/** Every weight key of these games in a list of weights. */
function chainDefaultsKeysOf(array $weights, string ...$games): array
{
    return array_values(array_filter(array_keys($weights), fn (string $key): bool => in_array(explode('/', $key, 2)[0], $games, true)));
}

test('with the board games and Hyperbitcoinization switched on the defaults are the numbers the user decided, and Block 0 signs them', function () {
    BlockliOn::play();
    NineMensMorrisOn::play();
    CheckersGame::play();
    HyperOn::play();
    $defaults = ChainDraft::defaults();

    expect(ChainDraft::stored())->toBeNull()
        ->and(ChainDraft::current())->toBe($defaults)
        ->and($defaults)->toBe([
            'block0_at' => null,
            'weeks' => 24,
            'supply' => 2_100_000,
            'subsidy' => 2_100,
            'halving_days' => 28,
            'claim_days' => 90,
            'trust_minimum' => 50,
            'message' => null,
            'weights' => [
                'chess/blitz' => 1000,
                'chess/correspondence' => 2000,
                'chess/rapid' => 1500,
                'blockli/blitz' => 1000,
                'blockli/rapid' => 1500,
                'blockli/correspondence' => 2000,
                'nine-mens-morris/correspondence' => 2000,
                'checkers/correspondence' => 2000,
                'hyperbitcoinization/live' => 1000,
                'hyperbitcoinization/correspondence' => 1000,
            ],
            'groups' => ['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27'], 'board-games' => ['blockli', 'nine-mens-morris', 'checkers']],
            'shares' => ['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20],
            'daily' => ['chess' => 5, 'board-games' => 5, 'hyperbitcoinization' => 5],
            'pairlimit' => [1, 3],
            'subtree' => 90,
            'moves' => 20,
        ])
        ->and(array_sum($defaults['shares']))->toBe(100)
        ->and(array_filter(ChainDraft::warnings($defaults), fn (string $warning): bool => str_contains($warning, 'The shares add up')))->toBe([])
        // Rocket League and EA Sports FC keep their rows in the form: the board can still give them a weight.
        ->and(chainDefaultsKeysOf($defaults['weights'], 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27'))->toBe([])
        ->and(array_keys(ChainDraft::table($defaults)))->toContain('rocket-league', 'ea-sports-fc', HyperMining::GAME)
        // Hyperbitcoinization is in the draft: nothing is left to propose.
        ->and(ChainDraft::proposal(HyperMining::GAME, $defaults))->toBeNull();

    // Six eras of 28 days in 24 weeks: a weight-1 win pays 2 100, 1 050, 525, 262, 131 and 65 sats.
    $season = SeasonParameters::fromDraft($defaults, 'pre-season', CarbonImmutable::parse('2026-11-20 19:00:00'));

    expect($season->eras())->toBe(6)
        ->and(array_map(fn (int $era): int => $season->rewardPerPlayer(1000, $era), range(1, 6)))->toBe([2100, 1050, 525, 262, 131, 65]);

    // Block 0 signs these weights, shares and daily limits, and nothing of a game that does not mine.
    $tags = SeasonRelease::parameterTags(SeasonRelease::draft(), 1_790_353_500);

    expect(array_values(array_filter($tags, fn (array $tag): bool => in_array($tag[0], ['share', 'daily'], true))))->toBe([
        ['share', 'chess', '50'], ['share', 'board-games', '30'], ['share', 'hyperbitcoinization', '20'],
        ['daily', 'chess', '5'], ['daily', 'board-games', '5'], ['daily', 'hyperbitcoinization', '5'],
    ])
        ->and($tags)->toContain(['weight', 'hyperbitcoinization/live', '1'], ['weight', 'hyperbitcoinization/correspondence', '1'], ['weight', 'chess/blitz', '1'], ['halving', '2419200'])
        ->and(array_filter($tags, fn (array $tag): bool => $tag[0] === 'weight' && (str_starts_with($tag[1], 'rocket-league/') || str_starts_with($tag[1], 'ea-sports-fc'))))->toBe([]);
});

test('a game that is switched off is left out of the defaults, and the draft warns about the share nobody can mine', function (bool $boards, bool $hyper, array $shares, ?string $warning) {
    if ($boards) {
        BlockliOn::play();
    }

    if ($hyper) {
        HyperOn::play();
    }

    $defaults = ChainDraft::defaults();

    expect($defaults['shares'])->toBe($shares)
        ->and($defaults['daily'])->toBe(array_fill_keys(array_keys($shares), 5))
        ->and(chainDefaultsKeysOf($defaults['weights'], HyperMining::GAME))->toBe($hyper ? ['hyperbitcoinization/live', 'hyperbitcoinization/correspondence'] : [])
        ->and(chainDefaultsKeysOf($defaults['weights'], 'blockli'))->toBe($boards ? ['blockli/blitz', 'blockli/rapid', 'blockli/correspondence'] : [])
        ->and(array_values(array_filter(ChainDraft::warnings($defaults), fn (string $line): bool => str_contains($line, 'The shares add up'))))->toBe($warning === null ? [] : [$warning]);

    // Nothing of a game that is switched off would be signed.
    $signed = array_merge(...array_map(fn (array $tag): array => in_array($tag[0], ['weight', 'share', 'daily'], true) ? [explode('/', $tag[1], 2)[0]] : [], SeasonRelease::parameterTags(SeasonRelease::draft(), 1_790_353_500)));

    expect(in_array(HyperMining::GAME, $signed, true))->toBe($hyper)
        ->and(in_array('board-games', $signed, true))->toBe($boards);
})->with([
    'board games and Hyperbitcoinization on' => [true, true, ['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20], null],
    'Hyperbitcoinization off' => [true, false, ['chess' => 50, 'board-games' => 30], 'The shares add up to 80 %: 20 % of every era budget cannot be mined.'],
    'board games off' => [false, true, ['chess' => 50, 'hyperbitcoinization' => 20], 'The shares add up to 70 %: 30 % of every era budget cannot be mined.'],
    'both off' => [false, false, ['chess' => 50], 'The shares add up to 50 %: 50 % of every era budget cannot be mined.'],
]);

test('the draft offers Rocket League and EA Sports FC as proposals; a filled-in proposal saves within the limits', function (string $which, array $weights, int $share, array $others) {
    BlockliOn::play();
    HyperOn::play();

    $proposal = ChainDraft::proposal($which, ChainDraft::defaults());

    expect(ChainDraft::proposalNames())->toContain($which)
        ->and($proposal['weights'])->toEqual($weights)
        ->and($proposal['shares'])->toBe([...$others, $which => $share])
        ->and(array_sum($proposal['shares']))->toBe(100)
        ->and($proposal['daily'])->toBe([$which => 5]);

    $page = Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="'.$which.'-proposal"', false)
        ->assertSet('draftShares.'.$which, '')
        ->call('fillProposal', $which)
        ->assertSet('draftShares.'.$which, (string) $share)
        ->assertSet('draftDaily.'.$which, '5')
        ->tap(fn () => expect(ChainDraft::stored())->toBeNull())
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->assertDontSee('data-test="'.$which.'-proposal"', false);

    foreach ($weights as $key => $milli) {
        $page->assertSet('draftWeights.'.$key, SeasonRelease::factor($milli));
    }

    expect(array_intersect_key(ChainDraft::stored()['weights'], $weights))->toEqual($weights)
        ->and(ChainDraft::stored()['shares'])->toEqual([...$others, $which => $share])
        ->and(ChainDraft::stored()['daily'][$which])->toBe(5)
        ->and(ChainDraft::proposal($which, ChainDraft::current()))->toBeNull();
})->with([
    'Rocket League' => ['rocket-league', ['rocket-league/3v3' => 1000, 'rocket-league/2v2' => 1000, 'rocket-league/1v1' => 1000], 36, ['chess' => 32, 'board-games' => 19, 'hyperbitcoinization' => 13]],
    'EA Sports FC' => ['ea-sports-fc', ['ea-sports-fc-26/1v1' => 1000, 'ea-sports-fc-26/2v2' => 1000, 'ea-sports-fc-27/1v1' => 1000, 'ea-sports-fc-27/2v2' => 1000], 22, ['chess' => 39, 'board-games' => 23, 'hyperbitcoinization' => 16]],
]);

test('a draft saved while Hyperbitcoinization was switched off is offered the game as a proposal: its default numbers, the shares at 100 %', function () {
    BlockliOn::play();
    saveChainDraft(['message' => 'Block 0: every fair win is a block']);

    expect(ChainDraft::stored()['shares'])->toBe(['chess' => 50, 'board-games' => 30]);

    HyperOn::play();
    $proposal = ChainDraft::proposal(HyperMining::GAME, ChainDraft::current());

    expect($proposal)->toBe([
        'weights' => ['hyperbitcoinization/live' => 1000, 'hyperbitcoinization/correspondence' => 1000],
        'shares' => ['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20],
        'daily' => ['hyperbitcoinization' => 5],
    ])
        ->and(array_sum($proposal['shares']))->toBe(100);

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="hyperbitcoinization-proposal"', false)
        ->call('fillProposal', HyperMining::GAME)
        ->assertSet('draftWeights.hyperbitcoinization/live', '1')
        ->assertSet('draftShares.hyperbitcoinization', '20')
        ->assertSet('draftShares.chess', '50')
        ->assertSet('draftDaily.hyperbitcoinization', '5')
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->assertDontSee('data-test="hyperbitcoinization-proposal"', false);

    expect(ChainDraft::stored()['shares'])->toEqual(['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20])
        ->and(ChainDraft::stored()['weights']['hyperbitcoinization/correspondence'])->toBe(1000)
        ->and(ChainDraft::proposal(HyperMining::GAME, ChainDraft::current()))->toBeNull();
});

test('while Hyperbitcoinization is switched off, neither the draft nor a live season offers its proposal', function () {
    expect(ChainDraft::proposal(HyperMining::GAME, ChainDraft::defaults()))->toBeNull();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertDontSee('data-test="hyperbitcoinization-proposal"', false)
        ->call('fillProposal', HyperMining::GAME)
        ->assertSet('draftWeights.chess/blitz', '1');

    openSeason();

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertDontSee('data-test="hyperbitcoinization-change-proposal"', false);
});

test('a live season offers Hyperbitcoinization as a change: filled in it saves nothing, saved it is a parameter change and no ladder', function () {
    BlockliOn::play();
    HyperOn::play();
    // A season released while the game was switched off: chess and the board games alone.
    $defaults = ChainDraft::defaults();
    $season = openSeason(['parameters' => [...$defaults, 'weights' => array_diff_key($defaults['weights'], config('season.chain.hyperbitcoinization_proposal.weights')), 'shares' => ['chess' => 60, 'board-games' => 40], 'daily' => ['chess' => 5, 'board-games' => 5]]]);

    expect(HyperMining::weight('live'))->toBe(0);

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="hyperbitcoinization-change-proposal"', false)
        ->assertSet('weights.hyperbitcoinization/live', '')
        ->call('fillHyperChange')
        ->assertSet('weights.hyperbitcoinization/live', '1')
        ->assertSet('weights.hyperbitcoinization/correspondence', '1')
        ->assertSet('shares.hyperbitcoinization', '20')
        ->assertSet('shares.chess', '48')
        ->assertSet('shares.board-games', '32')
        ->assertSet('daily.hyperbitcoinization', '5')
        ->tap(fn () => expect(SeasonParameterChange::query()->count())->toBe(0))
        ->set('reason', 'Hyperbitcoinization joins the season.')
        ->call('saveChange')
        ->assertSet('changeError', '')
        ->assertDontSee('data-test="hyperbitcoinization-change-proposal"', false);

    expect(SeasonParameterChange::query()->sole()->parameters)->toEqual([
        'weights' => ['hyperbitcoinization/live' => 1000, 'hyperbitcoinization/correspondence' => 1000],
        'shares' => ['chess' => 48, 'board-games' => 32, 'hyperbitcoinization' => 20],
        'daily' => ['hyperbitcoinization' => 5],
    ])
        ->and(HyperMining::weight('live'))->toBe(1000)
        ->and(HyperMining::weight('correspondence'))->toBe(1000)
        // The change is the league's 2158; the game has no ladder event (NIP rev. 9.24 and 9.26).
        ->and(NostrEvent::query()->where('kind', SeasonChains::PARAMETER_CHANGE)->count())->toBe(1)
        ->and(NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', 'hyperbitcoinization/%')->count())->toBe(0)
        ->and($season->refresh()->chainParameters()->inForceAt(now()->toImmutable())->weightFor('hyperbitcoinization/live'))->toBe(1000);
});

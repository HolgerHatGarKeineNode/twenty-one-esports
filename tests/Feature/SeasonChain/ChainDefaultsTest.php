<?php

/*
 * The defaults of the chain draft since 2026-11-18 (user decision): chess and
 * the board games alone mine at Block 0, at 60 and 40 %. Rocket League, EA
 * Sports FC and Hyperbitcoinization (NIP rev. 9.26, draft) are proposals of
 * the admin season page: for the draft, and Hyperbitcoinization for a live
 * season too. Nothing of a proposal is in force until the board saves it.
 */

use App\Models\NostrEvent;
use App\Models\SeasonParameterChange;
use App\Models\User;
use App\Support\Hyper\HyperMining;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\Series\Ladders;
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

test('by default only chess and the board games mine at Block 0, at 60 and 40 %; with the board games off the draft warns about the 40 %', function () {
    $series = ['rocket-league', 'ea-sports-fc', 'ea-sports-fc-26', 'ea-sports-fc-27', HyperMining::GAME];
    $off = ChainDraft::defaults();

    expect(chainDefaultsKeysOf($off['weights'], ...$series))->toBe([])
        ->and($off['shares'])->toBe(['chess' => 60])
        ->and($off['daily'])->toBe(['chess' => 5])
        ->and(ChainDraft::warnings($off))->toContain('The shares add up to 60 %: 40 % of every era budget cannot be mined.');

    BlockliOn::play();
    NineMensMorrisOn::play();
    CheckersGame::play();
    HyperOn::play();
    $on = ChainDraft::defaults();

    expect(chainDefaultsKeysOf($on['weights'], ...$series))->toBe([])
        ->and($on['shares'])->toBe(['chess' => 60, 'board-games' => 40])
        ->and(array_sum($on['shares']))->toBe(100)
        ->and($on['daily'])->toBe(['chess' => 5, 'board-games' => 5])
        ->and(array_filter(ChainDraft::warnings($on), fn (string $warning): bool => str_contains($warning, 'The shares add up')))->toBe([])
        // The series games and Hyperbitcoinization keep their rows in the form: the board can still give them a weight.
        ->and(array_keys(ChainDraft::table($on)))->toContain('rocket-league', 'ea-sports-fc', HyperMining::GAME);

    // Block 0 would sign no weight, share or daily limit of a game that does not mine.
    $tags = SeasonRelease::parameterTags(SeasonRelease::draft(), 1_790_353_500);
    $signed = array_merge(...array_map(fn (array $tag): array => in_array($tag[0], ['weight', 'share', 'daily'], true) ? [$tag[1]] : [], $tags));

    expect(array_filter($signed, fn (string $key): bool => in_array(explode('/', $key, 2)[0], $series, true)))->toBe([])
        ->and($signed)->toContain('chess/blitz', 'chess', 'board-games');
});

test('the draft offers Rocket League, EA Sports FC and Hyperbitcoinization as proposals; a filled-in proposal saves within the limits', function (string $which, array $weights, int $share, array $others) {
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
    'Rocket League' => ['rocket-league', ['rocket-league/3v3' => 1000, 'rocket-league/2v2' => 1000, 'rocket-league/1v1' => 1000], 36, ['chess' => 38, 'board-games' => 26]],
    'EA Sports FC' => ['ea-sports-fc', ['ea-sports-fc-26/1v1' => 1000, 'ea-sports-fc-26/2v2' => 1000, 'ea-sports-fc-27/1v1' => 1000, 'ea-sports-fc-27/2v2' => 1000], 22, ['chess' => 47, 'board-games' => 31]],
    'Hyperbitcoinization' => [HyperMining::GAME, ['hyperbitcoinization/live' => 2000, 'hyperbitcoinization/correspondence' => 2000], 10, ['chess' => 54, 'board-games' => 36]],
]);

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
    $season = openSeason(['parameters' => [...ChainDraft::defaults(), 'shares' => ['chess' => 60, 'board-games' => 40], 'daily' => ['chess' => 5, 'board-games' => 5]]]);

    expect(HyperMining::weight('live'))->toBe(0);

    Livewire::actingAs($this->board)->test('pages::admin.season')
        ->assertSee('data-test="hyperbitcoinization-change-proposal"', false)
        ->assertSet('weights.hyperbitcoinization/live', '')
        ->call('fillHyperChange')
        ->assertSet('weights.hyperbitcoinization/live', '2')
        ->assertSet('weights.hyperbitcoinization/correspondence', '2')
        ->assertSet('shares.hyperbitcoinization', '10')
        ->assertSet('shares.chess', '54')
        ->assertSet('shares.board-games', '36')
        ->assertSet('daily.hyperbitcoinization', '5')
        ->tap(fn () => expect(SeasonParameterChange::query()->count())->toBe(0))
        ->set('reason', 'Hyperbitcoinization joins the season.')
        ->call('saveChange')
        ->assertSet('changeError', '')
        ->assertDontSee('data-test="hyperbitcoinization-change-proposal"', false);

    expect(SeasonParameterChange::query()->sole()->parameters)->toEqual([
        'weights' => ['hyperbitcoinization/live' => 2000, 'hyperbitcoinization/correspondence' => 2000],
        'shares' => ['chess' => 54, 'board-games' => 36, 'hyperbitcoinization' => 10],
        'daily' => ['hyperbitcoinization' => 5],
    ])
        ->and(HyperMining::weight('live'))->toBe(2000)
        ->and(HyperMining::weight('correspondence'))->toBe(2000)
        // The change is the league's 2158; the game has no ladder event (NIP rev. 9.24 and 9.26).
        ->and(NostrEvent::query()->where('kind', SeasonChains::PARAMETER_CHANGE)->count())->toBe(1)
        ->and(NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', 'hyperbitcoinization/%')->count())->toBe(0)
        ->and($season->refresh()->chainParameters()->inForceAt(now()->toImmutable())->weightFor('hyperbitcoinization/live'))->toBe(2000);
});

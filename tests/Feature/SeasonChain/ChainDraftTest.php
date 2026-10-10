<?php

/*
 * The chain draft for Block 0 (P43, ChainDraft): every value the Season
 * Genesis signs is edited on the admin season page by the board, logged,
 * locked while a season is live, validated (refuse / warn), previewed by the
 * functions that sign it, bound to the release by its hash, and the one
 * source of the home page, the rules and the countdown. During a season a
 * Parameter Change (2158) may add a game.
 */

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonSettingChange;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create(['timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)]]);
});

/** The admin page as the board, with the chain draft form filled from the defaults. */
function draftPage(User $user): Testable
{
    return Livewire::actingAs($user)->test('pages::admin.season');
}

test('the board edits the chain draft on the page: every registry game and mode in the table, logged with who changed what', function () {
    $page = draftPage($this->board)
        ->assertSee('data-test="season-chain-draft"', false)
        ->assertSee('data-test="draft-row-ea-sports-fc"', false)
        ->assertSee('data-test="draft-weight-ea-sports-fc-26-2v2"', false)
        ->assertSet('draftShares', ['chess' => '35', 'rocket-league' => '40', 'ea-sports-fc' => '25', 'age-of-empires-2' => ''])
        ->assertSet('draftWeights.ea-sports-fc-27/1v1', '1')
        ->set('draft.block0_at', '2026-10-10T19:00')
        ->set('draft.weeks', '12')
        ->set('draft.supply', '1 000 000')
        ->set('draft.subsidy', '1 000')
        ->set('draft.message', 'Block 0: every fair win is a block')
        ->set('draftWeights.rocket-league/1v1', '')
        ->call('saveDraft')
        ->assertSet('draftError', '');

    $chain = ChainDraft::stored();
    $change = SeasonSettingChange::query()->latest('id')->first();

    expect($chain)->toMatchArray(['block0_at' => CarbonImmutable::parse('2026-10-10T19:00:00Z')->getTimestamp(), 'weeks' => 12, 'supply' => 1_000_000, 'subsidy' => 1_000, 'message' => 'Block 0: every fair win is a block'])
        ->and($chain['weights'])->not->toHaveKey('rocket-league/1v1')
        ->and($chain['groups'])->toBe(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']])
        ->and($change->changed_by_id)->toBe($this->board->id)
        ->and($change->changes)->toMatchArray(['chain.supply' => [2_100_000, 1_000_000], 'chain.weeks' => [24, 12], 'chain.weights.rocket-league/1v1' => [1000, null]])
        // The rating draft in the same row is untouched.
        ->and(RatingSettings::draft())->toBe(RatingSettings::defaults());

    $page->assertSee('Supply in sats: 2100000 → 1000000')
        ->assertSee('Rocket League 1v1 weight: 1000 → –');
});

test('only the board may save the chain draft, and it is locked while a season is live', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    draftPage($admin)
        ->assertSee('data-test="draft-board-only"', false)
        ->call('saveDraft')
        ->assertSet('draftError', 'Only a board member on the public admin list can change these values.');

    expect(SeasonSettingChange::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create())->get(route('admin.season'))->assertForbidden();

    openSeason();

    expect(fn () => ChainDraft::save($this->board, ChainDraft::defaults()))->toThrow(SeasonReleaseRefused::class, 'A season has been released')
        ->and(SeasonSettingChange::query()->count())->toBe(0);

    $this->actingAs($this->board)->get(route('admin.season'))->assertOk()->assertDontSee('data-test="season-chain-draft"', false);
});

test('the draft refuses what Block 0 must never sign', function (array $set, string $error) {
    $page = draftPage($this->board);

    foreach ($set as $key => $value) {
        $page->set($key, $value);
    }

    $page->call('saveDraft')->assertSet('draftError', fn (string $message): bool => str_contains($message, $error));

    expect(ChainDraft::stored())->toBeNull();
})->with([
    'a weight above 10' => [['draftWeights.chess/blitz' => '10.5'], 'weight of Chess blitz'],
    'a share of 0' => [['draftShares.chess' => '0'], 'Chess share % must be a whole number from 1 to 100'],
    'a daily limit above 100' => [['draftDaily.rocket-league' => '101'], 'Rocket League a day must be a whole number from 1 to 100'],
    'a supply below the minimum' => [['draft.supply' => '999'], 'Supply must be a whole number from 1000'],
    'the trust circle above 101' => [['draft.subtree' => '102'], 'from 1 to 101'],
    'a length outside the planner range' => [['draft.weeks' => '2'], 'The length is a whole number of weeks'],
    'a mining game without a share' => [['draftShares.ea-sports-fc' => ''], 'EA Sports FC mines, so it needs a share and a daily limit'],
    'a mining game without a daily limit' => [['draftDaily.chess' => ''], 'Chess mines, so it needs a share and a daily limit'],
    'shares above 100 %' => [['draftShares.chess' => '36'], 'The shares add up to 101 %'],
    'an era longer than the season' => [['draft.weeks' => '8', 'draft.halving_days' => '60'], 'An era of 60 days is longer than the season of 8 weeks'],
    'a message above 280 characters' => [['draft.message' => str_repeat('x', 281)], 'up to 280 characters'],
]);

test('Age of Empires II mines only once the board takes up its draft proposal: weight 1 per mode, 10 % and 5 a day, the other shares shrunk', function () {
    draftPage($this->board)
        ->assertSee('data-test="age-of-empires-2-proposal"', false)
        ->assertSee('Draft proposal, not decided yet')
        ->assertSet('draftWeights.age-of-empires-2/3v3', '')
        ->call('fillProposal', 'age-of-empires-2')
        ->assertSet('draftWeights.age-of-empires-2/1v1', '1')
        ->assertSet('draftWeights.age-of-empires-2/3v3', '1')
        ->assertSet('draftShares', ['chess' => '32', 'rocket-league' => '36', 'ea-sports-fc' => '22', 'age-of-empires-2' => '10'])
        ->assertSet('draftDaily.age-of-empires-2', '5')
        // Filling in saves nothing; the board saves it.
        ->tap(fn () => expect(ChainDraft::stored())->toBeNull())
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->assertDontSee('data-test="age-of-empires-2-proposal"', false);

    expect(ChainDraft::stored()['shares'])->toBe(['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, 'age-of-empires-2' => 10])
        ->and(ChainDraft::stored()['weights'])->toMatchArray(['age-of-empires-2/1v1' => 1000, 'age-of-empires-2/2v2' => 1000, 'age-of-empires-2/3v3' => 1000]);
});

test('chess rapid mines only once the board takes up its proposal: weight 1.5 in the chess share, the other shares unchanged', function () {
    draftPage($this->board)
        ->assertSee('data-test="chess-rapid-proposal"', false)
        ->assertSee('Chess rapid does not mine in this draft. Proposal: weight 1.5')
        ->assertSet('draftWeights.chess/rapid', '')
        ->call('fillProposal', 'chess-rapid')
        ->assertSet('draftWeights.chess/rapid', '1.5')
        ->assertSet('draftWeights.chess/blitz', '1')
        ->assertSet('draftShares', ['chess' => '35', 'rocket-league' => '40', 'ea-sports-fc' => '25', 'age-of-empires-2' => ''])
        ->assertSee('The proposal for Chess rapid is filled in.')
        ->tap(fn () => expect(ChainDraft::stored())->toBeNull())
        ->call('saveDraft')
        ->assertSet('draftError', '')
        ->assertDontSee('data-test="chess-rapid-proposal"', false);

    expect(ChainDraft::stored()['weights'])->toMatchArray(['chess/blitz' => 1000, 'chess/rapid' => 1500, 'chess/correspondence' => 2000])
        ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 25])
        ->and(ChainDraft::stored()['daily'])->toBe(['chess' => 5, 'rocket-league' => 5, 'ea-sports-fc' => 5]);
});

test('the page warns about what may be released: shares below 100 %, a game without rated play, a mode without a weight, and the estimator', function () {
    config(['esports.chess.rated_queue' => false]);
    saveChainDraft(['shares' => ['chess' => 30, 'rocket-league' => 40, 'ea-sports-fc' => 20], 'weights' => array_diff_key(ChainDraft::defaults()['weights'], ['rocket-league/1v1' => true])]);

    $warnings = ChainDraft::warnings(ChainDraft::current(), ['most-of-the-supply-stays-unmined']);

    expect($warnings)->toBe([
        'The shares add up to 90 %: 10 % of every era budget cannot be mined.',
        'Chess blitz has a weight, but its rated play is off, so its wins do not mine until it opens.',
        'Chess daily has a weight, but its rated play is off, so its wins do not mine until it opens.',
        // Age of Empires II mines only once the board takes up its draft proposal (ChainDraft::proposal()).
        'No weight, so these do not mine: Chess rapid, Rocket League 1v1, Age of Empires II: Definitive Edition 1v1, Age of Empires II: Definitive Edition 2v2, Age of Empires II: Definitive Edition 3v3.',
        'At this rate most of the supply stays unmined.',
    ]);

    draftPage($this->board)->assertSeeInOrder(['data-test="season-preview"', 'The shares add up to 90 %', 'No weight, so these do not mine: Chess rapid, Rocket League 1v1, Age of Empires II'], false);
});

test('the preview is what Block 0 signs: the same genesis tags, the ladder values and the trust gate', function () {
    saveChainDraft(['message' => 'Preview equals release', 'subsidy' => 1_500, 'shares' => ['chess' => 30, 'rocket-league' => 45, 'ea-sports-fc' => 25]]);

    $page = draftPage($this->board);
    $preview = $page->instance()->preview();
    $page->assertSee('Preview equals release')
        ->assertSee('[&quot;group&quot;,&quot;ea-sports-fc&quot;,&quot;ea-sports-fc-26&quot;,&quot;ea-sports-fc-27&quot;]', false)
        // Era 1: budget 1 050 000, chess 30 % = 315 000, 1 500 per blitz win: 210 wins; FC 2v2 pays two winners.
        ->assertSeeInOrder(['data-test="preview-wins"', 'Chess blitz', '>210<'], false);

    $page->set('supply', '2 100 000');
    $page->call('release', json_encode($this->boardSigner->signTemplates($page->instance()->prepareRelease())))->assertSet('releaseError', '');

    $genesis = SignedEvent::fromInput(NostrEvent::query()->where('kind', SeasonChains::GENESIS)->sole()->payload());
    $covered = array_values(array_filter($genesis->tags, fn (array $tag): bool => in_array($tag[0], ['season', 'supply', 'subsidy', 'weight', 'group', 'share', 'daily', 'pairlimit', 'subtree', 'moves', 'halving', 'ends', 'claim', 'consensus'], true)));
    $ladder = NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'ea-sports-fc-27/1v1/pre-season'])->sole()->payload()['tags'];

    expect($covered)->toBe($preview['genesis'])
        ->and($genesis->content)->toBe('Preview equals release')
        ->and($genesis->tagsNamed('group'))->toBe([['ea-sports-fc', 'ea-sports-fc-26', 'ea-sports-fc-27']])
        ->and($ladder)->toContain(...$preview['ladder'])
        ->and($ladder)->toContain($preview['trust'])
        ->and(Season::query()->sole()->parameters['groups'])->toBe(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']]);
});

test('a genesis without share groups signs exactly the tags and digest of NIP rev. 5', function () {
    $draft = [
        'slug' => 'pre-season', 'supply' => 1_000_000, 'subsidy' => 2_100, 'halving_seconds' => 900, 'claim_seconds' => 7_776_000,
        'parameters' => ['weights' => ['chess/blitz' => 1000, 'chess/correspondence' => 2000, 'rocket-league/2v2' => 2500], 'groups' => [], 'shares' => ['chess' => 50, 'rocket-league' => 50],
            'daily' => ['chess' => 10, 'rocket-league' => 10], 'pairlimit' => [3, 5], 'subtree' => 90, 'moves' => 20],
    ];
    $tags = SeasonRelease::parameterTags($draft, 1_790_353_500);
    $content = '25/Sep/2026 TWENTY ONE Esports: Chancellor on brink of second checkmate. Block 0 of the Pre-Season. Demo season of the protocol examples: eras of 15 minutes, forty minutes in total.';

    // The tags of the NIP rev. 5 example genesis, and its digest.
    expect($tags)->toBe([['season', 'pre-season'], ['supply', '1000000'], ['subsidy', '2100'], ['weight', 'chess/blitz', '1'], ['weight', 'chess/correspondence', '2'],
        ['weight', 'rocket-league/2v2', '2.5'], ['share', 'chess', '50'], ['share', 'rocket-league', '50'], ['daily', 'chess', '10'], ['daily', 'rocket-league', '10'],
        ['pairlimit', '3', '5'], ['subtree', '90'], ['moves', '20'], ['halving', '900'], ['ends', '1790353500'], ['claim', '7776000'], ['consensus', 'season-chain-v1']])
        ->and(SeasonRelease::digest($content, $tags))->toBe('82f1fa0c62e7aa3efd843b7b5251a697a7d05abeb48a6b9460a80df9324d0ca1')
        // A group is covered by the digest: the same draft with one signs another digest.
        ->and(SeasonRelease::digest($content, SeasonRelease::parameterTags([...$draft, 'parameters' => [...$draft['parameters'], 'groups' => ['rl' => ['rocket-league', 'chess']]]], 1_790_353_500)))
        ->not->toBe('82f1fa0c62e7aa3efd843b7b5251a697a7d05abeb48a6b9460a80df9324d0ca1');
});

test('the release is bound to the draft the page showed: a draft changed after prepare is refused and nothing is signed', function () {
    saveChainDraft(['message' => 'Bound to the draft']);

    $page = draftPage($this->board)->set('supply', '2 100 000');
    $templates = $page->instance()->prepareRelease();
    expect($templates)->not->toBeNull();

    // Another board member saves a new subsidy between prepare and release.
    saveChainDraft([...ChainDraft::stored(), 'subsidy' => 3_000]);

    $page->call('release', json_encode($this->boardSigner->signTemplates($templates)))
        ->assertSet('releaseError', 'The draft changed since you opened this page. Check the numbers again, then release.');

    expect(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);

    // The page read before the change cannot even prepare; a fresh page can.
    expect($page->instance()->prepareRelease())->toBeNull()
        ->and(draftPage($this->board)->set('supply', '2 100 000')->instance()->prepareRelease())->not->toBeNull();

    // A rating value is part of the draft, too.
    $page = draftPage($this->board)->set('supply', '2 100 000');
    RatingSettings::saveDraft($this->board, array_replace_recursive(RatingSettings::defaults(), ['rating' => ['k' => 30]]));

    expect($page->instance()->prepareRelease())->toBeNull()
        ->and($page->instance()->releaseError)->toContain('The draft changed');
});

test('a draft saved while the release is signing is caught inside the release transaction', function () {
    saveChainDraft(['message' => 'Bound to the draft']);
    $release = app(SeasonRelease::class);
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $hash = SeasonRelease::draftHash();
    $signed = $this->boardSigner->signTemplates([$release->prepare($this->board, '2100000', $endsAt, $hash)]);

    // The rival save of another process lands after the checks of release(), as its transaction
    // begins: a plain insert, so no model event forgets this request's memo of the draft.
    $rival = false;
    Event::listen(TransactionBeginning::class, function () use (&$rival): void {
        if (! $rival) {
            $rival = true;
            DB::table('season_setting_changes')->insert(['changed_by_pubkey' => str_repeat('c', 64), 'changes' => '[]', 'created_at' => now(), 'updated_at' => now(),
                'values' => json_encode([...RatingSettings::draft(), 'chain' => [...ChainDraft::stored(), 'claim_days' => 30]])]);
        }
    });

    expect(fn () => $release->release($this->board, '2100000', $endsAt, $hash, $signed))->toThrow(SeasonReleaseRefused::class, 'The draft changed')
        ->and($rival)->toBeTrue()
        ->and(Season::query()->count())->toBe(0);
});

test('without a genesis message in the draft Block 0 is refused', function () {
    $page = draftPage($this->board)->set('supply', '2 100 000');

    expect($page->instance()->prepareRelease())->toBeNull()
        ->and($page->instance()->releaseError)->toContain('Write the genesis message in the chain draft first');
});

test('a rule change during the season may add a game with its weight, share and daily limit together', function () {
    $admin = $this->board;
    $parameters = Season::factory()->make()->parameters;
    // A season released without EA Sports FC.
    openSeason(['parameters' => [
        ...$parameters,
        'weights' => array_filter($parameters['weights'], fn (string $key): bool => ! str_starts_with($key, 'ea-sports-fc'), ARRAY_FILTER_USE_KEY),
        'shares' => ['chess' => 35, 'rocket-league' => 40],
        'daily' => ['chess' => 5, 'rocket-league' => 5],
    ]]);
    $chains = app(SeasonChains::class);

    expect(fn () => $chains->changeParameters($admin, ['weights' => ['ea-sports-fc-27/1v1' => 1000]], 'FC joins.', CarbonImmutable::now()))
        ->toThrow(SeasonReleaseRefused::class, 'EA Sports FC mines, so it needs a share and a daily limit in the same change')
        ->and(fn () => $chains->changeParameters($admin, ['weights' => ['ea-sports-fc-27/1v1' => 1000], 'shares' => ['ea-sports-fc' => 30], 'daily' => ['ea-sports-fc' => 5]], 'FC joins.', CarbonImmutable::now()))
        ->toThrow(SeasonReleaseRefused::class, 'The shares add up to 105 %')
        ->and(fn () => $chains->changeParameters($admin, ['weights' => ['ea-sports-fc-27/9v9' => 1000]], 'x', CarbonImmutable::now()))
        ->toThrow(SeasonReleaseRefused::class, 'out of range')
        ->and(fn () => $chains->changeParameters($admin, ['shares' => ['ea-sports-fc-27' => 10]], 'x', CarbonImmutable::now()))
        ->toThrow(SeasonReleaseRefused::class, 'out of range');

    $change = $chains->changeParameters($admin, ['weights' => ['ea-sports-fc-27/1v1' => 1000, 'ea-sports-fc-26/1v1' => 1000], 'shares' => ['ea-sports-fc' => 25], 'daily' => ['ea-sports-fc' => 5]], 'Both FC editions join.', CarbonImmutable::now());
    $event = SignedEvent::fromInput($change->nostrEvent->payload());
    $inForce = Season::query()->sole()->chainParameters()->inForceAt(CarbonImmutable::now()->addMinute());

    expect($event->tagsNamed('weight'))->toBe([['ea-sports-fc-27/1v1', '1'], ['ea-sports-fc-26/1v1', '1']])
        ->and($event->tagsNamed('share'))->toBe([['ea-sports-fc', '25']])
        ->and($event->tagsNamed('daily'))->toBe([['ea-sports-fc', '5']])
        ->and($inForce->weightFor('ea-sports-fc-26/1v1'))->toBe(1000)
        ->and($inForce->dailyLimitFor('ea-sports-fc-27'))->toBe(5);
});

test('the Season page, the rules and the countdown read the saved draft; before one is saved they name no supply', function () {
    $this->get(route('mining'))->assertOk()->assertDontSee('data-test="home-supply"', false);
    $this->get(route('rules'))->assertOk()->assertDontSee('Supply, the most paid out after the season');

    $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
    saveChainDraft(['block0_at' => CarbonImmutable::parse('2026-10-03T12:00:00Z')->getTimestamp(), 'supply' => 1_234_567, 'message' => 'Chancellor on the brink of Block 0', 'moves' => 25, 'daily' => ['chess' => 4, 'rocket-league' => 4, 'ea-sports-fc' => 4]]);

    // Home: the countdown to the saved Block 0; the Season page: the supply, the genesis message and the rules.
    $this->get(route('home'))->assertOk()->assertSee('data-state="countdown"', false);
    $this->get(route('mining'))->assertOk()
        ->assertSee("1\u{00A0}234\u{00A0}567", false)
        ->assertSee('Up to 1')
        ->assertSee('nothing is set aside before')
        ->assertSee('Chancellor on the brink of Block 0')
        ->assertSee('25 moves or more in chess')
        ->assertSee('At most 4 blocks per player a day, per game')
        ->assertDontSee('sats in the Pre-Season pot');

    $this->get(route('rules'))->assertOk()
        ->assertSee('Supply, the most paid out after the season')
        ->assertSee("1\u{00A0}234\u{00A0}567 sats", false)
        ->assertSee('EA Sports FC 27 1v1');
});

test('no Pre-Season setting is read from the environment any more', function () {
    $hits = [];

    foreach (['app', 'config', 'resources', 'routes'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/ESPORTS_(BLOCK0_AT|PRESEASON_POT_SATS|GENESIS_MESSAGE)|preseason\.(block0_at|pot_sats|genesis_message)/', (string) file_get_contents($file->getPathname())) === 1) {
                $hits[] = $file->getPathname();
            }
        }
    }

    expect($hits)->toBe([])
        ->and((string) file_get_contents(base_path('.env.example')))->not->toContain('ESPORTS_BLOCK0_AT')
        ->and(config('esports.preseason'))->toBe(['display_timezone' => 'Europe/Berlin']);
});

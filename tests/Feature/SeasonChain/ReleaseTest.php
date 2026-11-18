<?php

/*
 * Block 0 (P7c, NIP rev. 5 "Season Genesis"): only a board admin on the admin
 * list releases it, after retyping the supply, by signing a release label
 * whose digest the league's genesis must match. Everything is queued for the
 * league relays only (PublishNostrEvent, faked here).
 */

use App\Jobs\NotifyBlockZero;
use App\Jobs\PublishNostrEvent;
use App\Jobs\RunTrustJob;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessQueue;
use App\Support\Engagement\HomeBoard;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\SeasonReview;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

const RELEASE_MESSAGE = '26/Sep/2026 TWENTY ONE Esports: every fair win is a block';

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)]]);

    // The genesis message is part of the board's chain draft (P43).
    saveChainDraft(['message' => RELEASE_MESSAGE]);
    $this->release = app(SeasonRelease::class);
    $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
});

/** Prepare, sign as the admin, release: the browser's three steps. */
function releaseAs(User $admin, TestSigner $signer, string $supply = '2 100 000', ?Closure $tamper = null): Season
{
    $release = app(SeasonRelease::class);
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $template = $release->prepare($admin, $supply, $endsAt, SeasonRelease::draftHash());

    if ($tamper !== null) {
        $template = $tamper($template);
    }

    return $release->release($admin, $supply, $endsAt, SeasonRelease::draftHash(), $signer->signTemplates([$template]));
}

test('the parameter digest is pinned: the example genesis with `eras 6` and `season-chain-v2`, and a `halving` tag is not part of it', function () {
    $tags = [['season', 'pre-season'], ['supply', '1000000'], ['subsidy', '2100'], ['weight', 'chess/blitz', '1'], ['weight', 'chess/correspondence', '2'],
        ['weight', 'rocket-league/2v2', '2.5'], ['share', 'chess', '50'], ['share', 'rocket-league', '50'], ['daily', 'chess', '10'], ['daily', 'rocket-league', '10'],
        ['pairlimit', '3', '5'], ['subtree', '90'], ['moves', '20'], ['eras', '6'], ['ends', '1790353500'], ['claim', '7776000'], ['consensus', 'season-chain-v2'],
        ['a', '31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:season/pre-season', 'wss://relay.example.org'], ['alt', 'Season genesis']];
    $content = '25/Sep/2026 TWENTY ONE Esports: Chancellor on brink of second checkmate. Block 0 of the Pre-Season. Demo season of the protocol examples: eras of 15 minutes, forty minutes in total.';

    expect(SeasonRelease::digest($content, $tags))->toBe('9d0f5be24c8386bce292b0c905306a19007aac13adb26e8f3d8a7d10c3487f8c')
        ->and(SeasonRelease::digest($content, [...$tags, ['halving', '900']]))->toBe('9d0f5be24c8386bce292b0c905306a19007aac13adb26e8f3d8a7d10c3487f8c')
        ->and(SeasonRelease::digest($content, array_map(fn (array $tag): array => $tag[0] === 'eras' ? ['eras', '5'] : $tag, $tags)))->not->toBe('9d0f5be24c8386bce292b0c905306a19007aac13adb26e8f3d8a7d10c3487f8c')
        ->and(SeasonRelease::factor(2500))->toBe('2.5')
        ->and(SeasonRelease::factor(1000))->toBe('1')
        ->and(SeasonRelease::factor(1250))->toBe('1.25');
});

test('a board admin releases Block 0 after retyping the supply: label, admin list, announcement and genesis, queued for the league relays', function () {
    expect(Seasons::state())->toBe('pre-launch')
        ->and(Ladders::isOpen('rocket-league', '3v3'))->toBeFalse();

    $season = releaseAs($this->board, $this->boardSigner);

    $events = NostrEvent::query()->orderBy('id')->get()->keyBy('kind');
    $genesis = SignedEvent::fromInput($events[SeasonChains::GENESIS]->payload());
    $label = SignedEvent::fromInput($events[SeasonRelease::LABEL]->payload());
    $adminList = SignedEvent::fromInput($events[SeasonRelease::ADMIN_LIST]->payload());
    $announcement = SignedEvent::fromInput($events[SeasonRelease::ANNOUNCEMENT]->payload());

    $ladders = NostrEvent::query()->where('kind', Ladders::KIND)->orderBy('d')->pluck('d')->all();

    expect($events->keys()->sort()->values()->all())->toBe([SeasonRelease::LABEL, SeasonChains::GENESIS, SeasonRelease::ADMIN_LIST, SeasonRelease::ANNOUNCEMENT, Ladders::KIND])
        // Every rated game and mode opens its ladder right after the genesis.
        ->and($ladders)->toBe(['age-of-empires-2/1v1/pre-season', 'age-of-empires-2/2v2/pre-season', 'age-of-empires-2/3v3/pre-season', 'chess/blitz/pre-season', 'chess/correspondence/pre-season', 'chess/rapid/pre-season', 'ea-sports-fc-26/1v1/pre-season', 'ea-sports-fc-26/2v2/pre-season', 'ea-sports-fc-27/1v1/pre-season', 'ea-sports-fc-27/2v2/pre-season', 'rocket-league/1v1/pre-season', 'rocket-league/2v2/pre-season', 'rocket-league/3v3/pre-season'])
        ->and($genesis->pubkey)->toBe($this->league->pubkey)
        ->and($genesis->hasValidSignature())->toBeTrue()
        ->and($genesis->content)->toBe(RELEASE_MESSAGE)
        ->and($genesis->tag('supply'))->toBe('2100000')
        ->and($genesis->tag('consensus'))->toBe('season-chain-v2')
        ->and($genesis->tagsNamed('p'))->toBe([[$this->board->pubkey, '', 'release']])
        ->and($genesis->tagsNamed('e'))->toBe([[$adminList->id, '', $this->league->pubkey], [$label->id, '', $this->board->pubkey]])
        ->and($label->pubkey)->toBe($this->board->pubkey)
        ->and($label->createdAt)->toBeLessThanOrEqual($genesis->createdAt)
        ->and($label->tag('x'))->toBe(SeasonRelease::digest($genesis->content, $genesis->tags))
        ->and($adminList->tagsNamed('p'))->toBe([[$this->board->pubkey]])
        // No hashtags (a standing rule): the season's calendar event carries no `t`.
        ->and($announcement->kind)->toBe(31923)
        ->and($announcement->tagsNamed('t'))->toBe([])
        ->and($adminList->tag('d'))->toBe('esports/'.$this->league->pubkey.'/admins')
        ->and($season->genesis_at->getTimestamp())->toBe($genesis->createdAt)
        ->and($season->ends_at->getTimestamp())->toBe((int) $genesis->tag('ends'))
        ->and($season->released_by_id)->toBe($this->board->id)
        ->and(Seasons::state())->toBe('live')
        ->and(Ladders::address('rocket-league', '3v3'))->toBe('32152:'.$this->league->pubkey.':rocket-league/3v3/pre-season');

    // Label, genesis, admin list, announcement and the thirteen ladders (chess rapid among them).
    Queue::assertPushed(PublishNostrEvent::class, 17);
});

test('a release from the untouched defaults signs them; a rated chess blitz win then mines block 1 at 2 100 sats, which the readout and the settlement review count', function () {
    // Production's switches: the board games and Hyperbitcoinization on. Nothing of the draft is edited but the
    // genesis message, which a release cannot do without.
    BlockliOn::play();
    NineMensMorrisOn::play();
    CheckersGame::play();
    HyperOn::play();
    saveChainDraft(['message' => RELEASE_MESSAGE]);

    expect(array_diff_key(ChainDraft::current(), ['message' => null]))->toBe(array_diff_key(ChainDraft::defaults(), ['message' => null]));

    $season = releaseAs($this->board, $this->boardSigner);
    $genesis = SignedEvent::fromInput(NostrEvent::query()->where('kind', SeasonChains::GENESIS)->sole()->payload());

    expect([$genesis->tag('supply'), $genesis->tag('subsidy'), $genesis->tag('eras'), $genesis->tag('claim'), $genesis->tag('consensus')])->toBe(['2100000', '2100', '6', '7776000', 'season-chain-v2'])
        ->and($genesis->tagsNamed('halving'))->toBe([])
        ->and($season->eras)->toBe(6)
        ->and((int) $genesis->tag('ends') - $genesis->createdAt)->toBeGreaterThan(24 * 604800 - 3600)->toBeLessThanOrEqual(24 * 604800)
        ->and($genesis->tagsNamed('share'))->toBe([['chess', '50'], ['board-games', '30'], ['hyperbitcoinization', '20']])
        ->and($genesis->tagsNamed('daily'))->toBe([['chess', '5'], ['board-games', '5'], ['hyperbitcoinization', '5']])
        ->and($genesis->tagsNamed('weight'))->toContain(['chess/blitz', '1'], ['hyperbitcoinization/live', '1'], ['hyperbitcoinization/correspondence', '1'])
        ->and([$genesis->tagsNamed('pairlimit'), $genesis->tag('subtree'), $genesis->tag('moves')])->toBe([[['1', '3']], '90', '20'])
        ->and($season->parameters['shares'])->toBe(['chess' => 50, 'board-games' => 30, 'hyperbitcoinization' => 20])
        ->and($season->minimum_trust)->toBe(50);

    // Two trusted players who list each other meet in the rated blitz queue of the released season.
    config(['esports.chess.rated_queue' => true]);
    app()->instance(TrustFacts::class, new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            $all = array_values(array_unique([...$players, ...$gatekeepers]));

            return [
                'key' => str_repeat('7', 64),
                'trust' => array_fill_keys($all, 100),
                'anchors' => [],
                'assertions' => array_fill_keys($all, str_repeat('a', 64)),
                'lists' => array_fill_keys($all, str_repeat('b', 64)),
                'connected' => true,
            ];
        }
    });

    [$a, $b] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);
    $queue->join($a, 'blitz', rated: true);
    $game = $queue->join($b, 'blitz', rated: true);
    $service = app(ChessGameService::class);

    // 20 full moves of a closed Ruy Lopez (Breyer), enough for consensus rule 2; then Black resigns.
    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6', 'e1g1', 'f8e7', 'f1e1', 'b7b5', 'a4b3', 'd7d6', 'c2c3', 'e8g8', 'h2h3', 'c6b8', 'd2d4', 'b8d7',
        'b1d2', 'c8b7', 'b3c2', 'f8e8', 'd2f1', 'e7f8', 'f1g3', 'g7g6', 'a2a4', 'c7c5', 'd4d5', 'c5c4', 'c1g5', 'h7h6', 'g5e3', 'd7c5', 'd1d2', 'h6h5', 'e3g5', 'f8e7'] as $index => $uci) {
        $game = $service->move($game->refresh(), $index % 2 === 0 ? $game->white : $game->black, $uci);
    }

    $service->resign($game->refresh(), $game->black);
    $attestation = SeasonAttestation::query()->sole();
    $winner = $game->white->pubkey;

    expect($attestation->only(['height', 'reason', 'game', 'mode', 'era', 'reward_per_player', 'reward']))
        ->toBe(['height' => 1, 'reason' => null, 'game' => 'chess', 'mode' => 'blitz', 'era' => 1, 'reward_per_player' => 2100, 'reward' => 2100]);

    $readout = app(SeasonChains::class)->readout($season);

    expect($readout['mined'])->toBe(2100)
        ->and($readout['mined_by_game_and_era'])->toBe(['chess' => [1 => 2100]])
        ->and(array_map(fn (array $block): array => [$block['weight_key'], $block['reward'], $block['winners']], $readout['blocks']))->toBe([['chess/blitz', 2100, 1]])
        ->and($readout['stored']['miners'])->toBe([$winner => ['blocks' => 1, 'sats' => 2100]]);

    $review = app(SeasonSettlement::class)->review($season);

    expect([$review['blocks'], $review['mined'], $review['voided'], $review['payout'], $review['mismatch']])->toBe([1, 2100, 0, 2100, null])
        // The rest is what no block mined: it is on the admin's review and on the public one.
        ->and($review['rest'])->toBe(2_097_900)
        ->and(app(SeasonReview::class)->of($season)['rest'])->toBe(2_097_900)
        ->and(array_map(fn (array $row): array => [$row['pubkey'], $row['blocks'], $row['mined'], $row['payout'], $row['heights']], $review['rows']))->toBe([[$winner, 1, 2100, 2100, [1]]]);
});

test('Block 0 is refused for anyone not on the board list, even an admin of the admins table', function () {
    $signer = new TestSigner;
    $admin = User::factory()->withPubkey($signer->pubkey)->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    expect($admin->isAdmin())->toBeTrue()
        ->and(fn () => releaseAs($admin, $signer))->toThrow(SeasonReleaseRefused::class, 'board member')
        ->and(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('Block 0 is refused with a wrongly retyped supply, without the league key or the trust key, and a second time', function () {
    expect(fn () => releaseAs($this->board, $this->boardSigner, '2 100 001'))->toThrow(SeasonReleaseRefused::class, 'Type the supply')
        ->and(fn () => releaseAs($this->board, $this->boardSigner, ''))->toThrow(SeasonReleaseRefused::class, 'Type the supply');

    config(['esports.league.nsec' => null]);
    expect(fn () => releaseAs($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'league key');

    config(['esports.league.nsec' => $this->league->secret, 'esports.trust.nsec' => null]);
    expect(fn () => releaseAs($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'trust key');

    config(['esports.trust.nsec' => (new TestSigner)->secret]);
    releaseAs($this->board, $this->boardSigner, '2100000');

    expect(fn () => releaseAs($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'Only one chain')
        ->and(Season::query()->count())->toBe(1);
});

test('a label that is not the prepared one (another digest) is refused and nothing is written', function () {
    $tamper = function (array $template): array {
        $template['tags'][3] = ['x', str_repeat('a', 64)];

        return $template;
    };

    expect(fn () => releaseAs($this->board, $this->boardSigner, tamper: $tamper))->toThrow(RejectedEvent::class)
        ->and(Season::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0)
        ->and(Seasons::isLive())->toBeFalse();
});

test('a stale release (the planned end drifted by more than an hour) is refused', function () {
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $template = $this->release->prepare($this->board, '2100000', $endsAt, SeasonRelease::draftHash());
    $this->travel(2)->hours();

    expect(fn () => $this->release->release($this->board, '2100000', $endsAt, SeasonRelease::draftHash(), $this->boardSigner->signTemplates([$template])))
        ->toThrow(SeasonReleaseRefused::class, 'expired');
});

test('the release starts one trust run for the new season, without the admin doing anything', function () {
    releaseAs($this->board, $this->boardSigner);

    Queue::assertPushed(RunTrustJob::class, 1);
});

test('a trust run that is refused or breaks right after Block 0 leaves the season released', function (Closure $break, Closure $noticed) {
    // Only the relay and notification jobs stay faked: the trust run goes through the sync queue, inside release().
    Queue::fake([PublishNostrEvent::class, NotifyBlockZero::class]);
    Http::preventStrayRequests();
    Exceptions::fake();
    Log::spy();
    $break();

    $season = releaseAs($this->board, $this->boardSigner);

    expect(Seasons::live()?->id)->toBe($season->id)
        ->and(TrustRun::query()->count())->toBe(0);
    $noticed();
})->with([
    'refused: the member list cannot be read' => [
        fn () => Http::fake(['*' => Http::response('down', 503)]),
        fn () => Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Trust run after Block 0 refused'))->once(),
    ],
    'broken: the relay reader throws' => [
        fn () => app()->bind(RelayReader::class, fn () => throw new RuntimeException('relay reader broke')),
        fn () => Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'relay reader broke'),
    ],
]);

test('the start page shows the live season right after the release, in German and in English, not the cached Pre-Season card', function () {
    $cardIn = function (string $locale): array {
        app()->setLocale($locale);

        return HomeBoard::season();
    };

    // Both locales read the card before the release: it is cached for a minute.
    expect($cardIn('de')['live'])->toBeFalse()
        ->and($cardIn('en')['live'])->toBeFalse();

    app()->setLocale('de');
    $season = releaseAs($this->board, $this->boardSigner);

    expect(array_intersect_key($cardIn('de'), ['state' => 0, 'slug' => 0]))->toBe(['state' => 'live', 'slug' => $season->slug])
        ->and(array_intersect_key($cardIn('en'), ['state' => 0, 'slug' => 0]))->toBe(['state' => 'live', 'slug' => $season->slug]);
});

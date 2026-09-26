<?php

/*
 * Block 0 (P7c, NIP rev. 5 "Season Genesis"): only a board admin on the admin
 * list releases it, after retyping the supply, by signing a release label
 * whose digest the league's genesis must match. Everything is queued for the
 * league relays only (PublishNostrEvent, faked here).
 */

use App\Jobs\PublishNostrEvent;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\Seasons;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

const RELEASE_MESSAGE = '26/Sep/2026 TWENTY ONE Esports: every fair win is a block';

beforeEach(function () {
    Queue::fake();
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret]);

    $this->boardSigner = new TestSigner;
    $this->board = User::factory()->withPubkey($this->boardSigner->pubkey)->create();
    config(['esports.board' => [NostrKeys::hexToNpub($this->board->pubkey)]]);

    $this->release = app(SeasonRelease::class);
    $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
});

/** Prepare, sign as the admin, release: the browser's three steps. */
function releaseAs(User $admin, TestSigner $signer, string $supply = '2 100 000', ?Closure $tamper = null): Season
{
    $release = app(SeasonRelease::class);
    $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
    $template = $release->prepare($admin, $supply, RELEASE_MESSAGE, $endsAt);

    if ($tamper !== null) {
        $template = $tamper($template);
    }

    return $release->release($admin, $supply, RELEASE_MESSAGE, $endsAt, $signer->signTemplates([$template]));
}

test('the parameter digest is the one of the NIP example genesis', function () {
    $tags = [['season', 'pre-season'], ['supply', '1000000'], ['subsidy', '2100'], ['weight', 'chess/blitz', '1'], ['weight', 'chess/correspondence', '2'],
        ['weight', 'rocket-league/2v2', '2.5'], ['share', 'chess', '50'], ['share', 'rocket-league', '50'], ['daily', 'chess', '10'], ['daily', 'rocket-league', '10'],
        ['pairlimit', '3', '5'], ['subtree', '90'], ['moves', '20'], ['halving', '900'], ['ends', '1790353500'], ['claim', '7776000'], ['consensus', 'season-chain-v1'],
        ['a', '31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:season/pre-season', 'wss://relay.example.org'], ['alt', 'Season genesis']];
    $content = '25/Sep/2026 TWENTY ONE Esports: Chancellor on brink of second checkmate. Block 0 of the Pre-Season. Demo season of the protocol examples: eras of 15 minutes, forty minutes in total.';

    expect(SeasonRelease::digest($content, $tags))->toBe('82f1fa0c62e7aa3efd843b7b5251a697a7d05abeb48a6b9460a80df9324d0ca1')
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

    expect($events->keys()->sort()->values()->all())->toBe([SeasonRelease::LABEL, SeasonChains::GENESIS, SeasonRelease::ADMIN_LIST, SeasonRelease::ANNOUNCEMENT])
        ->and($genesis->pubkey)->toBe($this->league->pubkey)
        ->and($genesis->hasValidSignature())->toBeTrue()
        ->and($genesis->content)->toBe(RELEASE_MESSAGE)
        ->and($genesis->tag('supply'))->toBe('2100000')
        ->and($genesis->tag('consensus'))->toBe('season-chain-v1')
        ->and($genesis->tagsNamed('p'))->toBe([[$this->board->pubkey, '', 'release']])
        ->and($genesis->tagsNamed('e'))->toBe([[$adminList->id, '', $this->league->pubkey], [$label->id, '', $this->board->pubkey]])
        ->and($label->pubkey)->toBe($this->board->pubkey)
        ->and($label->createdAt)->toBeLessThanOrEqual($genesis->createdAt)
        ->and($label->tag('x'))->toBe(SeasonRelease::digest($genesis->content, $genesis->tags))
        ->and($adminList->tagsNamed('p'))->toBe([[$this->board->pubkey]])
        ->and($adminList->tag('d'))->toBe('esports/'.$this->league->pubkey.'/admins')
        ->and($season->genesis_at->getTimestamp())->toBe($genesis->createdAt)
        ->and($season->ends_at->getTimestamp())->toBe((int) $genesis->tag('ends'))
        ->and($season->released_by_id)->toBe($this->board->id)
        ->and(Seasons::state())->toBe('live')
        ->and(Ladders::address('rocket-league', '3v3'))->toBe('32152:'.$this->league->pubkey.':rocket-league/3v3/pre-season');

    Queue::assertPushed(PublishNostrEvent::class, 4);
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

test('Block 0 is refused with a wrongly retyped supply, without the league key, and a second time', function () {
    expect(fn () => releaseAs($this->board, $this->boardSigner, '2 100 001'))->toThrow(SeasonReleaseRefused::class, 'Type the supply')
        ->and(fn () => releaseAs($this->board, $this->boardSigner, ''))->toThrow(SeasonReleaseRefused::class, 'Type the supply');

    config(['esports.league.nsec' => null]);
    expect(fn () => releaseAs($this->board, $this->boardSigner))->toThrow(SeasonReleaseRefused::class, 'league key');

    config(['esports.league.nsec' => $this->league->secret]);
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
    $template = $this->release->prepare($this->board, '2100000', RELEASE_MESSAGE, $endsAt);
    $this->travel(2)->hours();

    expect(fn () => $this->release->release($this->board, '2100000', RELEASE_MESSAGE, $endsAt, $this->boardSigner->signTemplates([$template])))
        ->toThrow(SeasonReleaseRefused::class, 'expired');
});

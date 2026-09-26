<?php

/*
 * The season chain in the app (P7c): every confirmed rated series in a live
 * season is attested (2154) and checked by the consensus rules; a valid win
 * is a block with reward = subsidy x weight x winners, an invalid one names
 * the rule that rejected it; parameter changes (2158) apply only to later
 * blocks.
 */

use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeasonAttestation;
use App\Models\SeasonParameterChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Board;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\ConsensusRule;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/** @return array{0: Lineup, 1: User, 2: TestSigner} */
function chainLineup(string $mode = '3v3'): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->mode($mode)->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

/**
 * A rated series from challenge to the confirmed result, challenger wins 2-0.
 *
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $a
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $b
 */
function chainSeries(array $a, array $b): SeriesMatch
{
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 3, true, [$start], $start - 600, '');

    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $service->answer($match, $b[1], 'accepted', $start, $b[2]->signTemplates($service->prepareAnswer($match, $b[1], 'accepted', $start)));
    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    foreach ([[3, 1], [2, 0]] as $index => [$challenger, $challenged]) {
        $service->saveLiveGame($match, $a[1], $index, $challenger, $challenged, null);
    }

    $service->report($match, $a[1], $a[2]->signTemplates($service->prepareReport($match, $a[1])));
    $service->respond($match, $b[1], 'confirmed', '', $b[2]->signTemplates($service->prepareResponse($match, $b[1], 'confirmed')));

    return $match->refresh();
}

/** @return list<list<string>> */
function attestationTags(SeasonAttestation $attestation): array
{
    return NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
}

beforeEach(function () {
    $this->season = openSeason();
});

test('a valid rated win is block 1: reward = subsidy x weight x winners, signed 2154 with the block tag', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $a = chainLineup();
    $b = chainLineup();

    $match = chainSeries($a, $b);
    $block = SeasonAttestation::query()->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($block->nostr_event_id)->payload());

    // Pre-Season: subsidy 20 000, rocket-league/3v3 weight 1x, era 1, three winners.
    expect($block->height)->toBe(1)
        ->and($block->rule)->toBeNull()
        ->and($block->era)->toBe(1)
        ->and($block->reward_per_player)->toBe(20_000)
        ->and($block->reward)->toBe(60_000)
        ->and($block->winners())->toHaveCount(3)
        ->and($block->link_event_id)->toBe($this->season->genesisId())
        ->and($event->kind)->toBe(SeasonChains::ATTESTATION)
        ->and($event->pubkey)->toBe($this->season->league_pubkey)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tagsNamed('block'))->toBe([['1', $this->season->genesisId()]])
        ->and($event->tag('resolution'))->toBe('confirmed')
        ->and($event->tag('winner'))->toBe('challenger')
        ->and($event->tag('match'))->toBe((string) $match->number)
        ->and($event->tagsNamed('elo'))->toHaveCount(2)
        ->and($event->tagsNamed('score'))->toBe([['1', 'challenger', '3', '1'], ['2', 'challenger', '2', '0']])
        ->and($event->tagsNamed('prev'))->toBe([])
        ->and($event->tagsNamed('e'))->toHaveCount(4);
});

test('an invalid win is attested with the rule that rejected it and names the tip; the next valid block links to block 1', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$a, $b, $c] = [chainLineup(), chainLineup(), chainLineup()];

    chainSeries($a, $b);
    $second = chainSeries($a, $b);
    chainSeries($c, $b);

    [$first, $rejected, $third] = SeasonAttestation::query()->orderBy('id')->get()->all();

    expect($rejected->source_id)->toBe($second->id)
        ->and($rejected->height)->toBeNull()
        ->and($rejected->rule)->toBe(ConsensusRule::PairingPerDay->value)
        ->and($rejected->reason)->toBe('pairing-daily-limit')
        ->and($rejected->reward)->toBe(0)
        ->and(attestationTags($rejected))->toContain(['block', '', $first->event_id])
        ->and($third->height)->toBe(2)
        ->and(attestationTags($third))->toContain(['block', '2', $first->event_id])
        // `prev` chains the attestations of one ladder, mined or not.
        ->and(attestationTags($rejected))->toContain(['prev', $first->event_id])
        ->and(attestationTags($third))->toContain(['prev', $rejected->event_id]);
});

test('without trust data every win fails closed at rule 1 and mines nothing', function () {
    chainSeries(chainLineup(), chainLineup());

    $attestation = SeasonAttestation::query()->sole();

    expect($attestation->height)->toBeNull()
        ->and($attestation->consensusRule())->toBe(ConsensusRule::TrustedAndConnected)
        ->and($attestation->reason)->toBe('not-trusted')
        ->and(attestationTags($attestation))->toContain(['block', '', $this->season->genesisId()]);
});

test('attesting is idempotent: the same series never gets a second attestation', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $match = chainSeries(chainLineup(), chainLineup());

    $again = app(SeasonChains::class)->attestSeries($match);

    expect(SeasonAttestation::query()->count())->toBe(1)
        ->and($again?->height)->toBe(1);
});

test('the share cap of an era stops a win that would take more than the game may mine', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    // Era 1 budget 100 000, Rocket League may mine 60 % of it: 60 000, one 3v3 block.
    $this->season->update(['supply' => 200_000]);

    foreach (range(1, 3) as $ignored) {
        chainSeries(chainLineup(), chainLineup());
    }

    $rows = SeasonAttestation::query()->orderBy('id')->get();

    expect($rows->pluck('height')->all())->toBe([1, null, null])
        ->and($rows->pluck('reason')->all())->toBe([null, 'share-cap', 'share-cap'])
        ->and($rows->pluck('rule')->all())->toBe([null, ConsensusRule::ShareCap->value, ConsensusRule::ShareCap->value])
        ->and(app(SeasonChains::class)->chain($this->season->refresh())->mined())->toBe(60_000);
});

test('a parameter change mid-season applies only to blocks attested after it, and is published as 2158', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    expect(Board::contains($admin->pubkey))->toBeTrue();

    chainSeries(chainLineup(), chainLineup());
    $this->travel(1)->minutes();

    $change = app(SeasonChains::class)->changeParameters($admin, ['weights' => ['rocket-league/3v3' => 2000]], 'Rocket League needs more reward.', CarbonImmutable::now());
    $this->travel(1)->minutes();
    chainSeries(chainLineup(), chainLineup());

    [$before, $after] = SeasonAttestation::query()->orderBy('id')->get()->all();
    $event = SignedEvent::fromInput($change->nostrEvent->payload());

    expect($before->reward)->toBe(60_000)
        ->and($after->reward)->toBe(120_000)
        ->and($before->refresh()->reward)->toBe(60_000)
        ->and($event->kind)->toBe(SeasonChains::PARAMETER_CHANGE)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tagsNamed('weight'))->toBe([['rocket-league/3v3', '2']])
        ->and($event->tag('tip'))->toBe($before->event_id)
        ->and($event->tagsNamed('p'))->toBe([[$admin->pubkey, '', 'change']])
        ->and($event->content)->toBe('Rocket League needs more reward.')
        ->and(SeasonParameterChange::query()->count())->toBe(1);
});

test('a parameter change is never retroactive and only a board admin can make one', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $admin = User::factory()->create();
    $stranger = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);

    chainSeries(chainLineup(), chainLineup());
    $chains = app(SeasonChains::class);

    // Same second as the attestation: it would judge an attested block by new rules.
    expect(fn () => $chains->changeParameters($admin, ['moves' => 30], 'Longer games.', CarbonImmutable::now()->subDay()))
        ->toThrow(SeasonReleaseRefused::class, 'never retroactive')
        ->and(fn () => $chains->changeParameters($stranger, ['moves' => 30], 'Longer games.', CarbonImmutable::now()->addHour()))
        ->toThrow(SeasonReleaseRefused::class, 'board member')
        ->and(fn () => $chains->changeParameters($admin, ['share' => ['chess' => 0]], 'x', CarbonImmutable::now()->addHour()))
        ->toThrow(SeasonReleaseRefused::class)
        ->and(fn () => $chains->changeParameters($admin, ['shares' => ['chess' => 0]], 'x', CarbonImmutable::now()->addHour()))
        ->toThrow(SeasonReleaseRefused::class, 'out of range')
        ->and(SeasonParameterChange::query()->count())->toBe(0);
});

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
use App\Models\Season;
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
use Tests\Support\TimeAnchors;
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
function chainSeries(array $a, array $b, ?Closure $beforeConfirm = null): SeriesMatch
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

    if ($beforeConfirm !== null) {
        $beforeConfirm();
        $service = app(SeriesService::class);
    }

    $service->respond($match, $b[1], 'confirmed', '', $b[2]->signTemplates($service->prepareResponse($match, $b[1], 'confirmed')));

    return $match->refresh();
}

/** @return list<list<string>> */
function attestationTags(SeasonAttestation $attestation): array
{
    return NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
}

beforeEach(function () {
    // The Pre-Season genesis these numbers were computed with before P43: subsidy 20 000, chess 40 %, Rocket League 60 %, no EA Sports FC.
    $parameters = Season::factory()->make()->parameters;
    $this->season = openSeason(['subsidy' => 20_000, 'parameters' => [
        ...$parameters,
        'weights' => array_filter($parameters['weights'], fn (string $key): bool => ! str_starts_with($key, 'ea-sports-fc'), ARRAY_FILTER_USE_KEY),
        'groups' => [],
        'shares' => ['chess' => 40, 'rocket-league' => 60],
        'daily' => ['chess' => 5, 'rocket-league' => 5],
    ]]);
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

/*
 * Regression (2026-09-27): failed deterministically on origin/master since
 * ~20:30 UTC ("Failed asserting that 2 is null"), while it passed earlier
 * the same day — time-of-day dependent, same class of bug as
 * RatedTrustGateTest's daily_pair_limit flake (see that file's comment).
 *
 * Root cause is this test's own clock, not ChainState::pairingBlocksOn()/
 * Candidate::utcDay() (app/Support/SeasonChain/ChainState.php:43-46,
 * Candidate.php:110-113): those compute `$attestedAt->utc()->format('Y-m-d')`,
 * a correct UTC-day boundary. chainSeries() (line ~47 above) travels the
 * clock forward ~1h40m every call (the same `now()->addHour()->startOfMinute()`
 * plus a 40-minute travel as gateAccepted() in RatedTrustGateTest); called
 * twice for the same pairing here, the second call's attested_at can land on
 * a different UTC day than the first depending on the real time the suite
 * happened to run. Rule 4 (PairingPerDay) scopes to one UTC day, so a
 * genuine day-crossing here is not a bug in the rule — it is this test
 * assuming the two series always land on the same day when the real clock
 * decides that.
 *
 * Pinned to the same five anchors as RatedTrustGateTest; 21:30 UTC
 * deterministically reproduces the reported failure (confirmed against the
 * exact message before fixing this), because starting there the 1h40m
 * drift between the first and second series here always crosses UTC
 * midnight. Both branches below assert the behaviour Rule 4 actually
 * defines, rather than assuming same-day.
 */
test('an invalid win is attested with the rule that rejected it and names the tip; the next valid block links to block 1', function (CarbonImmutable $anchor) {
    test()->travelTo($anchor);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$a, $b, $c] = [chainLineup(), chainLineup(), chainLineup()];

    chainSeries($a, $b);
    $firstDay = now()->utc()->toDateString();
    $second = chainSeries($a, $b);
    $secondDay = now()->utc()->toDateString();
    chainSeries($c, $b);

    [$firstBlock, $secondBlock, $thirdBlock] = SeasonAttestation::query()->orderBy('id')->get()->all();

    expect($secondBlock->source_id)->toBe($second->id);

    if ($firstDay === $secondDay) {
        // The pairing repeats on the same UTC day: rule 4 (PairingPerDay)
        // rejects the second series, names the still-unmoved tip (block 1),
        // and the third series (a different pairing) becomes block 2.
        expect($secondBlock->height)->toBeNull()
            ->and($secondBlock->rule)->toBe(ConsensusRule::PairingPerDay->value)
            ->and($secondBlock->reason)->toBe('pairing-daily-limit')
            ->and($secondBlock->reward)->toBe(0)
            ->and(attestationTags($secondBlock))->toContain(['block', '', $firstBlock->event_id])
            ->and($thirdBlock->height)->toBe(2)
            ->and(attestationTags($thirdBlock))->toContain(['block', '2', $firstBlock->event_id]);
    } else {
        // The drift crossed UTC midnight between the first and second
        // series: rule 4 is scoped to one UTC day, so the second series is
        // a fresh pairing on its own day and mines block 2; the third
        // series then becomes block 3.
        expect($secondBlock->height)->toBe(2)
            ->and($secondBlock->rule)->toBeNull()
            ->and(attestationTags($secondBlock))->toContain(['block', '2', $firstBlock->event_id])
            ->and($thirdBlock->height)->toBe(3)
            ->and(attestationTags($thirdBlock))->toContain(['block', '3', $secondBlock->event_id]);
    }

    // `prev` chains the attestations of one ladder, mined or not, in both branches above.
    expect(attestationTags($secondBlock))->toContain(['prev', $firstBlock->event_id])
        ->and(attestationTags($thirdBlock))->toContain(['prev', $secondBlock->event_id]);
})->with([
    '00:30 UTC' => [TimeAnchors::nextUtcTime(0, 30)],
    '12:00 UTC' => [TimeAnchors::nextUtcTime(12, 0)],
    '21:30 UTC' => [TimeAnchors::nextUtcTime(21, 30)],
    '23:30 UTC' => [TimeAnchors::nextUtcTime(23, 30)],
    'a Europe/Berlin DST transition' => [TimeAnchors::nextBerlinDstTransition()],
]);

test('a win without a gate pinned at the accept fails closed at rule 1 and mines nothing', function () {
    // Trusted through challenge and accept, then the pinned gate is gone (a series accepted
    // before the pin existed): rule 1 never falls back to live trust facts.
    app()->bind(TrustFacts::class, TrustedFacts::class);
    chainSeries(chainLineup(), chainLineup(), fn () => SeriesMatch::query()->update(['gate_at_accept' => null]));

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

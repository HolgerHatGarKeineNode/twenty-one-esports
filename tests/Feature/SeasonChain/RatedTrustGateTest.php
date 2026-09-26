<?php

/*
 * Rated play is trust-gated (security gate P7c, F1; plan: "gewertet nur ab
 * Trusted", "Gewertete Partien nur bei gegenseitigem Folgen"; NIP "Trust
 * gate"): every player of both lineups Trusted and the two captains listing
 * each other, checked when a rated series is challenged, accepted, and again
 * at the result. A rated pairing moves the rated Elo at most
 * `season.rating.daily_pair_limit` times a UTC day. Each player's clan is
 * the one at the accept (rule 3 and the 2154 `clan` rows).
 */

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/** Trust facts with fixed answers: `$untrusted` players rank 0, the rest 100. */
function gateFacts(bool $connected = true, array $untrusted = []): TrustFacts
{
    return new class($connected, $untrusted) implements TrustFacts
    {
        /** @param list<string> $untrusted */
        public function __construct(private bool $connected, private array $untrusted) {}

        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            $trust = [];

            foreach ($players as $player) {
                $trust[$player] = in_array($player, $this->untrusted, true) ? 0 : 100;
            }

            return ['trust' => $trust, 'anchors' => [], 'connected' => $this->connected];
        }
    };
}

/** @return array{0: Lineup, 1: User, 2: TestSigner} */
function gateLineup(string $mode = '1v1'): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->mode($mode)->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

function gateDraft(Lineup $from, Lineup $to): ChallengeDraft
{
    $start = now()->addHour()->startOfMinute()->getTimestamp();

    return new ChallengeDraft($from->id, $to->id, 3, true, [$start], $start - 600, '');
}

/** Challenge and accept a rated series; returns the match at its start. */
function gateAccepted(array $a, array $b): SeriesMatch
{
    $service = app(SeriesService::class);
    $draft = gateDraft($a[0], $b[0]);
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $start = $match->proposals[0];
    $service->answer($match, $b[1], 'accepted', $start, $b[2]->signTemplates($service->prepareAnswer($match, $b[1], 'accepted', $start)));
    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    return $match->refresh();
}

/** Enter a 2-0 for the challenger, report it and confirm it. */
function gateFinish(SeriesMatch $match, array $a, array $b, ?Closure $beforeConfirm = null): SeriesMatch
{
    $service = app(SeriesService::class);

    foreach ([[3, 1], [2, 0]] as $index => [$c, $d]) {
        $service->saveLiveGame($match, $a[1], $index, $c, $d, null);
    }

    $service->report($match, $a[1], $a[2]->signTemplates($service->prepareReport($match, $a[1])));

    if ($beforeConfirm !== null) {
        $beforeConfirm();
    }

    // Resolved again: a rebinding in $beforeConfirm reaches a new service only.
    $service = app(SeriesService::class);
    $service->respond($match, $b[1], 'confirmed', '', $b[2]->signTemplates($service->prepareResponse($match, $b[1], 'confirmed')));

    return $match->refresh();
}

function gateRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (SeriesRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

beforeEach(function () {
    openSeason();
});

test('a rated challenge is refused while trust ranks are not computed (no trust job), with a clear message', function () {
    [$a, $b] = [gateLineup(), gateLineup()];

    expect(fn () => app(SeriesService::class)->prepareChallenge($a[1], gateDraft($a[0], $b[0])))
        ->toThrow(SeriesRuleViolation::class, 'Rated play opens once trust ranks are computed')
        ->and(gateRefusal(fn () => app(SeriesService::class)->prepareChallenge($a[1], gateDraft($a[0], $b[0]))))->toBe('trust_not_computed');
});

test('a rated challenge is refused when a player is not Trusted', function () {
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts(untrusted: [$b[1]->pubkey]));

    expect(gateRefusal(fn () => app(SeriesService::class)->prepareChallenge($a[1], gateDraft($a[0], $b[0]))))->toBe('not_trusted');
});

test('a rated challenge is refused when the captains do not list each other', function () {
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts(connected: false));

    expect(gateRefusal(fn () => app(SeriesService::class)->prepareChallenge($a[1], gateDraft($a[0], $b[0]))))->toBe('not_connected');
});

test('accepting a rated challenge re-checks the trust gate', function () {
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts());
    $service = app(SeriesService::class);
    $draft = gateDraft($a[0], $b[0]);
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));

    app()->instance(TrustFacts::class, gateFacts(untrusted: [$a[1]->pubkey]));

    expect(gateRefusal(fn () => app(SeriesService::class)->prepareAnswer($match, $b[1], 'accepted', $match->proposals[0])))->toBe('not_trusted')
        ->and($match->refresh()->status)->toBe(SeriesStatus::Open);
});

test('a player whose trust dropped before the result moves no rated Elo', function () {
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts());
    $match = gateAccepted($a, $b);

    gateFinish($match, $a, $b, fn () => app()->instance(TrustFacts::class, gateFacts(connected: false)));

    expect(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and(RatingChange::query()->count())->toBe(0);
});

test('a rated pairing moves the rated Elo at most daily_pair_limit times a UTC day', function () {
    config(['season.rating.daily_pair_limit' => 2]);
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts());

    foreach (range(1, 3) as $ignored) {
        gateFinish(gateAccepted($a, $b), $a, $b);
    }

    $moved = RatingChange::query()->distinct()->count('source_id');

    expect(SeriesMatch::query()->where('status', SeriesStatus::Confirmed)->count())->toBe(3)
        ->and($moved)->toBe(2);
});

test('regression (audit F1): two sock-puppet lineups cannot farm rated Elo after Block 0', function () {
    // The audit: two fresh accounts with RL 1v1 lineups, six rated series, Elo 1000 -> 1090,
    // each one attested with a league-signed `elo` row. No trust job exists (NoTrustFacts).
    [$a, $b] = [gateLineup(), gateLineup()];

    expect(gateRefusal(fn () => app(SeriesService::class)->prepareChallenge($a[1], gateDraft($a[0], $b[0]))))->toBe('trust_not_computed');

    // Even a rated series that got past the door (made directly) moves nothing rated.
    foreach (range(1, 6) as $ignored) {
        $match = SeriesMatch::factory()->accepted()->create([
            'challenger_lineup_id' => $a[0]->id, 'challenged_lineup_id' => $b[0]->id, 'rated' => true,
            'status' => SeriesStatus::Confirmed, 'resolution' => SeriesResolution::Confirmed, 'winner' => 'challenger',
            'result_games' => [['winner' => 'challenger', 'challenger' => 2, 'challenged' => 1], ['winner' => 'challenger', 'challenger' => 1, 'challenged' => 0]],
            'finished_at' => now(),
        ]);
        app(RatingService::class)->applySeries($match);
        app(SeasonChains::class)->attestSeries($match);
    }

    $eloRows = SeasonAttestation::query()->get()
        ->filter(fn (SeasonAttestation $row) => collect(NostrEvent::query()->findOrFail($row->nostr_event_id)->payload()['tags'])->contains(fn ($tag) => $tag[0] === 'elo'));

    expect(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($eloRows)->toHaveCount(0);
});

test('rule 3 and the 2154 clan rows use each player\'s clan at the accept', function () {
    [$a, $b] = [gateLineup(), gateLineup()];
    app()->instance(TrustFacts::class, gateFacts());
    $match = gateAccepted($a, $b);
    $winner = $a[0]->seats->firstWhere('user_id', '!=', $a[1]->id)?->user ?? $a[1];
    $acceptClan = $a[0]->clan->address();
    $other = Clan::factory()->create();

    gateFinish($match, $a, $b, fn () => ClanMember::query()->where('user_id', $winner->id)->update(['clan_id' => $other->id]));

    $tags = NostrEvent::query()->findOrFail(SeasonAttestation::query()->sole()->nostr_event_id)->payload()['tags'];

    expect($tags)->toContain(['clan', $winner->pubkey, $acceptClan])
        ->and($tags)->not->toContain(['clan', $winner->pubkey, $other->address()])
        ->and(SeasonAttestation::query()->sole()->candidate['clans'][$winner->pubkey])->toBe($acceptClan);
});

test('a rated chess result between players without trust ranks moves no rated Elo', function () {
    [$white, $black] = [User::factory()->create(), User::factory()->create()];
    $game = ChessGame::factory()->finished('1-0')->create(['rated' => true, 'white_id' => $white->id, 'black_id' => $black->id]);

    expect(app(RatingService::class)->applyChessGame($game))->toBeFalse()
        ->and(Rating::query()->count())->toBe(0);

    app()->instance(TrustFacts::class, gateFacts());
    $trusted = ChessGame::factory()->finished('1-0')->create(['rated' => true, 'white_id' => $white->id, 'black_id' => $black->id]);

    expect(app(RatingService::class)->applyChessGame($trusted))->toBeTrue();
});

test('after Block 0 the challenge page keeps rated locked until trust ranks exist, and says why', function () {
    [$a] = [gateLineup('3v3')];

    Livewire::actingAs($a[1])->test('pages::challenges.create')
        ->assertSee('data-test="rated-needs-trust"', false)
        ->assertSee('Rated play opens once trust ranks are computed')
        ->assertSee('data-test="type-rated-locked"', false);

    app()->instance(TrustFacts::class, gateFacts());

    Livewire::actingAs($a[1])->test('pages::challenges.create')
        ->assertDontSee('data-test="type-rated-locked"', false)
        ->assertDontSee('data-test="rated-needs-trust"', false);
});

test('the TrustedFacts double reports trust as available', function () {
    expect((new TrustedFacts)->available())->toBeTrue();
});

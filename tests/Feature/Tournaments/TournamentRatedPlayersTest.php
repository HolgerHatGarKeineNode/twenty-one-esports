<?php

use App\Enums\ClanRole;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Rated Rocket League series in players mode (P8c, NIP rev. 8.1)
|--------------------------------------------------------------------------
|
| A tournament whose players report the results rates its RL series under
| the guarantees of a ladder series: subjects pinned at the pairing (lineups
| in 2v2/3v3, pubkeys in 1v1, never a mix team), only on the ladder frozen at
| publish while it is open, the league signs the pairing (2150), the other
| side confirms (2153) or an admin decides a dispute, the daily pair cap
| holds, and no tournament match ever mines a season block.
|
*/

beforeEach(function () {
    Queue::fake();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/** A clan lineup side: [keyed clan owner (acting captain, seated as captain), signer, lineup]. */
function p8cLineupSide(string $mode): array
{
    [$owner, $signer] = keyedPlayer();
    $lineup = Lineup::factory()->mode($mode)->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id]);

    return [$owner, $signer, $lineup->load('clan', 'seats.user')];
}

/** A solo entry: [keyed player, signer, null]. */
function p8cSoloSide(): array
{
    [$player, $signer] = keyedPlayer();

    return [$player, $signer, null];
}

/**
 * A published players-mode Rocket League tournament of `$mode` with two
 * entries, running, its one match paired. A side is a p8c*Side() or
 * `['mix' => list<User>]`; `$beforePairing` runs after the publish.
 *
 * @return array{0: Tournament, 1: SeriesMatch}
 */
function p8cTournament(string $mode, array $sides, ?Closure $beforePairing = null): array
{
    $tournament = Tournament::factory()->rocketLeague()->create([
        'mode' => $mode, 'options' => FormatOptions::defaults(GameProfile::for('rocket-league', $mode))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'created_by_id' => organizer()->id,
    ]);
    $tournament = app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addHour());
    $tournament->forceFill(['status' => TournamentStatus::Running])->save();

    if ($beforePairing !== null) {
        $beforePairing();
    }

    foreach ($sides as $index => $side) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'rating' => 1100 - $index] + match (true) {
            isset($side['mix']) => ['name' => 'Mix '.$index, 'draw_position' => $index + 1, 'members' => array_map(fn (User $user) => $user->id, $side['mix'])],
            $side[2] instanceof Lineup => ['lineup_id' => $side[2]->id, 'name' => $side[2]->clan->name, 'members' => $side[2]->seats->pluck('user_id')->all()],
            default => ['user_id' => $side[0]->id, 'name' => $side[0]->displayName(), 'members' => [$side[0]->id]],
        });
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('9a', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole()];
}

/** The winner's side enters a clean series win and reports it, signed. */
function p8cReport(SeriesMatch $series, array $winner): SeriesMatch
{
    $service = app(SeriesService::class);
    $side = $series->refresh()->captainSideOf($winner[0]);

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner[0], $index, $side === 'challenger' ? 3 : 1, $side === 'challenger' ? 1 : 3, null);
    }

    $service->report($series, $winner[0], $winner[1]->signTemplates($service->prepareReport($series, $winner[0])));

    return $series->refresh();
}

/** The other side answers the report, signed. */
function p8cRespond(SeriesMatch $series, array $other, string $status = 'confirmed'): SeriesMatch
{
    $service = app(SeriesService::class);
    $reason = $status === 'disputed' ? 'Game 2 was ours.' : '';
    $service->respond($series, $other[0], $status, $reason, $other[1]->signTemplates($service->prepareResponse($series->refresh(), $other[0], $status, $reason)));

    return $series->refresh();
}

/** A rated ladder series between two lineup sides, challenged, accepted and won 2-0 by `$a`. */
function p8cLadderSeries(array $a, array $b): SeriesMatch
{
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[2]->id, $b[2]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[0], $draft, $a[1]->signTemplates($service->prepareChallenge($a[0], $draft)['templates']));
    $service->answer($match, $b[0], 'accepted', $start, $b[1]->signTemplates($service->prepareAnswer($match, $b[0], 'accepted', $start)));
    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    return p8cRespond(p8cReport($match->refresh(), $a), $b);
}

function p8cAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** @return list<list<string>> */
function p8cAttestationTags(SeriesMatch $series): array
{
    return NostrEvent::query()->findOrFail(SeasonAttestation::query()->where('source_id', $series->id)->sole()->nostr_event_id)->payload()['tags'];
}

/** @param list<string> $values */
function p8cSorted(array $values): array
{
    sort($values);

    return $values;
}

dataset('rated players-mode pairings', [
    '2v2 clan lineups (rated as lineups)' => ['2v2', fn () => [p8cLineupSide('2v2'), p8cLineupSide('2v2')]],
    '1v1 solo against solo (rated as pubkeys)' => ['1v1', fn () => [p8cSoloSide(), p8cSoloSide()]],
    '1v1 clan lineup against solo (rated as pubkeys)' => ['1v1', fn () => [p8cLineupSide('1v1'), p8cSoloSide()]],
]);

test('a players-mode RL series on an open frozen ladder is signed by the league at the pairing and rated only once the other side confirms', function (string $mode, Closure $sides) {
    $season = openSeason(['slug' => 'season-1']);
    [$a, $b] = $sides();
    [$tournament, $series] = p8cTournament($mode, [$a, $b]);
    $challenge = $series->challengeEvent;
    $challengeTags = $challenge->payload()['tags'];
    // 1v1 rates the players (the lineup's seated captain is its owner); team modes the lineups.
    $subjects = $mode === '1v1' ? ['user:'.$a[0]->id, 'user:'.$b[0]->id] : ['lineup:'.$a[2]->id, 'lineup:'.$b[2]->id];
    $entities = $mode === '1v1' ? [$a[0]->pubkey, $b[0]->pubkey] : [$a[2]->address(), $b[2]->address()];

    expect($series->rated)->toBeTrue()
        ->and($series->ladder_address)->toBe($tournament->ladder_address)->not->toBeNull()
        ->and(p8cSorted(array_values($series->rated_subjects)))->toBe(p8cSorted($subjects))
        ->and($series->gate_at_accept)->not->toBeNull()
        ->and($series->answer_event_id)->toBeNull()
        ->and($challenge->kind)->toBe(2150)
        ->and($challenge->pubkey)->toBe($season->league_pubkey)
        ->and($challengeTags)->toContain(['pairing', 'tournament'], ['a', $tournament->address(), ''], ['a', $tournament->ladder_address, ''], ['match', (string) $series->number])
        ->and(collect($challengeTags)->whereIn(0, ['respond_by', 'zap', 'block', 'entered-by'])->all())->toBe([]);

    // The room of a rated tournament pairing renders for either kind of side.
    $this->actingAs($a[0])->get(route('matches.room', $series))->assertOk()->assertSee('Rated series');

    // The report alone moves nothing: the other side has to confirm.
    $series = p8cReport($series, $a);

    expect(RatingChange::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and($series->latestReport->event->payload()['tags'])->toContain(['e', $challenge->event_id, '', $challenge->pubkey], ['a', $tournament->address(), '']);

    $series = p8cRespond($series, $b);
    $report = $series->latestReport;
    $tags = p8cAttestationTags($series);
    $attestation = SeasonAttestation::query()->sole();

    expect($series->status)->toBe(SeriesStatus::Confirmed)
        ->and($series->resolution)->toBe(SeriesResolution::Confirmed)
        ->and(RatingChange::query()->where('source', RatingChange::SERIES)->where('source_id', $series->id)->count())->toBe(2)
        ->and(p8cSorted(Rating::query()->where('pool', Rating::RATED)->where('mode', $mode)->pluck('subject')->all()))->toBe(p8cSorted($subjects))
        ->and(Rating::query()->where('pool', Rating::RATED)->where('subject', $subjects[0])->value('rating'))->toBeGreaterThan(1000)
        ->and(Rating::query()->where('pool', Rating::CASUAL)->count())->toBe(0)
        ->and($tags)->toContain(
            ['e', $challenge->event_id, '', $challenge->pubkey],
            ['e', $report->event->event_id, '', $report->event->pubkey],
            ['e', $report->responseEvent->event_id, '', $report->responseEvent->pubkey],
            ['resolution', 'confirmed'],
            ['a', $tournament->address(), ''],
        )
        ->and(p8cSorted(collect($tags)->where(0, 'elo')->pluck(1)->all()))->toBe(p8cSorted($entities))
        ->and(collect($tags)->whereIn(0, ['entered-by', 'block'])->all())->toBe([])
        ->and($attestation->candidate)->toBeNull()
        ->and($attestation->height)->toBeNull()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    // The proof names the league's pairing instead of an answer that never comes.
    $this->get(route('matches.show', $series))->assertOk()
        ->assertSee('by the league&#039;s pairing', false)->assertDontSee('waiting for the answer');
})->with('rated players-mode pairings');

test('a series with a mix team stays casual: no league challenge, no rating, no attestation', function () {
    openSeason(['slug' => 'season-1']);
    $a = p8cLineupSide('2v2');
    [$mixed, $signer] = keyedPlayer();
    [, $series] = p8cTournament('2v2', [$a, ['mix' => [$mixed, User::factory()->create()]]]);

    expect($series->rated)->toBeFalse()
        ->and($series->challenge_event_id)->toBeNull()
        ->and($series->gate_at_accept)->toBeNull()
        ->and($series->rated_subjects)->toBeNull();

    $series = p8cRespond(p8cReport($series, $a), [$mixed, $signer]);

    expect($series->status)->toBe(SeriesStatus::Confirmed)
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->whereIn('kind', [2150, 2152, 2153, 2154])->count())->toBe(0);
});

dataset('no open frozen ladder at the pairing', [
    'published before Block 0, a ladder opens before the pairing' => [
        fn () => config(['esports.league.nsec' => (new TestSigner)->secret]),
        fn () => openSeason(['slug' => 'season-1']),
    ],
    'frozen at publish, but the season ended before the pairing' => [
        fn () => openSeason(['slug' => 'season-1']),
        fn () => Season::query()->update(['ends_at' => now()->subMinute()]),
    ],
]);

test('without an open frozen ladder at the pairing the series stays casual and moves only the casual Elo', function (Closure $beforePublish, Closure $beforePairing) {
    $beforePublish();
    [$a, $b] = [p8cLineupSide('2v2'), p8cLineupSide('2v2')];
    [$tournament, $series] = p8cTournament('2v2', [$a, $b], $beforePairing);

    expect($series->rated)->toBeFalse()
        ->and($tournament->openLadder())->toBeNull()
        ->and($series->challenge_event_id)->toBeNull();

    p8cRespond(p8cReport($series, $a), $b);

    expect(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and(Rating::query()->where('pool', Rating::CASUAL)->count())->toBe(2)
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->where('kind', 2150)->count())->toBe(0);
})->with('no open frozen ladder at the pairing');

test('a series paired on season 1 is never rated or attested on the ladder of a later season', function () {
    $first = openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cLineupSide('2v2'), p8cLineupSide('2v2')];
    [, $series] = p8cTournament('2v2', [$a, $b]);
    p8cReport($series, $a);

    $first->forceFill(['ends_at' => now()->subMinutes(2)])->save();
    openSeason(['slug' => 'season-2', 'genesis_at' => now()->subMinute()->startOfSecond()]);
    $series = p8cRespond($series, $b);

    expect($series->rated)->toBeTrue()
        ->and($series->status)->toBe(SeriesStatus::Confirmed)
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);
});

test('two solo players of one clan meet casual: the league signs no challenge for a pairing inside a clan', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cSoloSide(), p8cSoloSide()];
    $clan = Clan::factory()->create(['owner_id' => $a[0]->id]);
    ClanMember::query()->updateOrCreate(['user_id' => $b[0]->id], ['clan_id' => $clan->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    [, $series] = p8cTournament('1v1', [$a, $b]);

    expect($series->rated)->toBeFalse()
        ->and($series->challenge_event_id)->toBeNull()
        ->and(NostrEvent::query()->where('kind', 2150)->count())->toBe(0);
});

test('a disputed result goes to an admin outside both clans, and the admin decision is rated like a ladder dispute', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cSoloSide(), p8cSoloSide()];
    // The solo loser plays for a clan: its admins may not decide (a player side has no lineup).
    $clan = Clan::factory()->create();
    ClanMember::query()->updateOrCreate(['user_id' => $b[0]->id], ['clan_id' => $clan->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    $clanAdmin = p8cAdmin();
    ClanMember::query()->create(['user_id' => $clanAdmin->id, 'clan_id' => $clan->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    [$tournament, $series] = p8cTournament('1v1', [$a, $b]);

    $series = p8cRespond(p8cReport($series, $a), $b, 'disputed');
    $service = app(SeriesService::class);
    $decision = ['type' => 'report', 'report' => $series->latestReport->id];

    expect($series->status)->toBe(SeriesStatus::Disputed)
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(fn () => $service->decide($series, $clanAdmin, $decision, 'Screenshot checked.'))->toThrow(SeriesRuleViolation::class, 'own clan');

    $service->decide($series->refresh(), p8cAdmin(), $decision, 'Screenshot checked.');
    $series->refresh();
    $tags = p8cAttestationTags($series);

    expect($series->status)->toBe(SeriesStatus::Resolved)
        ->and($series->resolution)->toBe(SeriesResolution::Admin)
        ->and(RatingChange::query()->where('source_id', $series->id)->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('user_id', $a[0]->id)->value('rating'))->toBeGreaterThan(1000)
        ->and($tags)->toContain(['resolution', 'admin'], ['e', $series->latestReport->responseEvent->event_id, '', $b[0]->pubkey], ['a', $tournament->address(), ''], ['clan', $b[0]->pubkey, $clan->address()])
        ->and(collect($tags)->whereIn(0, ['entered-by', 'block'])->all())->toBe([])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a tournament series never mines a season block, while the same two lineups mine one on the ladder (control)', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cLineupSide('2v2'), p8cLineupSide('2v2')];

    $ladder = p8cLadderSeries($a, $b);
    $control = SeasonAttestation::query()->where('source_id', $ladder->id)->sole();

    expect($control->candidate)->not->toBeNull()
        ->and($control->height)->toBe(1)
        ->and($control->reward)->toBeGreaterThan(0)
        ->and(collect(p8cAttestationTags($ladder))->firstWhere(0, 'block'))->not->toBeNull();

    [, $series] = p8cTournament('2v2', [$a, $b]);
    $series = p8cRespond(p8cReport($series, $a), $b);
    $attestation = SeasonAttestation::query()->where('source_id', $series->id)->sole();

    expect($series->rated)->toBeTrue()
        ->and(RatingChange::query()->where('source_id', $series->id)->count())->toBe(2)
        ->and($attestation->candidate)->toBeNull()
        ->and($attestation->height)->toBeNull()
        ->and($attestation->reward)->toBe(0)
        ->and(collect(p8cAttestationTags($series))->firstWhere(0, 'block'))->toBeNull()
        ->and(collect($series->challengeEvent->payload()['tags'])->firstWhere(0, 'zap'))->toBeNull();
});

test('the daily pair cap is shared with ladder series: a capped tournament result stands in the bracket but moves no Elo', function () {
    config(['season.rating.daily_pair_limit' => 1]);
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cLineupSide('2v2'), p8cLineupSide('2v2')];

    p8cLadderSeries($a, $b);
    [$tournament, $series] = p8cTournament('2v2', [$a, $b]);
    $series = p8cRespond(p8cReport($series, $a), $b);

    expect(RatingChange::query()->count())->toBe(2)
        ->and(RatingChange::query()->where('source_id', $series->id)->count())->toBe(0)
        ->and($series->status)->toBe(SeriesStatus::Confirmed)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(collect(p8cAttestationTags($series))->where(0, 'elo')->all())->toBe([]);

    // The next UTC day the same pairing counts again.
    $this->travelTo(now()->utc()->addDay()->startOfDay()->addHour());
    [, $next] = p8cTournament('2v2', [$a, $b]);
    p8cRespond(p8cReport($next, $a), $b);

    expect(RatingChange::query()->where('source_id', $next->id)->count())->toBe(2);
});

test('regression (P7d F3 class): a loser who deletes his account after the result was reported is still rated through the pinned subjects', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [p8cSoloSide(), p8cSoloSide()];
    [, $series] = p8cTournament('1v1', [$a, $b]);
    [$loserId, $loserKey] = [$b[0]->id, $b[0]->pubkey];

    // Reported first: an accepted series nobody reported would be a withdrawal forfeit (P18).
    $series = p8cReport($series, $a);
    app(DeleteAccount::class)($b[0]);
    app(SeriesService::class)->decide($series, p8cAdmin(), ['type' => 'report', 'report' => $series->latestReport->id], 'The loser deleted his account.');

    $loser = Rating::query()->where('pool', Rating::RATED)->where('subject', 'user:'.$loserId)->sole();

    expect(User::query()->whereKey($loserId)->exists())->toBeFalse()
        ->and(RatingChange::query()->where('source_id', $series->id)->count())->toBe(2)
        ->and($loser->rating)->toBeLessThan(1000)
        ->and($loser->user_id)->toBeNull()
        ->and(Rating::query()->where('pool', Rating::RATED)->where('user_id', $a[0]->id)->value('rating'))->toBeGreaterThan(1000)
        ->and(p8cSorted(collect(p8cAttestationTags($series))->where(0, 'elo')->pluck(1)->all()))->toBe(p8cSorted([$a[0]->pubkey, $loserKey]));
});

test('regression (P8c F1): an admin who plays in the series decides nothing, with or without a clan', function () {
    openSeason(['slug' => 'season-1']);
    $series = app(SeriesService::class);
    [$admin, $adminSigner] = keyedPlayer();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $adminSide = [$admin, $adminSigner, null];

    // His own report, decided by himself.
    [, $own] = p8cTournament('1v1', [p8cSoloSide(), $adminSide]);
    $own = p8cReport($own, $adminSide);
    expect(fn () => $series->decide($own->refresh(), $admin, ['type' => 'report', 'report' => $own->latestReport->id], 'Checked.'))
        ->toThrow(SeriesRuleViolation::class);

    // The other side reports, he disputes and decides a forfeit for himself.
    $victim = p8cSoloSide();
    [, $disputed] = p8cTournament('1v1', [$victim, $adminSide]);
    $disputed = p8cRespond(p8cReport($disputed, $victim), $adminSide, 'disputed');
    expect(fn () => $series->decide($disputed->refresh(), $admin, ['type' => 'forfeit', 'winner' => $disputed->captainSideOf($admin)], 'No.'))
        ->toThrow(SeriesRuleViolation::class);

    expect(RatingChange::query()->whereIn('source_id', [$own->id, $disputed->id])->count())->toBe(0)
        ->and($own->refresh()->resolution)->toBeNull()
        ->and($disputed->refresh()->resolution)->toBeNull();
});

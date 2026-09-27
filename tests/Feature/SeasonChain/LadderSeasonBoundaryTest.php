<?php

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| A rated ladder series belongs to the ladder its challenge named (P8c)
|--------------------------------------------------------------------------
|
| NIP "Rest": a match-flow event after the ladder's `ends` belongs to no
| season and is never attested; "Tournaments": a match is never rated on
| another ladder. A series challenged in season 1 and decided after season 1
| closed moves no rating and is not attested, also once season 2 is open.
|
*/

beforeEach(function () {
    Queue::fake();
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/** A 1v1 clan lineup whose keyed owner is its captain: [owner, signer, lineup]. */
function boundaryLineup(): array
{
    [$owner, $signer] = keyedPlayer();
    $lineup = Lineup::factory()->mode('1v1')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id]);

    return [$owner, $signer, $lineup->load('clan', 'seats.user')];
}

/** A rated series challenged by `$a`, accepted by `$b`, won 2-0 by `$a` and reported; not yet answered. */
function boundaryReported(array $a, array $b): SeriesMatch
{
    $service = app(SeriesService::class);
    $start = now()->addHour()->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[2]->id, $b[2]->id, 3, true, [$start], $start - 600, '');
    $match = $service->challenge($a[0], $draft, $a[1]->signTemplates($service->prepareChallenge($a[0], $draft)['templates']));
    $service->answer($match, $b[0], 'accepted', $start, $b[1]->signTemplates($service->prepareAnswer($match, $b[0], 'accepted', $start)));
    test()->travelTo(now()->setTimestamp($start)->addMinutes(40));

    foreach ([[3, 1], [2, 0]] as $index => [$x, $y]) {
        $service->saveLiveGame($match, $a[0], $index, $x, $y, null);
    }

    $service->report($match, $a[0], $a[1]->signTemplates($service->prepareReport($match, $a[0])));

    return $match->refresh();
}

function boundaryConfirm(SeriesMatch $match, array $b): SeriesMatch
{
    $service = app(SeriesService::class);
    $service->respond($match, $b[0], 'confirmed', '', $b[1]->signTemplates($service->prepareResponse($match->refresh(), $b[0], 'confirmed')));

    return $match->refresh();
}

test('control: a series challenged and confirmed inside season 1 is rated and attested on the season-1 ladder', function () {
    openSeason(['slug' => 'season-1']);
    [$a, $b] = [boundaryLineup(), boundaryLineup()];
    $match = boundaryConfirm(boundaryReported($a, $b), $b);

    expect($match->ladder_address)->toBe(Ladders::address('rocket-league', '1v1'))
        ->and(RatingChange::query()->where('source_id', $match->id)->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('season', 'season-1')->count())->toBe(2)
        ->and(SeasonAttestation::query()->where('source_id', $match->id)->sole()->ladder_address)->toBe($match->ladder_address);
});

dataset('season 1 closed before the result', [
    'season 2 is open by then' => [fn () => openSeason(['slug' => 'season-2', 'genesis_at' => now()->subMinute()->startOfSecond()])],
    'rest between seasons' => [fn () => null],
]);

test('a series challenged in season 1 and confirmed after season 1 closed is neither rated nor attested, on no ladder', function (Closure $after) {
    $first = openSeason(['slug' => 'season-1']);
    [$a, $b] = [boundaryLineup(), boundaryLineup()];
    $match = boundaryReported($a, $b);
    $challengedOn = $match->ladder_address;

    $first->forceFill(['ends_at' => now()->subMinutes(2)])->save();
    $after();
    $match = boundaryConfirm($match, $b);

    expect($challengedOn)->toContain('/season-1')
        ->and($match->status)->toBe(SeriesStatus::Confirmed)
        ->and($match->rated)->toBeTrue()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(Rating::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);
})->with('season 1 closed before the result');

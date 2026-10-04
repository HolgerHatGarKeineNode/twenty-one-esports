<?php

use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;

/*
| Who did not play wins nothing (user, 2026-10-04): a disqualified entry or one that lost as a no-show is taken out
| of the places, everyone behind moves up, and the pot goes to those who played.
*/

test('a disqualified entry wins nothing and everyone behind it moves up a place', function () {
    fakeWallet();
    $tournament = finishedPoolTournament(ownPotWallet(0), 100_000);
    $places = app(TournamentPlacements::class)->of($tournament);
    [$champion, $second] = [$places[0]['participants'][0], $places[1]['participants'][0]];

    $before = app(PayoutPlan::class)->compute($tournament, 99_000);
    expect(collect($before['rows'])->firstWhere('place', 1)['participant']->id)->toBe($champion);

    TournamentParticipant::query()->whereKey($champion)->update(['disqualified_at' => now()]);
    $after = app(PayoutPlan::class)->compute($tournament, 99_000);
    $rows = collect($after['rows']);

    expect($rows->pluck('participant.id')->all())->not->toContain($champion)
        ->and($rows->firstWhere('place', 1)['participant']->id)->toBe($second)
        // Three players left hold 50/30/20 of the whole pot as before (all three places stay held): no scaling.
        ->and($rows->firstWhere('place', 1)['amount'])->toBe(49_500)
        // The two losing semi-finalists moved up to share places 2 and 3: 30 % + 20 % between them.
        ->and($rows->where('place', 2)->pluck('amount')->all())->toBe([24_750, 24_750])
        ->and($after['remainder'])->toBe(0);
});

test('with only two players left the whole percent pot goes to them in the split\'s proportions', function () {
    fakeWallet();
    $tournament = finishedPoolTournament(ownPotWallet(0), 100_000);
    $places = app(TournamentPlacements::class)->of($tournament);
    // The two losing semi-finalists (tied third) are out: 50/30 of the split held now, 100 % before.
    TournamentParticipant::query()->whereKey($places[2]['participants'])->update(['disqualified_at' => now()]);

    $rows = collect(app(PayoutPlan::class)->compute($tournament, 100_000)['rows']);

    expect($rows->pluck('amount', 'place')->all())->toBe([1 => 62_500, 2 => 37_500]);
});

test('an entry that lost as a no-show wins nothing', function () {
    fakeWallet();
    $tournament = finishedPoolTournament(ownPotWallet(0), 100_000);
    $places = app(TournamentPlacements::class)->of($tournament);
    $third = $places[2]['participants'][0];
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereHas('slots', fn ($q) => $q->where('tournament_participant_id', $third))
        ->whereNotNull('result')->orderBy('id')->firstOrFail();
    $result = $match->result;
    $result['decided'] = 'noshow';
    $match->forceFill(['result' => $result])->save();

    expect(PayoutPlan::excluded($tournament))->toHaveKey($third)
        ->and(collect(app(PayoutPlan::class)->compute($tournament, 99_000)['rows'])->pluck('participant.id')->all())->not->toContain($third);
});

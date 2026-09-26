<?php

use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Engagement\ClanHashrate;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Tests\Support\TrustedFacts;

/*
 * The inputs of the city ranking (P10): clan hashrate from the season's
 * rated results (NIP "Clan hashrate"), per clan at the accept, summed per
 * meetup city. The summing itself is tests/Unit/CityRankingTest.
 */

test('rated games and series earn their clans hashrate, summed per meetup city; casual games earn nothing', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['season.casual.daily_pair_limit' => null]);

    [$a, $b, $c] = User::factory()->count(3)->create();
    $kempten = Clan::factory()->create(['owner_id' => $a->id, 'meetup_city' => 'Kempten']);
    $munich = Clan::factory()->create(['owner_id' => $b->id, 'meetup_city' => 'München']);
    Clan::factory()->create(['owner_id' => $c->id, 'meetup_city' => 'Kempten']);
    Clan::factory()->create(['meetup_city' => null]);

    // Rated chess: a wins for Kempten (3), b loses for München (1).
    $rated = ChessGame::factory()->rated()->finished('1-0')->create([
        'white_id' => $a->id, 'black_id' => $b->id,
        'clans_at_accept' => [$a->pubkey => $kempten->address(), $b->pubkey => $munich->address()],
    ]);
    expect(app(RatingService::class)->applyChessGame($rated))->toBeTrue();

    // Casual chess moves a casual rating only: no hashrate.
    app(RatingService::class)->applyChessGame(ChessGame::factory()->finished('1-0')->create(['white_id' => $c->id, 'black_id' => $b->id]));

    // A rated series the challenger won: 3 roster players each, +5 for the winning lineup's clan.
    $match = SeriesMatch::factory()->accepted()->create(['rated' => true, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenger']);
    $match->challengerLineup->clan->update(['meetup_city' => 'Kempten']);
    $match->challengedLineup->clan->update(['meetup_city' => 'München']);
    $roster = [];
    $clans = [];
    foreach (SeriesMatch::SIDES as $side) {
        foreach ($match->lineup($side)->activeSeats() as $seat) {
            $roster[] = ['user_id' => $seat->user_id, 'pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side, 'role' => $seat->role->value];
            $clans[$seat->user->pubkey] = $match->lineup($side)->clan->address();
        }
    }
    $match->forceFill(['resolved_roster' => $roster, 'clans_at_accept' => $clans])->save();
    foreach (SeriesMatch::SIDES as $index => $side) {
        $rating = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$match->lineup($side)->id, 'lineup_id' => $match->lineup($side)->id, 'rating' => 1000, 'results' => 1]);
        RatingChange::query()->create(['rating_id' => $rating->id, 'source' => RatingChange::SERIES, 'source_id' => $match->id, 'score' => $index === 0 ? 1 : 0, 'before' => 1000, 'after' => 1000, 'delta' => 0, 'results_before' => 0]);
    }

    $hashrate = app(ClanHashrate::class)->forSeason('season-1');

    expect($hashrate[$kempten->address()])->toBe(3)
        ->and($hashrate[$munich->address()])->toBe(1)
        ->and($hashrate[$match->challengerLineup->clan->address()])->toBe(3 * 3 + 5)
        ->and($hashrate[$match->challengedLineup->clan->address()])->toBe(3 * 1)
        ->and(app(ClanHashrate::class)->cities('season-1'))->toBe([
            ['city' => 'Kempten', 'hashrate' => 17, 'clans' => 3],
            ['city' => 'München', 'hashrate' => 4, 'clans' => 2],
        ])
        ->and(app(ClanHashrate::class)->forSeason('season-0'))->toBe([]);

    $this->get(route('clans.index'))->assertOk()->assertSeeInOrder(['data-test="city-ranking"', 'Kempten', '17', 'München', '4'], false);
});

test('before Block 0 there is no city ranking, only its empty state', function () {
    Clan::factory()->create(['meetup_city' => 'Kempten']);

    expect(app(ClanHashrate::class)->cities(null))->toBe([]);

    $this->get(route('clans.index'))->assertOk()->assertSee(__('The city ranking starts at Block 0, with the first rated games.'));
});

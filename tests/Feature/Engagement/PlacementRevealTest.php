<?php

use App\Models\ChessGame;
use App\Models\PlacementReveal;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\User;
use App\Support\Engagement\Placements;
use App\Support\Engagement\ResultEngagement;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Tests\Support\TrustedFacts;

/*
 * Placement (P10): the fifth rated result in a ladder reveals the rank once,
 * on the next page the player opens; casual results and later results reveal
 * nothing.
 */

beforeEach(function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['season.rating.daily_pair_limit' => null]);
});

/** A rated blitz rating row as if the player had played `$results` rated results. */
function placementRating(User $user, int $rating, int $results): Rating
{
    return Rating::query()->create([
        'pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results, 'wins' => $results,
    ]);
}

function placementGame(User $white, User $black, bool $rated = true): ChessGame
{
    $factory = ChessGame::factory()->finished('1-0');

    return ($rated ? $factory->rated() : $factory)->create(['white_id' => $white->id, 'black_id' => $black->id]);
}

test('the fifth rated result reveals the rank once, on the next page, and never again', function () {
    [$placing, $veteran] = User::factory()->count(2)->create();
    placementRating($placing, 1100, 4);
    placementRating($veteran, 1100, 30);

    expect(app(RatingService::class)->applyChessGame(placementGame($placing, $veteran)))->toBeTrue();

    // Provisional k 40 against an equal rating: 1100 + 20 = 1120, Platinum I (Platinum II starts at 1125).
    $reveal = PlacementReveal::query()->sole();
    expect($reveal->only(['user_id', 'game', 'mode', 'rating', 'tier']))->toBe(['user_id' => $placing->id, 'game' => 'chess', 'mode' => 'blitz', 'rating' => 1120, 'tier' => 'platinum-1'])
        ->and($reveal->shown_at)->toBeNull();

    $this->actingAs($placing)->get(route('clans.index'))->assertOk()
        ->assertSee('data-test="placement-reveal"', false)
        ->assertSee('data-test="placement-rank">'.__('Platinum').' I</h2>', false);

    $this->actingAs($placing)->get(route('clans.index'))->assertOk()->assertDontSee('data-test="placement-reveal"', false);
    $this->actingAs($veteran)->get(route('clans.index'))->assertOk()->assertDontSee('data-test="placement-reveal"', false);

    expect($reveal->refresh()->shown_at)->not->toBeNull();
});

test('two requests racing for one reveal show it once', function () {
    $player = User::factory()->create();
    PlacementReveal::query()->create(['user_id' => $player->id, 'rating_id' => placementRating($player, 1000, 5)->id, 'game' => 'chess', 'mode' => 'blitz', 'rating' => 1000, 'tier' => 'silver-3']);
    $placements = app(Placements::class);

    // Both requests read the unshown reveal; the first marks it, the second finds it taken.
    $read = PlacementReveal::query()->sole();
    $raced = PlacementReveal::query()->sole();

    expect($placements->take($read))->toBeTrue()
        ->and($placements->take($raced))->toBeFalse()
        ->and($placements->claim($player))->toBeNull();
});

test('a retried result, a casual fifth result and a sixth rated result reveal nothing new', function () {
    [$a, $b] = User::factory()->count(2)->create();
    placementRating($a, 1000, 4);
    placementRating($b, 1000, 5);

    $game = placementGame($a, $b);
    app(RatingService::class)->applyChessGame($game);

    // The same result handed in again: rating and reveal stay as they are.
    expect(app(RatingService::class)->applyChessGame($game))->toBeFalse();
    [$first, $second] = RatingChange::query()->where('source_id', $game->id)->orderBy('id')->get()->all();
    app(ResultEngagement::class)->handle($first, $second);

    expect(PlacementReveal::query()->pluck('user_id')->all())->toBe([$a->id]);

    // b had five before: the sixth places nobody; a casual game never does.
    $c = User::factory()->create();
    app(RatingService::class)->applyChessGame(placementGame($b, $c, rated: false));
    app(RatingService::class)->applyChessGame(placementGame($b, $a));

    expect(PlacementReveal::query()->pluck('user_id')->all())->toBe([$a->id])
        ->and(Rating::query()->where('pool', Rating::CASUAL)->count())->toBe(2);
});

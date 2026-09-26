<?php

use App\Enums\ClanRole;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Rating;
use App\Models\User;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Http;
use Tests\Support\TrustedFacts;

/*
 * The clan numbers on /clans, the clan page and the Rocket League page come
 * from the league's records (ClanStats), never from sample figures; before
 * Block 0 the rated panels say they start then.
 */

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/**
 * Laser Eyes [LSR] (the tag the old sample ledger knew) with three members
 * rated 1200, 1100 and 1000 in the live season's blitz ladder, and one rated
 * win of its owner that earns the clan 3 hashrate points.
 *
 * @return array{0: Clan, 1: User}
 */
function clanWithRealNumbers(): array
{
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);

    $owner = User::factory()->create(['name' => 'owner']);
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'name' => 'Laser Eyes', 'clantag' => 'LSR']);
    $members = [$owner];

    foreach ([1, 2] as $i) {
        $members[] = $member = User::factory()->create();
        ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    }

    foreach ([1200, 1100, 1000] as $index => $value) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$members[$index]->id, 'user_id' => $members[$index]->id, 'rating' => $value, 'results' => 9]);
    }

    // Other ladders never count for the Clan Rating: casual blitz, rated daily chess.
    Rating::query()->create(['pool' => Rating::CASUAL, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$members[1]->id, 'user_id' => $members[1]->id, 'rating' => 1900, 'results' => 9]);
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'chess', 'mode' => 'correspondence', 'subject' => 'user:'.$members[2]->id, 'user_id' => $members[2]->id, 'rating' => 1800, 'results' => 9]);

    $outsider = User::factory()->create();
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $owner->id, 'black_id' => $outsider->id, 'clans_at_accept' => [$owner->pubkey => $clan->address()]]);
    expect(app(RatingService::class)->applyChessGame($game))->toBeTrue();

    return [$clan->refresh(), $owner];
}

test('the clans page and the clan page show the same real Clan Rating, Hashrate and Block Height', function () {
    [$clan, $owner] = clanWithRealNumbers();

    // After the win: 1200 + 16 (established k 32 against a provisional 1000), 1100, 1000.
    $owner->refresh();
    $top = Rating::query()->where(['pool' => Rating::RATED, 'user_id' => $owner->id])->value('rating');
    $clanRating = (int) round(($top + 1100 + 1000) / 3);

    $this->get(route('clans.index'))->assertOk()
        ->assertSeeInOrder(['id="cr-h"', 'Laser Eyes', (string) $clanRating, __('avg top 3'), $top.' · 1100 · 1000'], false)
        ->assertSeeInOrder(['id="hs-h"', 'Laser Eyes', '<b class="text-right">3</b>'], false)
        ->assertDontSee('1151 · 1089 · 1034')
        ->assertDontSee('data-test="rating-empty"', false);

    $this->get(route('clans.show', $clan))->assertOk()
        ->assertSee('data-test="clan-rating">'.$clanRating.'</b>', false)
        ->assertSee('data-test="hashrate-s">3</b>', false)
        ->assertSee('data-test="hashrate-w">3</b>', false)
        ->assertSeeInOrder(['owner', 'data-test="block-height">1</b>'], false)
        ->assertDontSee('#404')
        ->assertDontSee('Testnet Cup');

    $this->get(route('games.rocket-league'))->assertOk()
        ->assertSeeInOrder(['id="rl-hr"', 'Laser Eyes'], false);
});

test('before Block 0 every rated clan panel shows its empty state and no numbers', function () {
    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'name' => 'Laser Eyes', 'clantag' => 'LSR']);

    $this->get(route('clans.index'))->assertOk()
        ->assertSee('data-test="rating-empty"', false)
        ->assertSee('data-test="hashrate-empty"', false)
        ->assertSee(__('Clan Ratings start at Block 0, with the first rated blitz games.'))
        // The rating panel is the clan directory too: the clan stays listed, without numbers.
        ->assertSeeInOrder(['id="cr-h"', 'Laser Eyes', __('starts at Block 0')], false)
        ->assertDontSee('1151 · 1089 · 1034');

    $this->get(route('clans.show', $clan))->assertOk()
        ->assertSee('data-test="rating-empty"', false)
        ->assertSee('data-test="hashrate-empty"', false)
        ->assertSee('data-test="block-height">0</b>', false)
        ->assertSee('data-test="clan-rating">–</b>', false);

    $this->actingAs($owner)->get(route('clans.manage', $clan))->assertOk()->assertSee(__('starts at Block 0'));

    $this->get(route('games.rocket-league'))->assertOk()->assertSee('data-test="rl-hashrate-empty"', false);
});

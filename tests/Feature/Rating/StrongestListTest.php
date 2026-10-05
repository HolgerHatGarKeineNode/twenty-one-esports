<?php

use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\LadderBoard;
use App\Support\Rating\StrongestList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The cross-game "Strongest" list (P40): the live season's Global Rating
 * (docs/nips/esports.md "Global Rating") on its own page and as a home tile,
 * honest empty states without a live season or a ranked player, a fixed
 * number of queries at any size, and the njump.me links of the ladder Proof.
 */
function p40Blitz(User $user, string $season, int $rating, int $results): Rating
{
    return Rating::query()->create(['pool' => Rating::RATED, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
        'user_id' => $user->id, 'rating' => $rating, 'results' => $results, 'wins' => intdiv($results, 2), 'draws' => 0, 'losses' => $results - intdiv($results, 2)]);
}

/**
 * The NIP example in a live season: alice holds the best of five blitz
 * ratings (weight 4) and the better of two 2v2 lineups (one series), so her
 * Global Rating is 1225; the four others have fewer than five results.
 *
 * @return array{season: Season, alice: User, others: list<User>}
 */
function p40NipExample(string $slug = 'season-3'): array
{
    $season = openSeason(['slug' => $slug]);
    $users = User::factory()->count(5)->sequence(['name' => 'alice'], ['name' => 'bob'], ['name' => 'carol'], ['name' => 'dave'], ['name' => 'erin'])->create();
    $alice = $users->first();
    $others = array_values($users->slice(1)->all());
    Rating::query()->create(['pool' => Rating::RATED, 'season' => $slug, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$alice->id,
        'user_id' => $alice->id, 'rating' => 1028, 'results' => 4, 'wins' => 3, 'draws' => 1, 'losses' => 0]);
    foreach ($others as $index => $other) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => $slug, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$other->id,
            'user_id' => $other->id, 'rating' => 1000 - $index * 5, 'results' => 1, 'losses' => 1]);
    }

    $match = SeriesMatch::factory()->create(['mode' => '2v2', 'rated' => true, 'resolved_roster' => [
        ['user_id' => $alice->id, 'pubkey' => $alice->pubkey, 'name' => 'alice', 'side' => 'challenger', 'role' => 'player'],
        ['user_id' => $others[0]->id, 'pubkey' => $others[0]->pubkey, 'name' => 'bob', 'side' => 'challenged', 'role' => 'player'],
    ]]);
    foreach ([[$match->challenger_lineup_id, 1016], [$match->challenged_lineup_id, 984]] as [$lineupId, $rating]) {
        $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => $slug, 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineupId,
            'lineup_id' => $lineupId, 'rating' => $rating, 'results' => 1, 'wins' => $rating > 1000 ? 1 : 0, 'losses' => $rating > 1000 ? 0 : 1]);
        RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::SERIES, 'source_id' => $match->id, 'score' => $rating > 1000 ? 1 : 0,
            'before' => 1000, 'after' => $rating, 'delta' => $rating - 1000, 'results_before' => 0]);
    }

    return ['season' => $season, 'alice' => $alice, 'others' => $others];
}

test('the list follows the NIP example: one ranked player, her games, and the four below the minimum counted', function () {
    ['alice' => $alice] = p40NipExample();

    $html = $this->get(route('ladder.strongest'))->assertOk()
        ->assertSee('data-test="strongest-season"', false)
        ->assertSee('1 ranked player')
        // Alice: four blitz results and one Rocket League series, in registry order.
        ->assertSee('Chess 4, RL 1')
        ->assertSee('4 more players have fewer than 5 rated results this season and are not ranked yet.')
        ->assertSee(route('players.show', $alice->npub), false)
        ->getContent();

    preg_match_all('/data-test="strongest-rating">(\d+)</', $html, $ratings);
    preg_match_all('/data-test="strongest-weight">(\d+)</', $html, $weights);
    expect($ratings[1])->toBe(['1225'])->and($weights[1])->toBe(['5'])
        ->and(substr_count($html, 'data-test="strongest-row"'))->toBe(1)
        // Two segments: chess weighs 4, Rocket League 1.
        ->and($html)->toContain('style="flex: 4 1 0; background: var(--color-chess)" data-game="chess"')
        ->and($html)->toContain('style="flex: 1 1 0; background: var(--color-rl)" data-game="rocket-league"');
});

test('the list ranks by Global Rating, then by weight, and agrees with the ladder page’s values', function () {
    openSeason(['slug' => 'season-1']);
    $users = User::factory()->count(7)->sequence(fn ($sequence) => ['name' => 'p'.$sequence->index])->create();
    // Seven blitz players; p3 has four results and no Global Rating.
    foreach ([[1400, 8], [1300, 6], [1200, 5], [1100, 4], [1000, 5], [900, 6], [800, 9]] as $index => [$rating, $results]) {
        p40Blitz($users[$index], 'season-1', $rating, $results);
    }

    $html = $this->get(route('ladder.strongest'))->assertOk()->getContent();
    preg_match_all('/data-test="strongest-name">([^<]+)</', $html, $names);
    preg_match_all('/data-test="strongest-rating">(\d+)</', $html, $ratings);
    $expected = LadderBoard::globalRatings('season-1');

    expect($names[1])->toBe(['p0', 'p1', 'p2', 'p4', 'p5', 'p6'])
        ->and(array_map('intval', $ratings[1]))->toBe(array_values(array_map(fn (User $user): int => $expected[$user->id], [$users[0], $users[1], $users[2], $users[4], $users[5], $users[6]])))
        ->and($html)->toContain('1 more player has fewer than 5 rated results this season and is not ranked yet.')
        // A strip is as long as the player's results against the busiest listed player (p6, 9): p0 has 8.
        ->and($html)->toContain('style="width: 88.9%" aria-hidden="true" data-test="game-strip"')
        ->and($html)->toContain('style="width: 100%" aria-hidden="true" data-test="game-strip"');

    // Home: the first five, in the same order.
    $home = $this->get(route('home'))->assertOk()->getContent();
    preg_match_all('/data-test="home-strongest-rating">(\d+)</', $home, $homeRatings);
    expect($homeRatings[1])->toBe(array_slice($ratings[1], 0, StrongestList::HOME));
});

test('before Block 0 the page and the home tile say the list opens at Block 0, without a number', function () {
    $this->get(route('ladder.strongest'))->assertOk()
        ->assertSee('data-state="pre-launch"', false)
        ->assertSee('The list opens at Block 0')
        ->assertDontSee('data-test="strongest-row"', false)
        ->assertDontSee('data-test="strongest-season"', false);

    $this->get(route('home'))->assertOk()
        ->assertSee('data-test="home-strongest-empty"', false)
        ->assertSee('The list opens with a live season: from Block 0, 5 rated results in any game put a player here.')
        ->assertDontSee('data-test="home-strongest-row"', false);
});

test('between seasons the list stays empty even though the ended season has ratings', function () {
    $ended = Season::factory()->ended()->create(['slug' => 'season-1']);
    foreach (User::factory()->count(3)->create() as $index => $user) {
        p40Blitz($user, $ended->slug, 1000 + $index * 50, 6);
    }

    $this->get(route('ladder.strongest'))->assertOk()
        ->assertSee('data-state="between"', false)
        ->assertSee('The season has ended')
        ->assertDontSee('data-test="strongest-row"', false);
});

test('in a live season without a ranked player the page counts who is on the way', function () {
    openSeason(['slug' => 'season-1']);
    foreach (User::factory()->count(2)->create() as $user) {
        p40Blitz($user, 'season-1', 1000, 3);
    }

    $this->get(route('ladder.strongest'))->assertOk()
        ->assertSee('data-state="live"', false)
        ->assertSee('Nobody has 5 rated results yet')
        ->assertSee('2 players are on the way.');

    $this->get(route('home'))->assertOk()
        ->assertSee('Nobody is ranked yet: 5 rated results in any game of the season put a player here.');
});

test('the page and the home tile run the same number of queries for six players and for sixty', function () {
    openSeason(['slug' => 'season-1']);
    $count = function (string $uri): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $seed = function (int $players): void {
        foreach (User::factory()->count($players)->create() as $index => $user) {
            p40Blitz($user, 'season-1', 900 + $index * 7, 5 + $index % 4);
        }
    };

    $seed(6);
    $smallPage = $count(route('ladder.strongest'));
    $smallHome = $count(route('home'));
    $seed(54);
    $largePage = $count(route('ladder.strongest'));
    $largeHome = $count(route('home'));

    fwrite(STDERR, "\n[strongest queries] page 6 players {$smallPage}, 60 players {$largePage}; home {$smallHome}, {$largeHome}\n");
    expect($largePage)->toBe($smallPage)->and($largeHome)->toBe($smallHome);
});

test('the ranking is cached until the next rating change', function () {
    ['alice' => $alice] = p40NipExample();
    $list = StrongestList::current();

    expect($list->ranking()['ranked'][0]['rating'])->toBe(1225);

    // A new blitz result for bob changes the stamp, so the list recomputes.
    $bob = Rating::query()->where('game', 'chess')->where('user_id', '!=', $alice->id)->orderByDesc('rating')->firstOrFail();
    $bob->forceFill(['rating' => 1100, 'results' => 5, 'losses' => 5])->save();
    RatingChange::query()->create(['rating_id' => $bob->id, 'source' => RatingChange::CHESS, 'source_id' => 777, 'score' => 1,
        'before' => 1000, 'after' => 1100, 'delta' => 100, 'results_before' => 4]);

    $ranked = StrongestList::current()->ranking()['ranked'];
    expect(array_column($ranked, 'user'))->toBe([$bob->user_id, $alice->id]);
});

test('the ladder Proof links the ladder address and its signer to njump.me and keeps the protocol link', function () {
    $season = openSeason(['slug' => 'season-1']);
    NostrEvent::query()->create(['event_id' => str_repeat('a', 64), 'pubkey' => $season->league_pubkey, 'kind' => 32152, 'd' => 'chess/blitz/season-1',
        'signed_at' => now()->subMinutes(5)->getTimestamp(), 'raw' => '{}']);
    p40Blitz(User::factory()->create(), 'season-1', 1000, 2);

    $naddr = NostrKeys::naddr(32152, $season->league_pubkey, 'chess/blitz/season-1');
    $npub = NostrKeys::hexToNpub($season->league_pubkey);

    $html = $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=rated')->assertOk()
        ->assertSee('<a href="https://njump.me/'.$naddr.'" rel="noopener noreferrer" target="_blank"', false)
        ->assertSee('<a href="https://njump.me/'.$npub.'" rel="noopener noreferrer" target="_blank"', false)
        ->assertSee('(opens njump.me in a new tab)')
        ->assertSee(route('protocol').'#verify', false)
        ->assertSee(route('ladder.strongest'), false)
        ->getContent();

    expect(substr_count($html, 'data-test="proof-link"'))->toBe(2);
});

test('a casual ladder’s Proof has no njump link', function () {
    p40Blitz(User::factory()->create(), '', 1000, 2)->forceFill(['pool' => Rating::CASUAL])->save();

    $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=casual')->assertOk()
        ->assertDontSee('njump.me', false);
});

test('the navigation links the list beside each game’s ladder and once in the phone’s More sheet, not on every hub card', function () {
    $html = $this->get(route('ladder.strongest'))->assertOk()->getContent();

    expect($html)->toContain('data-test="ctx-strongest"')
        ->and($html)->toContain('data-test="mobile-strongest"')
        ->and(substr_count($html, 'href="'.route('ladder.strongest').'"'))->toBe(2)
        // The ctx link of the current page is marked.
        ->and($html)->toMatch('/href="'.preg_quote(route('ladder.strongest'), '/').'"(?:\s+wire:navigate)?\s+aria-current="page"\s+class="ctx-link"/')
        ->and($html)->toMatch('/aria-current="page"\s+data-test="mobile-strongest"/');
});

test('the German page reads German', function () {
    p40NipExample();
    app()->setLocale('de');

    $this->withSession(['locale' => 'de'])->get(route('ladder.strongest'))->assertOk()
        ->assertSee('Die stärksten Spieler')
        ->assertSee('1 Spieler in der Wertung')
        ->assertSee('4 weitere Spieler haben in dieser Season weniger als 5 gewertete Ergebnisse und sind noch nicht in der Wertung.');
});

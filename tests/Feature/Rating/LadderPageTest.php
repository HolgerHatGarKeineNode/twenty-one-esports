<?php

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\LadderBoard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The ladder page around its rows (P32): season context, overview and share
 * of wins, tier lines, form, Block Height and Global Rating, the clan view
 * and the Proof, by state (Pre-Season, rated, casual) and with a fixed
 * number of queries whatever the ladder's size.
 */

/**
 * A rated chess blitz row with `$results` results; `$form` adds that many
 * rated changes (1 win, 0.5 draw, 0 loss), oldest first.
 *
 * @param  list<float>  $form
 */
function p32Row(User $user, string $pool, int $rating, int $results, int $wins, array $form = [], string $game = 'chess', string $mode = 'blitz'): Rating
{
    $row = Rating::query()->create([
        'pool' => $pool, 'season' => $pool === Rating::RATED ? 'season-1' : '', 'game' => $game, 'mode' => $mode,
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results,
        'wins' => $wins, 'draws' => 0, 'losses' => $results - $wins,
    ]);

    foreach ($form as $index => $score) {
        RatingChange::query()->create([
            'rating_id' => $row->id, 'source' => RatingChange::CHESS, 'source_id' => 9000 + $row->id * 10 + $index, 'match_number' => 400 + $index,
            'score' => $score, 'before' => $rating, 'after' => $rating, 'delta' => 0, 'results_before' => $index,
        ])->forceFill(['created_at' => now()->subMinutes(100 - $index)])->save();
    }

    return $row;
}

function p32Member(Clan $clan, User $user): void
{
    ClanMember::query()->firstOrCreate(['user_id' => $user->id], ['clan_id' => $clan->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
}

/**
 * Four rated blitz players (two clans), the ladder's event and one
 * attestation on it.
 *
 * @return array{season: Season, users: list<User>, clans: list<Clan>}
 */
function p32RatedLadder(): array
{
    $season = openSeason(['slug' => 'season-1']);
    [$top, $high, $mid, $low] = User::factory()->count(4)->sequence(['name' => 'topdog'], ['name' => 'highroller'], ['name' => 'midway'], ['name' => 'lowkey'])->create();
    $orange = Clan::factory()->create(['name' => 'Orange Squad', 'clantag' => 'ORG', 'owner_id' => $top->id]);
    $blue = Clan::factory()->create(['name' => 'Blue Nodes', 'clantag' => 'BLU', 'owner_id' => $low->id]);
    p32Member($orange, $high);
    p32Member($orange, $mid);

    p32Row($top, Rating::RATED, 1430, 12, 9, [1.0, 1.0, 0.0, 1.0, 1.0, 1.0]);
    p32Row($high, Rating::RATED, 1180, 7, 4, [0.0, 1.0]);
    p32Row($mid, Rating::RATED, 1010, 4, 2);
    p32Row($low, Rating::RATED, 940, 6, 1, [0.0, 0.5, 0.0]);

    $d = 'chess/blitz/season-1';
    NostrEvent::query()->create(['event_id' => str_repeat('a', 64), 'pubkey' => $season->league_pubkey, 'kind' => 32152, 'd' => $d, 'signed_at' => now()->subMinutes(5)->getTimestamp(), 'raw' => '{}']);

    return ['season' => $season, 'users' => [$top, $high, $mid, $low], 'clans' => [$orange, $blue]];
}

test('a rated player ladder shows the season, the overview, tier lines, form, Block Height, Global Rating and the proof', function () {
    ['season' => $season] = p32RatedLadder();

    $response = $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk();

    $response->assertSee('data-test="ladder-season"', false)->assertSee('since')->assertSee(route('mining'), false)
        // Overview: 11 distinct games from the changes, 4 players, average Elo round((1430+1180+1010+940)/4) = 1140.
        ->assertSeeInOrder(['data-test="ladder-stat-games">11<', 'data-test="ladder-stat-entries">4<', 'data-test="ladder-stat-average">1140<'], false)
        ->assertSee('Standings after match #402')
        // Share of wins: 9 + 4 + 2 + 1 = 16 wins, most first.
        ->assertSeeInOrder(['Share of wins', '16 wins on this ladder', 'topdog', '56%', 'highroller', '25%', 'midway', '13%', 'lowkey', '6%'])
        // One tier line above each group, drawn from the config thresholds (a provisional row sits by its Elo).
        ->assertSeeInOrder(['Grand Champion III from 1425', 'topdog', 'Diamond I from 1175', 'highroller', 'Silver III from 1000', 'midway', 'Bronze III from 925', 'lowkey'])
        // Form: the last five, oldest first (topdog's sixth-latest drops out).
        ->assertSee('Last results, oldest first: win, loss, win, win, win')
        ->assertSee('Last results, oldest first: loss, draw, loss')
        ->assertSee('Block Height')->assertSee('Global');

    // Block Height counts rated changes (6 and 3), Global Rating needs five rated results:
    // topdog p = 3.5/4 → 1000 + 200·invnorm(0.875) = 1230, highroller p = 2.5/4 → 1064, lowkey p = 0.5/4 → 770, midway (4) none.
    $html = $response->getContent();
    preg_match_all('/data-test="ladder-height">([^<]*)</', $html, $heights);
    preg_match_all('/data-test="ladder-global">([^<]*)</', $html, $globals);
    expect(substr_count($html, 'data-test="tier-line"'))->toBe(4)
        ->and($heights[1])->toBe(['6', '2', '0', '3'])
        ->and($globals[1])->toBe(['1230', '1064', '–', '770']);

    $naddr = NostrKeys::naddr(32152, $season->league_pubkey, 'chess/blitz/season-1');
    $response->assertSee('data-test="ladder-proof"', false)
        ->assertSee(substr($naddr, 0, 12).'…'.substr($naddr, -4).' · kind 32152')
        ->assertSee(substr(NostrKeys::hexToNpub($season->league_pubkey), 0, 12))
        ->assertSee(route('protocol').'#verify', false)
        ->assertDontSee('Not built here');
});

test('the casual ladder has no tiers, no Block Height or Global Rating, and its proof says it stays with the league', function () {
    [$one, $two] = User::factory()->count(2)->sequence(['name' => 'casualcarl'], ['name' => 'casualcora'])->create();
    p32Row($one, Rating::CASUAL, 1040, 3, 2, [1.0, 1.0, 0.0]);
    p32Row($two, Rating::CASUAL, 960, 3, 1, [0.0, 0.0, 1.0]);

    $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk()
        ->assertSee('aria-pressed="true" data-test="pool-casual"', false)
        ->assertSeeInOrder(['Share of wins', 'casualcarl', '67%', 'casualcora', '33%'])
        ->assertSee('Last results, oldest first: win, win, loss')
        ->assertDontSee('data-test="tier-line"', false)
        ->assertDontSee('data-test="ladder-height"', false)
        ->assertDontSee('data-test="ladder-global"', false)
        ->assertDontSee('data-test="ladder-season"', false)
        ->assertSee('casual: no Nostr events, league data only')
        ->assertSee('Casual ratings stay with the league; only rated results go on Nostr.');
});

test('before Block 0 the rated ladder is the Pre-Season card without overview, table or proof', function () {
    $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=rated')->assertOk()
        ->assertSee('data-test="ladder-preseason"', false)
        ->assertDontSee('data-test="ladder-overview"', false)
        ->assertDontSee('data-test="ladder-proof"', false)
        ->assertDontSee('data-test="ladder-season"', false);
});

test('an empty open ladder invites the first game and shows no overview', function () {
    openSeason(['slug' => 'season-1']);

    $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=rated')->assertOk()
        ->assertSee('data-test="ladder-empty-play"', false)
        ->assertDontSee('data-test="ladder-overview"', false)
        ->assertSee('Block 0')
        ->assertSee('not published yet');
});

test('the clan view of a player ladder ranks clans by the average of their three best members', function () {
    p32RatedLadder();

    $response = $this->get(route('ladder.show', ['chess', 'blitz']).'?view=clans')->assertOk()
        ->assertSee('aria-pressed="true" data-test="view-clans"', false)
        ->assertDontSee('data-test="tier-line"', false);

    // Orange: 1430, 1180, 1010 → 1207; Blue has one rated member, so no value and no rank.
    $response->assertSeeInOrder(['Orange Squad', '1207', '#1', 'Blue Nodes', '1 of 3 players']);
    expect(substr_count($response->getContent(), 'data-test="ladder-clan"'))->toBe(2);
});

test('the clan view of a lineup game lists every clan with its lineup in each mode', function () {
    openSeason(['slug' => 'season-1']);
    $strikers = Lineup::factory()->mode('3v3')->create();
    $strikers->clan->forceFill(['name' => 'Strikers'])->save();
    $strikers2 = Lineup::factory()->mode('2v2')->create(['clan_id' => $strikers->clan_id]);
    $rockets = Lineup::factory()->mode('3v3')->create();
    $rockets->clan->forceFill(['name' => 'Rockets'])->save();

    foreach ([[$strikers, 1100], [$strikers2, 990], [$rockets, 1150]] as [$lineup, $rating]) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-1', 'game' => 'rocket-league', 'mode' => $lineup->mode,
            'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => $rating, 'results' => 3, 'wins' => 2, 'losses' => 1]);
    }

    $html = $this->get(route('ladder.show', ['rocket-league', '3v3']).'?view=clans')->assertOk()
        ->assertSeeInOrder(['Rockets', '1150', '#1', 'Strikers', '990', '#1', '1100', '#2'])
        ->getContent();

    expect(substr_count($html, 'data-test="ladder-clan"'))->toBe(2);
});

test('the Global Rating follows the NIP example: blitz results and a lineup series weighted together', function () {
    // docs/nips/esports.md "Global Rating": alice's 1028 is the highest of five blitz ratings (p = 0.9, weight 4)
    // and her lineup the higher of two in 2v2 (p = 0.75, one series) → pbar 0.87 → 1225.
    $users = User::factory()->count(5)->create();
    $alice = $users->first();
    $others = $users->slice(1)->values();
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-3', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$alice->id,
        'user_id' => $alice->id, 'rating' => 1028, 'results' => 4, 'wins' => 3, 'draws' => 1, 'losses' => 0]);
    foreach ($others as $index => $other) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-3', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$other->id,
            'user_id' => $other->id, 'rating' => 1000 - $index * 5, 'results' => 1, 'losses' => 1]);
    }

    $match = SeriesMatch::factory()->create(['mode' => '2v2', 'rated' => true, 'resolved_roster' => [
        ['user_id' => $alice->id, 'pubkey' => $alice->pubkey, 'name' => 'alice', 'side' => 'challenger', 'role' => 'player'],
        ['user_id' => $others[0]->id, 'pubkey' => $others[0]->pubkey, 'name' => 'x', 'side' => 'challenged', 'role' => 'player'],
    ]]);
    foreach ([[$match->challenger_lineup_id, 1016], [$match->challenged_lineup_id, 984]] as [$lineupId, $rating]) {
        $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => 'season-3', 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineupId,
            'lineup_id' => $lineupId, 'rating' => $rating, 'results' => 1, 'wins' => $rating > 1000 ? 1 : 0, 'losses' => $rating > 1000 ? 0 : 1]);
        RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::SERIES, 'source_id' => $match->id, 'score' => $rating > 1000 ? 1 : 0,
            'before' => 1000, 'after' => $rating, 'delta' => $rating - 1000, 'results_before' => 0]);
    }

    expect(LadderBoard::globalRatings('season-3'))->toBe([$alice->id => 1225]);
});

test('the ladder page runs the same number of queries for four rows and for forty', function () {
    p32RatedLadder();
    $html = '';
    $count = function () use (&$html): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = (string) $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk()->getContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count();
    $clan = Clan::query()->where('name', 'Orange Squad')->firstOrFail();
    foreach (User::factory()->count(36)->create() as $index => $user) {
        p32Member($clan, $user);
        p32Row($user, Rating::RATED, 900 + $index * 10, 6, 3, [1.0, 0.0, 1.0]);
    }
    $large = $count();

    fwrite(STDERR, "\n[ladder queries] 4 rows {$small}, 40 rows {$large}\n");
    // One tier line per tier that has rows, not one per row.
    $tiers = Rating::query()->where('pool', Rating::RATED)->pluck('rating')->map(fn (int $rating): string => LadderBoard::line($rating)['token'])->unique()->count();
    expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(40)
        ->and(substr_count($html, 'data-test="tier-line"'))->toBe($tiers)->and($tiers)->toBeLessThan(40);
});

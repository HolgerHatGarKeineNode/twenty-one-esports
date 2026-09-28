<?php

use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use Livewire\Livewire;

/*
 * A series game's page (P26): the match cards (live, next, final), the
 * ladder's top five, the clans of the game and the one-line empty states.
 */

beforeEach(fn () => $this->freezeTime());

test('the match cards run live, next, final, only of this game, and a ready check is no card', function () {
    $live = SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(10)]);
    $open = SeriesMatch::factory()->create(['status' => SeriesStatus::Open]);
    $final = SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subHour(), 'finished_at' => now()->subMinutes(30),
        'result_games' => [['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger']]]);
    $fc = SeriesMatch::factory()->create([
        'challenger_lineup_id' => Lineup::factory()->game('ea-sports-fc-27', '1v1')->ready()->create()->id,
        'challenged_lineup_id' => Lineup::factory()->game('ea-sports-fc-27', '1v1')->ready()->create()->id,
        'status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5),
    ]);
    [$readyCheck] = casualPairing('rocket-league');

    $cards = Livewire::test('pages::games.series', ['slug' => 'rocket-league'])->instance()->matches['cards'];

    expect(array_map(fn (array $card) => [$card['match']->id, $card['state']], $cards))->toBe([[$live->id, 'live'], [$open->id, 'next'], [$final->id, 'final']])
        ->and(collect($cards)->pluck('match.id'))->not->toContain($fc->id)->not->toContain($readyCheck->id);

    $this->get(route('games.rocket-league'))->assertOk()
        ->assertSeeInOrder(['data-test="game-matches"', 'data-state="live"', 'data-state="next"', 'data-state="final"'], false)
        ->assertSee(route('matches.show', $final), false)
        ->assertDontSee('data-test="game-matches-empty"', false);
});

test('a player side shows the player, a lineup side the clan', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = SeriesMatch::factory()->create([
        'challenger_lineup_id' => null, 'challenged_lineup_id' => null,
        'number' => MatchNumber::query()->create(['user_id' => $anna->id, 'used_at' => now()])->id,
        'created_by_id' => null, 'game' => 'rocket-league', 'mode' => '1v1',
        'challenger_name' => $anna->displayName(), 'challenged_name' => $bert->displayName(), 'challenger_tag' => 'ANNA', 'challenged_tag' => 'BERT',
        'challenger_lineup_address' => '', 'challenged_lineup_address' => '',
        'sides' => ['challenger' => [$anna->id], 'challenged' => [$bert->id]],
        'status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5),
    ]);

    $this->get(route('games.rocket-league'))->assertOk()
        ->assertSee(route('matches.show', $match), false)
        ->assertSee('data-avatar', false)
        ->assertSee(e($anna->displayName()), false);
});

test('the ladder shows the top five of the mode with the most results, rated before casual', function () {
    $lineups = Lineup::factory()->count(6)->ready()->create();
    foreach ($lineups as $index => $lineup) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '3v3', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id,
            'rating' => 1000 + $index * 10, 'results' => 2, 'wins' => 1, 'draws' => 0, 'losses' => 1]);
    }
    $player = User::factory()->create();
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$player->id, 'user_id' => $player->id,
        'rating' => 1300, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);

    $ladder = Livewire::test('pages::games.series', ['slug' => 'rocket-league'])->instance()->ladder;

    expect($ladder['mode'])->toBe('3v3')
        ->and($ladder['pool'])->toBe(Rating::CASUAL)
        ->and($ladder['rows']->pluck('rating')->all())->toBe([1050, 1040, 1030, 1020, 1010]);

    $this->get(route('games.rocket-league'))->assertOk()
        ->assertSee('3v3 ladder')->assertSee(route('ladder.show', ['rocket-league', '3v3']), false)
        ->assertSeeInOrder(['data-test="game-ladder"', $lineups[5]->clan->name, $lineups[4]->clan->name], false);
});

test('the clans of the game are listed with their wins here, the most first', function () {
    $winner = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['name' => 'Laser Eyes'])->id]);
    $loser = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['name' => 'Aardvark Army'])->id]);
    Clan::factory()->create(['name' => 'Chess Only']);
    SeriesMatch::factory()->create(['challenger_lineup_id' => $winner->id, 'challenged_lineup_id' => $loser->id, 'status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()]);

    $this->get(route('games.rocket-league'))->assertOk()
        ->assertSeeInOrder(['data-test="game-clans"', 'Laser Eyes', '1 win', 'Aardvark Army', '0 wins'], false)
        ->assertDontSee('Chess Only');
});

test('a game with nothing played shows one line per part and no chart', function () {
    $this->get(route('games.series', 'ea-sports-fc-26'))->assertOk()
        ->assertSee('data-test="game-matches-empty"', false)
        ->assertSee('data-test="game-ladder-empty"', false)
        ->assertSee('data-test="rl-hashrate-empty"', false)
        ->assertSee('data-test="game-clans-empty"', false)
        ->assertDontSee('data-test="game-weeks"', false)
        ->assertDontSee('role="img"', false)
        ->assertSee('data-test="game-worth"', false);
});

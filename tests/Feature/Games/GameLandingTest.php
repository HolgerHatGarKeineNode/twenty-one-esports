<?php

use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Games\GameLanding;
use Illuminate\Support\Facades\DB;

/*
 * A game landing's own numbers (P56, App\Support\Games\GameLanding): the
 * pulse of the hero, the viewer's open series and standing, the three first
 * steps, and the page built from them: one primary action, counts that are
 * rows, and a query count that does not grow with the data.
 */

beforeEach(fn () => $this->freezeTime());

function landingRating(string $game, string $mode, array $attributes): Rating
{
    $subject = isset($attributes['user_id']) ? 'user:'.$attributes['user_id'] : 'lineup:'.$attributes['lineup_id'];

    return Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $game, 'mode' => $mode, 'subject' => $subject,
        'rating' => 1000, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0, ...$attributes]);
}

function landingDuel(User $a, User $b, string $game, array $attributes): SeriesMatch
{
    return SeriesMatch::factory()->create([
        'challenger_lineup_id' => null, 'challenged_lineup_id' => null,
        'number' => MatchNumber::query()->create(['user_id' => $a->id, 'used_at' => now()])->id,
        'created_by_id' => null, 'game' => $game, 'mode' => '1v1', 'best_of' => 3,
        'challenger_name' => $a->displayName(), 'challenged_name' => $b->displayName(), 'challenger_tag' => '', 'challenged_tag' => '',
        'challenger_lineup_address' => '', 'challenged_lineup_address' => '',
        'sides' => ['challenger' => [$a->id], 'challenged' => [$b->id]], ...$attributes,
    ]);
}

test('a game with nothing yet has a zero pulse, no open series, no standing and no step done', function () {
    $landing = app(GameLanding::class);
    $user = User::factory()->create();

    expect($landing->pulse('rocket-league'))->toBe(['live' => 0, 'open' => 0, 'searching' => 0, 'clans' => 0, 'ranked' => 0])
        ->and($landing->chessPulse())->toBe(['live' => 0, 'searching' => 0, 'daily' => 0, 'ranked' => 0])
        ->and($landing->openMatch($user, 'rocket-league'))->toBeNull()
        ->and($landing->standing($user, 'rocket-league'))->toBeNull()
        ->and($landing->steps(null, 'rocket-league'))->toBe(['login' => false, 'side' => false, 'played' => false])
        ->and($landing->steps($user, 'rocket-league'))->toBe(['login' => true, 'side' => false, 'played' => false])
        ->and($landing->nextTournament('rocket-league'))->toBeNull();
});

test('the pulse counts rows of this game only: live and open series, searchers, clans with a lineup, ladder entries with a result', function () {
    SeriesMatch::factory()->accepted()->create();                                           // live: accepted, started
    SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted, 'start_at' => now()->addHour()]); // accepted, still ahead
    SeriesMatch::factory()->count(2)->create();                                            // open challenges
    SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
    [$fcA, $fcB] = User::factory()->count(2)->create();
    landingDuel($fcA, $fcB, 'ea-sports-fc-27', ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5)]);

    $searcher = User::factory()->create();
    SeriesQueueEntry::query()->create(['user_id' => $searcher->id, 'game' => 'rocket-league', 'mode' => '1v1', 'platform' => 'pc', 'crossplay' => true, 'joined_at' => now()]);
    SeriesQueueEntry::query()->create(['user_id' => $fcA->id, 'game' => 'ea-sports-fc-27', 'mode' => '1v1', 'platform' => 'pc', 'crossplay' => true, 'joined_at' => now()]);

    // A second lineup of one clan counts that clan once.
    $clan = Lineup::query()->first()->clan;
    Lineup::factory()->mode('2v2')->create(['clan_id' => $clan->id]);

    $ranked = User::factory()->create();
    landingRating('rocket-league', '1v1', ['user_id' => $ranked->id]);
    landingRating('rocket-league', '1v1', ['user_id' => $ranked->id, 'pool' => Rating::RATED, 'season' => 's1']);
    landingRating('rocket-league', '1v1', ['user_id' => $searcher->id, 'results' => 0]);
    landingRating('ea-sports-fc-27', '1v1', ['user_id' => $fcA->id]);

    // Every series factory row makes two fresh clans with a lineup each: four series, eight clans.
    expect(app(GameLanding::class)->pulse('rocket-league'))->toBe(['live' => 1, 'open' => 2, 'searching' => 1, 'clans' => 10, 'ranked' => 1])
        ->and(app(GameLanding::class)->pulse('ea-sports-fc-27'))->toBe(['live' => 1, 'open' => 0, 'searching' => 1, 'clans' => 0, 'ranked' => 1]);
});

test('the chess pulse counts live boards, the blitz queue, daily games and chess ladder entries', function () {
    ChessGame::factory()->count(2)->create();
    ChessGame::factory()->daily()->create();
    ChessGame::factory()->finished()->create();
    $waiting = User::factory()->create();
    ChessQueueEntry::query()->create(['user_id' => $waiting->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()]);
    landingRating('chess', 'blitz', ['user_id' => $waiting->id]);

    expect(app(GameLanding::class)->chessPulse())->toBe(['live' => 2, 'searching' => 1, 'daily' => 1, 'ranked' => 1]);
});

test('the viewer\'s open series is theirs and of this game, live before the next one', function () {
    $mine = Lineup::factory()->ready()->create();
    $me = $mine->clan->owner;
    $open = SeriesMatch::factory()->create(['challenger_lineup_id' => $mine->id]);
    $live = SeriesMatch::factory()->accepted()->create(['challenged_lineup_id' => $mine->id]);
    SeriesMatch::factory()->accepted()->create();                                           // someone else's
    [$other] = User::factory()->count(1)->create();
    $fc = landingDuel($me, $other, 'ea-sports-fc-27', ['status' => SeriesStatus::Open]);

    $landing = app(GameLanding::class);

    expect($landing->openMatch($me, 'rocket-league')?->id)->toBe($live->id)
        ->and($landing->openMatch($me, 'ea-sports-fc-27')?->id)->toBe($fc->id);

    $live->forceFill(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()])->save();

    expect($landing->openMatch($me, 'rocket-league')?->id)->toBe($open->id)
        ->and($landing->openMatch(User::factory()->create(), 'rocket-league'))->toBeNull();
});

test('the standing is the viewer\'s best entry with a result and its place, their lineup\'s included', function () {
    $mine = Lineup::factory()->ready()->create();
    $me = $mine->clan->owner;
    foreach ([1200, 1100, 1050] as $rating) {
        landingRating('rocket-league', '3v3', ['lineup_id' => Lineup::factory()->ready()->create()->id, 'rating' => $rating]);
    }
    landingRating('rocket-league', '3v3', ['lineup_id' => $mine->id, 'rating' => 1080, 'results' => 4]);
    landingRating('rocket-league', '1v1', ['user_id' => $me->id, 'rating' => 1020]);
    landingRating('rocket-league', '2v2', ['user_id' => $me->id, 'rating' => 1500, 'results' => 0]);

    expect(app(GameLanding::class)->standing($me, 'rocket-league'))->toBe(['mode' => '3v3', 'pool' => Rating::CASUAL, 'rating' => 1080, 'results' => 4, 'rank' => 3, 'lineup' => true])
        ->and(app(GameLanding::class)->standing($me, 'ea-sports-fc-27'))->toBeNull();
});

test('the steps tick a clan and a first finished series of this game', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    Clan::factory()->create(['owner_id' => $anna->id]);
    landingDuel($anna, $bert, 'ea-sports-fc-27', ['status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'start_at' => now()->subDay(), 'finished_at' => now()->subDay()]);

    $landing = app(GameLanding::class);

    expect($landing->steps($anna, 'rocket-league'))->toBe(['login' => true, 'side' => true, 'played' => false])
        ->and($landing->steps($bert, 'ea-sports-fc-27'))->toBe(['login' => true, 'side' => true, 'played' => true])
        ->and($landing->steps($bert, 'rocket-league'))->toBe(['login' => true, 'side' => false, 'played' => false]);
});

test('the page leads with one action per viewer: log in, the own match, else a challenge, and pulse links carry the counts', function () {
    $mine = Lineup::factory()->ready()->create();
    $captain = $mine->clan->owner;
    $open = SeriesMatch::factory()->create(['challenger_lineup_id' => $mine->id]);
    $cta = fn (string $html): string => str($html)->after('data-test="game-cta"')->before('>')->toString();

    $guest = $this->get(route('games.rocket-league'))->assertOk()->getContent();
    $player = $this->actingAs(User::factory()->create())->get(route('games.rocket-league'))->assertOk()->getContent();
    $own = $this->actingAs($captain)->get(route('games.rocket-league'))->assertOk()->getContent();

    expect($cta($guest))->toContain('data-action="login"')
        // The chat relays are off in tests, so no casual 1v1 to lead with: a challenge.
        ->and($cta($player))->toContain('data-action="challenge"')
        ->and($cta($own))->toContain('data-action="match"')
        ->and($own)->toContain('href="'.route('matches.show', $open).'"')
        ->and($own)->toContain('data-test="game-pulse-open" data-count="1"')
        ->and($own)->toContain('data-test="game-pulse-clans" data-count="2"')
        // The hero comes before the invite, and the invite before the matches.
        ->and(strpos($own, 'data-test="game-cta"'))->toBeLessThan(strpos($own, 'data-test="invite-module"'))
        ->and(strpos($own, 'data-test="invite-module"'))->toBeLessThan(strpos($own, 'data-test="game-matches"'))
        ->and($guest)->not->toContain('data-test="game-steps"')
        ->and($player)->toContain('data-test="game-steps"');
});

test('the page shows a standing once the player has a result', function () {
    $me = User::factory()->create();
    landingRating('ea-sports-fc-27', '1v1', ['user_id' => $me->id, 'rating' => 1043, 'results' => 3]);
    landingRating('ea-sports-fc-27', '1v1', ['user_id' => User::factory()->create()->id, 'rating' => 1100]);

    $this->actingAs($me)->get(route('games.series', 'ea-sports-fc-27'))->assertOk()
        ->assertSeeHtml('data-test="game-standing-rank">#2</b>')
        ->assertSee('1043 Elo after 3 series')
        ->assertDontSeeHtml('data-test="game-steps"');
});

test('the landing\'s query count does not grow with the series, clans and ladder entries', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('games.rocket-league'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $seed = function (int $n): void {
        foreach (range(1, $n) as $i) {
            $series = SeriesMatch::factory()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'start_at' => now()->subDays($i), 'finished_at' => now()->subDays($i),
                'result_games' => [['challenger' => 2, 'challenged' => 1, 'winner' => 'challenger']]]);
            landingRating('rocket-league', '3v3', ['lineup_id' => $series->challenger_lineup_id, 'rating' => 1000 + $i]);
        }
        Tournament::factory()->signup()->create(['game' => 'rocket-league', 'mode' => '3v3', 'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3)]);
    };

    $seed(2);
    // The first request fills the caches of the shell (footer counts, the header's tournaments): measured from the second.
    $count();
    $few = $count();
    $seed(10);
    $many = $count();

    expect($many)->toBe($few);
});

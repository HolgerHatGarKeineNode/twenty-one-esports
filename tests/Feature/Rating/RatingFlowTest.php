<?php

use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Rating\Ratings;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrustedFacts;

/**
 * Ratings at result time (P7b): chess games rate their players, casual and
 * rated apart, idempotent per game; one match-number sequence for chess and
 * Rocket League; the ladder page. Series ratings are in SeriesFlowTest.
 */
function openLadders(): void
{
    openSeason(['slug' => 'season-1']);
    // Rated results also need Trusted players who list each other (RatedTrustGate).
    app()->bind(TrustFacts::class, TrustedFacts::class);
}

/** Seeds a rating row as if the entity had played `$results` rated results. */
function seedRating(User $user, string $pool, int $rating, int $results, string $mode = 'blitz'): Rating
{
    return Rating::query()->create([
        'pool' => $pool, 'season' => $pool === Rating::RATED ? 'season-1' : '', 'game' => 'chess', 'mode' => $mode,
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results, 'wins' => $results,
    ]);
}

function ratingOf(User $user, string $pool = Rating::CASUAL, string $mode = 'blitz'): ?Rating
{
    return Rating::query()->where(['pool' => $pool, 'game' => 'chess', 'mode' => $mode, 'subject' => 'user:'.$user->id])->first();
}

/** A game that White wins by resignation after 1. e4, played through the service. */
function resignedGame(?User $white = null, ?User $black = null, string $mode = 'blitz'): ChessGame
{
    $service = app(ChessGameService::class);
    $game = ChessGame::factory()->create(array_filter(['white_id' => $white?->id, 'black_id' => $black?->id, 'mode' => $mode]));

    if ($mode === ChessGame::CORRESPONDENCE) {
        $game->forceFill(['initial_ms' => 86_400_000, 'increment_ms' => 0])->save();
    }

    $game = $service->move($game, $game->white, 'e2e4');

    return $service->resign($game->refresh(), $game->black)->refresh();
}

test('a finished casual game moves both casual ratings by the engine\'s numbers and records the history', function () {
    $game = resignedGame();

    $white = ratingOf($game->white);
    $black = ratingOf($game->black);

    // Both provisional: k 40, E 0.5, so the winner gains round(40 × 0.5) = 20.
    expect([$white->rating, $white->results, $white->wins, $black->rating, $black->results, $black->losses])->toBe([1020, 1, 1, 980, 1, 1])
        ->and(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $game->id)->orderBy('id')->get()
            ->map(fn (RatingChange $change) => [$change->before, $change->after, $change->delta, $change->score, $change->match_number])->all())
        ->toBe([[1000, 1020, 20, 1.0, $game->number], [1000, 980, -20, 0.0, $game->number]])
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0);
});

test('a rated game on an open ladder uses the rated k-factors and leaves the casual ratings alone', function () {
    openLadders();
    [$established, $newcomer] = [User::factory()->create(), User::factory()->create()];
    seedRating($established, Rating::RATED, 1000, 5);
    seedRating($established, Rating::CASUAL, 1234, 9);

    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $established->id, 'black_id' => $newcomer->id]);

    expect(app(RatingService::class)->applyChessGame($game))->toBeTrue()
        // Engine example: established k 32 gains 16, the provisional side (k 40) loses 20.
        ->and(ratingOf($established, Rating::RATED)->rating)->toBe(1016)
        ->and(ratingOf($newcomer, Rating::RATED)->rating)->toBe(980)
        ->and(ratingOf($established)->only(['rating', 'results']))->toBe(['rating' => 1234, 'results' => 9])
        ->and(ratingOf($newcomer))->toBeNull();
});

test('a casual result never reaches the rated ladder or a tier, even with the ladder open', function () {
    openLadders();
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    seedRating($a, Rating::RATED, 1300, 9);

    for ($i = 0; $i < 3; $i++) {
        app(RatingService::class)->applyChessGame(ChessGame::factory()->finished('1-0')->create(['white_id' => $a->id, 'black_id' => $b->id]));
    }

    expect(ratingOf($a, Rating::RATED)->only(['rating', 'results']))->toBe(['rating' => 1300, 'results' => 9])
        ->and(ratingOf($b, Rating::RATED))->toBeNull()
        ->and(ratingOf($a)->results)->toBe(3)
        ->and(Ratings::forUser($a->id, 'chess', 'blitz', Rating::CASUAL)['tier'])->toBeNull();
});

test('a game is rated once: retrying the result or finishing again changes nothing', function () {
    $game = resignedGame();
    $before = Rating::query()->orderBy('id')->pluck('rating')->all();

    expect(app(RatingService::class)->applyChessGame($game))->toBeFalse()
        ->and(fn () => app(ChessGameService::class)->resign($game, $game->white))->toThrow(ChessRuleViolation::class)
        ->and(Rating::query()->orderBy('id')->pluck('rating')->all())->toBe($before)
        ->and(RatingChange::query()->count())->toBe(2);

    // The unique index is the second lock: a raced duplicate insert fails.
    $change = RatingChange::query()->first();
    expect(fn () => RatingChange::query()->create([...$change->only(['rating_id', 'opponent_rating_id', 'source', 'source_id', 'score', 'before', 'after', 'delta', 'results_before'])]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an aborted game rates nothing', function () {
    $game = ChessGame::factory()->create();
    app(ChessGameService::class)->abort($game, $game->white);

    expect(RatingChange::query()->count())->toBe(0);
});

test('a rating is provisional below five results, then gets a tier on the rated ladder', function () {
    openLadders();
    config(['season.rating.daily_pair_limit' => null]);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    $rate = fn (bool $rated) => app(RatingService::class)->applyChessGame(ChessGame::factory()->when($rated, fn ($factory) => $factory->rated())->finished('1/2-1/2')->create(['white_id' => $a->id, 'black_id' => $b->id]));
    $summary = fn (string $pool) => Ratings::forUser($a->id, 'chess', 'blitz', $pool);

    foreach (range(1, 4) as $i) {
        $rate(true);
    }
    expect($summary(Rating::RATED))->toMatchArray(['results' => 4, 'provisional' => true, 'tier' => 'provisional']);

    $rate(true);
    expect($summary(Rating::RATED))->toMatchArray(['rating' => 1000, 'results' => 5, 'provisional' => false, 'tier' => 'silver-3']);

    // Casual: provisional below five games as well, never a tier (cap off for the count).
    config(['season.casual.daily_pair_limit' => null]);
    foreach (range(1, 4) as $i) {
        $rate(false);
    }
    expect($summary(Rating::CASUAL))->toMatchArray(['results' => 4, 'provisional' => true, 'tier' => null]);
    $rate(false);
    expect($summary(Rating::CASUAL))->toMatchArray(['results' => 5, 'provisional' => false, 'tier' => null]);
});

test('before Block 0 a rated game is refused: the queue locks it and a rated result moves nothing', function () {
    [$a, $b] = [User::factory()->create(), User::factory()->create()];

    expect(fn () => app(ChessQueue::class)->join($a, 'blitz', rated: true))->toThrow(ChessRuleViolation::class);

    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $a->id, 'black_id' => $b->id]);

    expect(app(RatingService::class)->applyChessGame($game))->toBeFalse()
        ->and(Rating::query()->count())->toBe(0);
});

test('the casual farming guard counts at most three results per pairing and UTC day', function () {
    $this->travelTo(now()->utc()->setTime(10, 0));
    [$a, $b, $c] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $play = fn (User $white, User $black) => app(RatingService::class)->applyChessGame(ChessGame::factory()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]));

    $moved = [$play($a, $b), $play($b, $a), $play($a, $b), $play($b, $a)];

    expect($moved)->toBe([true, true, true, false])
        ->and(ratingOf($a)->results)->toBe(3)
        // Another pairing is not capped by it.
        ->and($play($a, $c))->toBeTrue();

    $this->travelTo(now()->utc()->addDay()->setTime(0, 0, 1));
    expect($play($b, $a))->toBeTrue()
        ->and(ratingOf($a)->results)->toBe(5);
});

test('chess games and series take their numbers from one sequence, and a chess number opens its game', function () {
    $first = ChessGame::factory()->create();
    $series = SeriesMatch::factory()->create();
    $second = ChessGame::factory()->daily()->create();

    $numbers = [$first->number, $series->number, $second->number];

    expect($numbers)->toBe(array_values(array_unique($numbers)))
        ->and($numbers)->toBe([$numbers[0], $numbers[0] + 1, $numbers[0] + 2])
        ->and($first->number())->toBe('#'.$first->number)
        ->and(MatchNumber::query()->whereKey($first->number)->value('user_id'))->toBe($first->white_id);

    $this->get(route('matches.show', $second->number))->assertRedirect(route('games.show', $second));
    $this->get(route('matches.show', $series->number))->assertOk();
});

test('the numbering migration gives existing games unique numbers after all series and keeps series numbers', function () {
    $migration = require database_path('migrations/2026_09_25_230124_add_match_numbers_to_chess_games.php');
    $series = SeriesMatch::factory()->count(2)->create();
    $migration->down();

    $users = User::factory()->count(2)->create();
    $row = ChessGame::factory()->make(['white_id' => $users[0]->id, 'black_id' => $users[1]->id])->getAttributes();
    foreach (range(1, 3) as $i) {
        DB::table('chess_games')->insert([...$row, 'created_at' => now()->subDays(4 - $i), 'updated_at' => now()]);
    }

    $migration->up();

    $chess = DB::table('chess_games')->orderBy('id')->pluck('number')->all();
    $all = [...$chess, ...$series->pluck('number')->all()];

    expect($chess)->each->toBeGreaterThan($series->max('number'))
        ->and($all)->toBe(array_values(array_unique($all)))
        ->and(SeriesMatch::query()->orderBy('id')->pluck('number')->all())->toBe($series->pluck('number')->all())
        ->and(MatchNumber::query()->whereIn('id', $chess)->whereNull('used_at')->count())->toBe(0);

    // down() gives the chess numbers back as gaps and leaves the series alone.
    $migration->down();
    expect(MatchNumber::query()->whereIn('id', $chess)->count())->toBe(0)
        ->and(MatchNumber::query()->whereIn('id', $series->pluck('number'))->count())->toBe(2);
    $migration->up();
});

test('the ladder lists players by rating with their tier, and shows the Pre-Season state before Block 0', function () {
    [$low, $top, $mid] = User::factory()->count(3)->sequence(['name' => 'lowkey'], ['name' => 'topdog'], ['name' => 'midway'])->create();

    $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=rated')
        ->assertOk()->assertSee('Pre-Season starts at Block 0')->assertDontSee('topdog');

    seedRating($low, Rating::CASUAL, 940, 2);
    seedRating($top, Rating::CASUAL, 1180, 7);
    seedRating($mid, Rating::CASUAL, 1010, 6);

    $this->get(route('ladder.show', ['chess', 'blitz']).'?pool=casual')
        ->assertOk()->assertSeeInOrder(['topdog', '1180', 'midway', '1010', 'lowkey', '940'])
        ->assertDontSee('Diamond I');

    openLadders();
    seedRating($low, Rating::RATED, 1430, 12);
    seedRating($top, Rating::RATED, 1180, 7);
    seedRating($mid, Rating::RATED, 1010, 4);

    $this->get(route('ladder.show', ['chess', 'blitz']))
        ->assertOk()
        ->assertSeeInOrder(['lowkey', 'Grand Champion III', '1430', 'topdog', 'Diamond I', '1180', 'midway', 'Provisional', '1010'])
        ->assertSee(route('players.show', $top->npub));

    $this->get('/ladder/chess/bullet')->assertNotFound();
    $this->get(route('ladder.show', ['rocket-league', '3v3']))->assertOk();
});

test('the ladder opens on casual while the rated ladder has no row, and on rated once it has one', function () {
    [$casual, $rated] = User::factory()->count(2)->sequence(['name' => 'casualcarl'], ['name' => 'ratedrita'])->create();
    seedRating($casual, Rating::CASUAL, 1050, 3);
    $ladder = route('ladder.show', ['chess', 'blitz']);
    $pressed = fn (string $pool) => 'aria-pressed="true" data-test="pool-'.$pool.'"';

    // Pre-season: casual, with the note that links to the rated ladder.
    $this->get($ladder)->assertOk()
        ->assertSee($pressed('casual'), false)->assertSee('casualcarl')
        ->assertSee('The rated ladder starts at Block 0.')->assertSee($ladder.'?pool=rated', false)
        ->assertDontSee('Pre-Season starts at Block 0');

    // Season open, still no rated result: casual, and the note says so.
    openLadders();
    $this->get($ladder)->assertOk()
        ->assertSee($pressed('casual'), false)
        ->assertSee('The rated ladder has no results this season yet.');

    // The first rated row: rated is the default, no note.
    seedRating($rated, Rating::RATED, 1020, 1);
    $this->get($ladder)->assertOk()
        ->assertSee($pressed('rated'), false)->assertSee('ratedrita')->assertDontSee('casualcarl')
        ->assertDontSee('data-test="ladder-rated-note"', false);

    // ?pool= pins a tab either way.
    $this->get($ladder.'?pool=casual')->assertOk()->assertSee($pressed('casual'), false)->assertSee('casualcarl');
});

test('the player page and the player card show the casual blitz Elo and the lineup Elo before Block 0, the tier after', function () {
    $lineup = Lineup::factory()->mode('2v2')->ready()->create();
    $player = $lineup->clan->owner;
    seedRating($player, Rating::CASUAL, 1020, 7);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineup->id,
        'lineup_id' => $lineup->id, 'rating' => 987, 'results' => 3]);

    foreach ([route('players.show', $player->npub), route('players.card', $player->npub)] as $url) {
        $this->get($url)->assertOk()
            ->assertSeeInOrder(['Chess blitz', 'Casual', '1020', 'RL 2v2', 'Casual', '987', 'provisional'])
            ->assertDontSee('Silver III');
    }

    openLadders();
    seedRating($player, Rating::RATED, 1060, 6);

    $this->get(route('players.show', $player->npub))->assertOk()->assertSeeInOrder(['Chess blitz', '1060', 'Gold II']);
});

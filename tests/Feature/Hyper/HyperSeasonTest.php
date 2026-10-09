<?php

use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\HyperRating;
use App\Models\HyperRatingChange;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperSeason;
use Illuminate\Support\Carbon;
use Tests\Support\HyperOn;

/*
| Hyperbitcoinization in the season (plan "Hyperbitcoinization", P5): only matches of players in a live chain season
| are rated; a free-for-all scores points by place, a 1v1 and a team match move an Elo of their own; leaving or timing
| out a rated match is a forfeit (last place, 0 points, a loss); the ladder page shows the three tables.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

test('a match of players only, begun in a live season, is rated in it; a bot seat, a cup or no season leave it casual', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    $before = HyperOn::versus($anna, $bert);
    $season = openSeason(ladders: false);
    $rated = HyperOn::versus($anna, $bert);
    $bots = HyperOn::versus($anna, $bert, bots: 1);
    $cup = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert]], rated: false);

    expect([$before->rated, $before->season])->toBe([false, null])
        ->and([$rated->rated, $rated->season])->toBe([true, $season->slug])
        ->and([$bots->rated, $bots->season])->toBe([false, null])
        ->and([$cup->rated, $cup->season])->toBe([false, null]);
});

test('a rated free-for-all scores points by place, and the ladder sums them per player', function () {
    $season = openSeason(ladders: false);
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl], ['user' => $dora]], seed: 7);

    $match = HyperOn::finish(HyperOn::ending($match, winner: 0, runnerUp: 1, out: [2 => 3, 3 => 4]), $anna);

    expect($match->seats->pluck('place', 'seat')->all())->toBe([0 => 1, 1 => 2, 2 => 3, 3 => 4])
        ->and($match->seats->pluck('points', 'seat')->all())->toBe([0 => 8, 1 => 5, 2 => 3, 3 => 1])
        ->and(HyperRating::query()->count())->toBe(0);

    // A second table of three: Bert wins it, Carl second, Anna third.
    $second = app(HyperMatches::class)->create([['user' => $bert], ['user' => $carl], ['user' => $anna]], seed: 9);
    HyperOn::finish(HyperOn::ending($second, winner: 0, runnerUp: 1, out: [2 => 3]), $bert);

    $table = app(HyperSeason::class)->ffaStandings($season->slug);

    expect(array_map(fn (array $row): array => [$row['user']->id, $row['points'], $row['matches'], $row['wins']], $table))->toBe([
        [$bert->id, 5 + 6, 2, 1],
        [$anna->id, 8 + 1, 2, 1],
        [$carl->id, 3 + 3, 2, 0],
        [$dora->id, 1, 1, 0],
    ]);

    $this->get(route('hyper.ladder'))->assertOk()
        ->assertSeeInOrder([$bert->displayName(), '11', $anna->displayName(), '9', $carl->displayName(), '6', $dora->displayName()])
        ->assertSee('data-test="hyper-ladder-play"', false);
});

test('a rated 1v1 moves both players\' duel Elo; a casual one moves nothing', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    HyperOn::finish(HyperOn::ending(HyperOn::versus($anna, $bert), winner: 0, runnerUp: 1), $anna);

    expect(HyperRatingChange::query()->count())->toBe(0);

    $season = openSeason(ladders: false);
    $match = HyperOn::finish(HyperOn::ending(HyperOn::versus($anna, $bert), winner: 0, runnerUp: 1), $anna);
    $ratings = HyperRating::query()->where(['season' => $season->slug, 'kind' => HyperRating::DUEL])->get()->keyBy('user_id');

    // Both at the start of 1000, both provisional (k 40): the expected score is 0.5, so ±20.
    expect($ratings[$anna->id]->only(['rating', 'results', 'wins', 'losses']))->toBe(['rating' => 1020, 'results' => 1, 'wins' => 1, 'losses' => 0])
        ->and($ratings[$bert->id]->only(['rating', 'results', 'wins', 'losses']))->toBe(['rating' => 980, 'results' => 1, 'wins' => 0, 'losses' => 1])
        ->and(HyperRatingChange::query()->where('hyper_match_id', $match->id)->orderBy('id')->get(['before', 'after', 'delta', 'score'])->toArray())->toBe([
            ['before' => 1000, 'after' => 1020, 'delta' => 20, 'score' => 1.0],
            ['before' => 1000, 'after' => 980, 'delta' => -20, 'score' => 0.0],
        ]);

    $this->get(route('hyper.ladder', ['board' => 'duel']))->assertOk()->assertSeeInOrder([$anna->displayName(), '1020', $bert->displayName(), '980']);
});

test('a rated team match rates every member against the teams\' average Elo with the member\'s own k-factor', function () {
    $season = openSeason(ladders: false);
    [$red, $blue] = Clan::factory()->count(2)->create();
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    foreach ([[$anna, $red], [$carl, $red], [$bert, $blue], [$dora, $blue]] as [$user, $clan]) {
        HyperOn::inClan($user, $clan);
    }
    // Anna is no newcomer: 1100 after ten results (k 32); everybody else starts at 1000 (provisional, k 40).
    HyperRating::query()->create(['season' => $season->slug, 'kind' => HyperRating::TEAM, 'user_id' => $anna->id, 'rating' => 1100, 'results' => 10, 'wins' => 6, 'losses' => 4]);

    $match = HyperOn::teamEndgame(HyperOn::teams($red, $blue, $anna, $bert, $carl, $dora));
    $match->seats->firstWhere('seat', 3)->forceFill(['place' => 4])->save();
    HyperOn::finish($match, $anna);
    $after = HyperRating::query()->where(['season' => $season->slug, 'kind' => HyperRating::TEAM])->pluck('rating', 'user_id');

    // Red's average 1050 against 1000: E = 1 / (1 + 10^(-50/400)) = 0.5715; Anna +round(32 × 0.4285) = +14,
    // Carl +round(40 × 0.4285) = +17, Bert and Dora −17.
    expect($after->all())->toEqual([$anna->id => 1114, $bert->id => 983, $carl->id => 1017, $dora->id => 983])
        ->and(HyperRating::query()->where('kind', HyperRating::DUEL)->count())->toBe(0);
});

test('leaving a rated match is a forfeit: last place and a lost Elo, even when the own team wins', function () {
    openSeason(ladders: false);
    [$red, $blue] = Clan::factory()->count(2)->create();
    [$anna, $bert, $carl, $dora] = User::factory()->count(4)->create();
    foreach ([[$anna, $red], [$carl, $red], [$bert, $blue], [$dora, $blue]] as [$user, $clan]) {
        HyperOn::inClan($user, $clan);
    }

    $match = HyperOn::teams($red, $blue, $anna, $bert, $carl, $dora);
    app(HyperMatches::class)->leave($match, $carl);
    $match = HyperOn::teamEndgame($match->refresh());
    $match->seats->firstWhere('seat', 3)->forceFill(['place' => 4])->save();
    $match = HyperOn::finish($match, $anna);
    $carlSeat = $match->seats->firstWhere('user_id', $carl->id);
    $delta = HyperRatingChange::query()->where('hyper_match_id', $match->id)->with('rating')->get()->mapWithKeys(fn (HyperRatingChange $change): array => [$change->rating->user_id => $change->delta]);

    expect($carlSeat->takeover)->toBe(HyperSeat::TAKEOVER_FORFEIT)
        // Red won (Anna place 1), Bert next; Carl forfeited: last of four, behind Dora.
        ->and($match->seats->pluck('place', 'seat')->all())->toBe([0 => 1, 1 => 2, 2 => 4, 3 => 3])
        ->and($delta[$anna->id])->toBeGreaterThan(0)
        ->and($delta[$carl->id])->toBeLessThan(0)
        ->and(HyperRatingChange::query()->whereHas('rating', fn ($rating) => $rating->where('user_id', $carl->id))->value('score'))->toBe(0.0);
});

test('a forfeit in a rated free-for-all scores 0 points, and a forfeiter whose bot won still takes the last place', function () {
    openSeason(ladders: false);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7);
    app(HyperMatches::class)->leave($match, $carl);
    $match = HyperOn::finish(HyperOn::ending($match->refresh(), winner: 0, runnerUp: 1, out: [2 => 3]), $anna);

    expect($match->seats->pluck('points', 'seat')->all())->toBe([0 => 6, 1 => 3, 2 => 0]);

    // The bot that plays a forfeited seat on wins the match: the places as the rules core left them, then forfeits last.
    $seats = collect([
        new HyperSeat(['seat' => 0, 'place' => 1, 'takeover' => HyperSeat::TAKEOVER_FORFEIT, 'left_at' => Carbon::parse('2026-10-09 10:00')]),
        new HyperSeat(['seat' => 1, 'place' => 2]),
        new HyperSeat(['seat' => 2, 'place' => 3, 'takeover' => HyperSeat::TAKEOVER_FORFEIT, 'left_at' => Carbon::parse('2026-10-09 11:00')]),
        new HyperSeat(['seat' => 3, 'place' => 4]),
    ]);
    $match = new HyperMatch;
    $match->setRelation('seats', $seats);
    HyperSeason::placeForfeits($match);

    // Seat 0 forfeited first: the very last place; seat 2 just before it.
    expect($seats->pluck('place', 'seat')->all())->toBe([0 => 4, 1 => 1, 2 => 3, 3 => 2]);
});

test('three timed-out turns in a row forfeit a rated seat; a rated correspondence turn that runs out is never played by a bot', function () {
    openSeason(ladders: false);
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);
    $matches = app(HyperMatches::class);

    foreach (range(1, 3) as $turn) {
        $this->travel((int) config('esports.hyper.turn_seconds') + 1)->seconds();
        $match = $matches->checkClock($match->refresh());

        if ($turn < 3) {
            $matches->act($match, $bert, ['type' => 'end_turn']);
        }
    }

    $seat = $match->refresh()->seats->firstWhere('user_id', $anna->id);
    expect($seat->only(['bot', 'takeover', 'timeouts']))->toBe(['bot' => true, 'takeover' => HyperSeat::TAKEOVER_FORFEIT, 'timeouts' => 3])
        ->and($seat->left_at)->not->toBeNull();

    $daily = app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert]], mode: HyperMatch::CORRESPONDENCE);
    $this->travel((int) config('esports.hyper.correspondence_hours') + 1)->hours();
    $daily = $matches->checkClock($daily);

    expect($daily->rated)->toBeTrue()
        ->and($daily->actions()->where('source', 'timer')->pluck('action')->all())->toBe([['type' => 'end_turn']]);
});

test('the ladder page switches between its three tables and says before Block 0 that every match is casual', function () {
    $this->get(route('hyper.ladder'))->assertOk()
        ->assertSee('data-test="hyper-ladder-preseason"', false)
        ->assertSee('data-test="hyper-ladder-play"', false);

    openSeason(ladders: false);
    $viewer = User::factory()->create();

    foreach (['ffa', 'duel', 'team'] as $board) {
        $page = $this->actingAs($viewer)->get(route('hyper.ladder', ['board' => $board]))->assertOk()
            ->assertSee('data-test="hyper-ladder-empty"', false)
            ->assertSee('data-test="hyper-ladder-mine"', false);

        expect(preg_match_all('/aria-current="page"\s+data-test="hyper-ladder-board-(\w+)"/', (string) $page->getContent(), $current))->toBe(1)
            ->and($current[1])->toBe([$board]);
    }

    $this->get(route('hyper.index'))->assertOk()->assertSee(route('hyper.ladder'), false);
});

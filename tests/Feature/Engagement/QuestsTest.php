<?php

use App\Models\ChessGame;
use App\Models\QuestCredit;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Engagement\Quests;
use App\Support\Engagement\ResultEngagement;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TrustedFacts;

/*
 * Weekly quests (P10): progress is counted on the server from results that
 * moved a rating, once per result, however often the result is reported.
 */

beforeEach(function () {
    Queue::fake();
    config(['season.casual.daily_pair_limit' => null]);
});

function questGame(User $white, User $black, string $result = '1-0'): ChessGame
{
    $game = ChessGame::factory()->finished($result)->create(['white_id' => $white->id, 'black_id' => $black->id]);
    app(RatingService::class)->applyChessGame($game);

    return $game;
}

/**
 * @return array<string, array{progress: int, target: int, done: bool}>
 */
function questsOf(User $user): array
{
    return app(Quests::class)->progress($user);
}

test('three games complete the weekly quest, and a result handled twice counts once', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $game = questGame($a, $b);
    [$first, $second] = RatingChange::query()->where('source_id', $game->id)->orderBy('id')->get()->all();
    app(ResultEngagement::class)->handle($first, $second);
    app(ResultEngagement::class)->handle($first, $second);

    expect(questsOf($a)[Quests::THREE_GAMES])->toBe(['progress' => 1, 'target' => 3, 'done' => false]);

    questGame($b, $a, '1/2-1/2');
    questGame($a, $b, '0-1');

    expect(questsOf($a)[Quests::THREE_GAMES])->toBe(['progress' => 3, 'target' => 3, 'done' => true])
        ->and(questsOf($b)[Quests::THREE_GAMES]['done'])->toBeTrue()
        ->and(QuestCredit::query()->where('quest', Quests::THREE_GAMES)->count())->toBe(6);

    $this->actingAs($a)->get(route('home'))->assertOk()
        ->assertSee('data-test="quests"', false)
        ->assertSee(__(':done of :count done', ['done' => 1, 'count' => 3]));

    // A new ISO week starts from zero.
    $this->travelTo(CarbonImmutable::now()->addWeek()->startOfWeek()->addHour());
    expect(questsOf($a)[Quests::THREE_GAMES]['progress'])->toBe(0);
});

test('beating a higher-rated player counts, beating a lower-rated one or losing does not', function () {
    [$underdog, $favourite] = User::factory()->count(2)->create();
    foreach ([[$underdog, 1000], [$favourite, 1200]] as [$user, $rating]) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => 10]);
    }

    questGame($favourite, $underdog);

    expect(questsOf($underdog)[Quests::GIANT_SLAYER]['progress'])->toBe(0)
        ->and(questsOf($favourite)[Quests::GIANT_SLAYER]['progress'])->toBe(0);

    questGame($favourite, $underdog, '0-1');

    expect(questsOf($underdog)[Quests::GIANT_SLAYER])->toBe(['progress' => 1, 'target' => 1, 'done' => true])
        ->and(questsOf($favourite)[Quests::GIANT_SLAYER]['progress'])->toBe(0);
});

test('a series of two clan lineups counts for every roster player once, even confirmed and handled twice', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $series = app(SeriesService::class);
    $captainA = $match->challengerLineup->clan->owner;
    $captainB = $match->challengedLineup->clan->owner;

    foreach ([[3, 1], [2, 1]] as $index => [$challenger, $challenged]) {
        $series->saveLiveGame($match, $captainA, $index, $challenger, $challenged, null);
    }

    $series->report($match, $captainA, []);
    $series->respond($match->refresh(), $captainB, 'confirmed', '', []);

    // The result reported again: a second confirmation, a retried rating, the hook run again.
    expect(fn () => $series->respond($match->refresh(), $captainB, 'confirmed', '', []))->toThrow(SeriesRuleViolation::class)
        ->and(app(RatingService::class)->applySeries($match->refresh()))->toBeFalse();
    [$first, $second] = RatingChange::query()->where('source', RatingChange::SERIES)->where('source_id', $match->id)->orderBy('id')->get()->all();
    app(ResultEngagement::class)->handle($first, $second);

    $players = collect($match->refresh()->countedRoster())->pluck('user_id')->map(fn ($id) => (int) $id);

    expect($players)->toHaveCount(6);

    foreach ($players as $userId) {
        $progress = questsOf(User::query()->findOrFail($userId));

        expect($progress[Quests::THREE_GAMES]['progress'])->toBe(1)
            ->and($progress[Quests::FOR_THE_CLAN]['done'])->toBeTrue();
    }

    expect(QuestCredit::query()->count())->toBe(12);
});

test('a chess game counts for the clan only when it is rated and the player had a clan at the pairing', function () {
    [$a, $b] = User::factory()->count(2)->create();

    questGame($a, $b);

    expect(questsOf($a)[Quests::FOR_THE_CLAN]['progress'])->toBe(0);

    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $rated = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $a->id, 'black_id' => $b->id, 'clans_at_accept' => [$a->pubkey => '32150:'.$a->pubkey.':satoshis']]);

    expect(app(RatingService::class)->applyChessGame($rated))->toBeTrue()
        ->and(questsOf($a)[Quests::FOR_THE_CLAN]['done'])->toBeTrue()
        ->and(questsOf($b)[Quests::FOR_THE_CLAN]['progress'])->toBe(0);
});

<?php

use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\User;
use App\Support\Rating\LadderBoard;
use App\Support\Rating\StrongestList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| The rating stamp (performance plan P2, finding S6)
|--------------------------------------------------------------------------
|
| The caches of rating-derived values (ladder scores, the Strongest list)
| key on LadderBoard::ratingStamp(). It used to scan rating_changes on every
| request; now it is a version in the cache that every write of a rating
| change renews, once the write is committed.
|
*/

/** A rated blitz rating in `$season` with one change of its own, so it can be reverted. */
function stampedBlitz(User $user, string $season, int $rating): RatingChange
{
    $row = Rating::query()->create(['pool' => Rating::RATED, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id,
        'user_id' => $user->id, 'rating' => $rating, 'results' => 5, 'wins' => 5, 'draws' => 0, 'losses' => 0]);

    return RatingChange::query()->create(['rating_id' => $row->id, 'source' => RatingChange::CHESS, 'source_id' => $user->id, 'score' => 1,
        'before' => 1000, 'after' => $rating, 'delta' => $rating - 1000, 'results_before' => 4]);
}

test('reading the stamp asks the cache, not the database', function () {
    LadderBoard::ratingStamp();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $stamp = LadderBoard::ratingStamp();
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toBe([])
        ->and($stamp)->toBe(LadderBoard::ratingStamp())->not->toBe('');
});

test('a new, an edited, a reverted and a deleted rating change each give a new stamp', function () {
    openSeason(['slug' => 'season-1']);
    $stamps = [LadderBoard::ratingStamp()];

    $change = stampedBlitz(User::factory()->create(), 'season-1', 1100);
    $stamps[] = LadderBoard::ratingStamp();

    $change->forceFill(['match_number' => 402])->save();
    $stamps[] = LadderBoard::ratingStamp();

    // How RatingService::correct() takes a result back.
    $change->forceFill(['reverted_at' => now()])->save();
    $stamps[] = LadderBoard::ratingStamp();

    $change->delete();
    $stamps[] = LadderBoard::ratingStamp();

    expect(array_unique($stamps))->toHaveCount(5);
});

test('the stamp moves when the write is committed, not before', function () {
    openSeason(['slug' => 'season-1']);
    $before = LadderBoard::ratingStamp();

    DB::transaction(function () use ($before): void {
        stampedBlitz(User::factory()->create(), 'season-1', 1100);

        expect(LadderBoard::ratingStamp())->toBe($before);
    });

    expect(LadderBoard::ratingStamp())->not->toBe($before);
});

test('a flushed cache starts a new stamp instead of reusing an old one', function () {
    $first = LadderBoard::ratingStamp();
    Cache::flush();

    expect(LadderBoard::ratingStamp())->not->toBe($first);
});

test('a cache that cannot be written does not fail the rating write', function () {
    openSeason(['slug' => 'season-1']);
    $cache = Mockery::mock(Cache::getFacadeRoot());
    $cache->shouldReceive('forever')->andThrow(new RuntimeException('cache store down'));
    Cache::swap($cache);
    Exceptions::fake();

    $change = stampedBlitz(User::factory()->create(), 'season-1', 1100);

    expect($change->exists)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache store down');
});

test('the Strongest list stays cached while ratings move without a change, and follows a reverted change', function () {
    openSeason(['slug' => 'season-1']);
    [$anna, $bert] = User::factory()->count(2)->create();
    $annas = stampedBlitz($anna, 'season-1', 1200);
    stampedBlitz($bert, 'season-1', 1100);

    expect(array_column(StrongestList::current()->ranking()['ranked'], 'user'))->toBe([$anna->id, $bert->id]);

    // A correction lowers Anna's rating and reverts her change. The rating row alone does not move the stamp:
    // the list is still the cached one, which proves it is cached.
    Rating::query()->whereKey($annas->rating_id)->update(['rating' => 1000]);
    expect(array_column(StrongestList::current()->ranking()['ranked'], 'user'))->toBe([$anna->id, $bert->id]);

    $annas->forceFill(['reverted_at' => now()])->save();
    expect(array_column(StrongestList::current()->ranking()['ranked'], 'user'))->toBe([$bert->id, $anna->id]);
});

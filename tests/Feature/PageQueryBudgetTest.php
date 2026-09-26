<?php

use App\Models\Admin;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| Query budget of the shell and the busiest pages (P5g)
|--------------------------------------------------------------------------
|
| Counts the queries of one request by what they ask, so a count that grows
| back (a lookup per row, a gate asked per menu) turns red here instead of
| in production.
|
*/

/**
 * The SQL of every query one GET runs.
 *
 * @return list<string>
 */
function queriesOf(string $uri): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($uri)->assertOk();
    DB::disableQueryLog();

    return array_column(DB::getQueryLog(), 'query');
}

/**
 * @param  list<string>  $queries
 */
function countQueries(array $queries, string $needle): int
{
    return count(array_filter($queries, fn (string $sql) => str_contains($sql, $needle)));
}

test('the footer counters are counted once and then served from the cache', function () {
    User::factory()->count(2)->create();
    $counters = fn (array $queries) => countQueries($queries, 'count(*) as "aggregate" from "users"')
        + countQueries($queries, 'count(*) as "aggregate" from "clans"')
        + countQueries($queries, 'from "chess_games" where "status" = ?');

    expect($counters(queriesOf(route('rules'))))->toBe(3)
        ->and($counters(queriesOf(route('rules'))))->toBe(0)
        ->and(test()->get(route('rules'))->getContent())->toContain(__('Players').' <b class="text-ink">2</b>');
});

test('the header asks the admin gate once per page', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin);

    expect(countQueries(queriesOf(route('rules')), 'from "admins" where "pubkey"'))->toBe(1);
});

test('/matches looks up the clan of the filter once, and not at all without a filter', function () {
    $series = SeriesMatch::factory()->accepted()->create();
    $clan = $series->lineup('challenger')->clan;

    expect(countQueries(queriesOf(route('matches.index')), 'from "clans" where "slug" = ?'))->toBe(0)
        ->and(countQueries(queriesOf(route('matches.index', ['clan' => $clan->slug])), 'from "clans" where "slug" = ?'))->toBe(1);
});

test('/challenges/create lists the opponents without a query per opponent', function () {
    $captain = Lineup::factory()->ready()->create()->clan->owner;
    $this->actingAs($captain);
    $userLookups = fn () => countQueries(queriesOf(route('challenges.create')), 'from "users" where "users"."id" = ?');

    Lineup::factory()->ready()->count(2)->create();
    $few = $userLookups();

    Lineup::factory()->ready()->count(4)->create();
    $many = $userLookups();

    expect(Clan::query()->count())->toBe(7)
        ->and($many)->toBe($few);
});

test('a failing cache store does not take the pages down: the footer counts directly', function () {
    User::factory()->count(2)->create();
    // Only flexible() fails; every other cache call goes to the real manager.
    $cache = Mockery::mock(Cache::getFacadeRoot());
    $cache->shouldReceive('flexible')->andThrow(new RuntimeException('cache store down'));
    Cache::swap($cache);
    Exceptions::fake();

    $this->get(route('rules'))->assertOk()->assertSee(__('Players').' <b class="text-ink">2</b>', false);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'cache store down');
});

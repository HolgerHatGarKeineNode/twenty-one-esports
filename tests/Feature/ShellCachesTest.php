<?php

use App\Enums\SeriesStatus;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Cache::flexible for home's newcomers and the admin badge (performance plan P2, S9)
|--------------------------------------------------------------------------
|
| Both are served from the cache between their triggers, and each trigger
| shows at once: a new or deleted player or clan for the newcomers; a series
| moving into or out of a case for the badge, through the model and through
| the guarded update of an admin decision, which writes past the model.
|
*/

/**
 * The SQL of one GET.
 *
 * @return list<string>
 */
function shellCacheQueries(string $uri): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($uri)->assertOk();
    DB::disableQueryLog();

    return array_column(DB::getQueryLog(), 'query');
}

/** The number of open cases the admin badge shows on /rules. */
function adminBadge(): int
{
    $html = (string) test()->get(route('rules'))->assertOk()->getContent();

    return preg_match('/data-test="admin-count"><span class="sr-only">, <\/span>(\d+)/', $html, $count) === 1 ? (int) $count[1] : 0;
}

test('home reads the newcomers from the cache, shows a new player and a deleted clan at once, and a new name without a trigger', function () {
    $first = User::factory()->create(['name' => 'firstblock']);
    $clan = Clan::factory()->create(['name' => 'Halving Crew']);
    $newest = fn (array $queries): int => count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "users" order by "created_at" desc')));

    expect($newest(shellCacheQueries(route('home'))))->toBe(1)
        ->and($newest(shellCacheQueries(route('home'))))->toBe(0);

    // One trigger at a time, each followed by a read: a second trigger would hide a missing first one.
    $first->forceFill(['name' => 'genesisblock'])->save();
    $this->get(route('home'))->assertOk()->assertSee('genesisblock')->assertSee('Halving Crew');

    User::factory()->create(['name' => 'latecomer']);
    $this->get(route('home'))->assertOk()->assertSee('latecomer')->assertSee('Halving Crew');

    // The rows are read by id, so a deleted clan drops out anyway; the week's count is the cached part.
    $week = fn (int $clans): string => trans_choice(':count new clan|:count new clans', $clans);
    $this->get(route('home'))->assertOk()->assertSee($week(1));
    $clan->delete();
    $this->get(route('home'))->assertOk()->assertDontSee('Halving Crew')->assertSee($week(0));
});

test('the admin badge comes from the cache and follows a new dispute and an admin decision at once', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $this->actingAs($admin);
    $series = SeriesMatch::factory()->accepted()->create();
    $cases = fn (array $queries): int => count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(*) as "aggregate" from "series_matches"')));

    expect(adminBadge())->toBe(0)
        ->and($cases(shellCacheQueries(route('rules'))))->toBe(0);

    // Through the model: the series is disputed.
    $series->forceFill(['status' => SeriesStatus::Disputed])->save();
    expect(adminBadge())->toBe(1);

    // Through the guarded update of decide(), past the model: the case is closed.
    app(SeriesService::class)->decide($series->refresh(), $admin, ['type' => 'void'], 'Nobody played.');
    expect($series->refresh()->status)->toBe(SeriesStatus::Resolved)
        ->and(adminBadge())->toBe(0);
});

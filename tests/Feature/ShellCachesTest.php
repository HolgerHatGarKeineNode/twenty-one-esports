<?php

use App\Enums\SeriesStatus;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Engagement\HomeHub;
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

test('the newcomers come from the cache, with a new player and a deleted clan at once, and a new name without a trigger', function () {
    // Home no longer lists them (plan "Refactor und Design-Revamp", board Main); HomeHub keeps the cached read for the pages that will.
    $first = User::factory()->create(['name' => 'firstblock']);
    $clan = Clan::factory()->create(['name' => 'Halving Crew']);
    $read = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $newcomers = (new HomeHub(null))->newcomers();
        DB::disableQueryLog();

        return [$newcomers, count(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "users" order by "created_at" desc')))];
    };
    $names = fn (array $newcomers): array => $newcomers['players']->map->displayName()->all();

    expect($read()[1])->toBe(1)->and($read()[1])->toBe(0);

    $first->forceFill(['name' => 'genesisblock'])->save();
    expect($names($read()[0]))->toContain('genesisblock');

    User::factory()->create(['name' => 'latecomer']);
    expect($names($read()[0]))->toContain('latecomer')
        ->and($read()[0]['clansThisWeek'])->toBe(1);

    $clan->delete();
    expect($read()[0]['clans']->pluck('name')->all())->not->toContain('Halving Crew')
        ->and($read()[0]['clansThisWeek'])->toBe(0);
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

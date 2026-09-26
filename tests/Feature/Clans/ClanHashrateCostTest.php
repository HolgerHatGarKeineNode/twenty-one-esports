<?php

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\User;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TrustedFacts;

/*
 * Security gate P10 (Low): the season's hashrate is read once per request at
 * most, and a request within the next minute reads it from the cache; the
 * live search on /clans never recomputes it.
 */

/**
 * How often the season's rated results were read while `$request` ran.
 */
function hashrateComputes(Closure $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    DB::disableQueryLog();

    return count(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'max(rating_changes.created_at) as recorded_at')));
}

test('/clans computes the hashrate at most once, then serves it from the cache for a minute', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$a, $b] = User::factory()->count(2)->create();
    $clan = Clan::factory()->create(['owner_id' => $a->id, 'meetup_city' => 'Kempten']);
    app(RatingService::class)->applyChessGame(ChessGame::factory()->rated()->finished('1-0')->create([
        'white_id' => $a->id, 'black_id' => $b->id, 'clans_at_accept' => [$a->pubkey => $clan->address()],
    ]));

    expect(hashrateComputes(fn () => $this->get(route('clans.index'))->assertOk()->assertSeeInOrder(['id="hs-h"', $clan->name, '<b class="text-right">3</b>'], false)))->toBe(1)
        ->and(hashrateComputes(fn () => $this->get(route('clans.index'))->assertOk()))->toBe(0)
        ->and(hashrateComputes(fn () => $this->get(route('clans.show', $clan))->assertOk()))->toBe(0)
        ->and(hashrateComputes(fn () => $this->get(route('games.rocket-league'))->assertOk()))->toBe(0)
        // Live search, keystroke by keystroke.
        ->and(hashrateComputes(fn () => Livewire::test('pages::clans.index')->set('search', 'K')->set('search', 'Ke')->set('search', 'Kem')->assertOk()))->toBe(0);

    $this->travel(61)->seconds();

    expect(hashrateComputes(fn () => $this->get(route('clans.index'))->assertOk()))->toBe(1);
});

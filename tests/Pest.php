<?php

use App\Models\NostrEvent;
use App\Models\Season;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\SeasonChains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\Support\BrowserAssets;
use Tests\Support\TestSigner;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->tia()->defaultBranch('master');

require_once __DIR__.'/Support/tournaments.php';
require_once __DIR__.'/Support/shares.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('feature')
    ->in('Feature');

pest()->group('unit')->in('Unit');

pest()->extend(TestCase::class)->group('nostr')->in('Nostr');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('relay')
    ->in('Relay');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Browser tests run their pages through this same in-process app
    // (Pest\Browser\Drivers\LaravelHttpServer), reading public/build/*
    // directly (scripts/test-browser.sh runs `npm run build` first). If a
    // developer's `composer dev` happens to be running elsewhere and its
    // public/hot file exists, @vite() would otherwise point every browser
    // test's page at the (unrelated, maybe-not-running-here) Vite dev
    // server instead of the built manifest — the page loads with a
    // "failed to connect to websocket" console error and stale/missing
    // assets. Point at a path that never exists instead of touching or
    // deleting the developer's real public/hot.
    //
    // The built assets come through /__test/assets/ (Tests\Support\BrowserAssets),
    // which lets the browser cache them within a context.
    ->beforeEach(function (): void {
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled-for-browser-tests'));
        BrowserAssets::use();
    })
    ->group('browser')
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A live chain season (Block 0 an hour ago) signed by a throwaway league key,
 * which becomes `esports.league.nsec`, so rated play is open and every rated
 * result is attested. The genesis is a real signed 2156, so block 1 can name
 * it. The real release path is tested in tests/Feature/SeasonChain.
 *
 * @param  array<string, mixed>  $attributes
 */
function openSeason(array $attributes = []): Season
{
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    $season = Season::factory()->make(['league_pubkey' => $league->pubkey, ...$attributes]);
    $genesis = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(SeasonChains::GENESIS, [['season', $season->slug], ['alt', 'Season Genesis']], $season->genesis_message, $season->genesis_at->getTimestamp())));
    $season->genesis_event_id = $genesis->id;
    $season->save();

    return $season;
}

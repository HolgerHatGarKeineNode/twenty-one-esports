<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\CupBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The cup board (P53)
|--------------------------------------------------------------------------
|
| The casual cups grouped by game with the cover, EU and US as a pair, each
| start as clock, day and city, the places as a seat bar and the status as
| an icon and a word; on the tournaments page a filter bar (game, region,
| free places, order) that works without a reload (user, 2026-09-28: "zu
| textlich. BILDER!!!!" and "Die Casual Cups liegen da so lose durcheinander
| rum"). The filter itself runs in the browser (CasualCupRegionsTest); here
| the data it filters on.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    // Monday 5 October 2026: every cup starts Saturday 20:00 on its region's clock.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    cupTick();
});

/** The rows of the rendered board: [game, region, free] per row, in page order. */
function boardRows(string $html): array
{
    preg_match_all('/data-cup-row data-order="\d+" data-game="([^"]+)" data-region="([^"]*)"\s+data-start="(\d+)" data-free="([01])"/', $html, $rows, PREG_SET_ORDER);

    return array_map(fn (array $row): array => [$row[1], $row[2], $row[4]], $rows);
}

test('the board groups the cups by game in the league\'s order, EU before US, and leaves out a given cup and other games', function () {
    $board = app(CupBoard::class);
    $groups = $board->groups();

    expect(array_column($groups, 'game'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-26'])
        ->and(array_map(fn (array $group): array => array_column($group['cups'], 'regionLabel'), $groups))->toBe([['EU', 'US'], ['EU', 'US'], ['EU', 'US']])
        ->and(collect($groups)->flatMap(fn (array $group) => $group['cups'])->every(fn (array $cup): bool => $cup['places'] === 4 && $cup['taken'] === 0 && $cup['free']))->toBeTrue();

    $eu = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    $chess = $board->groups('chess', $eu->id);

    expect($chess)->toHaveCount(1)
        ->and(array_column($chess[0]['cups'], 'regionLabel'))->toBe(['US']);
});

test('a guest reads each start on its region\'s clock, named by the city; a player with a zone reads their own, fixed', function () {
    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();

    expect($html)->toContain('data-test="cup-clock">8:00 PM</span>', 'data-test="cup-day">Sat, Oct 10</span>', 'data-test="cup-city">Berlin</span>', 'data-test="cup-city">New York</span>')
        // Never a bare offset or UTC stamp on the board.
        ->and(str($html)->after('data-test="cup-mentions"')->before('id="all-h"')->toString())->not->toContain('UTC', 'GMT');

    app()->setLocale('de');
    $german = app(CupBoard::class)->groups()[0]['cups'];
    expect([$german[0]['clock'], $german[0]['day'], $german[0]['city'], $german[1]['clock'], $german[1]['city']])->toBe(['20:00', 'Sa, 10. Okt', 'Berlin', '20:00', 'New York']);
    app()->setLocale('en');

    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Tokyo']));
    $own = $this->get(route('tournaments.index'))->assertOk()->getContent();

    // 20:00 Berlin is 3:00 AM Sunday in Tokyo; 20:00 New York is 9:00 AM Sunday. No browser rewrite.
    expect($own)->toContain('data-test="cup-clock">3:00 AM</span>', 'data-test="cup-clock">9:00 AM</span>', 'data-test="cup-day">Sun, Oct 11</span>', 'data-test="cup-city">Tokyo</span>')
        ->not->toContain('cupStart(');
});

test('the tournaments page has the filter bar and every row carries game, region, start and free places; the list below leaves the board\'s cups out', function () {
    $full = Tournament::query()->where('cup_open_series', 'rocket-league-us')->sole();
    $full->forceFill(['status' => TournamentStatus::Running])->save();
    $special = openTournament(['name' => 'RL Sunday'], rocketLeague: true);

    $html = $this->get(route('tournaments.index'))->assertOk()
        ->assertSeeHtml('data-test="cup-filters"')
        ->assertSeeHtml('x-data="cupBoard()"')
        ->assertSeeHtml('data-test="cup-filter-free"')
        ->assertSeeHtml('data-test="cup-sort-start"')
        ->getContent();

    expect(boardRows($html))->toBe([
        ['chess', 'eu', '1'], ['chess', 'us', '1'],
        ['rocket-league', 'eu', '1'], ['rocket-league', 'us', '0'],
        ['ea-sports-fc-26', 'eu', '1'], ['ea-sports-fc-26', 'us', '1'],
    ])
        ->and(substr_count($html, 'data-test="cup-group-cover"'))->toBe(3)
        ->and(substr_count($html, 'data-test="cup-filter-game-'))->toBe(5);

    // A running cup reads "Running" with the play icon; the special tournament is a card above the board, and no
    // cup has ended, so there is no list of past cups under it (OrganizerBoardTest).
    $organizers = str($html)->after('data-test="organizer-tournaments"')->before('data-test="cup-mentions"')->toString();
    expect($organizers)->toContain('RL Sunday')->not->toContain('Casual Cup')
        ->and($html)->not->toContain('id="all-h"')
        ->and(substr_count($html, 'data-test="cup-status"'))->toBe(6);
    expect($special->refresh()->isCasualCup())->toBeFalse();
});

test('home names the board with a link to the filters, a game page shows its own pair without covers or filters', function () {
    $this->get(route('home'))->assertOk()
        ->assertSeeHtml('id="cup-board-h"')
        ->assertSeeHtml('data-test="cup-board-all"')
        ->assertDontSeeHtml('data-test="cup-filters"');

    $game = $this->get('/games/rocket-league')->assertOk()->getContent();

    expect(boardRows($game))->toBe([['rocket-league', 'eu', '1'], ['rocket-league', 'us', '1']])
        ->and($game)->not->toContain('data-test="cup-group-cover"', 'data-test="cup-filters"', 'id="cup-board-h"');
});

test('the browser rewrites a start to a real zone of its own, never to a spoofed UTC one', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/cupBoard.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ fail 0');
});

<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\CupBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    // Monday 5 October 2026: every cup starts at its game's slot on its region's clock (chess and Rocket League
    // Saturday 20:00, FC 26 Friday 18:00).
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
        ->and($run->output())->toContain('ℹ pass 5')->toContain('ℹ fail 0');
});

/*
|--------------------------------------------------------------------------
| The cups' head on the tournaments page (P3 of plan mempool-streifen)
|--------------------------------------------------------------------------
|
| User, 2026-09-29: the cups "gehen ... total unter" and nobody reads them
| as tournaments, "weil es nirgends steht"; the organizers' tournaments stay
| above them. The head says tournament and what a cup is, shows the last
| cup's winner and the next cup to sign up for with faces, countdown and
| the way in; it stays when no cup is on. Its queries do not grow with the
| cups or the players.
|
*/

/** `$n` players signed up solo to `$cup`, straight into the table (the board only reads them). */
function boardSignups(Tournament $cup, int $n): void
{
    foreach (range(1, $n) as $index) {
        $user = User::factory()->create(['name' => "cup_player_{$cup->id}_{$index}"]);
        TournamentSignup::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => $user->name, 'members' => [$user->id]]);
    }
}

/** The part of the page between the organizers' tournaments and the past cups. */
function cupHead(string $html): string
{
    return str($html)->after('data-test="cup-hall"')->before('data-test="cup-filters"')->toString();
}

test('with a cup open for sign-up the head says tournament and what a cup is, and brings the next cup with its faces, countdown and sign-up', function () {
    $next = Tournament::query()->where('cup_open_series', 'ea-sports-fc-26-eu')->sole();
    boardSignups($next, 3);

    $html = $this->get(route('tournaments.index'))->assertOk()
        ->assertSeeInOrder(['data-test="organizer-tournaments"', 'data-test="cup-hall"', 'Casual cups', 'data-test="cup-kind"', 'Tournaments', 'The league opens a cup for every game and region on its own. Its games are casual and move only your casual Elo.', 'data-test="cup-next"', 'data-test="cup-filters"'], false)
        ->getContent();
    $head = cupHead($html);

    // FC 26 starts Friday 18:00, the others Saturday 20:00, each on its clock: FC 26 in Berlin is the earliest.
    expect($head)->toContain('data-tournament="'.$next->id.'"', 'data-test="cup-next-name">'.$next->name.'</a>')
        // The format follows the sign-ups (CasualCups::plannedFormat()): 3 in play a round robin, never the stored double elimination.
        ->toContain('EA Sports FC 26 1v1 tournament, 4 places', 'Format follows the sign-ups: 3 players → Round Robin')
        ->toContain('role="timer"', 'data-test="cup-next-countdown">4 days 06:00:00</span>')
        ->toContain('href="'.route('login').'"')
        ->and(substr_count($head, 'data-test="cup-next-face"'))->toBe(3)
        ->and($head)->toContain('title="cup_player_'.$next->id.'_1"')
        // The board says tournament too, and every row shows who is in.
        ->and($html)->toContain('Rapid 10+5 tournament</span>')
        ->and(str($html)->after('data-test="cup-hall"')->before('id="formats-h"')->toString())->not->toContain('Double Elimination', 'data-test="cup-format"')
        ->and(substr_count($html, 'data-test="cup-faces"'))->toBe(1)
        // No cup has ended yet: no winner, and no line about the next cup.
        ->and($head)->not->toContain('data-test="cup-winner"', 'data-test="cup-none"');

    // Signed in: the button leads to the sign-up; once in, it says so and the open seat is no link any more.
    $player = User::factory()->create();
    $this->actingAs($player);
    expect(cupHead($this->get(route('tournaments.index'))->getContent()))->toContain('href="'.route('tournaments.signup', $next).'"', __('Take your seat'));

    // The fourth player fills the cup: the head moves on to the next cup with a free place.
    TournamentSignup::query()->create(['tournament_id' => $next->id, 'user_id' => $player->id, 'name' => 'me', 'members' => [$player->id]]);
    expect(cupHead($this->get(route('tournaments.index'))->getContent()))->not->toContain('data-tournament="'.$next->id.'"');

    // Grown to 8 places, it is the next cup again, and the head says the viewer is in; no open seat is a link.
    $next->forceFill(['capacity' => 8])->save();
    $mine = cupHead($this->get(route('tournaments.index'))->getContent());
    expect($mine)->toContain('data-tournament="'.$next->id.'"', 'You’re in', 'hh-seat is-taken is-you', '4 of 8 spots taken')->not->toContain(__('Take your seat'));
});

test('in German the head says Turnier and Casual-Elo', function () {
    app()->setLocale('de');
    $head = cupHead(Blade::render('<x-tournaments.cup-mentions heading filters />'));
    app()->setLocale('en');

    expect($head)->toContain('Turniere', 'Die Liga eröffnet für jedes Spiel und jede Region selbst einen Cup. Seine Partien sind casual und bewegen nur dein Casual-Elo.', 'Turnier EA Sports FC 26 1v1, 4 Plätze', 'Das Format folgt den Anmeldungen: 2 Spieler → Duell', 'Anmeldung offen');
});

test('when every cup is running the head has no next cup, and each running row shows the format its field got', function () {
    Tournament::query()->casualCup()->update(['status' => TournamentStatus::Running]);
    // Four players at the close: a round robin evening (CasualCups::formatFor()).
    Tournament::query()->where('cup_open_series', 'chess-eu')->update(['format' => TournamentFormat::RoundRobin]);

    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();

    expect(cupHead($html))->toContain('data-test="cup-explainer"')->not->toContain('data-test="cup-next"', 'data-test="cup-none"')
        ->and(substr_count($html, 'data-test="cup-status"'))->toBe(6)
        ->and(substr_count($html, 'data-test="cup-format"'))->toBe(6)
        ->and($html)->toContain('data-test="cup-format">Round Robin</span>', 'data-test="cup-filters"');
});

test('a cup whose start has passed is not the next cup, even before the league\'s clock closes it; the head switches at zero', function () {
    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();
    // The countdown's zero event hides the button and says sign-up closed (resources/js/tournamentLanding.js).
    expect(cupHead($html))->toContain('x-on:countdown-zero="started = true"', 'data-test="cup-next-closed"', 'x-show="! started"');

    // Past every start (Saturday 20:00 New York is the last), no tick: every cup still says sign-up in the table.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 02:00:00', 'UTC'));
    $late = $this->get(route('tournaments.index'))->assertOk()->getContent();

    expect(Tournament::query()->casualCup()->where('status', TournamentStatus::Signup)->count())->toBe(6)
        ->and(cupHead($late))->not->toContain('data-test="cup-next"');
});

test('with no cup on the head stays, names the league\'s gap, and shows the last cup\'s winner', function () {
    Tournament::query()->casualCup()->delete();

    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();
    expect($html)->toContain('data-test="cup-hall"', 'data-test="cup-none"', 'No cup takes players right now. The next one opens 1 day after the last cup’s final or call-off.')
        ->not->toContain('data-test="cup-filters"', 'data-test="cup-next"', 'data-test="cup-winner"', 'data-test="cup-group"');

    $winner = User::factory()->create(['name' => 'satoshi_rook']);
    $cup = wonCasualCup($winner, User::factory()->create(['name' => 'hal_finney']), 7);
    $head = str($this->get(route('tournaments.index'))->assertOk()->getContent())->after('data-test="cup-hall"')->before('id="all-h"')->toString();

    expect($head)->toContain('data-test="cup-winner"', 'Won the last cup', 'data-test="cup-winner-name">satoshi_rook</span>', 'data-test="cup-winner-cup">Chess Casual Cup EU #7</span>', 'href="'.route('tournaments.show', $cup).'"')
        ->not->toContain('hal_finney');
});

test('with the cups switched off or no league key, no head promises a cup', function () {
    Tournament::query()->casualCup()->delete();
    wonCasualCup(User::factory()->create(), User::factory()->create());

    config(['esports.casual_cups.enabled' => []]);
    expect($this->get(route('tournaments.index'))->assertOk()->getContent())->not->toContain('data-test="cup-hall"', 'data-test="cup-mentions"');

    config(['esports.casual_cups.enabled' => ['chess'], 'esports.league.nsec' => null]);
    expect($this->get(route('tournaments.index'))->assertOk()->getContent())->not->toContain('data-test="cup-hall"', 'data-test="cup-mentions"');
});

test('home and the game pages keep the side mention without the head', function () {
    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-test="cup-hall"')
        ->and($this->get('/games/rocket-league')->assertOk()->getContent())->not->toContain('data-test="cup-hall"', 'data-test="cup-winner"');
});

/**
 * The queries of the cup section alone, after adding `$cups` open chess cups of 32 places with `$players` each and
 * one more finished cup with its winner. Warmed up with one render first, the cache flushed before the count.
 */
function cupSectionQueries(int $cups, int $players): int
{
    static $number = 100;
    wonCasualCup(User::factory()->create(), User::factory()->create(), ++$number);

    foreach (range(1, $cups) as $ignored) {
        $cup = Tournament::factory()->create([
            'name' => 'Chess Casual Cup EU #'.++$number, 'game' => 'chess', 'mode' => 'blitz', 'capacity' => 32, 'status' => TournamentStatus::Signup, 'created_by_id' => null,
            'cup_series' => 'chess-eu', 'cup_number' => $number, 'starts_at' => now()->addDays(3), 'signup_closes_at' => now()->addDays(3),
            'slug' => "chess-casual-cup-eu-{$number}",
        ]);
        boardSignups($cup, $players);
    }

    $render = fn (): string => Blade::render('<x-tournaments.cup-mentions heading filters />');
    $render();
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $render();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// Every size stays under the board's caps (64 cups, 16 seat tiles, 3 faces a row are drawn, the rest counted):
// one cup with one player is under all of them, so a query per cup or per player shows between 1 and 5.
test('the cup section reads a flat number of queries for 1, 5 and 25 more cups with 1, 5 and 25 players each', function () {
    $counts = [];

    foreach ([1, 5, 25] as $n) {
        $counts[$n] = cupSectionQueries($n, $n);
    }

    expect($counts[5])->toBe($counts[1])
        ->and($counts[25])->toBe($counts[1]);
});

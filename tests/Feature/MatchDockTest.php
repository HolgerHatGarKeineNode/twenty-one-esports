<?php

use App\Enums\ChessInviteStatus;
use App\Enums\InviteStatus;
use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessChallenge;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\SeriesReport;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The match dock (P5f): which items a player sees, in which order, and what
 * the dock says about them. OpenMatches is the definition; the component and
 * the page render it.
 */

beforeEach(function () {
    $this->freezeTime();
});

/** Black to move after 1. e4. */
const DOCK_BLACK_TO_MOVE = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1';

/**
 * A daily game of $user; `mine` says whether it is $user's move.
 */
function dockDaily(User $user, bool $mine, int $hoursLeft): ChessGame
{
    return ChessGame::factory()->daily()->create([
        'white_id' => $user->id,
        'fen' => $mine ? ChessGame::START_FEN : DOCK_BLACK_TO_MOVE,
        'ply' => $mine ? 0 : 1,
        'deadline_ms' => (int) now()->addHours($hoursLeft)->getTimestampMs(),
    ]);
}

/**
 * @return list<string>
 */
function dockKeys(User $user, ?int $excludeGame = null, ?int $excludeSeries = null): array
{
    return app(OpenMatches::class)->for($user, $excludeGame, $excludeSeries)->map(fn (DockItem $item) => $item->key)->all();
}

/**
 * A series between a fresh lineup of $me's clan and a fresh opponent.
 *
 * @param  array<string, mixed>  $attributes
 */
function dockSeries(Lineup $mine, string $mySide, array $attributes): SeriesMatch
{
    $theirs = Lineup::factory()->ready()->create();

    return SeriesMatch::factory()->create([
        'challenger_lineup_id' => $mySide === 'challenger' ? $mine->id : $theirs->id,
        'challenged_lineup_id' => $mySide === 'challenger' ? $theirs->id : $mine->id,
        ...$attributes,
    ]);
}

test('chess items come live first, then what is on the player by deadline, then what waits', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();

    $blitz = ChessGame::factory()->create(['white_id' => $friend->id, 'black_id' => $me->id]);
    $soon = dockDaily($me, mine: true, hoursLeft: 5);
    $later = dockDaily($me, mine: true, hoursLeft: 20);
    $theirs = dockDaily($me, mine: false, hoursLeft: 2);
    $invite = ChessInvite::query()->create(['inviter_id' => $friend->id, 'invitee_id' => $me->id, 'mode' => 'blitz', 'status' => ChessInviteStatus::Pending, 'expires_at' => now()->addMinute()]);
    $challenge = ChessChallenge::query()->create(['challenger_id' => $friend->id, 'challenged_id' => $me->id, 'mode' => ChessGame::CORRESPONDENCE, 'color' => 'random', 'status' => ChessInviteStatus::Pending, 'expires_at' => now()->addHours(30)]);
    $clanInvite = ClanInvite::query()->create(['clan_id' => Clan::factory()->create()->id, 'inviter_id' => $friend->id, 'invitee_id' => $me->id, 'status' => InviteStatus::Pending]);

    // Not on the dock: a finished game, what the player sent, an expired invite, someone else's game.
    ChessGame::factory()->daily()->finished()->create(['white_id' => $me->id]);
    ChessChallenge::query()->create(['challenger_id' => $me->id, 'challenged_id' => $friend->id, 'mode' => ChessGame::CORRESPONDENCE, 'color' => 'random', 'status' => ChessInviteStatus::Pending, 'expires_at' => now()->addHours(30)]);
    ChessInvite::query()->create(['inviter_id' => $friend->id, 'invitee_id' => $me->id, 'mode' => 'blitz', 'status' => ChessInviteStatus::Pending, 'expires_at' => now()->subSecond()]);
    ChessGame::factory()->daily()->create();

    expect(dockKeys($me))->toBe([
        'game-'.$blitz->id,
        'invite-'.$invite->id,
        'game-'.$soon->id,
        'game-'.$later->id,
        'challenge-'.$challenge->id,
        'clan-invite-'.$clanInvite->id,
        'game-'.$theirs->id,
    ])
        ->and(app(OpenMatches::class)->summary($me))->toBe(['open' => 7, 'need' => 5, 'wait' => 2]);
});

test('series join the dock only when live, soon, or waiting on someone', function () {
    $me = User::factory()->create();
    $mine = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $me->id])->id]);

    $live = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $soon = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Accepted, 'start_at' => now()->addMinutes(30)]);
    $toAnswer = dockSeries($mine, 'challenged', ['status' => SeriesStatus::Open, 'respond_by' => now()->addHours(2)]);
    $toAccept = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Reported, 'start_at' => now()->subHour()]);
    SeriesReport::query()->create(['series_match_id' => $toAccept->id, 'side' => 'challenged', 'games' => [['winner' => 'challenged', 'challenger' => 1, 'challenged' => 3]], 'roster' => [], 'status' => ReportStatus::Open]);
    $sentReport = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Reported, 'start_at' => now()->subHour()]);
    SeriesReport::query()->create(['series_match_id' => $sentReport->id, 'side' => 'challenger', 'games' => [['winner' => 'challenger', 'challenger' => 3, 'challenged' => 1]], 'roster' => [], 'status' => ReportStatus::Open]);
    $disputed = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Disputed, 'start_at' => now()->subHour()]);

    // Not on the dock: a challenge the player sent, a series three hours away, a finished one.
    dockSeries($mine, 'challenger', ['status' => SeriesStatus::Open]);
    dockSeries($mine, 'challenger', ['status' => SeriesStatus::Accepted, 'start_at' => now()->addHours(3)]);
    dockSeries($mine, 'challenger', ['status' => SeriesStatus::Confirmed]);

    // A result to accept has no deadline, so it follows the ones that have one.
    $items = app(OpenMatches::class)->for($me)->keyBy('key');

    expect($items->keys()->all())->toBe([
        'series-'.$live->number,
        'series-'.$soon->number,
        'series-'.$toAnswer->number,
        'series-'.$toAccept->number,
        'series-'.$sentReport->number,
        'series-'.$disputed->number,
    ])
        ->and($items->map(fn (DockItem $item) => [$item->phase, $item->needsYou])->values()->all())->toBe([
            ['live', true], ['starts', true], ['answer', true], ['accept', true], ['waiting', false], ['dispute', false],
        ])
        // The score from the player's side.
        ->and($items['series-'.$toAccept->number]->trailing)->toBe('0 : 1');

    // A player of the lineup who is not its captain waits; the answer is the captain's.
    $player = $mine->seats->first(fn ($seat) => $seat->user_id !== $me->id)->user;
    expect(collect(dockKeys($player))->contains('series-'.$toAnswer->number))->toBeFalse()
        ->and(app(OpenMatches::class)->for($player)->firstWhere('key', 'series-'.$toAccept->number)->phase)->toBe('waiting');
});

test('the dock shows the counts, the tabs that fit and says how many hidden ones need the player', function () {
    $me = User::factory()->create();
    foreach ([2, 4, 6, 8, 10] as $hours) {
        dockDaily($me, mine: true, hoursLeft: $hours);
    }
    dockDaily($me, mine: false, hoursLeft: 3);

    Livewire::actingAs($me)->test('match-dock')
        ->assertOk()
        ->assertSeeHtml('data-test="match-dock"')
        ->assertSeeInOrder(['data-test="dock-count"', '5', 'need you'], false)
        // Three tabs from lg: 3 hidden, two of them on the player.
        ->assertSeeInOrder(['+3 more', '2 need you'])
        // Four from xl: 2 hidden, one on the player.
        ->assertSeeInOrder(['+2 more', '1 needs you'])
        ->assertSee('5 need you, 6 open')
        ->call('$refresh')
        ->assertOk();
});

test('with nothing open there is no dock at all, and a guest gets none either', function () {
    $me = User::factory()->create();
    ChessGame::factory()->daily()->finished()->create(['white_id' => $me->id]);

    $this->actingAs($me)->get(route('clans.index'))
        ->assertOk()
        ->assertSee('data-test="match-dock-root"', false)
        ->assertDontSee('data-test="match-dock"', false)
        ->assertDontSee('data-test="dock-mobile-bar"', false)
        ->assertDontSee('data-test="dock-spacer"', false);

    auth()->logout();
    dockDaily($me, mine: true, hoursLeft: 5);

    $this->get(route('clans.index'))->assertOk()->assertDontSee('match-dock', false);
});

test('the game on screen has no tab, and on a running game the dock moves into the title row', function () {
    $me = User::factory()->create();
    $onScreen = dockDaily($me, mine: true, hoursLeft: 5);
    $other = dockDaily($me, mine: false, hoursLeft: 5);

    $this->actingAs($me)->get(route('games.show', $onScreen))
        ->assertOk()
        ->assertSee('data-dock-item="game-'.$other->id.'"', false)
        ->assertDontSee('data-dock-item="game-'.$onScreen->id.'"', false)
        ->assertSee('x-teleport="[data-dock-slot]"', false)
        ->assertSee('data-dock-slot', false)
        ->assertDontSee('data-test="match-dock"', false);

    // Elsewhere both games have their tab, in the floating bar.
    $this->get(route('clans.index'))
        ->assertSee('data-dock-item="game-'.$onScreen->id.'"', false)
        ->assertSee('data-test="match-dock"', false)
        ->assertDontSee('x-teleport', false);
});

test('the series on screen has no tab in its room', function () {
    $me = User::factory()->create();
    $mine = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $me->id])->id]);
    $room = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $other = dockSeries($mine, 'challenger', ['status' => SeriesStatus::Disputed]);

    $this->actingAs($me)->get(route('matches.room', $room))
        ->assertOk()
        ->assertSee('data-dock-item="series-'.$other->number.'"', false)
        ->assertDontSee('data-dock-item="series-'.$room->number.'"', false);
});

test('the dock reads the game and series of the current route', function () {
    $game = ChessGame::factory()->create();
    $request = fn (string $url) => tap(Request::create($url), fn ($request) => $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request)->bind($request)));

    expect(OpenMatches::onScreen($request('/games/'.$game->id)))->toBe(['game' => $game->id, 'series' => null])
        ->and(OpenMatches::onScreen($request('/matches/402/room')))->toBe(['game' => null, 'series' => 402])
        ->and(OpenMatches::onScreen($request('/clans')))->toBe(['game' => null, 'series' => null])
        ->and(OpenMatches::onScreen(null))->toBe(['game' => null, 'series' => null]);
});

test('the dock reads a player\'s series without a query per series', function () {
    $owner = User::factory()->create();
    $mine = Lineup::factory()->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $owner->id])->id]);
    // A seated player who is not the captain: the lookup goes through the seat, its user and their clan.
    $player = $mine->seats->first(fn ($seat) => $seat->user_id !== $owner->id)->user;

    $queries = function () use ($player): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Model::preventLazyLoading();

        try {
            app(OpenMatches::class)->for(User::query()->findOrFail($player->id));
        } finally {
            Model::preventLazyLoading(false);
            DB::disableQueryLog();
        }

        return count(DB::getQueryLog());
    };

    dockSeries($mine, 'challenger', ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(20)]);
    $one = $queries();
    dockSeries($mine, 'challenger', ['status' => SeriesStatus::Disputed]);
    dockSeries($mine, 'challenged', ['status' => SeriesStatus::Accepted, 'start_at' => now()->subMinutes(5)]);

    expect(dockKeys($player))->toHaveCount(3)
        ->and($queries())->toBe($one);
});

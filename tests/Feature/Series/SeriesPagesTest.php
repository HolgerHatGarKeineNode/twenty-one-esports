<?php

use App\Enums\SeriesStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\MatchNumber;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The clan owner (captain) of one side of a factory series.
 */
function seriesCaptain(SeriesMatch $match, string $side = 'challenger'): User
{
    return $match->lineup($side)->clan->owner;
}

/**
 * A factory series moved through the service to the given state.
 */
function seriesIn(string $state): SeriesMatch
{
    $series = app(SeriesService::class);
    $match = SeriesMatch::factory()->accepted()->create();
    $a = seriesCaptain($match);
    $b = seriesCaptain($match, 'challenged');

    if ($state === 'accepted') {
        return $match;
    }

    $series->setLobby($match, $a, 'e21-room', 'pw-room', 'EU');
    $series->saveLiveGame($match, $a, 0, 3, 1, null);
    $series->saveLiveGame($match, $a, 1, null, null, 'challenger');
    $series->report($match, $a, []);

    match ($state) {
        'confirmed' => $series->respond($match, $b, 'confirmed', '', []),
        'disputed' => $series->respond($match, $b, 'disputed', 'Game 2 was ours.', []),
        default => null,
    };

    return $match->refresh();
}

test('a running series pins the captain\'s lobby above the score with copy buttons, once; after the result it is not pinned', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $captain = seriesCaptain($match);
    $other = seriesCaptain($match, 'challenged');

    $before = Livewire::actingAs($other)->test('pages::matches.room', ['match' => $match])->html();
    expect($before)->not->toContain('data-test="room-lobby-pin"');

    app(SeriesService::class)->setLobby($match, $captain, 'e21-pinned-room', 'pw-pinned-room', 'EU');
    $html = Livewire::actingAs($other)->test('pages::matches.room', ['match' => $match])->html();

    expect($html)->toContain('data-test="room-lobby-pin"')->toContain('data-test="room-lobby-copy-name"')->toContain('data-test="room-lobby-copy-password"')
        // Under the versus, first thing after who-vs-who (user, 2026-10-04); the password in plain text, never hidden (user, 2026-10-10).
        ->and($html)->toContain('data-test="room-lobby-password">pw-pinned-room<')->not->toContain('••••')
        ->and(strpos($html, 'data-test="room-lobby-pin"'))->toBeGreaterThan(strpos($html, 'data-test="series-score"'))
        ->and($html)->toContain('data-test="room-lobby-name"')
        ->and(strpos($html, 'data-test="room-lobby-name"'))->toBeGreaterThan(strpos($html, 'data-test="series-score"'));

    $done = seriesIn('confirmed');
    expect(Livewire::actingAs(seriesCaptain($done))->test('pages::matches.room', ['match' => $done])->html())->not->toContain('data-test="room-lobby-pin"');
});

test('every series page survives a Livewire roundtrip', function (string $page, string $who, Closure $params) {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    [$user, $args] = $params($admin);
    $component = $who === 'guest' ? Livewire::test($page, $args) : Livewire::actingAs($user)->test($page, $args);

    $component->assertOk()->call('$refresh')->assertOk();
})->with([
    'matches, guest' => ['pages::matches.index', 'guest', fn () => [null, (fn () => [])(SeriesMatch::factory()->create())]],
    'match page, reported' => ['pages::matches.show', 'guest', fn () => [null, ['match' => (string) seriesIn('reported')->number]]],
    'match page, confirmed, as captain' => ['pages::matches.show', 'user', fn () => [seriesCaptain($m = seriesIn('confirmed')), ['match' => (string) $m->number]]],
    'room, open challenge, challenged captain' => ['pages::matches.room', 'user', fn () => [seriesCaptain($m = SeriesMatch::factory()->create(), 'challenged'), ['match' => $m]]],
    'room, accepted, captain' => ['pages::matches.room', 'user', fn () => [seriesCaptain($m = seriesIn('accepted')), ['match' => $m]]],
    'room, reported, other captain' => ['pages::matches.room', 'user', fn () => [seriesCaptain($m = seriesIn('reported'), 'challenged'), ['match' => $m]]],
    'room, disputed' => ['pages::matches.room', 'user', fn () => [seriesCaptain($m = seriesIn('disputed')), ['match' => $m]]],
    'room, confirmed' => ['pages::matches.room', 'user', fn () => [seriesCaptain($m = seriesIn('confirmed'), 'challenged'), ['match' => $m]]],
    'new challenge, captain' => ['pages::challenges.create', 'user', fn () => [Lineup::factory()->ready()->create()->clan->owner, []]],
    'new challenge, no clan' => ['pages::challenges.create', 'user', fn () => [User::factory()->create(), []]],
    'admin disputes' => ['pages::admin.disputes', 'user', fn (User $admin) => [$admin, (fn () => [])(seriesIn('disputed'))]],
    'admin dispute' => ['pages::admin.dispute', 'user', fn (User $admin) => [$admin, ['match' => seriesIn('disputed')]]],
]);

test('a captain sends a casual challenge from the page: nothing is signed, the number is reserved, the room opens', function () {
    $mine = Lineup::factory()->ready()->create();
    $theirs = Lineup::factory()->ready()->create();

    $page = Livewire::actingAs($mine->clan->owner)->test('pages::challenges.create')
        ->assertSet('lineupId', $mine->id)
        ->call('pickOpponent', $theirs->id)
        ->call('prepareSend')->assertReturned([]);

    $page->call('send', '[]')->assertHasNoErrors();

    $match = SeriesMatch::query()->sole();
    $page->assertRedirect(route('matches.room', $match));

    expect($match->status)->toBe(SeriesStatus::Open)
        ->and($match->challenged_lineup_id)->toBe($theirs->id)
        ->and($match->rated)->toBeFalse()
        ->and(MatchNumber::query()->find($match->number)?->user_id)->toBe($mine->clan->owner_id);
});

test('an outsider is sent from the room to the public match page, an unknown number shows the not-found state', function () {
    $match = SeriesMatch::factory()->accepted()->create();

    $this->actingAs(User::factory()->create())->get(route('matches.room', $match))->assertRedirect(route('matches.show', $match));
    $this->get('/matches/999999')->assertNotFound()->assertSee('Match #999999 not found');
});

test('the room tick answers without a render until something changed, and loads the match once per tick', function () {
    $match = SeriesMatch::factory()->accepted()->create(['start_at' => now()->addMinutes(2)->startOfMinute()]);
    $captain = seriesCaptain($match);
    $room = Livewire::actingAs($captain)->test('pages::matches.room', ['match' => $match]);

    $seatQueries = function () use ($room): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $room->call('sync')->assertOk();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'from "lineup_seats"'))->count();
    };

    // Nothing new: no HTML in the answer, and one load of both lineups' seats.
    expect($seatQueries())->toBe(2)
        ->and($room->effects)->not->toHaveKey('html');

    // The kick-off passes: the status line changes, so the tick renders.
    $this->travel(3)->minutes();
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html')
        ->and($room->html())->toContain(__('Match running'));

    $room->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html');

    // The other captain writes a score: the next tick shows it.
    app(SeriesService::class)->saveLiveGame($match->refresh(), seriesCaptain($match, 'challenged'), 0, 3, 1, null);
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html')
        ->and($room->get('sheet')[0])->toMatchArray(['c' => 3, 'd' => 1])
        ->and($room->html())->toContain('data-test="series-score">1 : 0<');
});

test('the room syncs on a push and at its next clock edge, not on an 8 s interval (performance plan P3)', function () {
    $match = SeriesMatch::factory()->accepted()->create(['start_at' => now()->addMinutes(2)->startOfMinute()]);
    $captain = seriesCaptain($match);
    $room = Livewire::actingAs($captain)->test('pages::matches.room', ['match' => $match]);

    // The client part (resources/js/matchRoom.js roomSync) gets the series to listen for and the fallback seconds;
    // the next edge is the kick-off.
    expect($room->html())->toContain('x-data="roomSync(')->not->toContain('setInterval')
        ->and($room->html())->toContain('data-next-edge="'.$match->start_at->getTimestampMs().'"');

    // After the kick-off the next edge is the no-show window, then there is none.
    $this->travel(3)->minutes();
    $room->call('$refresh')->assertOk();
    expect($room->html())->toContain('data-next-edge="'.$match->refresh()->noshowReportableAt()->getTimestampMs().'"');

    $this->travelTo($match->noshowReportableAt()->addMinute());
    $room->call('$refresh')->assertOk();
    expect($room->html())->toContain('data-next-edge="0"');
});

test('the room tick renders when a rating or a captain changed outside the match row', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $room = Livewire::actingAs(seriesCaptain($match))->test('pages::matches.room', ['match' => $match]);

    $room->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html');

    // A casual rating for the challenger lineup (another series ended meanwhile).
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => $match->mode,
        'subject' => 'lineup:'.$match->challenger_lineup_id, 'lineup_id' => $match->challenger_lineup_id, 'rating' => 1800, 'results' => 6, 'wins' => 6]);
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html')
        ->and($room->html())->toContain($match->challenger_tag.' 1,800');

    // The challenged clan's owner, who sits in no lineup, renames themselves: the captain line follows.
    $owner = User::factory()->create(['name' => 'Old Captain']);
    $match->lineup('challenged')->clan->forceFill(['owner_id' => $owner->id])->save();
    $room->call('sync')->assertOk();
    expect($room->html())->toContain('Old Captain');

    $owner->forceFill(['name' => 'Renamed Captain'])->save();
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html')
        ->and($room->html())->toContain('Renamed Captain');
});

test('the room renders at least once a minute, even when nothing it knows of changed', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $room = Livewire::actingAs(seriesCaptain($match))->test('pages::matches.room', ['match' => $match]);

    $room->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html');

    $this->travel(61)->seconds();
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html');

    $room->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html');
});

test('a game the captain has half entered keeps its first box through a sync of the room and counts once the second is filled', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $room = Livewire::actingAs(seriesCaptain($match))->test('pages::matches.room', ['match' => $match]);

    $room->set('sheet.0.c', '3')->set('sheet.0.d', '1');
    expect($match->refresh()->live_games)->toBe([['challenger' => 3, 'challenged' => 1, 'winner' => 'challenger']]);

    // Game 2, box by box, and between the boxes the sync the page asks for on its own (resources/js/matchRoom.js
    // roomSync: the push of the captain's own save of game 1, a clock edge, the fallback poll). It answers
    // without a render, so the box in the page is whatever the component holds.
    $room->set('sheet.1.c', '2')->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html')
        ->and($room->get('sheet')[1])->toMatchArray(['c' => '2', 'd' => null, 'winner' => null]);

    $room->set('sheet.1.d', '0');
    expect($match->refresh()->live_games[1] ?? null)->toBe(['challenger' => 2, 'challenged' => 0, 'winner' => 'challenger'])
        ->and($room->html())->toContain('data-test="series-score">2 : 0<');
});

test('a half entered game stays in its box while the captain saves another game', function () {
    $match = SeriesMatch::factory()->accepted()->create(['best_of' => 5]);
    $room = Livewire::actingAs(seriesCaptain($match))->test('pages::matches.room', ['match' => $match]);

    $room->set('sheet.0.c', '3')->set('sheet.0.d', '1')->set('sheet.1.c', '4');
    // Back to game 1 for a correction, by its stepper: the save reads the sheet again.
    $room->call('stepGoal', 0, 'd', 1)->assertOk();

    expect($match->refresh()->live_games)->toBe([['challenger' => 3, 'challenged' => 2, 'winner' => 'challenger']])
        ->and($room->get('sheet')[1])->toMatchArray(['c' => '4', 'd' => null])
        ->and($room->get('sheet')[0])->toMatchArray(['c' => 3, 'd' => 2]);
});

test('a half entered game gives way to what is stored: the other captain\'s score for it, a decided series, a closed sheet', function () {
    $series = app(SeriesService::class);
    $half = function (int $index, int $bestOf = 3): array {
        $match = SeriesMatch::factory()->accepted()->create(['best_of' => $bestOf]);
        $room = Livewire::actingAs(seriesCaptain($match))->test('pages::matches.room', ['match' => $match]);
        $room->set('sheet.0.c', '3')->set('sheet.0.d', '1')->set("sheet.{$index}.c", '2');
        expect($room->get('sheet')[$index]['c'])->toBe('2');

        return [$match->refresh(), $room, seriesCaptain($match, 'challenged')];
    };

    // The other captain enters that very game first: their stored score shows, not the half one.
    [$match, $room, $other] = $half(1);
    $series->saveLiveGame($match, $other, 1, 0, 4, null);
    $room->call('sync')->assertOk();
    expect($room->get('sheet')[1])->toMatchArray(['c' => 0, 'd' => 4, 'winner' => 'challenged']);

    // The other captain's game 2 decides the series: game 3 is not needed, its row is empty again (and left out).
    [$match, $room, $other] = $half(2);
    $series->saveLiveGame($match, $other, 1, 5, 0, null);
    $room->call('sync')->assertOk();
    expect($room->get('sheet')[2])->toMatchArray(['c' => null, 'd' => null])
        ->and($room->html())->not->toContain('data-test="goals-2-c"');

    // The series ends without that game (a decision, a no-show): the sheet is closed, its boxes are locked and empty.
    [$match, $room] = $half(1);
    $match->update(['status' => SeriesStatus::Resolved]);
    $room->call('sync')->assertOk();
    expect($room->get('sheet')[1])->toMatchArray(['c' => null, 'd' => null])
        ->and($room->get('sheet')[0])->toMatchArray(['c' => 3, 'd' => 1]);

    // The captain switches the half game to "Winner only": its goals are dropped (toggleWinnerOnly).
    [$match, $room] = $half(1);
    $room->call('toggleWinnerOnly', 1)->assertOk();
    expect($room->get('sheet')[1])->toMatchArray(['c' => null, 'd' => null]);
});

test('a captain can copy the opposing players\' npubs in the room and on the match page', function (string $state) {
    $match = seriesIn($state);
    $captain = seriesCaptain($match);
    $opponent = seriesCaptain($match, 'challenged');

    $this->actingAs($captain)->get(route('matches.room', $match))->assertOk()
        ->assertSee('data-npub="'.$opponent->npub.'"', false);

    $this->actingAs($captain)->get(route('matches.show', $match))->assertOk()
        ->assertSee('data-npub="'.$opponent->npub.'"', false)
        ->assertDontSee('data-npub="'.$captain->npub.'"', false);
})->with(['accepted', 'confirmed']);

test('the game filter on /matches narrows the table to chess or Rocket League, with chess games listed', function () {
    $series = SeriesMatch::factory()->accepted()->create();
    $live = ChessGame::factory()->create();
    $daily = ChessGame::factory()->daily()->finished()->create();
    $row = fn (string $key) => 'wire:key="'.$key.'"';

    $page = Livewire::test('pages::matches.index')
        ->assertSeeHtml($row('m-'.$series->id))->assertSeeHtml($row('c-'.$live->id))->assertSeeHtml($row('c-'.$daily->id));

    $page->call('pickGame', 'chess')->assertSet('game', 'chess')
        ->assertDontSeeHtml($row('m-'.$series->id))->assertSeeHtml($row('c-'.$live->id))->assertSeeHtml($row('c-'.$daily->id));

    $page->call('pickStatus', 'live')
        ->assertSeeHtml($row('c-'.$live->id))->assertDontSeeHtml($row('c-'.$daily->id));

    $page->call('pickStatus', 'all')->call('pickGame', 'rocket-league')
        ->assertSeeHtml($row('m-'.$series->id))->assertDontSeeHtml('data-test="chess-row"');

    $page->call('pickGame', 'nonsense')->assertSet('game', 'all');
});

test('/matches asks nothing about chess for the statuses a chess game never has', function () {
    $open = SeriesMatch::factory()->create(['status' => SeriesStatus::Open]);
    ChessGame::factory()->create();
    ChessGame::factory()->daily()->finished()->create();

    DB::enableQueryLog();
    $page = Livewire::test('pages::matches.index')->call('pickStatus', 'waiting');
    $sql = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Waiting, scheduled, to confirm and disputed are series states only.
    expect($sql->filter(fn (string $query) => preg_match('/\b(1 = 0|0 = 1)\b/', $query) === 1)->values()->all())->toBe([])
        ->and($page->get('counts'))->toBe(['waiting' => 1, 'scheduled' => 0, 'live' => 1, 'to_confirm' => 0, 'disputed' => 0, 'done' => 1]);
    $page->assertSeeHtml('wire:key="m-'.$open->id.'"')->assertDontSeeHtml('data-test="chess-row"');
});

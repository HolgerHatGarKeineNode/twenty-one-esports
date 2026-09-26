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
        ->and($room->html())->toContain(__('Score to submit'));

    $room->call('sync')->assertOk();
    expect($room->effects)->not->toHaveKey('html');

    // The other captain writes a score: the next tick shows it.
    app(SeriesService::class)->saveLiveGame($match->refresh(), seriesCaptain($match, 'challenged'), 0, 3, 1, null);
    $room->call('sync')->assertOk();
    expect($room->effects)->toHaveKey('html')
        ->and($room->get('sheet')[0])->toMatchArray(['c' => 3, 'd' => 1])
        ->and($room->html())->toContain('data-test="series-score">1 : 0<');
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
        ->and($room->html())->toContain($match->challenger_tag.' 1800');

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

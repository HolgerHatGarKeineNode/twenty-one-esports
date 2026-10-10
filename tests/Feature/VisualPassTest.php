<?php

use App\Enums\TournamentFormat;
use App\Models\Clan;
use App\Models\Tournament;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The visual pass, part C (P53)
|--------------------------------------------------------------------------
|
| Pictures and bars where the pages still read as text (user, 2026-09-28):
| home's one line per game, the tournaments by state, the next tournament
| off air, the share cap split, the clans' counter line, the empty states
| with a picture and one button, and the casual game as its cover. Real data
| only: a picture without its data is left out, never faked.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', 'rocket-league']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

test('home lists every open cup as one row with its cover and seats in this week\'s list, and no board', function () {
    cupTick();

    $week = str($this->get(route('home'))->assertOk()->getContent())->after('data-test="home-week"')->before('</section>')->toString();

    // Two games, two regions each (Main.dc.html "Läuft diese Woche").
    expect(substr_count($week, 'data-test="week-row"'))->toBe(4)
        ->and(substr_count($week, 'data-game-cover='))->toBe(4)
        ->and(substr_count($week, 'data-test="seats"'))->toBe(4)
        ->and($week)->not->toContain('data-test="cup-filters"');
});

test('the tournaments page shows the states as one bar with icon, count and word each', function () {
    openTournament(['name' => 'Autumn Blitz']);
    runningChess(TournamentFormat::SingleElimination, 4);

    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();

    expect($html)->toContain('data-test="state-segment-signup"', 'data-test="state-segment-running"', 'data-test="state-count-finished"')
        // A state with nothing in it has its legend entry, no segment.
        ->not->toContain('data-test="state-segment-finished"');
});

test('off air, /live shows the next tournament with its cover and its places', function () {
    openTournament(['name' => 'Autumn Blitz', 'starts_at' => now()->addDays(3)]);

    $this->get(route('live'))->assertOk()
        ->assertSeeHtml('data-test="live-offline-next-cover"')
        ->assertSeeHtml('data-test="live-offline-next-seats"')
        ->assertSee('0 of 12 spots taken');
});

test('the season page splits the share cap per era as a bar', function () {
    $this->get(route('mining'))->assertOk()->assertSeeHtml('data-test="share-cap-bar"');
});

test('the clans page folds its numbers into one line above the clan cards, and shows the map only with a meetup pin', function () {
    $clan = Clan::factory()->create();

    $this->get(route('clans.index'))->assertOk()
        ->assertSeeHtml('data-test="clan-counters"')
        ->assertSeeHtml('data-test="clan-grid"')
        ->assertDontSeeHtml('id="map-h"');

    $clan->forceFill(['meetup_name' => 'Einundzwanzig Berlin', 'meetup_city' => 'Berlin', 'meetup_latitude' => 52.52, 'meetup_longitude' => 13.4])->save();

    $this->get(route('clans.index'))->assertOk()->assertSeeHtml('id="map-h"');
});

test('empty tournament and clan states show the real next tournament and clans with one button, and nothing made up without them', function () {
    $player = User::factory()->create();

    // Nothing open, no clan: the sentences stand alone.
    $bare = $this->actingAs($player)->get(route('players.show', $player->npub))->assertOk()->getContent();
    expect($bare)->toContain('data-test="player-tournaments-empty"', 'data-test="player-clans-empty"', 'data-test="open-picture-cta"', 'data-test="join-picture-cta"')
        ->not->toContain('data-test="open-picture"', 'data-test="join-picture"');

    openTournament(['name' => 'Autumn Blitz']);
    Clan::factory()->count(2)->create();

    $own = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    expect($own)->toContain('data-test="open-picture"', 'Autumn Blitz', 'data-test="join-picture"', '2 clans play in the league')
        ->and(substr_count($own, 'data-test="open-picture-cta"'))->toBe(1);

    // A visitor sees the pictures without the buttons.
    auth()->logout();
    $visitor = $this->get(route('players.show', $player->npub))->assertOk()->getContent();
    expect($visitor)->toContain('data-test="open-picture"')->not->toContain('data-test="open-picture-cta"', 'data-test="join-picture-cta"');

    // /me: the clan state has one button now.
    $me = $this->actingAs($player)->get(route('dashboard'))->assertOk()->getContent();
    expect(substr_count(str($me)->after('data-test="me-clan"')->before('data-test="me-looking"')->toString(), 'href="'.route('clans.index').'"'))->toBe(2)
        ->and(str($me)->after('data-test="me-clan"')->before('data-test="me-looking"')->toString())->not->toContain(route('clans.create'));
});

test('the casual challenge picks the game by its cover, one radio per game', function () {
    $user = User::factory()->create();
    $html = $this->actingAs($user)->get(route('challenges.casual'))->assertOk()->getContent();

    foreach ((array) config('esports.casual.games') as $slug) {
        expect($html)->toContain('data-test="casual-game-'.$slug.'"', 'type="radio" wire:model="game" name="casual-game" value="'.$slug.'"');
    }

    expect(substr_count($html, 'data-game-cover='))->toBeGreaterThanOrEqual(count((array) config('esports.casual.games')));
});

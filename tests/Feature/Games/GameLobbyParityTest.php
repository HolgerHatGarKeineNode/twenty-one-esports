<?php

use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardInvites;
use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

/*
 * A board game's lobby arranged as the chess lobby (P5 of plan
 * mempool-streifen; user, 2026-09-29: "ich hätte genau die selbe Anordnung
 * erwartet ... ich sehe auch keine Online Leute"), and the casual cups on
 * every game page with a head that says they are tournaments ("Casual Cups
 * aber, kein Titel gar nix").
 */

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
});

/** The lobby sections, by the hook each one carries, in the order they must appear on both lobbies. */
const LOBBY_SECTIONS = [
    'title' => 'data-test="lobby-title"',
    'play' => 'data-test="play"',
    'next tournament' => 'data-test="next-tournament',
    'cups' => 'data-test="cup-mentions"',
    'your games' => 'id="your-games"',
    'live' => 'aria-labelledby="live-h"',
    'online' => 'data-test="online-now"',
    'ladder' => 'data-test="lobby-ladder"',
    // Logged in only, on both lobbies (plan brettspiel-chat-und-follows, P2): a guest has no follow list here.
    'follows' => 'data-test="follows-here"',
    // Since P1 of plan brettspiel-chat-und-follows on both: each board game has its own channel (GameChannels).
    'chat' => 'data-test="game-chat"',
];

/**
 * The sections a page shows, in page order.
 *
 * @return list<string>
 */
function lobbySections(string $html): array
{
    $found = array_filter(array_map(fn (string $hook): int|false => strpos($html, $hook), LOBBY_SECTIONS), fn (int|false $at): bool => $at !== false);
    asort($found);

    return array_keys($found);
}

function lobbyCups(): void
{
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', NineMensMorris::SLUG, 'rocket-league']]);
    test()->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    app(CasualCups::class)->tick();
}

test('the board lobby has the chess lobby\'s sections in the chess lobby\'s order, for a guest and a player', function (bool $signedIn) {
    lobbyCups();

    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $chess = lobbySections($this->get(route('chess.lobby'))->assertOk()->getContent());
    $board = lobbySections($this->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()->getContent());

    // The chat sits right under the play row since 2026-10-04 (a bar on the phone, the side column from 1280 px).
    $expected = ['title', 'play', 'chat', 'next tournament', 'cups', 'your games', 'live', 'online', 'ladder', ...($signedIn ? ['follows'] : [])];

    // The board games run no casual cup since 2026-10-07 (correspondence only): the same order without it.
    expect($board)->toBe(array_values(array_diff($expected, ['cups'])))
        ->and($chess)->toBe($expected);
})->with(['guest' => [false], 'player' => [true]]);

test('the board lobby\'s online list is the presence channel\'s, with its own "Looking to play" switch, never a stored flag passed off as online', function () {
    [$me, $away] = User::factory()->count(2)->create();
    // Looking, but nobody knows whether they are online: the old lobby listed them anyway.
    $away->forceFill(['name' => 'Offline Olga', 'looking_to_play' => NineMensMorris::SLUG.'/correspondence'])->save();

    $this->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()
        ->assertSeeHtml('data-test="online-now"')
        ->assertSee('Log in to see who is online and to invite a friend.')
        ->assertDontSee('Offline Olga');

    $this->actingAs($me)->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()
        ->assertSeeHtml('x-data="boardLobby(')
        ->assertSee('\u0022lookingKey\u0022:\u0022nine-mens-morris', false)
        ->assertSeeHtml('data-test="looking-toggle"')
        ->assertSeeHtml('data-test="online-empty"')
        ->assertSee("looking: Nine Men's Morris")
        ->assertSeeHtml('data-test="play-online-count"')
        ->assertDontSee('Offline Olga');

    // The switch saves the wanted state and answers with the stored one; off declines the open invites.
    $page = Livewire::actingAs($me)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG]);
    expect($page->instance()->setLookingToPlay(true))->toBeTrue()
        ->and($me->refresh()->looking_to_play)->toBe(NineMensMorris::SLUG.'/correspondence');

    $invite = app(BoardInvites::class)->invite($away, $me, NineMensMorris::SLUG);
    expect($page->instance()->setLookingToPlay(false))->toBeFalse()
        ->and($me->refresh()->looking_to_play)->toBeNull()
        ->and($invite->refresh()->status->value)->not->toBe('pending');
});

test('the invited row is marked for the inviter until the invite expires', function () {
    [$me, $bert] = User::factory()->count(2)->create();
    $bert->forceFill(['looking_to_play' => NineMensMorris::SLUG.'/correspondence'])->save();

    $page = Livewire::actingAs($me)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->call('invite', $bert->id)
        ->assertSeeHtml('data-test="lobby-invited"');

    expect($page->get('invitedUserId'))->toBe($bert->id)
        ->and($page->get('invitedUntilMs'))->toBeGreaterThan((int) now()->getTimestampMs());
});

test('the board lobby asks the same number of queries for 1, 3, 5 and 25 live games, ladder rows and correspondence games', function () {
    $me = User::factory()->create();
    $counts = [];

    foreach ([1, 3, 5, 25] as $n) {
        BoardGame::query()->delete();
        Rating::query()->delete();
        $service = app(BoardGameService::class);

        for ($i = 0; $i < $n; $i++) {
            [$white, $black] = User::factory()->count(2)->create();
            // A live game left from before the board games' blitz was dropped (2026-10-07): the list still shows it.
            $service->start(NineMensMorris::SLUG, $white, $black)->forceFill(['mode' => 'blitz', 'deadline_ms' => null])->save();
            Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => NineMensMorris::SLUG, 'mode' => 'correspondence', 'subject' => 'user:'.$white->id, 'user_id' => $white->id, 'rating' => 1000 + $i, 'results' => 1]);
            $service->start(NineMensMorris::SLUG, $me, User::factory()->create(), BoardGame::CORRESPONDENCE);
        }

        $this->actingAs($me)->get(route('board.lobby', NineMensMorris::SLUG))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->actingAs($me)->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()->getContent();
        DB::disableQueryLog();
        $counts[$n] = count(DB::getQueryLog());

        expect(substr_count($html, 'data-test="live-game"'))->toBe(min($n, 3))
            ->and(substr_count($html, 'data-test="lobby-correspondence-game"'))->toBe(min($n, 5));
    }

    expect(array_unique($counts))->toHaveCount(1, json_encode($counts));
});

test('every game page heads its casual cups as tournaments, with the explainer and the way to all cups, and never a fee', function (string $page) {
    lobbyCups();
    $url = match ($page) {
        'chess lobby' => route('chess.lobby'),
        'board lobby' => route('board.lobby', NineMensMorris::SLUG),
        'series game page' => route('games.series', 'rocket-league'),
    };

    $html = $this->get($url)->assertOk()
        ->assertSeeHtml('data-test="cup-head"')
        ->assertSeeHtml('aria-labelledby="cup-game-h"')
        ->assertSeeInOrder(['Casual cups', 'Tournaments', 'The league opens a cup for every game and region on its own. Its games are casual and move only your casual Elo.', 'Next cup starts in'])
        ->assertSeeHtml('href="'.route('tournaments.index').'#casual-cups" class="inline-flex min-h-11 items-center text-btc-hi hover:text-btc" data-test="cup-all"')
        ->assertSeeHtml('data-test="cup-mention"')
        ->getContent();

    // The head sits above the rows, and no line of it talks about money.
    expect(strpos($html, 'data-test="cup-head"'))->toBeLessThan(strpos($html, 'data-test="cup-mention"'))
        ->and(strtolower(strip_tags(substr($html, strpos($html, 'data-test="cup-head"'), 2000))))->not->toContain('fee');
})->with(['chess lobby', 'series game page']);

test('with the cups switched off or no league key, the game pages promise no cup, though cups are still open', function (string $page, string $switch) {
    lobbyCups();
    config($switch === 'cups off' ? ['esports.casual_cups.enabled' => []] : ['esports.league.nsec' => null]);
    $url = match ($page) {
        'chess lobby' => route('chess.lobby'),
        'board lobby' => route('board.lobby', NineMensMorris::SLUG),
        'series game page' => route('games.series', 'rocket-league'),
    };

    $this->get($url)->assertOk()
        ->assertDontSeeHtml('data-test="cup-head"')
        ->assertDontSeeHtml('data-test="cup-explainer"')
        ->assertDontSee('Next cup starts in');
})->with(['chess lobby', 'board lobby', 'series game page'])->with(['cups off', 'no league key']);

test('the German game pages say it in German', function () {
    lobbyCups();
    $this->actingAs(User::factory()->create());

    $html = $this->withSession(['locale' => 'de'])->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()->getContent();

    // No casual cup for a board game since 2026-10-07: its lobby names correspondence, never blitz.
    expect($html)->toContain('Turniere')->toContain('sucht: Mühle')->toContain('Fernpartie')
        ->not->toContain('Nächster Cup startet in')->not->toContain('Blitz spielen')->not->toContain('Blitz 5+3 ·');
});

test('with the board games switched off the lobby is a 404, and the chess lobby keeps its cup head', function () {
    lobbyCups();
    config(['esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $this->get('/games/'.NineMensMorris::SLUG)->assertNotFound();
    $this->get(route('chess.lobby'))->assertOk()->assertSeeHtml('data-test="cup-head"');
});

<?php

/*
 * The chess lobby's overview (lobby v2, 2026-09-27): every way to play as a
 * tile, the panels they open, and the player's own business and the live
 * lobby under them, for a player and as a guest. The tiles count real
 * things: players searching, daily games waiting for this player's move,
 * running boards, the next tournament and the ladder's top five.
 *
 * And the blitz invite rule of the same day: only a player whose "Looking
 * to play" is on can be invited, and turning it off declines what is open.
 */

use App\Enums\ChessInviteStatus;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

/** The `data-test` hooks of every function the lobby offers, in page order. */
dataset('functions', [
    'rapid tile' => ['play-rapid'],
    'blitz tile' => ['play-blitz'],
    'either' => ['either'],
    'casual/rated' => ['game-kind'],
    'casual' => ['kind-casual'],
    'rated' => ['kind-rated'],
    'the "?" help' => ['kind-help'],
    'why rated is closed' => ['kind-why'],
    'strength range' => ['blitz-range'],
    'daily chess tile' => ['play-daily'],
    'challenge tile' => ['play-challenge'],
    'invite tile' => ['play-invite'],
    'invite module' => ['invite-module'],
    'tournaments tile' => ['play-tournaments'],
    'team match tile' => ['play-team'],
    'your games' => ['lobby-daily'],
    'live boards' => ['now-playing'],
    'all live games' => ['all-live-games'],
    'online now' => ['online-now'],
    'ladder' => ['lobby-ladder'],
    'ladder link' => ['lobby-ladder-link'],
    'ladder switch' => ['lobby-ladder-modes'],
]);

test('a player finds every function of the lobby', function (string $hook) {
    $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()
        ->assertSeeHtml('data-test="'.$hook.'"');
})->with('functions');

test('a guest finds every function of the lobby, in its logged-out state', function (string $hook) {
    $this->get(route('chess.lobby'))->assertOk()->assertSeeHtml('data-test="'.$hook.'"');
})->with('functions');

test('the player-only controls are the player\'s; a guest gets the way to log in instead', function () {
    $player = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk();
    $player->assertSeeHtml('data-test="find-opponent-button"')
        ->assertSeeHtml('data-test="looking-toggle"')
        ->assertSeeHtml('data-state="daily"')
        ->assertSeeHtml('aria-controls="online-now"')
        ->assertDontSeeHtml('data-test="find-opponent-login"');

    auth()->logout();
    $guest = $this->get(route('chess.lobby'))->assertOk();
    $guest->assertSeeHtml('data-test="find-opponent-login"')
        ->assertSeeHtml('data-state="guest"')
        ->assertDontSeeHtml('data-test="find-opponent-button"')
        ->assertDontSeeHtml('data-test="looking-toggle"')
        ->assertSee(__('Log in to see your games.'));

    // The guest's challenge tile leads to the login, not to a list it cannot see.
    expect(tileOf($guest->getContent(), 'play-challenge'))->toContain('href="'.route('login').'"');
});

test('tiles are actions: rapid, blitz and the invite open their panel in place, daily and tournaments lead into their flow, team match is not a control', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()->getContent();

    // Rapid 10+5 first and the one orange tile (user, 2026-10-05), blitz second; both open the quick-play panel in their mode.
    expect(strpos($html, 'data-test="play-rapid"'))->toBeLessThan(strpos($html, 'data-test="play-blitz"'))
        ->and(tileOf($html, 'play-rapid'))->toStartWith('<button')->toContain('aria-controls="lobby-quick"')->toContain('bg-btc-chip')->toContain('10+5')
        ->and(tileOf($html, 'play-blitz'))->toStartWith('<button')->toContain('aria-controls="lobby-quick"')->toContain('aria-expanded="false"')->not->toContain('bg-btc-chip')
        ->and(tileOf($html, 'play-invite'))->toStartWith('<button')->toContain('aria-controls="lobby-invite"')
        ->and(tileOf($html, 'play-daily'))->toStartWith('<a')->toContain('href="'.route('chess.challenge').'"')
        ->and(tileOf($html, 'play-tournaments'))->toStartWith('<a')->toContain('href="'.route('tournaments.index').'"')
        ->and(tileOf($html, 'play-team'))->toStartWith('<div')->toContain('aria-disabled="true"')->toContain(__('Soon'))
        // The panels exist once, closed until their tile opens them.
        ->and(substr_count($html, 'id="lobby-quick"'))->toBe(1)
        ->and(substr_count($html, 'id="lobby-invite"'))->toBe(1)
        // The explanations are behind "?", not in the first view.
        ->and($html)->toMatch('/id="blitz-help" x-show="help" x-cloak/');
});

test('Rated is disabled before Block 0, with its badge; the reason is behind "?"', function () {
    expect(Ladders::isOpen('chess', 'blitz'))->toBeFalse();

    $html = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()->getContent();

    expect(openingTag($html, 'kind-rated'))->toMatch('/\sdisabled(\s|>|=)/')
        ->and($html)->toContain('data-rated-open="false"')
        ->and(preg_replace('/\s+/', ' ', strip_tags(tileOf($html, 'kind-rated-badge'))))->toContain(__('from Block 0'))
        ->and(openingTag($html, 'kind-casual'))->not->toMatch('/\sdisabled(\s|>|=)/');
});

test('the tiles count real things: players searching, daily games waiting for your move, running boards', function () {
    $player = User::factory()->create();
    [$a, $b, $c] = User::factory()->count(3)->create();

    // Two searching far apart, so they do not pair with each other.
    foreach ([[$a, 1000], [$b, 2400]] as [$user, $rating]) {
        ChessQueueEntry::query()->create(['user_id' => $user->id, 'mode' => 'blitz', 'rated' => false, 'rating' => $rating, 'joined_at' => now()]);
    }
    // Two daily games wait for the player's move (White to move), one for the opponent's.
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'black_id' => $a->id]);
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'black_id' => $b->id]);
    ChessGame::factory()->daily()->create(['white_id' => $c->id, 'black_id' => $player->id]);
    ChessGame::factory()->count(4)->create();

    $html = $this->actingAs($player)->get(route('chess.lobby'))->assertOk()->getContent();

    expect(textOf($html, 'play-blitz-searching'))->toBe('2')
        ->and(textOf($html, 'play-daily-count'))->toStartWith('2')
        // All four running boards are counted, three are shown.
        ->and(textOf($html, 'live-count'))->toBe('4')
        ->and(substr_count($html, 'data-test="live-game"'))->toBe(3)
        // Your move first.
        ->and(preg_match_all('/data-test="lobby-daily-game" data-mine="(true|false)"/', $html, $mine))->toBe(3)
        ->and($mine[1])->toBe(['true', 'true', 'false']);
});

test('without anything to count the tiles say so honestly', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()->getContent();

    expect(textOf($html, 'play-blitz-searching'))->toBe('0')
        ->and($html)->not->toContain('data-test="play-daily-count"')
        ->and(textOf($html, 'live-count'))->toBe('0')
        ->and($html)->toContain(__('No live game right now.'))
        ->and($html)->toContain('data-test="lobby-daily-empty"')
        ->and($html)->toContain('data-test="lobby-ladder-empty"')
        ->and(tileOf($html, 'play-tournaments'))->toContain(__('None open'));
});

test('the tournaments tile names the next chess tournament open for sign-up and leads to it', function () {
    Tournament::factory()->rocketLeague()->signup()->create(['signup_closes_at' => now()->addDay()]);
    $later = Tournament::factory()->signup()->create(['name' => 'Later Blitz', 'signup_closes_at' => now()->addDays(5)]);
    $next = Tournament::factory()->signup()->create(['name' => 'Friday Blitz Kempten', 'signup_closes_at' => now()->addDays(2)]);

    $tile = tileOf($this->get(route('chess.lobby'))->assertOk()->getContent(), 'play-tournaments');

    expect($tile)->toContain('href="'.route('tournaments.show', $next).'"')
        ->toContain('Friday Blitz Kempten')
        ->not->toContain($later->name)
        ->not->toContain('RL Sunday');
});

test('the ladder shows its top five from the view the ladder opens on, rapid first', function () {
    $players = User::factory()->count(6)->create();
    foreach ($players as $index => $player) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$player->id,
            'user_id' => $player->id, 'rating' => 1000 + $index * 10, 'results' => 3, 'wins' => 2, 'draws' => 0, 'losses' => 1]);
    }

    $html = $this->get(route('chess.lobby'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="lobby-ladder-row"'))->toBe(5)
        ->and($html)->toContain('data-pool="casual"')
        ->and($html)->toContain($players[5]->displayName())
        ->and($html)->not->toContain('>'.e($players[0]->displayName()).'<')
        ->and(strpos($html, e($players[5]->displayName())))->toBeLessThan(strpos($html, e($players[1]->displayName())))
        ->and(tileOf($html, 'lobby-ladder-link'))->toContain('href="'.route('ladder.show', ['chess', 'rapid']).'"');

    // Blitz and daily by the switch; the rapid rows are not theirs.
    Livewire::test('pages::chess.lobby')->assertOk()
        ->call('showLadder', 'blitz')->assertSet('ladderMode', 'blitz')
        ->assertSeeHtml('data-test="lobby-ladder-empty"')->assertSeeHtml('href="'.route('ladder.show', ['chess', 'blitz']).'"')
        ->call('showLadder', 'correspondence')->assertSet('ladderMode', 'correspondence')
        ->call('showLadder', 'bullet')->assertSet('ladderMode', 'correspondence')
        ->call('$refresh')->assertOk();
});

test('/chess#blitz and #rapid open the quick-play panel in that mode, and a search shows its card whatever the tile', function () {
    $player = User::factory()->create();
    $html = $this->actingAs($player)->get(route('chess.lobby'))->assertOk()->getContent();
    expect($html)->toContain(".includes(location.hash.slice(1))) { liveMode = location.hash.slice(1); stage = 'quick' }");

    app(ChessQueue::class)->join($player);
    $html = $this->actingAs($player)->get(route('chess.lobby'))->assertOk()->getContent();

    expect($html)->toContain('data-test="searching"')
        ->and(tileOf($html, 'play-blitz'))->toContain('aria-expanded="true"')
        ->and($html)->toContain('data-test="play-blitz-state"');
});

test('a blitz invite reaches only a player who is looking to play; a tampered call is refused with the reason', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    expect(fn () => app(ChessInvites::class)->invite($anna, $bert))
        ->toThrow(fn (ChessRuleViolation $violation) => expect($violation->reason)->toBe('not_looking'));

    // The lobby's button is hidden for Bert; a hand-made call to invite() still gets nowhere.
    Livewire::actingAs($anna)->test('pages::chess.lobby')
        ->call('invite', $bert->id)
        ->assertSet('error', __(':name is not looking for a game right now.', ['name' => $bert->displayName()]))
        ->assertNoRedirect();

    expect(ChessInvite::query()->count())->toBe(0);

    // Looking on: the invite goes out.
    $bert->forceFill(['looking_to_play' => 'chess/blitz'])->save();
    Livewire::actingAs($anna)->test('pages::chess.lobby')->call('invite', $bert->id)->assertSet('error', '');

    expect(ChessInvite::query()->sole()->only(['inviter_id', 'invitee_id', 'status']))
        ->toBe(['inviter_id' => $anna->id, 'invitee_id' => $bert->id, 'status' => ChessInviteStatus::Pending]);
});

test('a refused invite leaves the inviter\'s earlier invite and search untouched', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $carl = User::factory()->lookingToPlay()->create();
    $open = app(ChessInvites::class)->invite($anna, $carl);

    expect(fn () => app(ChessInvites::class)->invite($anna, $bert))->toThrow(ChessRuleViolation::class);

    expect($open->refresh()->status)->toBe(ChessInviteStatus::Pending);
});

test('turning "Looking to play" off declines the blitz invites still open to that player', function () {
    $bert = User::factory()->lookingToPlay()->create();
    [$anna, $carl] = User::factory()->count(2)->create();
    $first = app(ChessInvites::class)->invite($anna, $bert);
    $second = app(ChessInvites::class)->invite($carl, $bert);

    Livewire::actingAs($bert)->test('pages::chess.lobby')->call('setLookingToPlay', false);

    expect($bert->refresh()->looking_to_play)->toBeNull()
        ->and($first->refresh()->status)->toBe(ChessInviteStatus::Declined)
        ->and($second->refresh()->status)->toBe(ChessInviteStatus::Declined)
        ->and(app(ChessInvites::class)->incoming($bert))->toHaveCount(0);
});

test('the online list offers Invite only on the rows of players who are looking for blitz', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('chess.lobby'))->assertOk()->getContent();

    expect($html)->toContain('<template x-if="! invited(m) && m.looking === \'chess/blitz\'">');
});

/** The element carrying data-test="$hook", up to its closing tag (tiles nest no element of their own kind). */
function tileOf(string $html, string $hook): string
{
    $at = strpos($html, 'data-test="'.$hook.'"');
    expect($at)->not->toBeFalse("no element with data-test=\"{$hook}\"");
    $start = strrpos(substr($html, 0, $at), '<');
    preg_match('/^<(\w+)/', substr($html, $start), $tag);
    $end = strpos($html, '</'.$tag[1].'>', $at);

    return substr($html, $start, $end - $start + strlen($tag[1]) + 3);
}

/** Only the opening tag of the element carrying data-test="$hook" (quote-aware). */
function openingTag(string $html, string $hook): string
{
    preg_match('/^<\w+(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', tileOf($html, $hook), $match);

    return $match[0];
}

/** The squashed text of the element carrying data-test="$hook". */
function textOf(string $html, string $hook): string
{
    preg_match('/data-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\//s', $html, $match);

    return trim(preg_replace('/\s+/', ' ', strip_tags($match[1] ?? '')));
}

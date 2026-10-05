<?php

use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardChallenge;
use App\Models\Clan;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

/*
 * "Your follows here" in a board game's lobby (plan
 * brettspiel-chat-und-follows, P2; the user, 2026-09-30: "Follows mit
 * Herausfordern-Link"): the chess lobby's section in chess's slot. A follow's
 * challenge opens the board game's correspondence page with them picked,
 * sent only by the player's own click there; a follow online and looking for
 * this board game's blitz gets the lobby's blitz invite, from the presence
 * "Online now" reads. The correspondence page says why a `?to=` cannot be
 * challenged, and brings a guest back after the login.
 */

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
});

function boardFollows(User $me, string $slug = NineMensMorris::SLUG): Testable
{
    return Livewire::actingAs($me)->test('follows-here', ['context' => 'board', 'subject' => $slug]);
}

test('a board lobby\'s follow is challenged by correspondence with them picked, and invited to blitz while they look for it', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create(['name' => 'Mill Mia']);

    $html = boardFollows($me)->call('match', [$friend->pubkey])
        ->assertSee('Mill Mia')
        ->assertSeeHtml('href="'.e(route('board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => $friend->npub])).'"')
        ->assertSeeHtml('aria-label="Challenge Mill Mia to Nine Men&#039;s Morris by correspondence"')
        // The blitz invite and the presence line are the page's, from the lobby's presence, for this board game's key.
        ->assertSeeHtml('data-test="follows-here-invite"')
        ->assertSeeHtml('x-on:click="inviteBlitz('.$friend->id.')"')
        ->assertSeeHtml('data-test="follows-here-presence"')
        ->assertSee("looking: Nine Men's Morris")
        // Not chess's: no daily chess challenge, no 1v1, no invite DM (a board game has no invite link) but the way to the own page.
        ->assertDontSee(route('chess.challenge'), false)
        ->assertDontSeeHtml('data-test="follows-here-1v1"')
        ->assertDontSeeHtml('data-test="follows-invite"')
        ->assertSeeHtml('data-test="follows-invite-elsewhere"')
        ->html();

    expect($html)->toContain('\u0022lookingKey\u0022:\u0022nine-mens-morris');

    // The link lands on the form with the follow picked, and nothing is sent by opening it.
    $page = $this->actingAs($me)->get(route('board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => $friend->npub]))->assertOk();
    $page->assertSee('Challenge Mill Mia')->assertDontSeeHtml('data-test="challenge-to-problem"');
    expect(BoardChallenge::query()->count())->toBe(0);

    // The chess lobby's rows read the same presence for chess's key; the own page's rows say nothing about presence.
    expect(Livewire::actingAs($me)->test('follows-here', ['context' => 'chess'])->call('match', [$friend->pubkey])->html())
        ->toContain('\u0022lookingKey\u0022:\u0022chess')->toContain('looking: live chess')->toContain('data-test="follows-here-invite"');
    expect(Livewire::actingAs($me)->test('follows-here', ['context' => 'me'])->call('match', [$friend->pubkey])->html())
        ->toContain('\u0022lookingKey\u0022:null')->not->toContain('data-test="follows-here-presence"')->not->toContain('data-test="follows-here-invite"');
});

test('the board lobby shows the section to a player and not to a guest, in chess\'s slot', function () {
    $this->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()->assertDontSeeHtml('data-test="follows-here"');

    $html = $this->actingAs(User::factory()->create())->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()
        ->assertSeeHtml('data-context="board"')
        ->getContent();

    expect(strpos($html, 'data-test="lobby-ladder"'))->toBeLessThan(strpos($html, 'data-test="follows-here"'))
        ->and(strpos($html, 'data-test="follows-here"'))->toBeLessThan(strpos($html, 'data-test="weekly-events"') ?: PHP_INT_MAX);
});

test('with a board game switched off its follows are a 404 like its lobby, and chess\'s stay', function (string $switch) {
    $me = User::factory()->create();
    config($switch === 'all board games off' ? ['esports.board_games.enabled' => false] : ['esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    boardFollows($me)->assertStatus(404);
    // A slug that is no board game at all, chess included, is none either.
    Livewire::actingAs($me)->test('follows-here', ['context' => 'board', 'subject' => 'chess'])->assertStatus(404);

    $this->actingAs($me)->get('/games/'.NineMensMorris::SLUG)->assertNotFound();
    $this->actingAs($me)->get(route('chess.lobby'))->assertOk()->assertSeeHtml('data-context="chess"');
    Livewire::actingAs($me)->test('follows-here', ['context' => 'chess'])->assertOk();

    if ($switch === 'nine men\'s morris off') {
        // The other board game keeps its section.
        boardFollows($me, Checkers::SLUG)->assertOk();
    }
})->with(['all board games off', 'nine men\'s morris off']);

test('matching and listing a board lobby\'s follows asks the same number of queries for 1, 5 and 25 follows', function () {
    $me = User::factory()->create();
    $counts = [];

    foreach ([1, 5, 25] as $n) {
        // Every other one in a clan, the first included: the row names it.
        $follows = collect(range(1, $n))->map(fn (int $i): User => $i % 2 === 1 ? Clan::factory()->create()->owner : User::factory()->create());
        $page = boardFollows($me);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('match', $follows->pluck('pubkey')->all());
        DB::disableQueryLog();
        $counts[$n] = count(DB::getQueryLog());

        expect(substr_count($page->html(), 'data-test="follows-here-player"'))->toBe(min($n, 12))
            ->and(substr_count($page->html(), 'data-test="follows-here-challenge"'))->toBe(min($n, 12));
    }

    expect(array_unique($counts))->toHaveCount(1, json_encode($counts));
});

test('the correspondence page picks an npub from the link and says why one cannot be challenged, never sending on its own', function (string $case) {
    $me = User::factory()->create();
    $friend = User::factory()->create(['name' => 'Mill Mia']);
    $stranger = NostrKeys::hexToNpub(hash('sha256', 'nobody here'));

    if ($case === 'open challenge') {
        app(BoardChallenges::class)->challenge($friend, $me, NineMensMorris::SLUG);
    }

    [$to, $problem] = match ($case) {
        'npub' => [$friend->npub, null],
        'user id' => [(string) $friend->id, null],
        'no npub' => ['npub1notakey', 'That is not an npub. Pick your opponent from the list.'],
        'own npub' => [$me->npub, 'You cannot challenge yourself.'],
        'unknown npub' => [$stranger, 'Nobody in the league has that key. Pick your opponent from the list.'],
        'unknown user id' => ['999999', 'No player found.'],
        'open challenge' => [$friend->npub, 'There is already an open challenge between the two of you.'],
    };
    $before = BoardChallenge::query()->count();

    $page = Livewire::actingAs($me)->withQueryParams(['to' => $to])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG])->assertOk();

    if ($problem === null) {
        $page->assertDontSeeHtml('data-test="challenge-to-problem"')
            ->assertSee('Challenge Mill Mia')
            ->assertSeeHtmlInOrder(['data-test="pick-player"', 'aria-checked="true"', 'Mill Mia']);
        expect(BoardChallenge::query()->count())->toBe($before);

        // Sent only on the player's own click.
        $page->call('send')->assertSeeHtml('data-test="correspondence-status"');
        expect(BoardChallenge::query()->count())->toBe($before + 1);

        return;
    }

    $page->assertSeeHtml('data-test="challenge-to-problem"')->assertSee($problem)
        // The button stays off; with an open challenge it still names whom (the challenge to answer sits at the top).
        ->assertSeeHtml('wire:click="send" disabled')
        ->call('send')
        ->assertSeeHtml('data-test="correspondence-error"');
    expect(BoardChallenge::query()->count())->toBe($before);

    // Picking someone from the list clears it.
    $other = User::factory()->create(['name' => 'Other Otto']);
    $page->call('pick', $other->id)->assertDontSeeHtml('data-test="challenge-to-problem"')->assertSee('Challenge Other Otto');
})->with(['npub', 'user id', 'no npub', 'own npub', 'unknown npub', 'unknown user id', 'open challenge']);

test('a guest who opens a follow\'s challenge link comes back to it when logging in from there, and only then', function () {
    $friend = User::factory()->create(['name' => 'Mill Mia']);
    $url = route('board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => $friend->npub]);

    // A visit alone leaves the login's landing alone (review of P2: a stale intent sent a later login elsewhere here).
    $this->get($url)->assertOk()
        ->assertSeeHtml('data-test="correspondence-login"')
        ->assertSeeHtml('wire:click="logIn"')
        ->assertDontSeeHtml('data-test="challenge-to-problem"')
        ->assertSessionMissing('url.intended');

    // "Log in to play" on the page: the login comes back to the form with the follow picked.
    Livewire::withQueryParams(['to' => $friend->npub])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG])
        ->call('logIn')
        ->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe($url);

    // Without a picked opponent it comes back to the page itself.
    session()->forget('url.intended');
    Livewire::withQueryParams([])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG])->call('logIn')->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('board.correspondence', NineMensMorris::SLUG));
});

/**
 * The names of the pick list, in page order.
 *
 * @return list<string>
 */
function boardPickOrder(string $html): array
{
    preg_match_all('/data-test="pick-player"[^>]*>(?:\s|<[^>]*>)*?<span class="truncate">([^<]*)</', $html, $found);

    return $found[1];
}

test('a tap in the pick list keeps its order, and a linked player heads it from the first load on', function (bool $linked) {
    $me = User::factory()->create();
    $players = collect(range(1, 9))->map(fn (int $i): User => User::factory()->create(['name' => 'Player '.$i, 'updated_at' => now()->subMinutes(20 - $i)]));
    // The linked player is the oldest: without the link they would not be in the list at all.
    $link = $players->first();

    $page = Livewire::actingAs($me)->withQueryParams($linked ? ['to' => $link->npub] : [])->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG]);
    $before = boardPickOrder($page->html());

    expect($before)->toHaveCount(8)
        ->and($before[0])->toBe($linked ? 'Player 1' : 'Player 9');

    // Tapping the fifth row picks it where it is: nothing moves under the finger.
    $fifth = User::query()->where('name', $before[4])->sole();
    $page->call('pick', $fifth->id)->assertSeeHtml('aria-checked="true"');
    expect(boardPickOrder($page->html()))->toBe($before);

    // And back to the linked one: still the same order.
    $page->call('pick', $link->id);
    expect(boardPickOrder($page->html()))->toBe($linked ? $before : boardPickOrder($page->html()));
})->with(['from a link' => [true], 'without a link' => [false]]);

test('the correspondence page offers blitz as chess\'s challenge page does: the lobby\'s "Online now"', function () {
    $this->actingAs(User::factory()->create())->get(route('board.correspondence', NineMensMorris::SLUG))->assertOk()
        ->assertSeeHtml('href="'.route('board.lobby', NineMensMorris::SLUG).'#online-now"')
        ->assertSee('Blitz 5+3 now: invite a player who is looking to play');
});

test('the German board lobby follows and correspondence form say it in German', function () {
    $me = User::factory()->create(['locale' => 'de']);
    $friend = User::factory()->create(['name' => 'Mill Mia']);
    app()->setLocale('de');

    boardFollows($me)->call('match', [$friend->pubkey])
        ->assertSee('Mill Mia zu einer Fernpartie Mühle herausfordern')
        ->assertSee('sucht: Mühle')
        ->assertSee('Herausfordern');

    $this->actingAs($me)->withSession(['locale' => 'de'])->get(route('board.correspondence', ['board' => NineMensMorris::SLUG, 'to' => 'npub1notakey']))->assertOk()
        ->assertSee('Das ist keine npub. Wähle deinen Gegner aus der Liste.')
        ->assertSee('Jetzt Blitz 5+3: lade jemanden ein, der eine Partie sucht');
});

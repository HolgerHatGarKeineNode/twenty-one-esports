<?php

use App\Enums\InviteLinkType;
use App\Models\InviteLink;
use App\Models\User;
use App\Support\Invites\InviteLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * "Your follows here" (P47): the browser reads the player's kind 3 and hands
 * the pubkeys to the league, which matches them in one query and shows who
 * plays here with the page's challenge action; the invite DM's link is the
 * player's personal link, made on the preview click.
 */

test('matching many follows is one query, keeps the follow order, and drops the player and junk', function () {
    $me = User::factory()->create();
    $players = User::factory()->count(30)->create();
    $strangers = array_map(fn (int $i): string => hash('sha256', 'stranger-'.$i), range(1, 1500));
    $follows = [...$strangers, $players[7]->pubkey, $me->pubkey, 'junk', 42, $players[2]->pubkey, strtoupper($players[3]->pubkey), $players[7]->pubkey];

    $page = Livewire::actingAs($me)->test('follows-here', ['context' => 'me']);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, '"users"')) {
            $queries++;
        }
    });
    $answer = $page->instance()->match($follows);
    $counted = $queries;

    expect($counted)->toBe(1)
        ->and($answer['here'])->toBe([$players[7]->pubkey, $players[2]->pubkey])
        ->and($page->instance()->hereIds)->toBe([$players[7]->id, $players[2]->id]);

    // The list is one more query (plus the eager clan), and renders in follow order.
    $page->call('match', $follows)
        ->assertSeeInOrder([$players[7]->displayName(), $players[2]->displayName()])
        ->assertSee('data-test="follows-here-challenge"', false)
        ->assertSee('data-test="follows-here-1v1"', false);

    // At most MAX_FOLLOWS keys are matched.
    $late = User::factory()->create();
    $beyond = [...array_map(fn (int $i): string => hash('sha256', 'filler-'.$i), range(1, 5000)), $late->pubkey];
    expect($page->instance()->match($beyond)['here'])->toBe([]);
});

test('the page decides the challenge: chess daily on the lobby, a 1v1 on a game with casual 1v1s, none on a tournament', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create(['name' => 'friendly']);

    Livewire::actingAs($me)->test('follows-here', ['context' => 'chess'])->call('match', [$friend->pubkey])
        ->assertSee('data-test="follows-here-challenge"', false)->assertDontSee('data-test="follows-here-1v1"', false)
        ->assertSee(route('chess.challenge', ['to' => $friend->npub]), false);

    $casualGame = (array) config('esports.casual.games');
    Livewire::actingAs($me)->test('follows-here', ['context' => 'series', 'subject' => $casualGame[0]])->call('match', [$friend->pubkey])
        ->assertDontSee('data-test="follows-here-challenge"', false)->assertSee('data-test="follows-here-1v1"', false)
        ->assertDontSee('data-test="follows-invite"', false)->assertSee('data-test="follows-invite-elsewhere"', false);

    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament();
    Livewire::actingAs($me)->test('follows-here', ['context' => 'tournament', 'subject' => (string) $tournament->id])->call('match', [$friend->pubkey])
        ->assertSee('friendly')->assertDontSee('data-test="follows-here-challenge"', false)->assertDontSee('data-test="follows-here-1v1"', false)
        ->assertSee('data-test="follows-invite"', false);

    // A guest gets nothing.
    auth()->logout();
    Livewire::test('follows-here', ['context' => 'me'])->assertDontSee('data-test="follows-here"', false);
});

test('the invite text carries the personal link, made on the preview click and reused', function () {
    $me = User::factory()->create();
    $page = Livewire::actingAs($me)->test('follows-here', ['context' => 'me']);

    expect(InviteLink::query()->count())->toBe(0);

    $first = $page->instance()->inviteText(app(InviteLinks::class));
    $again = $page->instance()->inviteText(app(InviteLinks::class));
    $link = InviteLink::query()->sole();

    expect($link->type)->toBe(InviteLinkType::Daily)
        ->and($link->max_uses)->toBeNull()
        ->and($link->inviter_id)->toBe($me->id)
        ->and($first['link'])->toBe($link->url())
        ->and($first['text'])->toContain($link->url())
        ->and($again['link'])->toBe($first['link']);

    // On a tournament open for sign-up: the personal tournament link.
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament(['name' => 'Testnet Cup']);
    $answer = Livewire::actingAs($me)->test('follows-here', ['context' => 'tournament', 'subject' => (string) $tournament->id])
        ->instance()->inviteText(app(InviteLinks::class));
    $personal = InviteLink::query()->where(['inviter_id' => $me->id, 'tournament_id' => $tournament->id])->sole();

    expect($answer['link'])->toBe($personal->url())
        ->and($answer['text'])->toContain('Testnet Cup')->toContain($personal->url());

    // No invite where there is no personal link: a game page.
    Livewire::actingAs($me)->test('follows-here', ['context' => 'series', 'subject' => 'rocket-league'])->call('inviteText')->assertStatus(404);
});

test('the section is on the own page, the chess lobby, a game page and an open tournament', function () {
    $me = User::factory()->create();
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament();

    foreach ([route('dashboard'), route('chess.lobby'), route('games.rocket-league'), route('tournaments.show', $tournament)] as $url) {
        $this->actingAs($me)->get($url)->assertOk()->assertSee('data-test="follows-here"', false);
    }
});

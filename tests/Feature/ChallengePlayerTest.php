<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| "Challenge a player", for every game
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-13: "Challenge :name" on a profile led to daily chess
| only, "statt das Spiel auswählen zu können". /challenge lists every game a
| player can be challenged to directly (daily chess, each board game with a
| correspondence mode, a scheduled casual 1v1), each to the page that sends
| that challenge with the opponent filled in. Nothing is sent here.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
});

test('a profile challenges to every game: daily chess, each board game by correspondence and a casual 1v1, the player filled in', function () {
    BlockliOn::play();
    BlockspaceOn::play();
    NineMensMorrisOn::play();
    $me = User::factory()->create();
    $rival = User::factory()->create(['name' => 'Rivalin']);

    $profile = $this->actingAs($me)->get(route('players.show', $rival->npub))->assertOk()->getContent();
    expect($profile)->toContain('href="'.e(route('challenge', ['to' => $rival->npub])).'"')
        ->not->toContain('href="'.e(route('chess.challenge', ['to' => $rival->npub])).'"');

    $page = $this->actingAs($me)->get(route('challenge', ['to' => $rival->npub]))->assertOk();

    $page->assertSee('Challenge Rivalin')
        ->assertSee('data-test="challenge-option-chess"', false)
        ->assertSee('data-test="challenge-option-blockli"', false)
        ->assertSee('data-test="challenge-option-blockspace"', false)
        ->assertSee('data-test="challenge-option-nine-mens-morris"', false)
        ->assertSee('data-test="challenge-option-casual"', false)
        ->assertSee(e(route('chess.challenge', ['to' => $rival->npub])), false)
        ->assertSee(e(route('board.correspondence', ['board' => 'blockli', 'to' => $rival->npub])), false)
        ->assertSee(e(route('board.correspondence', ['board' => 'nine-mens-morris', 'to' => $rival->npub])), false)
        ->assertSee(e(route('challenges.casual', ['to' => $rival->id])), false);

    // A user id names the player as well; the board game's page fills the form from the npub it is handed.
    $this->actingAs($me)->get(route('challenge', ['to' => $rival->id]))->assertOk()->assertSee('Challenge Rivalin');
    Livewire::actingAs($me)->withQueryParams(['to' => $rival->npub])->test('pages::board.correspondence', ['board' => 'blockli'])
        ->assertSee('Rivalin');
});

test('without a player, or with the own or an unknown one, it is the plain list with nothing filled in', function () {
    BlockliOn::play();
    $me = User::factory()->create();

    foreach (['', $me->npub, 'npub1nothing', '999999'] as $to) {
        $this->actingAs($me)->get(route('challenge', array_filter(['to' => $to])))->assertOk()
            ->assertSee('Challenge a player')
            ->assertSee('href="'.e(route('chess.challenge')).'"', false)
            ->assertSee(e(route('board.correspondence', ['board' => 'blockli'])).'"', false)
            ->assertDontSee('?to=', false);
    }
});

test('with daily chess the only game on offer it goes straight there', function () {
    config(['esports.casual.games' => []]);
    $me = User::factory()->create();
    $rival = User::factory()->create();

    $this->actingAs($me)->get(route('challenge', ['to' => $rival->npub]))
        ->assertRedirect(route('chess.challenge', ['to' => $rival->npub]));
});

test('the game hub, "Your correspondence games" and "Your follows here" on the hub lead to the choice; the chess lobby keeps daily chess', function () {
    BlockliOn::play();
    $me = User::factory()->create();
    $friend = User::factory()->create();

    $this->actingAs($me)->get(route('play'))->assertOk()->assertSee('href="'.route('challenge').'"', false);
    $this->actingAs($me)->get(route('me.correspondence'))->assertOk()->assertSee('href="'.route('challenge').'"', false);

    Livewire::actingAs($me)->test('follows-here', ['context' => 'me'])->call('match', [$friend->pubkey])
        ->assertSee(route('challenge', ['to' => $friend->npub]), false);
    Livewire::actingAs($me)->test('follows-here', ['context' => 'chess'])->call('match', [$friend->pubkey])
        ->assertSee(route('chess.challenge', ['to' => $friend->npub]), false);
});

test('a guest is sent to log in', function () {
    $this->get(route('challenge'))->assertRedirect(route('login'));
});

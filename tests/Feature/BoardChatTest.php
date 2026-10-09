<?php

use App\Games\Checkers;
use App\Models\ChatMute;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| The players' chat on a board game's page (plan "Blockli-Optimierung", P1)
|--------------------------------------------------------------------------
|
| The two players chat privately (NIP-17 from the browser, as on a chess game's page); spectators and guests get
| no chat. The `match` tag is `board:<id>`: board games and chess games are numbered apart and must not share a chat.
|
*/

beforeEach(function () {
    config(['esports.board_games.games.'.Checkers::SLUG.'.enabled' => true]);
    BlockliOn::play();
});

test('both players of every board game get the chat, tagged with the board game, spectators and guests none', function (string $slug) {
    [$anna, $bert, $carla] = User::factory()->count(3)->create();
    $game = app(BoardGameService::class)->start($slug, $anna, $bert);

    $chat = fn (?User $viewer) => ($viewer ? Livewire::actingAs($viewer) : Livewire::withoutLazyLoading())->test('pages::board.show', ['boardGame' => $game])->instance()->chatConfig();

    expect($chat($anna))->toMatchArray(['me' => $anna->pubkey, 'match' => 'board:'.$game->id, 'opponent' => ['pubkey' => $bert->pubkey, 'name' => $bert->displayName()]])
        ->and($chat($bert)['opponent']['pubkey'])->toBe($anna->pubkey)
        ->and($chat($carla))->toBeNull();
    auth()->logout();
    expect($chat(null))->toBeNull();

    $this->actingAs($anna)->get(route('board.show', $game))->assertOk()->assertSee('data-test="chat"', false)->assertSee('data-test="chat-sheet-toggle"', false);
    $this->actingAs($carla)->get(route('board.show', $game))->assertOk()->assertDontSee('data-test="chat"', false);
    Livewire::actingAs($anna)->test('pages::board.show', ['boardGame' => $game])->call('$refresh')->assertOk();
})->with(['blockli', Checkers::SLUG]);

test('a player mutes the opponent on the account, a spectator cannot', function () {
    [$anna, $bert, $carla] = User::factory()->count(3)->create();
    $game = app(BoardGameService::class)->start('blockli', $anna, $bert);

    Livewire::actingAs($anna)->test('pages::board.show', ['boardGame' => $game])->call('setMuted', $bert->pubkey, true)->assertReturned(true);
    Livewire::actingAs($carla)->test('pages::board.show', ['boardGame' => $game])->call('setMuted', $bert->pubkey, true)->assertReturned(false);

    expect(ChatMute::query()->pluck('user_id')->all())->toBe([$anna->id]);

    Livewire::actingAs($anna)->test('pages::board.show', ['boardGame' => $game])->call('setMuted', $bert->pubkey, false)->assertReturned(true);
    expect(ChatMute::query()->count())->toBe(0);
});

<?php

use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\ScoreAccountClaim;
use App\Models\User;
use App\Support\Tmnf\TmnfLinks;
use Livewire\Livewire;

/*
| The TMNF login on the settings page (plan "Trackmania und Restposten", P1):
| a private gamer tag, linked with a one-time code shown on request, never on
| a public page. Switched off, none of it exists.
*/

test('switched off, the settings page has no TMNF field and the code cannot be asked for', function () {
    $player = User::factory()->create(['gamer_tags' => ['tmnf' => 'satoshi_drives']]);

    $this->actingAs($player)->get(route('gaming.edit'))->assertOk()
        ->assertDontSee('data-test="tag-field-tmnf"', false)->assertDontSee('data-test="tmnf-link"', false);

    expect(app(GameRegistry::class)->find(TrackmaniaNationsForever::SLUG))->toBeNull();
    Livewire::actingAs($player)->test('pages::settings.gaming')->call('showTmnfCode')->assertStatus(404);
});

test('the login is a private gamer tag of its own card, and the link block asks for no code until the player does', function () {
    tmnfOn();
    $player = User::factory()->create();

    Livewire::actingAs($player)->test('pages::settings.gaming')
        ->assertSee('data-test="tag-field-tmnf"', false)->assertDontSee('data-test="tmnf-link"', false)
        ->set('gamerTags.tmnf', 'satoshi_drives')->call('save')->assertHasNoErrors()
        ->assertSee('data-test="tmnf-link-show"', false)->assertDontSee('data-test="tmnf-link-code"', false)
        ->call('showTmnfCode')
        ->assertSeeHtml('data-test="tmnf-link-code">link '.TmnfLinks::codeFor($player->refresh()).'</code>')
        ->assertSee('data-test="tmnf-link-howto"', false)->assertSee('data-test="tmnf-chat-keys"', false);

    expect($player->gamer_tags)->toBe(['tmnf' => 'satoshi_drives']);
});

test('a linked login shows as linked, and no public page names it', function () {
    tmnfOn();
    $player = User::factory()->create(['gamer_tags' => ['tmnf' => 'satoshi_drives']]);
    ScoreAccountClaim::query()->create(['game' => 'tmnf', 'account_id' => 'satoshi_drives', 'user_id' => $player->id]);

    Livewire::actingAs($player)->test('pages::settings.gaming')
        ->assertSee('data-test="tmnf-linked"', false)->assertDontSee('data-test="tmnf-link-show"', false);

    auth()->logout();
    $this->get(route('players.show', $player->npub))->assertOk()->assertDontSee('satoshi_drives');
});

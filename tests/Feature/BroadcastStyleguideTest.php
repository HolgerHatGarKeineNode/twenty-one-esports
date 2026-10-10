<?php

use App\Http\Controllers\BroadcastStyleguideController;
use App\Models\Admin;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The broadcast design system (plan "OBS-Broadcast-Overlays", P1)
|--------------------------------------------------------------------------
|
| /broadcast/styleguide is for admins: a guest goes to the login, a player
| gets 403. The admin's page carries the engine (three.js before the Vite
| entry), the demo copy in their language and the asset pack's files;
| `?stage=1` marks the document for the transparent stage alone.
| tests/Browser/BroadcastStyleguideTest.php measures the running page.
|
*/

function broadcastAdmin(string $locale = 'en'): User
{
    $user = User::factory()->create(['locale' => $locale]);
    Admin::query()->create(['pubkey' => $user->pubkey]);

    return $user;
}

test('the styleguide is for admins only: a guest is sent to the login and a player is refused', function () {
    $this->get('/broadcast/styleguide')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/broadcast/styleguide')->assertForbidden();
    $this->actingAs(broadcastAdmin())->get('/broadcast/styleguide')->assertOk();
});

test('the admin page loads three.js before the engine and carries the demo program in the admin\'s language', function () {
    $html = $this->actingAs(broadcastAdmin('de'))->get(route('broadcast.styleguide'))
        ->assertOk()
        ->assertSee('data-test="broadcast-styleguide"', false)
        ->assertSee('Broadcast-Designsystem')
        ->assertDontSee('stage-only', false)
        ->getContent();

    expect(strpos($html, '/hyper/vendor/three.min.js'))->toBeGreaterThan(strpos($html, 'id="broadcast-config"'))
        ->and($html)->toContain('Steigt von Platz 14 auf Platz 3');
});

test('?stage=1 marks the document for the transparent stage alone', function () {
    $this->actingAs(broadcastAdmin())->get('/broadcast/styleguide?stage=1')
        ->assertOk()
        ->assertSee('class="dark stage-only"', false);
});

test('every asset the page names exists in the pack, with the sounds and their licence', function () {
    foreach (BroadcastStyleguideController::ART as $id) {
        expect(public_path("broadcast/art/{$id}.webp"))->toBeFile();
    }

    foreach (['whoosh', 'hit', 'riser', 'shimmer'] as $sound) {
        expect(public_path("broadcast/sound/{$sound}.ogg"))->toBeFile();
    }

    expect(public_path('broadcast/sound/LICENSE.txt'))->toBeFile()
        ->and(public_path('broadcast/art/plate-haze.webp'))->toBeFile();
});

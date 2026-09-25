<?php

use App\Enums\Platform;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('the gaming profile saves avatar, platform, gamer tags, time zone and language', function () {
    Storage::fake('public');
    $user = User::factory()->create(['locale' => 'en']);
    $this->actingAs($user)->get(route('gaming.edit'))->assertOk();

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('avatar', UploadedFile::fake()->image('me.png', 64, 64))
        ->set('platform', 'playstation')
        ->set('gamerTags.epic', ' rocketeer ')
        ->set('timezone', 'Europe/Zurich')
        ->set('locale', 'de')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->platform)->toBe(Platform::PlayStation)
        ->and($user->gamer_tags)->toBe(['epic' => 'rocketeer'])
        ->and($user->timezone)->toBe('Europe/Zurich')
        ->and($user->locale)->toBe('de')
        ->and(session('locale'))->toBe('de');
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('the gaming profile rejects an unknown time zone and platform', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('platform', 'dreamcast')
        ->set('timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors(['platform', 'timezone']);
});

test('deleting the account removes our data and logs out', function () {
    Storage::fake('public');
    $user = User::factory()->create(['avatar_path' => UploadedFile::fake()->image('a.png')->store('avatars', 'public')]);
    Admin::query()->create(['pubkey' => $user->pubkey]);

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->call('deleteAccount')
        ->assertHasErrors('confirmDeletion')
        ->set('confirmDeletion', true)
        ->call('deleteAccount')
        ->assertRedirect(route('home'));

    expect(User::query()->count())->toBe(0)
        ->and(Admin::query()->count())->toBe(0)
        ->and(session('status'))->toContain('Nostr');
    Storage::disk('public')->assertMissing($user->avatar_path);
    $this->assertGuest();
});

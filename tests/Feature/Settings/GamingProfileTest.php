<?php

use App\Enums\Platform;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The settings tabs Gamer tags and Account (P51): saving and clearing gamer
 * tags, the privacy copy that tells who sees them, and the account basics
 * that moved off the gaming profile.
 */

test('gamer tags save trimmed and empty fields stay unsaved', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('gamerTags.epic', ' rocketeer ')
        ->set('gamerTags.ea', 'Striker_21')
        ->set('gamerTags.steam', '   ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('gamer-tags-saved');

    expect($user->refresh()->gamer_tags)->toBe(['epic' => 'rocketeer', 'ea' => 'Striker_21']);
});

test('a gamer tag is cleared with one click, and only that tag', function () {
    $user = User::factory()->create(['gamer_tags' => ['epic' => 'rocketeer', 'ea' => 'Striker_21']]);

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('gamerTags.psn', 'typed but not saved')
        ->call('clear', 'epic')
        ->assertSet('gamerTags.epic', '')
        ->assertSet('gamerTags.psn', 'typed but not saved');

    expect($user->refresh()->gamer_tags)->toBe(['ea' => 'Striker_21']);

    Livewire::actingAs($user)->test('pages::settings.gaming')->call('clear', 'ea');

    expect($user->refresh()->gamer_tags)->toBeNull();
});

test('keep all private removes every saved tag, and an unknown service is ignored', function () {
    $user = User::factory()->create(['gamer_tags' => ['epic' => 'rocketeer', 'ea' => 'Striker_21']]);

    Livewire::actingAs($user)->test('pages::settings.gaming')->call('clear', 'battlenet');
    expect($user->refresh()->gamer_tags)->toBe(['epic' => 'rocketeer', 'ea' => 'Striker_21']);

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->call('clearAll')
        ->assertSet('gamerTags.epic', '')
        ->assertSet('gamerTags.ea', '');

    expect($user->refresh()->gamer_tags)->toBeNull();
});

test('gamer tags reject an unknown service and an overlong tag', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('gamerTags.ea', str_repeat('x', 65))
        ->call('save')
        ->assertHasErrors('gamerTags.ea');

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('gamerTags.battlenet', 'someone')
        ->call('save')
        ->assertHasErrors('gamerTags');

    expect($user->refresh()->gamer_tags)->toBeNull();
});

test('the gamer tag page says tags are optional, who sees them and the private way', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('gaming.edit'))
        ->assertOk()
        ->assertSee('Every field is optional')
        ->assertSee('Only you see them: they are not on your profile, not shown to other players and not published on Nostr.')
        ->assertSee('end-to-end encrypted over Nostr (NIP-17)')
        ->assertSee('Nothing saved. All your accounts stay private.')
        ->assertSee(route('rules').'#casual-1v1', false)
        ->assertSee('data-test="tag-card-rocket-league"', false)
        ->assertSee('data-test="tag-card-ea-sports-fc-27"', false)
        ->assertSee('data-test="tag-card-none"', false)
        ->assertDontSee('data-test="clear-all-tags"', false);
});

test('a saved tag shows as saved with its remove button, the rest as private', function () {
    $user = User::factory()->create(['gamer_tags' => ['ea' => 'Striker_21']]);

    $html = $this->actingAs($user)->get(route('gaming.edit'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="tag-state-saved"'))->toBe(1)
        ->and(substr_count($html, 'data-test="tag-state-private"'))->toBe(count(config('esports.gamer_tags')) - 1)
        ->and($html)->toContain('data-test="tag-clear-ea"')
        ->not->toContain('data-test="tag-clear-epic"')
        ->toContain('data-test="clear-all-tags"')
        ->toContain('One tag saved.');
});

test('the gamer tag page answers a roundtrip', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::settings.gaming')->call('$refresh')->assertOk();
    Livewire::actingAs(User::factory()->create())->test('pages::settings.account')->call('$refresh')->assertOk();
});

test('both tabs need a login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['gaming.edit', 'settings.account']);

test('the account tab saves avatar, platform, time zone and language', function () {
    Storage::fake('public');
    $user = User::factory()->create(['locale' => 'en', 'gamer_tags' => ['epic' => 'rocketeer']]);
    $this->actingAs($user)->get(route('settings.account'))->assertOk();

    Livewire::actingAs($user)->test('pages::settings.account')
        ->set('avatar', UploadedFile::fake()->image('me.png', 64, 64))
        ->set('platform', 'playstation')
        ->set('timezone', 'Europe/Zurich')
        ->set('locale', 'de')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->platform)->toBe(Platform::PlayStation)
        ->and($user->timezone)->toBe('Europe/Zurich')
        ->and($user->locale)->toBe('de')
        ->and($user->gamer_tags)->toBe(['epic' => 'rocketeer'])
        ->and(session('locale'))->toBe('de');
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('the account tab rejects an unknown time zone and platform', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.account')
        ->set('platform', 'dreamcast')
        ->set('timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors(['platform', 'timezone']);
});

test('deleting the account removes our data and logs out', function () {
    Storage::fake('public');
    $user = User::factory()->create(['avatar_path' => UploadedFile::fake()->image('a.png')->store('avatars', 'public')]);
    Admin::query()->create(['pubkey' => $user->pubkey]);

    Livewire::actingAs($user)->test('pages::settings.account')
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

test('every settings page shows the same seven tabs, its own one active and named in the heading', function (string $route, string $heading) {
    $html = $this->actingAs(User::factory()->create())->get(route($route))->assertOk()->getContent();

    preg_match('/<nav aria-label="Settings sections".*?<\/nav>/s', $html, $nav);
    preg_match_all('/data-test="(settings-[a-z-]+-tab)"/', $nav[0] ?? '', $tabs);
    preg_match('/<a [^>]*aria-current="page"[^>]*>([^<]+)<\/a>/', $nav[0] ?? '', $active);
    preg_match('/<h1[^>]*data-test="settings-heading"[^>]*>([^<]+)<\/h1>/', $html, $h1);

    expect($tabs[1])->toBe(['settings-gamer-tags-tab', 'settings-account-tab', 'settings-notifications-tab', 'settings-chess-tab', 'settings-opponents-tab', 'settings-badges-tab', 'settings-nostr-address-tab'])
        ->and(substr_count($nav[0], 'aria-current="page"'))->toBe(1)
        ->and(html_entity_decode(trim($active[1] ?? '')))->toBe($heading)
        ->and(html_entity_decode(trim($h1[1] ?? '')))->toBe($heading)
        // The tabs switch with wire:navigate (P6b, App\Support\Navigation\Navigate).
        ->and(substr_count($nav[0], 'wire:navigate'))->toBe(7);
})->with([
    ['gaming.edit', 'Gamer tags'],
    ['settings.account', 'Account'],
    ['settings.notifications', 'Notifications'],
    ['settings.chess', 'Chess'],
    ['settings.opponents', 'Opponents'],
    ['settings.badges', 'Badges and sharing'],
    ['settings.nip05', 'Nostr address'],
]);

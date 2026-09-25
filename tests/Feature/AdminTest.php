<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;

test('a board npub from the config is admin', function () {
    $board = User::factory()->withPubkey(NostrKeys::npubToHex(config('esports.board')[0]))->create();

    expect($board->isAdmin())->toBeTrue();
    $this->actingAs($board)->get(route('admin.admins'))->assertOk();
});

test('an admin from the database is admin', function () {
    $user = User::factory()->create();
    Admin::query()->create(['pubkey' => $user->pubkey]);

    expect($user->isAdmin())->toBeTrue();
    $this->actingAs($user)->get(route('admin.admins'))->assertOk();
});

test('a normal user gets 403 on the admin area', function () {
    $user = User::factory()->create();

    Route::middleware(['web', 'auth', 'admin'])->get('admin-middleware-probe', fn () => 'ok');

    $this->actingAs($user)->get(route('admin.admins'))->assertForbidden();
    $this->actingAs($user)->get('admin-middleware-probe')->assertForbidden();
    Livewire::actingAs($user)->test('pages::admin.admins')->assertForbidden();
});

test('the admin page adds and removes an admin', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $newcomer = User::factory()->create();

    $page = Livewire::actingAs($admin)->test('pages::admin.admins')
        ->set('key', $newcomer->npub)
        ->call('add')
        ->assertHasNoErrors();

    expect($newcomer->isAdmin())->toBeTrue();

    $page->call('remove', Admin::query()->where('pubkey', $newcomer->pubkey)->value('id'));

    expect($newcomer->isAdmin())->toBeFalse()
        ->and($admin->isAdmin())->toBeTrue();
});

test('the admin page refuses a key that is not a public key', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $nsec = (new Key)->convertPrivateKeyToBech32((new TestSigner)->secret);

    Livewire::actingAs($admin)->test('pages::admin.admins')
        ->set('key', $nsec)
        ->call('add')
        ->assertHasErrors('key');

    expect(Admin::query()->count())->toBe(1);
});

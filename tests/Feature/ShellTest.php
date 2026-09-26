<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Js;

dataset('shell pages', [
    'home' => ['/'],
    'login' => ['/login'],
    'styleguide' => ['/styleguide'],
    'chess placeholder' => ['/chess'],
    'match list' => ['/matches'],
    'ladder placeholder' => ['/ladder/chess/blitz'],
    'rules placeholder' => ['/rules'],
]);

dataset('locales', [
    'en' => ['en', 'Main navigation'],
    'de' => ['de', 'Hauptnavigation'],
]);

test('shell pages render in each locale', function (string $uri, string $locale, string $navigationLabel) {
    $this->withSession(['locale' => $locale])
        ->get($uri)
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false)
        ->assertSee('aria-label="'.$navigationLabel.'"', false);
})->with('shell pages')->with('locales');

test('unknown pages answer 404 in the visitor locale', function () {
    $this->withSession(['locale' => 'de'])
        ->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('Seite nicht gefunden');
});

test('the admin navigation item is hidden for guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('aria-label="Admin"', false);
});

test('the admin navigation item shows for admins', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)
        ->get('/')
        ->assertOk()
        ->assertSee('aria-label="Admin"', false);
});

test('the styleguide is not reachable in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/styleguide')->assertNotFound();
});

test('the login page offers only Google and Nostr, without linking a membership', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Lightning')
        ->assertDontSee('Link my membership')
        ->assertSee('Your badge shows up by itself once you log in.');
});

test('the login page wires both buttons to the Nostr login', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('x-data="nostrLogin(', false)
        ->assertSee('challengeUrl: '.Js::from(route('auth.nostr.challenge')), false)
        ->assertSee('x-on:click="loginWithGoogle()"', false)
        ->assertSee('x-on:click="loginWithNostr()"', false);
});

test('the account menu shows name and short npub, never an email', function () {
    $user = User::factory()->create(['name' => 'Satoshi Nakamoto']);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Satoshi Nakamoto')
        ->assertSee($user->shortNpub())
        ->assertSee('href="'.route('gaming.edit').'"', false)
        ->assertSee('x-on:submit="window.forgetNostrSigner?.()"', false)
        ->assertDontSee('email');
});

test('the account menu links to the admin area only for admins', function (bool $isAdmin) {
    $user = User::factory()->create();

    if ($isAdmin) {
        Admin::query()->create(['pubkey' => $user->pubkey]);
    }

    $response = $this->actingAs($user)->get('/')->assertOk();

    $isAdmin
        ? $response->assertSee('href="'.route('admin.admins').'"', false)
        : $response->assertDontSee(route('admin.admins'), false);
})->with(['admin' => true, 'player' => false]);

test('the games menu and the account menus link every chess page, so none is found only by chance', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('clans.index'))->assertOk()->getContent();

    expect($html)->toContain('data-test="games-menu"')
        ->toContain('href="'.route('me.correspondence').'"')
        ->toContain('href="'.route('settings.chess').'"')
        ->toContain('href="'.route('chess.challenge').'"')
        ->toContain('href="'.route('ladder.show', ['chess', 'blitz']).'"')
        ->toContain('data-test="mobile-chess-settings"');
});

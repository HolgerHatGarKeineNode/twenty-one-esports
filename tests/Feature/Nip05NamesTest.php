<?php

use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\Nip05Hold;
use App\Models\User;
use App\Support\Nostr\Nip05Names;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * NIP-05 names on the league's domain (P47): opt-in in the settings,
 * `name@<app host>` served from /.well-known/nostr.json; lowercase a-z, 0-9,
 * dot, underscore, hyphen; unique; reserved names; a change limit; released
 * with the account; revoked by an admin.
 */

beforeEach(function () {
    config([
        'app.url' => 'https://esports.example',
        'esports.relays' => ['wss://league-one.example', 'ws://127.0.0.1:7777', 'wss://league-two.example'],
        'esports.nip05.change_days' => 30,
    ]);
});

test('a claimed name answers in nostr.json with the pubkey and the league relays, case-insensitively', function () {
    $anna = User::factory()->create();

    expect(app(Nip05Names::class)->claim($anna, '  Anna.Sats_42-x '))->toBeNull()
        ->and($anna->nip05_name)->toBe('anna.sats_42-x')
        ->and(Nip05Names::address($anna))->toBe('anna.sats_42-x@esports.example');

    // NIP-05: {"names": {name: hex pubkey}, "relays": {hex pubkey: [urls]}}; only wss relays go out.
    $this->get(route('nostr.nip05', ['name' => 'ANNA.sats_42-X']))
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertExactJson([
            'names' => ['anna.sats_42-x' => $anna->pubkey],
            'relays' => [$anna->pubkey => ['wss://league-one.example', 'wss://league-two.example']],
        ]);

    // Without league relays the document names the key alone.
    config(['esports.relays' => []]);
    $this->get(route('nostr.nip05', ['name' => 'anna.sats_42-x']))->assertExactJson(['names' => ['anna.sats_42-x' => $anna->pubkey]]);

    // No name, an unknown name, an invalid one: no names (the document never lists every player).
    foreach ([[], ['name' => ''], ['name' => 'nobody'], ['name' => '../etc'], ['name' => str_repeat('a', 200)]] as $query) {
        $this->get(route('nostr.nip05', $query))->assertOk()->assertContent('{"names":{}}');
    }
});

test('names follow the character rules, are unique, and reserved names are refused', function () {
    $names = app(Nip05Names::class);
    $anna = User::factory()->create();
    $ben = User::factory()->create();

    foreach (['ab', str_repeat('a', 31), 'anna sats', 'anna@sats', 'änna', '.anna', '-anna', '_anna', 'anna+1'] as $bad) {
        expect($names->claim($anna, $bad))->not->toBeNull("{$bad} was accepted");
    }

    foreach (['admin', 'Support', 'league', 'twentyone', 'esports', 'pool', 'nostr'] as $reserved) {
        expect($names->claim($anna, $reserved))->toBe('This name is reserved.');
    }

    expect($anna->refresh()->nip05_name)->toBeNull()
        ->and($names->claim($anna, 'satoshi'))->toBeNull()
        ->and($names->claim($ben, 'SATOSHI'))->toBe('This name is taken.')
        ->and($ben->refresh()->nip05_name)->toBeNull();

    // The unique index decides a race the check did not see.
    expect(fn () => User::query()->whereKey($ben->id)->update(['nip05_name' => 'satoshi']))->toThrow(UniqueConstraintViolationException::class);
});

test('a name changes at most once per change period; the first claim is free, a release counts', function () {
    $names = app(Nip05Names::class);
    $anna = User::factory()->create();

    expect($names->claim($anna, 'anna'))->toBeNull();
    expect($names->claim($anna, 'anna2'))->toStartWith('You can pick a new name from')
        ->and($anna->refresh()->nip05_name)->toBe('anna');

    $this->travel(31)->days();
    expect($names->claim($anna, 'anna2'))->toBeNull()
        ->and($anna->refresh()->nip05_name)->toBe('anna2');

    // Released at once, but the next claim waits for the period.
    $names->release($anna);
    expect($anna->refresh()->nip05_name)->toBeNull()
        ->and($names->claim($anna, 'anna3'))->toStartWith('You can pick a new name from');
});

test('audit F2: a name given up is held for the change period against other keys, not against its own', function () {
    $names = app(Nip05Names::class);
    $anna = User::factory()->create();
    $ben = User::factory()->create();

    $names->claim($anna, 'satsqueen');
    $names->release($anna);

    expect($names->claim($ben, 'satsqueen'))->toStartWith('This name was given up recently and is held until');

    // Its own key logs in again with a new account: the name is its own to take back.
    app(DeleteAccount::class)($anna);
    $again = User::factory()->withPubkey($anna->pubkey)->create();
    expect($names->claim($again, 'satsqueen'))->toBeNull()
        ->and($again->refresh()->nip05_name)->toBe('satsqueen');

    // A changed name is held the same way.
    $this->travel(31)->days();
    expect($names->claim($again, 'satsking'))->toBeNull()
        ->and($names->claim($ben, 'satsqueen'))->toStartWith('This name was given up recently')
        ->and($names->claim(User::factory()->create(), 'satsqueen'))->toStartWith('This name was given up recently');

    $this->travel(31)->days();
    expect($names->claim($ben, 'satsqueen'))->toBeNull();
});

test('deleting the account ends the name at once; another key gets it after the change period', function () {
    $anna = User::factory()->create();
    app(Nip05Names::class)->claim($anna, 'anna');

    app(DeleteAccount::class)($anna);

    $this->get(route('nostr.nip05', ['name' => 'anna']))->assertContent('{"names":{}}');
    expect(app(Nip05Names::class)->claim(User::factory()->create(), 'anna'))->toStartWith('This name was given up recently');

    $this->travel(31)->days();
    expect(app(Nip05Names::class)->claim(User::factory()->create(), 'anna'))->toBeNull();
});

test('an admin revokes a name: it answers no more and nobody claims it again', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $anna = User::factory()->create();
    app(Nip05Names::class)->claim($anna, 'satoshi');

    $this->actingAs(User::factory()->create())->get(route('admin.nip05'))->assertForbidden();

    Livewire::actingAs($admin)->test('pages::admin.nip05')
        ->assertSee('satoshi@esports.example')
        ->call('revoke', $anna->id)
        ->assertSee('satoshi@esports.example was revoked.');

    expect($anna->refresh()->nip05_name)->toBeNull()
        ->and($anna->nip05_revoked_name)->toBe('satoshi')
        ->and($anna->nip05_revoked_at)->not->toBeNull();

    $this->get(route('nostr.nip05', ['name' => 'satoshi']))->assertContent('{"names":{}}');
    expect(app(Nip05Names::class)->claim(User::factory()->create(), 'satoshi'))->toBe('This name is reserved.');

    // The player sees why, and picks another name after the change period.
    Livewire::actingAs($anna)->test('pages::settings.nip05')->assertSee('An admin took back satoshi@esports.example.');
});

test('audit F2: a revoked name stays held through deleting the account, logging in again and a second revocation, until an admin lifts it', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $names = app(Nip05Names::class);
    $key = new TestSigner;
    $anna = User::factory()->withPubkey($key->pubkey)->create();

    $names->claim($anna, 'satoshi');
    $names->revoke($anna, $admin);

    // Delete, log in again with the same key: still refused, even a year on.
    app(DeleteAccount::class)($anna);
    $again = User::factory()->withPubkey($key->pubkey)->create();
    $this->travel(365)->days();
    expect($names->claim($again, 'satoshi'))->toBe('This name is reserved.');

    // A second revocation of another name keeps both held.
    expect($names->claim($again, 'nakamoto'))->toBeNull();
    $names->revoke($again, $admin);
    $other = User::factory()->create();
    expect($names->claim($other, 'satoshi'))->toBe('This name is reserved.')
        ->and($names->claim($other, 'nakamoto'))->toBe('This name is reserved.')
        ->and(Nip05Hold::query()->where('reason', Nip05Hold::REVOKED)->pluck('name')->sort()->values()->all())->toBe(['nakamoto', 'satoshi']);

    // The admin page lists the holds and lifts one; then the name is free.
    $hold = Nip05Hold::query()->where('name', 'satoshi')->sole();
    Livewire::actingAs($admin)->test('pages::admin.nip05')
        ->assertSee('data-test="nip05-holds"', false)
        ->assertSee('satoshi@esports.example')
        ->call('lift', $hold->id)
        ->assertSee('The hold on satoshi was lifted.');

    expect($names->claim($other, 'satoshi'))->toBeNull()
        ->and($names->claim(User::factory()->create(), 'nakamoto'))->toBe('This name is reserved.');
});

test('audit F4: a reserved word is refused as a part of a name, as a longer word and in look-alike spelling', function () {
    $names = app(Nip05Names::class);
    $player = User::factory()->create();
    $variants = ['support', 'admin', 'support-team', 'admin.team', 'einundzwanzig-esports', 'twentyone.official', 'official-support',
        'league.admin', 'mod-team', 'esports-support', 'moderation', 'administrators', 'e21', 'twentyone-esports'];

    foreach ($variants as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, 'This name is reserved.']);
    }

    // Look-alikes: 0 for o, rn for m, 3 for e, 5 for s, 1 for l; longer words that start with one; more staff words.
    foreach (['supp0rt', 'adrnin', 'm0d-team', '0fficial', 'tw3ntyone', 'e5ports', 'wa11et', 'help.desk', 'security-team', 'staff', 'supporter', 'officialsats'] as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, 'This name is reserved.']);
    }

    // Ordinary names stay free.
    foreach (['satoshi', 'anna.sats', 'hodl-hanna', 'laser_eyes', 'chessmaster'] as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, null]);
    }
});

test('the settings page claims, shows the next step, and gives the name up after a confirmation', function () {
    $anna = User::factory()->create();

    $this->actingAs($anna)->get(route('settings.nip05'))->assertOk()->assertSee('data-test="nip05-input"', false)->assertDontSee('data-test="nip05-current"', false);

    Livewire::actingAs($anna)->test('pages::settings.nip05')
        ->set('name', 'Admin')->call('claim')->assertSet('error', 'This name is reserved.')
        ->set('name', 'Anna')->call('claim')->assertSet('error', '')
        ->assertSee('anna@esports.example')
        ->assertSee('data-test="nip05-not-in-profile"', false)
        ->call('release')->assertSet('confirmRelease', true)
        ->call('release')->assertSet('name', '');

    expect($anna->refresh()->nip05_name)->toBeNull();

    // Once the Nostr profile names the address, the page says so.
    $ben = User::factory()->create(['nip05' => 'ben@esports.example']);
    app(Nip05Names::class)->claim($ben, 'ben');
    Livewire::actingAs($ben)->test('pages::settings.nip05')->assertSee('data-test="nip05-in-profile"', false);
});

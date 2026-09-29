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

    expect(app(Nip05Names::class)->claim($anna, '  Anna.Sats_21-x '))->toBeNull()
        ->and($anna->nip05_name)->toBe('anna.sats_21-x')
        ->and(Nip05Names::address($anna))->toBe('anna.sats_21-x@esports.example');

    // NIP-05: {"names": {name: hex pubkey}, "relays": {hex pubkey: [urls]}}; only wss relays go out.
    $this->get(route('nostr.nip05', ['name' => 'ANNA.sats_21-X']))
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertExactJson([
            'names' => ['anna.sats_21-x' => $anna->pubkey],
            'relays' => [$anna->pubkey => ['wss://league-one.example', 'wss://league-two.example']],
        ]);

    // Without league relays the document names the key alone.
    config(['esports.relays' => []]);
    $this->get(route('nostr.nip05', ['name' => 'anna.sats_21-x']))->assertExactJson(['names' => ['anna.sats_21-x' => $anna->pubkey]]);

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

test('one key holds at most one given-up name: each new release ends its older release hold, never a revoked one', function () {
    $admin = User::factory()->create();
    $names = app(Nip05Names::class);
    $key = new TestSigner;
    $other = User::factory()->create();

    // An earlier name of the key, revoked by an admin: that hold is never ended by the key's own releases.
    $first = User::factory()->withPubkey($key->pubkey)->create();
    $names->claim($first, 'satoshi');
    $names->revoke($first, $admin);
    app(DeleteAccount::class)($first);

    // Park by deletion: claim, delete the account, log in again (a fresh account's first claim is free), claim, delete.
    $jack = User::factory()->withPubkey($key->pubkey)->create();
    expect($names->claim($jack, 'jack'))->toBeNull();
    app(DeleteAccount::class)($jack);

    $odell = User::factory()->withPubkey($key->pubkey)->create();
    expect($names->claim($odell, 'odell'))->toBeNull();
    app(DeleteAccount::class)($odell);

    expect($names->claim($other, 'odell'))->toStartWith('This name was given up recently')
        ->and($names->claim($other, 'jack'))->toBeNull()
        ->and($names->claim(User::factory()->create(), 'satoshi'))->toBe('This name is reserved.')
        ->and(Nip05Hold::query()->active()->where('pubkey', $key->pubkey)->orderBy('name')->pluck('reason', 'name')->all())
        ->toBe(['odell' => Nip05Hold::RELEASED, 'satoshi' => Nip05Hold::REVOKED]);

    // Another key's hold is untouched by this key's release.
    $ben = User::factory()->create();
    $names->claim($ben, 'benny');
    $names->release($ben);
    $again = User::factory()->withPubkey($key->pubkey)->create();
    $names->claim($again, 'kate');
    $names->release($again);

    expect($names->claim(User::factory()->create(), 'benny'))->toStartWith('This name was given up recently')
        ->and($names->claim(User::factory()->create(), 'odell'))->toBeNull()
        ->and($names->claim(User::factory()->create(), 'kate'))->toStartWith('This name was given up recently');
});

test('the admin page searches the held names as written and as they read, past the first 50, with _ taken literally', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $names = app(Nip05Names::class);

    foreach (['sats_queen', 'satsxqueen', 'adm1nistrator'] as $name) {
        Nip05Hold::query()->create(['name' => $name, 'skeleton' => Nip05Names::skeleton($name), 'reason' => Nip05Hold::REVOKED, 'pubkey' => str_repeat('c', 64)]);
    }

    // 60 newer releases of 60 keys push the three past the first 50 (made directly: `filler1` and
    // `filler11` read the same, so the second could not be claimed).
    for ($i = 0; $i < 60; $i++) {
        Nip05Hold::query()->create(['name' => 'filler'.$i, 'skeleton' => Nip05Names::skeleton('filler'.$i), 'reason' => Nip05Hold::RELEASED,
            'pubkey' => str_pad(dechex($i), 64, 'd', STR_PAD_LEFT), 'held_until' => now()->addDays(30)]);
    }

    $claimed = User::factory()->create();
    $names->claim($claimed, 'lnd_node');
    $names->claim(User::factory()->create(), 'lndxnode');

    $page = Livewire::actingAs($admin)->test('pages::admin.nip05')
        ->assertSee('data-test="nip05-hold-search"', false)
        ->assertSee('63 names')
        ->assertSee('Showing 50 of 63. Search to find the others.');

    $held = fn (string $search): array => $page->set('holdSearch', $search)->instance()->holds()->pluck('name')->sort()->values()->all();

    expect($held('sats_queen'))->toBe(['sats_queen'])
        ->and($held('SATSX'))->toBe(['satsxqueen'])
        ->and($held('admin'))->toBe(['adm1nistrator'])
        ->and($held('filler59'))->toBe(['filler59'])
        ->and($held('nobody'))->toBe([]);

    $page->set('holdSearch', 'nobody')->assertSee('No name matches.')->assertDontSee('Showing 50 of 63');

    // The claimed-names search takes _ literally too.
    expect($page->set('search', 'lnd_node')->instance()->claimed()->pluck('nip05_name')->all())->toBe(['lnd_node']);
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

test('re-audit N3: a name that reads as a reserved word or contains a staff word is refused; generic words stay free in a longer name', function () {
    $names = app(Nip05Names::class);
    $player = User::factory()->create();

    // Plausible player names the first rule refused (29 of 35 measured): all free now.
    $plausible = ['satoshi_21', 'hodl-21', 'stacker.21', 'nostrich', 'nostrich21', 'nostr-pleb', 'streamer', 'leaguefan', 'team-rocket', 'bot-hunter',
        'the_pool_shark', 'live.laugh', 'news.junkie', 'root-beer', 'null-pointer', 'api-guy', 'directory', 'systematic', 'securitybrian', 'relayrunner',
        'anna.sats_21-x', 'satoshi', 'hodl-hanna', 'laser_eyes', 'chessmaster'];

    foreach ($plausible as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, null]);
    }

    // The bypasses the re-audit found, and the first audit's variants that impersonate staff or the league.
    $refused = ['adm1n', 'off1cial', 'e1nundzwanzig', 'l1ga', 'theadmin', 'realadmin', 'teamsupport', 'helpdesk', 'e21admin', '21admin', 'admln', 'offlcial', 'suport',
        'suppoort', 'moderat0r', 'the.admin', 'adminx', 'mysupport', 'staffmember', 'memberstaff', 'einundzwanzigteam', 'teameinundzwanzig',
        'support', 'admin', 'support-team', 'admin.team', 'einundzwanzig-esports', 'twentyone.official', 'official-support', 'league.admin',
        'esports-support', 'moderation', 'administrators', 'e21', 'twentyone-esports', 'adrnin', 'tw3ntyone', 'help.desk', 'wa11et', 'po0l', 'l.i.g.a'];

    foreach ($refused as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, 'This name is reserved.']);
    }

    // Generic words are only reserved as the whole name.
    foreach (['team', 'league', 'stream', 'pool', 'relay', 'bot', 'security', 'news', 'api', 'root', 'null', 'system'] as $name) {
        expect([$name, $names->problem($name, $player)])->toBe([$name, 'This name is reserved.'])
            ->and([$name.'-fan', $names->problem($name.'-fan', $player)])->toBe([$name.'-fan', null]);
    }
});

test('re-audit N3: a hold covers every spelling that reads the same, for other keys', function () {
    $names = app(Nip05Names::class);
    $odell = User::factory()->create();
    $names->claim($odell, 'odell');
    $names->revoke($odell);
    $other = User::factory()->create();

    foreach (['odell', 'ODELL', ' Odell ', 'odell_', 'odell-', 'odell.', 'o.dell', 'o_dell', '0dell', 'odel1', 'odelll'] as $name) {
        expect([$name, $names->problem($name, $other)])->toBe([$name, 'This name is reserved.']);
    }

    // A name given up is held the same way against others, and stays its own key's.
    $hanna = User::factory()->create();
    $names->claim($hanna, 'hanna');
    $names->release($hanna);

    expect($names->problem('h.anna', $other))->toStartWith('This name was given up recently')
        ->and($names->problem('hana', $other))->toStartWith('This name was given up recently')
        ->and($names->problem('hannah', $other))->toBeNull();
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

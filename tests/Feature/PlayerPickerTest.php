<?php

use App\Models\Admin;
use App\Models\ClanInvite;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The player picker (<x-player-picker>, route players.search)
|--------------------------------------------------------------------------
|
| Suggestions come from existing players only (name, NIP-05, full npub, an
| npub prefix of at least 8 characters), for logged-in players, throttled,
| and each row carries only what the public player page shows. The pages
| that pick a player store the picked id and refuse a submit without one.
|
| `allow-npub` (keys=1): rows carry the hex pubkey, and a fully valid npub or
| hex key without an account comes back as one `unregistered` row; the clan
| invite, the admins and the organizers take that key. Names still never
| become a key.
|
*/

/**
 * @param  array<string, mixed>  $query
 * @return list<array<string, mixed>>
 */
function pickerSearch(User $user, array $query): array
{
    return test()->actingAs($user)->getJson(route('players.search', $query))->assertOk()->json();
}

test('a name finds the player, anywhere in the name and in any case, with only public fields', function () {
    $caller = User::factory()->create();
    $nick = User::factory()->create(['name' => 'nonce_nick']);
    User::factory()->create(['name' => 'satsjaeger']);

    $rows = pickerSearch($caller, ['q' => 'NICK']);

    expect($rows)->toBe([[
        'id' => $nick->id,
        'name' => 'nonce_nick',
        'npub' => $nick->shortNpub(),
        'avatar' => route('avatars.generated', ['pubkey' => $nick->pubkey, 'v' => 1]),
        'fallback' => route('avatars.generated', ['pubkey' => $nick->pubkey, 'v' => 1]),
    ]]);
});

test('a stored NIP-05 finds the player', function () {
    $caller = User::factory()->create();
    $player = User::factory()->create(['name' => 'queen_q']);
    $player->forceFill(['nip05' => 'queen@example.org'])->save();

    expect(array_column(pickerSearch($caller, ['q' => 'queen@exa']), 'id'))->toBe([$player->id]);
});

test('a full npub and an npub prefix of 8 characters find the player; a shorter prefix finds nobody', function () {
    $caller = User::factory()->create();
    $player = User::factory()->create(['name' => 'hodlqueen']);

    expect(array_column(pickerSearch($caller, ['q' => $player->npub]), 'id'))->toBe([$player->id])
        ->and(array_column(pickerSearch($caller, ['q' => $player->pubkey]), 'id'))->toBe([$player->id])
        ->and(array_column(pickerSearch($caller, ['q' => Str::substr($player->npub, 0, 13)]), 'id'))->toBe([$player->id])
        ->and(pickerSearch($caller, ['q' => Str::substr($player->npub, 0, 12)]))->toBe([]);
});

test('unknown text, a single character and LIKE wildcards find nobody', function () {
    $caller = User::factory()->create(['name' => 'caller']);
    User::factory()->create(['name' => 'nonce_nick']);

    expect(pickerSearch($caller, ['q' => 'Nobody Here']))->toBe([])
        ->and(pickerSearch($caller, ['q' => 'n']))->toBe([])
        ->and(pickerSearch($caller, ['q' => '%%']))->toBe([])
        ->and(pickerSearch($caller, ['q' => '__']))->toBe([])
        ->and(pickerSearch($caller, ['q' => 'npub1qqqqqqqqqqqqqqqq']))->toBe([]);
});

test('excluded players are never suggested, and at most 8 rows come back', function () {
    $caller = User::factory()->create(['name' => 'blitz_caller']);
    $players = User::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'blitz_'.$sequence->index])->create();

    $rows = pickerSearch($caller, ['q' => 'blitz', 'exclude' => [$caller->id, $players[0]->id, $players[1]->id]]);
    $ids = array_column($rows, 'id');

    expect($rows)->toHaveCount(8)
        ->and($ids)->not->toContain($caller->id)
        ->and($ids)->not->toContain($players[0]->id)
        ->and($ids)->not->toContain($players[1]->id);
});

test('a guest is refused', function () {
    User::factory()->create(['name' => 'nonce_nick']);

    $this->getJson(route('players.search', ['q' => 'nonce']))->assertUnauthorized();
    $this->get(route('players.search', ['q' => 'nonce']))->assertRedirect(route('login'));
});

test('the search is rate limited per player', function () {
    $caller = User::factory()->create();

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($caller)->getJson(route('players.search', ['q' => 'ab']))->assertOk();
    }

    $this->actingAs($caller)->getJson(route('players.search', ['q' => 'ab']))->assertTooManyRequests();
    $this->actingAs(User::factory()->create())->getJson(route('players.search', ['q' => 'ab']))->assertOk();
});

test('the create page adds the picked player as director and refuses a submit without a pick', function () {
    $organizer = User::factory()->create(['name' => 'hodlqueen']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $director = User::factory()->create(['name' => 'nonce_nick']);

    $page = Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Blitz Night Munich')
        ->call('pickResultsMode', 'director')
        ->call('addDirector')
        ->assertSet('directorError', __('Pick a player from the suggestions.'))
        ->assertSet('directorIds', []);

    // Free text never reaches a user: the property holds an id or nothing.
    expect(fn () => $page->set('directorId', 'nonce_nick'))->toThrow(TypeError::class);

    $page->set('directorId', $director->id)
        ->call('addDirector')
        ->assertSet('directorError', '')
        ->assertSet('directorId', null)
        ->assertSet('directorIds', [$director->id])
        ->call('create')
        ->assertHasNoErrors();

    expect(Tournament::query()->sole()->directors->pluck('id')->all())->toBe([$director->id]);
});

test('the director desk adds the picked player and refuses a submit without a pick', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = Tournament::factory()->create(['results_mode' => 'director']);
    $director = User::factory()->create(['name' => 'nonce_nick']);

    $desk = Livewire::actingAs($admin)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->call('addDirector')
        ->assertHasErrors(['directorId']);

    expect($tournament->directors()->count())->toBe(0)
        ->and(fn () => $desk->set('directorId', 'nonce_nick'))->toThrow(TypeError::class);

    $desk->set('directorId', $director->id)
        ->call('addDirector')
        ->assertHasNoErrors()
        ->assertSet('directorId', null);

    expect($tournament->directors()->pluck('users.id')->all())->toBe([$director->id]);
});

test('the pages render the picker with the organizer and the added directors excluded', function () {
    $organizer = User::factory()->create(['name' => 'hodlqueen']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament = Tournament::factory()->create(['results_mode' => 'director', 'created_by_id' => $organizer->id]);
    $director = User::factory()->create();
    $tournament->directors()->attach($director->id, ['added_by_id' => $organizer->id]);

    Livewire::actingAs($organizer)->test('pages::tournaments.director', ['tournament' => $tournament])
        ->assertSeeHtml('role="combobox"')
        ->assertSeeHtml('data-exclude="'.e(json_encode([$organizer->id, $director->id])).'"');
});

/** A valid key that no player here has. */
function pickerStrangerKey(): string
{
    return (new TestSigner)->pubkey;
}

test('strict mode is unchanged: no key in the rows and no row for an npub without an account', function () {
    $caller = User::factory()->create();
    $player = User::factory()->create(['name' => 'nonce_nick']);

    expect(array_keys(pickerSearch($caller, ['q' => 'nonce'])[0]))->toBe(['id', 'name', 'npub', 'avatar', 'fallback'])
        ->and(pickerSearch($caller, ['q' => NostrKeys::hexToNpub(pickerStrangerKey())]))->toBe([])
        ->and(array_column(pickerSearch($caller, ['q' => $player->npub]), 'id'))->toBe([$player->id]);
});

test('allow-npub offers a valid npub without an account as itself, and a player here with their key', function () {
    $caller = User::factory()->create();
    $player = User::factory()->create(['name' => 'nonce_nick']);
    $stranger = pickerStrangerKey();
    $npub = NostrKeys::hexToNpub($stranger);

    expect(pickerSearch($caller, ['q' => $npub, 'keys' => 1]))->toBe([[
        'id' => null,
        'name' => 'npub1…'.Str::substr($npub, -4),
        'npub' => '',
        'avatar' => route('avatars.generated', ['pubkey' => $stranger, 'v' => 1]),
        'fallback' => route('avatars.generated', ['pubkey' => $stranger, 'v' => 1]),
        'key' => $stranger,
        'unregistered' => true,
    ]])
        ->and(array_column(pickerSearch($caller, ['q' => $stranger, 'keys' => 1]), 'key'))->toBe([$stranger])
        ->and(array_column(pickerSearch($caller, ['q' => 'https://example.org/players/'.$npub, 'keys' => 1]), 'key'))->toBe([$stranger])
        ->and(pickerSearch($caller, ['q' => 'nonce', 'keys' => 1])[0])->toMatchArray(['id' => $player->id, 'key' => $player->pubkey, 'unregistered' => false])
        // An excluded player's key is not offered as a stranger either.
        ->and(pickerSearch($caller, ['q' => $player->npub, 'keys' => 1, 'exclude' => [$player->id]]))->toBe([]);
});

test('allow-npub refuses a name that matches nobody and an invalid npub', function () {
    $caller = User::factory()->create();
    $npub = NostrKeys::hexToNpub(pickerStrangerKey());
    $broken = Str::substr($npub, 0, -1).(Str::substr($npub, -1) === 'q' ? 'p' : 'q');

    expect(pickerSearch($caller, ['q' => 'Nobody Here', 'keys' => 1]))->toBe([])
        ->and(pickerSearch($caller, ['q' => $broken, 'keys' => 1]))->toBe([])
        ->and(pickerSearch($caller, ['q' => str_repeat('g', 64), 'keys' => 1]))->toBe([]);
});

test('the admins page takes a picked key, with or without an account, and refuses names and broken keys', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    User::factory()->create(['name' => 'nonce_nick']);
    $stranger = pickerStrangerKey();
    $broken = Str::substr(NostrKeys::hexToNpub($stranger), 0, -1).'x';

    Livewire::actingAs($admin)->test('pages::admin.admins')
        ->call('add')
        ->assertHasErrors(['key' => __('Pick a player from the suggestions or paste a full npub.')])
        ->set('key', 'nonce_nick')->call('add')->assertHasErrors(['key'])
        ->set('key', $broken)->call('add')->assertHasErrors(['key'])
        ->set('key', $stranger)->call('add')->assertHasNoErrors()->assertSet('key', null);

    expect(Admin::query()->orderBy('id')->pluck('pubkey')->all())->toBe([$admin->pubkey, $stranger]);
});

test('the organizers take a picked key, with or without an account, and refuse names and broken keys', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    User::factory()->create(['name' => 'nonce_nick']);
    $stranger = pickerStrangerKey();

    Livewire::actingAs($admin)->test('pages::admin.tournaments')
        ->call('addOrganizer')
        ->assertHasErrors(['organizerKey' => __('Pick a player from the suggestions or paste a full npub.')])
        ->set('organizerKey', 'nonce_nick')->call('addOrganizer')->assertHasErrors(['organizerKey'])
        ->set('organizerKey', str_repeat('g', 64))->call('addOrganizer')->assertHasErrors(['organizerKey'])
        ->set('organizerKey', $stranger)->call('addOrganizer')->assertHasNoErrors()->assertSet('organizerKey', null);

    expect(TournamentOrganizer::query()->pluck('pubkey')->all())->toBe([$stranger]);
});

test('the clan invite takes an npub without an account (a stub player) and refuses a loose name', function () {
    Queue::fake();
    $service = app(ClanService::class);
    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $draft = new ClanDraft('Laser Eyes', 'LSR');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));
    $queen = User::factory()->create(['name' => 'queen_q']);
    $stranger = pickerStrangerKey();

    $page = Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->set('player', 'queen_q')
        ->call('prepareInvite')
        ->assertHasErrors(['player' => __('Pick a player from the suggestions or paste a full npub.')]);

    expect(User::query()->where('pubkey', $stranger)->exists())->toBeFalse();

    $page->set('player', $stranger);
    $templates = $page->call('prepareInvite')->assertHasNoErrors()->effects['returns'][0];
    $page->call('invite', json_encode($signer->signTemplates($templates)))->assertHasNoErrors()->assertSet('player', null);

    $invitee = User::query()->where('pubkey', $stranger)->sole();

    expect($invitee->npub)->toBe(NostrKeys::hexToNpub($stranger))
        ->and(ClanInvite::query()->where('invitee_id', $invitee->id)->exists())->toBeTrue()
        ->and(ClanInvite::query()->where('invitee_id', $queen->id)->exists())->toBeFalse();
});

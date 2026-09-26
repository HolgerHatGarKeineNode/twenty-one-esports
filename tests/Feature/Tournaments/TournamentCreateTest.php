<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Creating tournaments (P8a)
|--------------------------------------------------------------------------
|
| Admins and the organizers an admin unlocked create tournaments with the
| format chooser; guests and members are refused. The chooser recommends by
| TOURNAMENT-FORMATS.md, and creating stores a draft with the chosen format,
| its options, the time and the results mode with its named directors.
|
*/

function tournamentAdmin(): User
{
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function tournamentOrganizer(): User
{
    $organizer = User::factory()->create(['name' => 'hodlqueen']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);

    return $organizer;
}

test('guests are sent to the login, members are refused', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test('pages::admin.tournament-create')->assertForbidden();
})->with(['admin.tournaments', 'admin.tournaments.create']);

test('admins and organizers open the list and the create page', function (string $who) {
    $user = $who === 'admin' ? tournamentAdmin() : tournamentOrganizer();

    $this->actingAs($user)->get(route('admin.tournaments'))->assertOk();
    $this->actingAs($user)->get(route('admin.tournaments.create'))->assertOk()
        ->assertSee('data-recommended="swiss"', false)
        ->assertSee(__('Pick a format'));
})->with(['admin', 'organizer']);

test('a Livewire roundtrip of the chooser stays clean', function () {
    Livewire::actingAs(tournamentOrganizer())->test('pages::admin.tournament-create')
        ->call('$refresh')->assertOk()
        ->call('stepPlayers', 1)->assertOk()
        ->call('pickGame', 'rl3')->assertOk();
});

test('12 blitz players in one evening: Swiss with 6 rounds is recommended and created', function () {
    $organizer = tournamentOrganizer();

    // The chooser is rendered on mount; later requests of its island are not
    // island requests in a Livewire test, so the HTML is read on the first render.
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->assertSee('data-recommended="swiss"', false)
        ->assertSee('about 1 h 39 min')
        // Round Robin is 4 min over the 3 hours.
        ->assertSee('4 min too long')
        ->set('name', 'Blitz Night Munich')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.tournaments'));

    $tournament = Tournament::query()->sole();

    expect($tournament->format)->toBe(TournamentFormat::Swiss)
        ->and($tournament->formatOptions()->swissRounds)->toBe(6)
        ->and($tournament->capacity)->toBe(12)
        ->and($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->results_mode)->toBe(TournamentResultsMode::Players)
        ->and($tournament->created_by_id)->toBe($organizer->id)
        ->and([$tournament->game, $tournament->mode, $tournament->time_window, $tournament->on_site])->toBe(['chess', 'blitz', 180, false])
        ->and($tournament->plannedDuration())->toEqual(99);
});

test('Rocket League 3v3 recommends a format with a final and disables free for all with its reason', function () {
    $page = Livewire::actingAs(tournamentAdmin())->test('pages::admin.tournament-create')
        ->assertSee('Chess is always one player against one.')
        ->call('pickGame', 'rl3');
    $evaluation = $page->instance()->evaluation;

    expect($evaluation->recommended)->toBe(TournamentFormat::SingleElimination)
        ->and($page->instance()->format)->toBe(TournamentFormat::SingleElimination)
        ->and($evaluation->row(TournamentFormat::FreeForAll)->reason)->toBe('Needs 3 or more players in one match. Rocket League is always one team against one.')
        ->and($page->get('window'))->toBe(180);
});

test('a picked format, its options and the organizer\'s times are stored', function () {
    Livewire::actingAs(tournamentAdmin())->test('pages::admin.tournament-create')
        ->set('name', 'Halving Cup')
        ->call('pickGame', 'rl2')
        ->set('players', '8')
        ->call('select', 'double-elimination')
        ->call('option', 'grandFinal', 'single')
        // Bo7 is no series the game allows (P8b DoD gate): refused, the default stays.
        ->call('pickFinalBestOf', 7)
        ->call('pickBestOf', 5)
        ->set('setup', '10')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect($tournament->format)->toBe(TournamentFormat::DoubleElimination)
        ->and($tournament->formatOptions()->grandFinal)->toBe('single')
        ->and($tournament->formatOptions()->finalBestOf)->toBe(5)
        ->and($tournament->formatOptions()->bestOf)->toBe(5)
        ->and($tournament->times)->toBe(['setup' => 10])
        ->and($tournament->profile()->setup)->toBe(10.0);
});

test('a disabled format cannot be picked', function () {
    Livewire::actingAs(tournamentAdmin())->test('pages::admin.tournament-create')
        ->set('name', 'Party')
        ->call('select', 'free-for-all')
        ->call('create');

    expect(Tournament::query()->sole()->format)->toBe(TournamentFormat::Swiss);
});

test('on site preselects the director mode; named directors are stored', function () {
    $director = User::factory()->create(['name' => 'nonce_nick']);

    Livewire::actingAs(tournamentOrganizer())->test('pages::admin.tournament-create')
        ->set('name', 'Blitz Night Munich')
        ->call('pickWhere', 'site')
        ->assertSet('resultsMode', 'director')
        ->set('directorName', 'Nobody Here')
        ->call('addDirector')
        ->assertSet('directorError', __('No player with this name.'))
        ->set('directorName', 'NONCE_NICK')
        ->call('addDirector')
        ->assertSet('directorIds', [$director->id])
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect($tournament->results_mode)->toBe(TournamentResultsMode::Director)
        ->and($tournament->on_site)->toBeTrue()
        ->and($tournament->stations)->toBe(6)
        ->and($tournament->directors->pluck('id')->all())->toBe([$director->id])
        ->and($tournament->isDirectedBy($director))->toBeTrue()
        ->and($tournament->isDirectedBy($tournament->creator))->toBeTrue()
        ->and(Gate::forUser($director)->allows('direct-tournament', $tournament))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('direct-tournament', $tournament))->toBeFalse();
});

test('switching back to players drops the named directors', function () {
    $director = User::factory()->create(['name' => 'nonce_nick']);

    Livewire::actingAs(tournamentAdmin())->test('pages::admin.tournament-create')
        ->set('name', 'Online Blitz')
        ->call('pickResultsMode', 'director')
        ->set('directorName', 'nonce_nick')
        ->call('addDirector')
        ->call('pickResultsMode', 'players')
        ->call('create');

    expect(Tournament::query()->sole()->directors)->toHaveCount(0);
});

test('a name, a start in the future and a valid count are required', function () {
    Livewire::actingAs(tournamentAdmin())->test('pages::admin.tournament-create')
        ->set('date', now()->subDay()->format('Y-m-d'))
        ->call('create')
        ->assertHasErrors(['name'])
        ->set('name', 'Too late')
        ->call('create')
        ->assertHasErrors(['date'])
        ->set('date', now()->addDay()->format('Y-m-d'))
        ->set('players', 'many')
        ->call('create')
        ->assertHasErrors(['players']);

    expect(Tournament::query()->count())->toBe(0);
});

test('an organizer lists only their own tournaments and never manages the organizers', function () {
    $organizer = tournamentOrganizer();
    Tournament::factory()->create(['name' => 'Mine', 'created_by_id' => $organizer->id]);
    Tournament::factory()->create(['name' => 'Someone else\'s']);

    $this->actingAs($organizer)->get(route('admin.tournaments'))
        ->assertOk()->assertSee('Mine')->assertDontSee('Someone else&#039;s', false)->assertDontSee(__('Add organizer'));

    Livewire::actingAs($organizer)->test('pages::admin.tournaments')
        ->set('organizerKey', User::factory()->create()->npub)
        ->call('addOrganizer')
        ->assertForbidden();
});

test('an admin unlocks and removes an organizer, who can then create tournaments', function () {
    $admin = tournamentAdmin();
    $player = User::factory()->create();

    expect(Gate::forUser($player)->allows('create-tournaments'))->toBeFalse();

    Livewire::actingAs($admin)->test('pages::admin.tournaments')
        ->set('organizerKey', $player->npub)
        ->call('addOrganizer')
        ->assertHasNoErrors()
        ->set('organizerKey', $player->npub)
        ->call('addOrganizer')
        ->assertHasErrors(['organizerKey']);

    expect(Gate::forUser($player)->allows('create-tournaments'))->toBeTrue();

    Livewire::actingAs($admin)->test('pages::admin.tournaments')
        ->call('removeOrganizer', TournamentOrganizer::query()->sole()->id);

    expect(Gate::forUser($player->refresh())->allows('create-tournaments'))->toBeFalse();
});

test('a draft is shown to its creator and admins only; a published tournament to everyone', function () {
    $organizer = tournamentOrganizer();
    $draft = Tournament::factory()->create(['created_by_id' => $organizer->id]);
    $open = Tournament::factory()->signup()->create();

    $this->get(route('tournaments.show', $draft))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $draft))->assertNotFound();
    $this->actingAs($organizer)->get(route('tournaments.show', $draft))->assertOk()->assertSee($draft->name);
    $this->actingAs(tournamentAdmin())->get(route('tournaments.show', $draft))->assertOk();

    auth()->logout();
    // No stored round count: the default, log2 12 = 4 rounds of 14 min and 3 breaks.
    $this->get(route('tournaments.show', $open))->assertOk()->assertSee('about 1 h 5 min');
});

test('an organizer finds their tournaments in the header menu, a member does not', function () {
    $this->actingAs(tournamentOrganizer())->get(route('home'))->assertSee(route('admin.tournaments'));
    $this->actingAs(User::factory()->create())->get(route('home'))->assertDontSee(route('admin.tournaments'));
});

test('an organizer manages their own tournaments only, an admin all of them', function () {
    $organizer = tournamentOrganizer();
    $own = Tournament::factory()->create(['created_by_id' => $organizer->id]);
    $other = Tournament::factory()->create();

    expect(Gate::forUser($organizer)->allows('manage-tournament', $own))->toBeTrue()
        ->and(Gate::forUser($organizer)->allows('manage-tournament', $other))->toBeFalse()
        ->and(Gate::forUser(tournamentAdmin())->allows('manage-tournament', $other))->toBeTrue();
});

<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Payouts\PayoutPlan;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Series\Ladders;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\LobbySwitch;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Lobby tournaments (plan "AoE2 und Trackmania", P10)
|--------------------------------------------------------------------------
|
| Age of Empires II tournaments are one lobby match: the draw splits every
| entry evenly into lobbies of at most 8, each lobby is one diplomacy game
| with settings fixed for its player count, nobody advances. Place 1 can be
| shared, a player reports the places with the end screen, a director
| confirms; a shared place 1 shares its prizes equally.
|
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/**
 * A running Age of Empires II lobby tournament of `$n` players (seed 1 strongest), bracket stored and synced.
 */
function runningLobby(int $n, TournamentResultsMode $mode = TournamentResultsMode::Players): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => $n, 'results_mode' => $mode, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'slug' => 'aoe-lobby-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
        'ladder_address' => Ladders::address('age-of-empires-2', '1v1'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The lobbies of a tournament, by position, with their slots. */
function lobbiesOf(Tournament $tournament)
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->orderBy('position')->get();
}

/** The places of a lobby in slot order as participant id => place. */
function lobbyPlaces(TournamentMatch $match, array $places): array
{
    return $match->slots->mapWithKeys(fn ($slot, int $index): array => [$slot->tournament_participant_id => $places[$index]])->all();
}

test('the draw splits 3 to 40 entries evenly into lobbies of at most 8, in one round, nobody advances', function (int $n, array $sizes) {
    $tournament = runningLobby($n);
    $lobbies = lobbiesOf($tournament);

    expect($lobbies->map(fn (TournamentMatch $match): int => $match->slots->count())->all())->toBe($sizes)
        ->and($lobbies->pluck('bracket')->unique()->all())->toBe(['heat'])
        ->and($lobbies->pluck('status')->unique()->all())->toBe(['ready'])
        // One round: every entry plays exactly one lobby, and no lobby waits for another.
        ->and($lobbies->flatMap(fn (TournamentMatch $match) => $match->slots->pluck('tournament_participant_id'))->unique()->count())->toBe($n)
        ->and($lobbies->pluck('tournament_round_id')->unique()->count())->toBe(1)
        ->and(Lobbies::split('age-of-empires-2', $n))->toBe($sizes)
        // A lobby is played in the game's lobby: no series starts.
        ->and(SeriesMatch::query()->count())->toBe(0);
})->with([
    '3' => [3, [3]],
    '8' => [8, [8]],
    '9' => [9, [5, 4]],
    '17' => [17, [6, 6, 5]],
    '40' => [40, [8, 8, 8, 8, 8]],
]);

test('each lobby\'s settings follow its players and are fixed at the draw with a league name and password', function () {
    $nine = lobbiesOf(runningLobby(9));
    $seventeen = lobbiesOf(runningLobby(17));
    $three = lobbiesOf(runningLobby(3));
    $eight = lobbiesOf(runningLobby(8));
    $lobby = $nine[0]->lobby;

    expect($nine->map(fn (TournamentMatch $match): string => $match->lobby['map_size'])->all())->toBe(['Normal', 'Medium'])
        ->and($seventeen->map(fn (TournamentMatch $match): string => $match->lobby['map_size'])->all())->toBe(['Normal', 'Normal', 'Normal'])
        ->and($three[0]->lobby['map_size'])->toBe('Small')
        ->and($eight[0]->lobby['map_size'])->toBe('Large')
        ->and([Lobbies::mapSize('age-of-empires-2', 2), Lobbies::mapSize('age-of-empires-2', 4), Lobbies::mapSize('age-of-empires-2', 6), Lobbies::mapSize('age-of-empires-2', 7)])->toBe(['Tiny', 'Medium', 'Normal', 'Large'])
        ->and($lobby)->toMatchArray(['players' => 5, 'map' => 'Arabia', 'civilizations' => 'free', 'population' => 200, 'lock_teams' => false, 'allied_victory' => true,
            'victory' => 'time-limit', 'time_limit_minutes' => 120, 'spectator_delay_minutes' => 2, 'restarts' => 1, 'restart_minutes' => 5])
        ->and($lobby['name'])->toBe('e21-t'.$nine[0]->tournament_id.'-l1')
        ->and($nine[0]->lobby_password)->toMatch('/^[a-z2-9]{6}$/')
        ->and($nine[0]->lobby_password)->not->toBe($nine[1]->lobby_password)
        // The players report until the time limit and an hour after it (set-up 15 min, 2 h, 60 min).
        ->and(LobbyResults::reportBy($nine[0])->getTimestamp())->toBe(now()->addMinutes(195)->getTimestamp());
});

test('creating an Age of Empires II tournament offers only the lobby format and refuses the others', function () {
    $organizer = organizer();
    $page = Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Diplomacy Night')
        ->call('pickGame', 'age-of-empires-2/1v1')
        ->set('players', '9');
    $evaluation = $page->instance()->evaluation;

    expect(collect($evaluation->rows)->filter->enabled->map->format->values()->all())->toBe([TournamentFormat::FreeForAll])
        ->and($evaluation->recommended)->toBe(TournamentFormat::FreeForAll)
        ->and($evaluation->row(TournamentFormat::DoubleElimination)->reason)->toBe('This game plays its tournaments as one lobby match of up to 8 players.');

    $page->call('select', 'double-elimination')->call('create')->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    // A disabled format is never stored: the selection falls back to the recommended lobby format.
    expect($tournament->format)->toBe(TournamentFormat::FreeForAll)
        ->and($tournament->formatOptions()->lobbyMinutes)->toBe(135)
        ->and($tournament->formatOptions()->heatSize)->toBe(8)
        ->and($tournament->plannedDuration())->toEqual(135);

    // Two players are too few for a lobby, and a team mode has no lobby format at all.
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Too small')->call('pickGame', 'age-of-empires-2/1v1')->set('players', '2')
        ->call('create')->assertHasErrors('format');
    $teams = Livewire::actingAs($organizer)->test('pages::admin.tournament-create')->call('pickGame', 'age-of-empires-2/2v2');

    expect(collect($teams->instance()->evaluation->rows)->filter->enabled->all())->toBe([])
        ->and(Tournament::query()->count())->toBe(1);
});

test('a casual Age of Empires II cup is drawn as lobbies, and its players hear their lobby', function () {
    $hash = hash('sha256', 'block 900001');
    $tip = 900000;
    Http::fake(function ($request) use (&$tip, $hash) {
        return match (true) {
            str_ends_with($request->url(), '/blocks/tip/height') => Http::response((string) $tip),
            str_ends_with($request->url(), '/block-height/900001') => Http::response($hash),
            str_ends_with($request->url(), '/block/'.$hash) => Http::response(['timestamp' => now()->addMinutes(10)->getTimestamp()]),
            default => Http::response('', 404),
        };
    });
    config(['esports.casual_cups.enabled' => ['age-of-empires-2']]);
    $cup = app(CasualCups::class)->ensure('age-of-empires-2', 'eu');

    foreach (range(1, 9) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($cup->refresh(), $player, $signer);
    }

    // Nine of 40 places: the cup never grows, and closes at its start.
    expect(app(CasualCups::class)->grow($cup->refresh(), 9))->toBeFalse()
        ->and($cup->refresh()->capacity)->toBe(40);

    $this->travelTo($cup->signup_closes_at->addMinute());
    cupTick();
    $tip = 900006;
    expect(app(TournamentDraws::class)->resolve($cup->refresh()))->toBeTrue();
    cupTick();

    $lobbies = lobbiesOf($cup->refresh());

    expect($cup->status)->toBe(TournamentStatus::Running)
        ->and($cup->format)->toBe(TournamentFormat::FreeForAll)
        ->and($lobbies->map(fn (TournamentMatch $match): int => $match->slots->count())->all())->toBe([5, 4])
        ->and($lobbies->map(fn (TournamentMatch $match): string => $match->lobby['map_size'])->all())->toBe(['Normal', 'Medium'])
        // Its one round opened with the lobby's time (set-up, the time limit, the report hour), not a 48 h window.
        ->and($lobbies[0]->round->window_ends_at->getTimestamp())->toBe(now()->addMinutes(195)->getTimestamp())
        ->and(SeriesMatch::query()->count())->toBe(0)
        ->and(DB::table('notifications')->pluck('data')->filter(fn (string $data): bool => (json_decode($data, true)['title'] ?? '') === 'AoE2 Casual Cup EU #1: your lobby is open')->count())->toBe(9);
});

test('a shared place 1 is reported with the end screen, confirmed by a director and pays the pot in equal shares', function () {
    $tournament = runningLobby(4);
    $lobby = lobbiesOf($tournament)->sole();
    $reporter = User::query()->find($lobby->slots[2]->participant->user_id);
    $director = $tournament->creator;
    $shot = UploadedFile::fake()->image('end-screen.png', 1280, 720);

    app(LobbyResults::class)->report($lobby, $reporter, lobbyPlaces($lobby, [1, 1, 3, 4]), $shot);

    expect($lobby->refresh()->lobby_report['places'])->toBe(lobbyPlaces($lobby, [1, 1, 3, 4]))
        ->and(Storage::disk('local')->exists($lobby->lobby_report['screenshot']))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    // A place 2 after a shared place 1 does not exist; a player of the lobby cannot confirm.
    expect(fn () => app(LobbyResults::class)->enter($lobby, $director, lobbyPlaces($lobby, [1, 1, 2, 3])))->toThrow(TournamentRuleViolation::class)
        ->and(fn () => app(LobbyResults::class)->confirm($lobby, $reporter, LobbyResults::reportIdentity($lobby->lobby_report)))->toThrow(TournamentRuleViolation::class);

    // The end screen is the directors' only: a player of the lobby is refused.
    $this->actingAs($reporter)->get(route('tournaments.lobby-screenshot', [$tournament, $lobby]))->assertForbidden();
    $this->actingAs($director)->get(route('tournaments.lobby-screenshot', [$tournament, $lobby]))->assertOk();

    app(LobbyResults::class)->confirm($lobby, $director, LobbyResults::reportIdentity($lobby->refresh()->lobby_report));

    $ids = $lobby->slots->pluck('tournament_participant_id')->all();
    $plan = app(PayoutPlan::class)->compute($tournament->refresh(), 1_000);
    $paid = collect($plan['rows'])->mapWithKeys(fn (array $row): array => [$row['participant']->id => $row['amount']])->all();

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($lobby->refresh()->result['ranks'])->toBe([1, 1, 3, 4])
        ->and($lobby->result['label'])->toBe('Shared place 1: Player 1, Player 2')
        ->and($lobby->lobby_report)->toBeNull()
        ->and(app(TournamentPlacements::class)->of($tournament))->toBe([
            ['place' => 1, 'participants' => [$ids[0], $ids[1]]], ['place' => 3, 'participants' => [$ids[2]]], ['place' => 4, 'participants' => [$ids[3]]],
        ])
        // 50/30/20: the two on place 1 share places 1 and 2 (80 %) equally, place 3 keeps its 20 %.
        ->and($paid)->toBe([$ids[0] => 400, $ids[1] => 400, $ids[2] => 200])
        ->and($plan['remainder'])->toBe(0);
});

test('with several lobbies every lobby\'s winners share place 1, and the next places follow across lobbies', function () {
    $tournament = runningLobby(9);
    [$first, $second] = lobbiesOf($tournament)->all();
    $director = $tournament->creator;

    app(LobbyResults::class)->enter($first, $director, lobbyPlaces($first, [1, 1, 3, 4, 5]));
    expect($tournament->refresh()->status)->toBe(TournamentStatus::Running);
    app(LobbyResults::class)->enter($second, $director, lobbyPlaces($second, [1, 2, 3, 4]));

    $a = $first->slots->pluck('tournament_participant_id')->all();
    $b = $second->slots->pluck('tournament_participant_id')->all();
    $places = app(TournamentPlacements::class)->of($tournament->refresh());

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($places[0])->toBe(['place' => 1, 'participants' => collect([$a[0], $a[1], $b[0]])->sort()->values()->all()])
        ->and($places[1])->toBe(['place' => 4, 'participants' => [$b[1]]])
        ->and(app(TournamentChampion::class)->of($tournament))->toBeNull();
});

test('a lobby nobody reports never holds the tournament up: after the deadline a director decides, a disqualification forfeits, an abort ends it', function () {
    $tournament = runningLobby(4);
    $lobby = lobbiesOf($tournament)->sole();
    $player = User::query()->find($lobby->slots[0]->participant->user_id);
    $director = $tournament->creator;

    $this->travel(196)->minutes();

    expect(fn () => app(LobbyResults::class)->report($lobby, $player, lobbyPlaces($lobby, [1, 2, 3, 4]), UploadedFile::fake()->image('late.png')))
        ->toThrow(TournamentRuleViolation::class, 'The time to report this lobby is over.');

    // The players' report path is closed; disqualifying all but one decides the lobby for the one left.
    $control = app(TournamentControl::class);
    foreach ([1, 2, 3] as $slot) {
        $control->disqualify($tournament->refresh(), $director, $lobby->slots[$slot]->tournament_participant_id, 'Never joined the lobby.');
    }

    expect($lobby->refresh()->result)->toMatchArray(['ranks' => [1, 2, 3, 4], 'by' => 'league', 'decided' => 'withdrawn'])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(TournamentPlacements::class)->of($tournament))->toBe([['place' => 1, 'participants' => [$lobby->slots[0]->tournament_participant_id]]]);

    // A second lobby tournament nobody reports: a director enters the places after the deadline; a third is aborted.
    $late = runningLobby(3);
    $this->travel(200)->minutes();
    app(LobbyResults::class)->enter(lobbiesOf($late)->sole(), $late->creator, lobbyPlaces(lobbiesOf($late)->sole(), [1, 2, 3]));
    $aborted = runningLobby(3);
    $control->abort($aborted, $aborted->creator, 'The lobby never filled.');

    expect($late->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($aborted->refresh()->status)->toBe(TournamentStatus::Cancelled);
});

test('the lobby card shows the settings to everyone, and the name and password only to its players and the directors', function () {
    $tournament = runningLobby(9);
    [$first, $second] = lobbiesOf($tournament)->all();
    $player = User::query()->find($first->slots[0]->participant->user_id);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="lobby-card"', false)->assertSee('Map size')->assertSee('Normal')->assertSee('Medium')
        ->assertSee('Allied Victory')->assertSee('Time Limit, 2 hours')
        ->assertDontSee($first->lobby_password)->assertDontSee('data-test="lobby-access"', false);

    Livewire::actingAs($player)->test('tournament-lobbies', ['tournament' => $tournament])
        ->assertSee($first->lobby['name'])->assertSee($first->lobby_password)
        ->assertDontSee($second->lobby_password)
        ->call('$refresh')->assertOk();

    $this->actingAs($player)->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'de']))->assertOk()
        ->assertSee('Kartengröße')->assertSee('Bündnissieg')->assertSee('Zeitlimit, 2 Stunden');
});

test('a lobby result is stored as its kind and names and worded in the reader\'s language: confirmed in English, read in German', function () {
    $tournament = runningLobby(4);
    $lobby = lobbiesOf($tournament)->sole();
    app()->setLocale('en');
    app(LobbyResults::class)->enter($lobby, $tournament->creator, lobbyPlaces($lobby, [1, 1, 3, 4]));

    expect($lobby->refresh()->result)->toMatchArray(['lobby_label' => 'shared', 'winner_names' => ['Player 1', 'Player 2']]);

    $this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'de']))->assertOk()
        ->assertSee('Geteilter Platz 1: Player 1, Player 2')->assertDontSee('Shared place 1: Player 1');
    $this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'en']))->assertOk()
        ->assertSee('Shared place 1: Player 1, Player 2');
});

test('a finished lobby tournament names its shared 1st place across all lobbies once, on its page', function () {
    $tournament = runningLobby(9);
    [$first, $second] = lobbiesOf($tournament)->all();
    app(LobbyResults::class)->enter($first, $tournament->creator, lobbyPlaces($first, [1, 1, 3, 4, 5]));
    app(LobbyResults::class)->enter($second, $tournament->creator, lobbyPlaces($second, [1, 2, 3, 4]));
    $names = collect([$first->slots[0], $first->slots[1], $second->slots[0]])->sortBy('tournament_participant_id')->map(fn ($slot): string => (string) $slot->participant->name)->implode(', ');

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="tournament-shared-first"', false)
        ->assertSeeInOrder(['Shared 1st place: '.$names])
        ->assertSee('Finished. Shared 1st place: '.$names.'.')
        ->assertDontSee('data-test="tournament-winner"', false);
    // A single winner keeps the winner card.
    $single = runningLobby(4);
    app(LobbyResults::class)->enter(lobbiesOf($single)->sole(), $single->creator, lobbyPlaces(lobbiesOf($single)->sole(), [1, 2, 3, 4]));
    $this->get(route('tournaments.show', $single))->assertOk()
        ->assertSee('data-test="tournament-winner"', false)->assertDontSee('data-test="tournament-shared-first"', false);
});

test('the format chooser describes the lobby for Age of Empires II and shows no series deadlines; a series game keeps both', function () {
    $organizer = organizer();
    $draft = fn (string $game): Tournament => Tournament::factory()->create(['game' => $game, 'mode' => '1v1', 'status' => TournamentStatus::Draft, 'capacity' => 9, 'created_by_id' => $organizer->id,
        'format' => $game === 'age-of-empires-2' ? TournamentFormat::FreeForAll : TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults(GameProfile::for($game, '1v1'))->toArray()]);

    // The chooser is an island: a Livewire test reads it on the first render (tests/Feature/Tournaments/TournamentCreateTest.php).
    Livewire::actingAs($organizer)->test('pages::admin.tournament-edit', ['tournament' => $draft('age-of-empires-2')])
        ->assertSee('One lobby match for everyone, all lobbies at the same time: up to 2 hours of play with its Time Limit, about 2 h 15 min with filling the lobby. No final, no opponent to find.')
        ->assertSee('Every lobby plays at the same time, 2 h 15 min with filling the lobby and the Time Limit.')
        ->assertDontSee('finding the opponent')->assertDontSee('Ends with a final')
        ->assertSee('data-test="tournament-deadlines-lobby"', false)
        ->assertDontSee('data-test="deadline-noshow_minutes"', false)->assertDontSee('data-test="deadline-response_minutes"', false);

    Livewire::actingAs($organizer)->test('pages::admin.tournament-edit', ['tournament' => $draft('rocket-league')])
        ->assertSee('data-test="deadline-noshow_minutes"', false)->assertSee('finding the opponent')
        ->assertDontSee('One lobby match for everyone')->assertDontSee('data-test="tournament-deadlines-lobby"', false);
});

test('the switch warns about a team-mode tournament of a lobby game once, however often it runs', function () {
    Log::spy();
    openTournament(['game' => 'age-of-empires-2', 'mode' => '2v2', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults(GameProfile::for('age-of-empires-2', '2v2'))->toArray(), 'capacity' => 8]);

    foreach (range(1, 3) as $ignored) {
        app(LobbySwitch::class)->run();
    }

    Log::shouldHaveReceived('warning')->with('Lobby switch: a team-mode tournament of a lobby game is left as it is', Mockery::any())->once();
});

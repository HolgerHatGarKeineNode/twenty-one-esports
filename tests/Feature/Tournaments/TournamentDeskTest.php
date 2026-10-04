<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentDesk;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The tournament desk (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "pro Turnier einen Turnierleiter-Chat auch als ephemeral Chat Nostr-Gruppe,
| damit alle Spieler mit der Turnierleitung chatten können, für Probleme oder
| Bugs oder sonstiges … auch prominent verfügbar für die Spieler": one private
| NIP-17 group per tournament, its members the active entrants and whoever
| runs the tournament (creator, directors, admins), open from sign-up until a
| day after the end. Only a member ever gets the group's member list.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** @return list<string> */
function deskPubkeys(Tournament $tournament): array
{
    return array_column(TournamentDesk::members($tournament->refresh()), 'pubkey');
}

/** @return list<string> the pubkeys the desk marks as the tournament direction */
function deskManagerPubkeys(Tournament $tournament): array
{
    return array_column(array_filter(TournamentDesk::members($tournament->refresh()), fn (array $member): bool => $member['manager']), 'pubkey');
}

test('the members are the active entrants, solo and lineup players, plus the creator, the directors and the admins', function () {
    $tournament = openTournament(rocketLeague: true);
    [$lineup, $captain, $captainKey] = keyedLineup();
    lineupSignup($tournament, $lineup, $captain, $captainKey);
    $director = User::factory()->create();
    $tournament->directors()->attach($director->id, ['added_by_id' => $tournament->created_by_id]);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $stranger = User::factory()->create();

    $players = array_map(fn ($seat) => $seat->user->pubkey, $lineup->activeSeats());

    expect(deskPubkeys($tournament))->toEqualCanonicalizing([...$players, $tournament->creator->pubkey, $director->pubkey, $admin->pubkey])
        ->and(deskManagerPubkeys($tournament))->toEqualCanonicalizing([$tournament->creator->pubkey, $director->pubkey, $admin->pubkey])
        ->and(deskPubkeys($tournament))->not->toContain($stranger->pubkey)
        ->and(TournamentDesk::isMember($tournament, $players === [] ? $stranger : $lineup->activeSeats()[0]->user))->toBeTrue()
        ->and(TournamentDesk::isMember($tournament, $stranger))->toBeFalse()
        ->and(TournamentDesk::isMember($tournament, null))->toBeFalse();

    // A solo player joins the group with the sign-up.
    $chess = openTournament();
    [$solo, $soloKey] = keyedPlayer();
    expect(deskPubkeys($chess))->not->toContain($solo->pubkey);
    soloSignup($chess, $solo, $soloKey);
    expect(deskPubkeys($chess))->toContain($solo->pubkey);
});

test('a withdrawn player is not a member any more, nor is a removed entry', function () {
    $tournament = openTournament();
    [$anna, $annaKey] = keyedPlayer();
    [$bert, $bertKey] = keyedPlayer();
    soloSignup($tournament, $anna, $annaKey);
    $bertEntry = soloSignup($tournament, $bert, $bertKey);

    expect(deskPubkeys($tournament))->toContain($anna->pubkey, $bert->pubkey);

    $service = app(TournamentSignups::class);
    $service->withdraw($tournament, $anna, $annaKey->signTemplates($service->prepareWithdraw($tournament, $anna)));
    $bertEntry->update(['removed_at' => now()]);

    expect(deskPubkeys($tournament))->not->toContain($anna->pubkey)
        ->and(deskPubkeys($tournament))->not->toContain($bert->pubkey)
        ->and(TournamentDesk::isMember($tournament->refresh(), $anna))->toBeFalse()
        ->and(TournamentDesk::for($tournament, $anna))->toBeNull();
});

test('after the draw the members are the bracket\'s entries, a disqualified one left out', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    $entries = $tournament->participants()->orderBy('id')->get();
    $entries[3]->update(['disqualified_at' => now()]);

    $players = $entries->take(3)->map(fn ($entry) => User::query()->find($entry->user_id)->pubkey)->all();

    expect(deskPubkeys($tournament))->toEqualCanonicalizing([...$players, $tournament->creator->pubkey])
        ->and(deskPubkeys($tournament))->not->toContain(User::query()->find($entries[3]->user_id)->pubkey);
});

test('the desk is open from sign-up until a day after the end, never for a draft or a called-off tournament', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $player = User::query()->find($tournament->participants()->first()->user_id);

    expect(TournamentDesk::for($tournament, $player))->not->toBeNull();

    $tournament->update(['status' => TournamentStatus::Finished]);
    TournamentMatch::query()->where('tournament_id', $tournament->id)->update(['updated_at' => now()->subHours(23)]);
    expect(TournamentDesk::isOpen($tournament->refresh()))->toBeTrue();

    TournamentMatch::query()->where('tournament_id', $tournament->id)->update(['updated_at' => now()->subHours(25)]);
    expect(TournamentDesk::isOpen($tournament->refresh()))->toBeFalse()
        ->and(TournamentDesk::for($tournament, $player))->toBeNull();

    foreach ([TournamentStatus::Draft, TournamentStatus::Cancelled] as $status) {
        $tournament->update(['status' => $status]);
        expect(TournamentDesk::isOpen($tournament->refresh()))->toBeFalse();
    }
});

test('the chat and its member list reach members only: a stranger and a guest get neither', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $player = User::query()->find($tournament->participants()->first()->user_id);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($player)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="desk-chat"', false)
        ->assertSee('data-test="desk-button"', false)
        ->assertSee($admin->pubkey, false);

    // The admin's key appears nowhere else on the page, so its absence shows the member list was not sent.
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $tournament))->assertOk()
        ->assertDontSee('data-test="desk-chat"', false)
        ->assertDontSee('data-test="desk-button"', false)
        ->assertDontSee($admin->pubkey, false);

    auth()->logout();
    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertDontSee('data-test="desk-chat"', false)
        ->assertDontSee($admin->pubkey, false);

    // The admin, a manager, has the desk too.
    $this->actingAs($admin)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('data-test="desk-chat"', false);
});

test('the desk button sits in the "What to do now" hero and survives a Livewire roundtrip', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $player = User::query()->find($tournament->participants()->first()->user_id);

    $this->actingAs($player)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['data-test="now-hero"', 'data-test="desk-button"', '</section>', 'data-test="desk-chat"'], false);

    Livewire::actingAs($player)->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()->assertSee('data-test="desk-button"', false);
});

test('the desk button sits in the champion hero for a day after the end', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Director);
    playOutAsDirector($tournament);
    $player = User::query()->find($tournament->participants()->first()->user_id);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    $this->actingAs($player)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['data-test="champion-hero"', 'data-test="desk-button"', 'data-test="champion-podium"'], false);
});

test('the desk button is on a tournament game\'s banner for its players, not for a spectator', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    $game = ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->orderBy('id')->firstOrFail();

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-banner"', 'data-test="desk-button"', 'data-test="tournament-banner-link"'], false);

    $this->actingAs(User::factory()->create())->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="tournament-banner"', false)
        ->assertDontSee('data-test="desk-button"', false);
});

test('a manager finds the desk on the admin edit page', function () {
    $tournament = openTournament();
    [$solo, $soloKey] = keyedPlayer();
    soloSignup($tournament, $solo, $soloKey);

    $this->actingAs($tournament->creator)->get(route('admin.tournaments.edit', $tournament))->assertOk()
        ->assertSee('data-test="desk-button"', false)
        ->assertSee(route('tournaments.show', $tournament).'#desk', false);
});

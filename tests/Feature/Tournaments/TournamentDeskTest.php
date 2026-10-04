<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\TournamentDesk;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Support\Facades\Http;
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

test('after the draw the members are the bracket\'s entries, a disqualified one kept', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4, TournamentResultsMode::Players);
    $entries = $tournament->participants()->orderBy('id')->get();
    $entries[3]->update(['disqualified_at' => now()]);

    $players = $entries->map(fn ($entry) => User::query()->find($entry->user_id)->pubkey)->all();
    $disqualified = User::query()->find($entries[3]->user_id);

    expect(deskPubkeys($tournament))->toEqualCanonicalizing([...$players, $tournament->creator->pubkey])
        ->and(TournamentDesk::isMember($tournament, $disqualified))->toBeTrue()
        ->and(TournamentDesk::for($tournament, $disqualified))->not->toBeNull();
});

/*
 * Tournament 2, 2026-10-04: the organizer saw a desk message of 11:06 UTC in Amethyst and none of 15:00/15:04. NIP-17
 * ("The set of `pubkey` + `p` tags defines a chat room. If a new `p` tag is added or a current one is removed, a new
 * room is created with a clean message history.") and Amethyst key a group by its pubkey set, so every change of the
 * desk's member list opens a new, empty conversation in every other client. During sign-up that cannot be helped
 * (a new entrant has to join); from the close on the set holds: the draw leaves the solo pool's reserves in, and a
 * disqualification does not take its players out.
 */
test('from the sign-up close to the end the desk keeps one member set: draw, reserves and disqualifications included', function () {
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
    config(['esports.bitcoin.draw_block' => 'next']);

    // RL 3v3: two lineups and seven solo players, so the draw makes two mix teams and leaves one reserve.
    $tournament = openTournament(['capacity' => 5], rocketLeague: true);
    $director = User::factory()->create();
    $tournament->directors()->attach($director->id, ['added_by_id' => $tournament->created_by_id]);
    [$lineupA, $captainA, $signerA] = keyedLineup();
    [$lineupB, $captainB, $signerB] = keyedLineup();
    lineupSignup($tournament, $lineupA, $captainA, $signerA);
    lineupSignup($tournament, $lineupB, $captainB, $signerB);

    $solos = [];

    foreach (range(1, 7) as $ignored) {
        [$player, $signer] = keyedPlayer();
        soloSignup($tournament, $player, $signer);
        $solos[] = $player->pubkey;
    }

    $atClose = deskPubkeys($tournament);
    expect($atClose)->toContain($tournament->creator->pubkey, $director->pubkey, $captainA->pubkey, $captainB->pubkey, ...$solos);

    $this->travel(25)->hours();
    $draws = app(TournamentDraws::class);
    expect($draws->close($tournament->refresh()))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Drawing)
        ->and(deskPubkeys($tournament))->toEqualCanonicalizing($atClose);

    $tip = 900006;
    expect($draws->resolve($tournament))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($draws->mixTeams($tournament, $hash)['reserves'])->toHaveCount(1)
        // The reserve signed up and may still come in: the draw does not open a new room for everybody.
        ->and(deskPubkeys($tournament))->toEqualCanonicalizing($atClose);

    $reserve = User::query()->findOrFail($draws->mixTeams($tournament, $hash)['reserves'][0]);
    expect(TournamentDesk::for($tournament, $reserve))->not->toBeNull();

    // A disqualified mix team and lineup stay in the desk (they may ask the direction why), the room stays one room.
    TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('id')->get()->take(2)
        ->each(fn (TournamentParticipant $entry) => $entry->update(['disqualified_at' => now()]));
    expect(deskPubkeys($tournament))->toEqualCanonicalizing($atClose);

    $tournament->update(['status' => TournamentStatus::Finished]);
    expect(deskPubkeys($tournament))->toEqualCanonicalizing($atClose);
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

/*
 * User, 2026-10-04: "Das mit dem Chat ist schon ok, aber leider versteckt hinter einem Button-Klick, das ist
 * schlechte UX." The desk is drawn open on the page for every member: history, hint and field in the markup with
 * no dialog and no open/close state around them, right under the "What to do now" hero, and during sign-up under
 * the sign-up box instead of the "Questions or a problem?" banner. A guest gets none of it.
 */
test('the desk chat is drawn open for a member, under the hero or under the sign-up box, never for a guest', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $player = User::query()->find($tournament->participants()->first()->user_id);

    $html = $this->actingAs($player)->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeInOrder(['data-test="now-hero"', 'data-test="desk-rail"', 'id="desk"', 'data-test="desk-chat"', 'data-test="desk-messages"', 'data-test="desk-hint"', 'data-test="desk-form"', 'data-test="tournament-hero"'], false)
        ->getContent();

    $panel = substr($html, (int) strpos($html, 'data-test="desk-rail"'));
    $panel = substr($panel, 0, (int) strpos($panel, 'data-test="desk-send"'));
    expect($panel)->not->toContain('role="dialog"')
        ->not->toContain('aria-modal')
        ->not->toContain('x-show="open"')
        ->not->toContain('desk-close')
        ->and($html)->toContain('chat-rail-host');

    // During sign-up there is no "What to do now": the page opens on the tournament's name, the open desk right under
    // the sign-up box (user, 2026-10-04), the old banner gone.
    $open = openTournament();
    [$solo, $soloKey] = keyedPlayer();
    soloSignup($open, $solo, $soloKey);
    $signup = $this->actingAs($solo)->get(route('tournaments.show', $open))->assertOk()
        ->assertSeeInOrder(['data-test="tournament-show"', 'data-test="tournament-hero"', 'id="t-name"', 'data-test="signup-cta"', 'data-test="who-is-in"', 'data-test="desk-rail"', 'data-test="desk-form"'], false)
        ->assertDontSee('data-test="desk-row"', false)
        ->assertDontSee('Questions or a problem?');
    expect(substr_count($signup->getContent(), 'data-test="desk-rail"'))->toBe(1);

    auth()->logout();
    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertDontSee('data-test="desk-rail"', false)
        ->assertDontSee('id="desk"', false)
        ->assertDontSee('chat-rail-host', false);
});

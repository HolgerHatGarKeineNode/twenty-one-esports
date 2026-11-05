<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| The tournament rules form says what the tournament will be
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-04, on the edit page of a Blockli tournament: the time control could be picked twice (the
| format chooser and a section below it, and the lower one won over what the plan was made for); "Jeder trifft
| jeden dreimal" with two games per pairing read "mindestens 9 Spiele" and "Alle spielen 9 Partien" for 18
| games, 6 against each opponent; "Wer Ergebnisse einträgt", the series deadlines and the drawn decider showed
| where they never apply. The form now shows one time control before the draw, counts games, words the results
| choice for a game played here, and shows only the deadlines and rules that reach the chosen game and format.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

function rulesFormAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A Blockli draft as the chooser stores it, in `$format` with these options. */
function rulesFormBlockli(TournamentFormat $format, array $options = [], int $players = 4, string $mode = 'blitz'): Tournament
{
    return Tournament::factory()->create([
        'game' => 'blockli', 'mode' => $mode, 'format' => $format, 'capacity' => $players,
        'options' => FormatOptions::fromArray($options, GameProfile::for('blockli', $mode))->toArray(),
        'status' => TournamentStatus::Draft,
    ]);
}

test('a Blockli table plans and counts the games per pairing chosen: two by default, one on choice', function () {
    $profile = GameProfile::for('blockli', 'blitz');
    $estimator = new Estimator;
    $row = fn (array $options, TournamentFormat $format = TournamentFormat::RoundRobin) => $estimator->evaluate(4, $profile, FormatOptions::fromArray($options, $profile), null, 180)->row($format);

    // Three times everyone, two games a pairing: 9 rounds of 28 min and 8 breaks of 3; 9 pairings, 18 games, 6 against each.
    $two = $row(['iterations' => 3]);
    expect($two->total())->toBe(276.0)->and($two->guaranteed())->toBe(9)->and($two->perMatch)->toBe(2)->and($two->guaranteedGames())->toBe(18);

    // One game a pairing: the rounds are one game long, the counts halve. It was planned at two games before.
    $one = $row(['iterations' => 3, 'legs' => 1]);
    expect($one->total())->toBe(150.0)->and($one->perMatch)->toBe(1)->and($one->guaranteedGames())->toBe(9);

    // Swiss and a knockout always play two games a pairing, whatever the table setting says.
    expect($row(['legs' => 1, 'swissRounds' => 3], TournamentFormat::Swiss)->guaranteedGames())->toBe(6)
        ->and($row(['legs' => 1], TournamentFormat::SingleElimination)->perMatch)->toBe(2);

    // Chess and a series game keep counting matches.
    expect($estimator->evaluate(4, GameProfile::for('chess', 'blitz'), FormatOptions::defaults(GameProfile::for('chess', 'blitz')), null, 180)->row(TournamentFormat::RoundRobin)->perMatch)->toBe(1)
        ->and($estimator->evaluate(8, GameProfile::for('rocket-league', '3v3'), FormatOptions::defaults(GameProfile::for('rocket-league', '3v3')), null, 240)->row(TournamentFormat::RoundRobin)->perMatch)->toBe(1);
});

test('the round robin of a Blockli draft says how many games everyone plays, per pairing and against each opponent', function () {
    $tournament = rulesFormBlockli(TournamentFormat::RoundRobin, ['iterations' => 3]);

    // The chooser is an island: a Livewire test reads it on the first render.
    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('Everyone plays 18 games: 9 pairings of 2 games each, 6 against each opponent.')
        ->assertSee('that makes 6 games against each opponent and 18 games for everyone')
        ->assertDontSee('Everyone plays 9 games.');

    $tournament->forceFill(['options' => [...$tournament->options, 'legs' => 1]])->save();
    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertSee('Everyone plays 9 games.')
        ->assertSee('that makes 3 games against each opponent and 9 games for everyone');
});

test('before the draw the chooser is the one place for the time control; a stale pick of the lower section changes nothing', function () {
    $admin = rulesFormAdmin();
    $tournament = rulesFormBlockli(TournamentFormat::RoundRobin);

    $page = Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertDontSeeHtml('data-test="tournament-mode-switch"')
        ->set('mode', 'correspondence')
        ->call('save')->assertSet('error', '');

    expect($tournament->refresh()->mode)->toBe('blitz');

    // The chooser's pick is what is saved, and what the plan above was made for.
    $page->call('pickGame', 'blockli/rapid')->call('select', 'round-robin')->call('save')->assertSet('error', '');
    expect($tournament->refresh()->mode)->toBe('rapid');

    // From the draw on the chooser is locked and the section switches the time control (BlockliLiveModesTest).
    $tournament->forceFill(['status' => TournamentStatus::Drawing])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertSeeHtml('data-test="tournament-mode-switch"');
});

test('a game played here asks where it is played and shows its first-move deadline only; a series game keeps its reports', function () {
    $admin = rulesFormAdmin();

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::RoundRobin)])
        ->assertSee('Where the games are played')->assertDontSee('Who enters results')
        ->assertSee('Here in the browser')->assertSee('At real boards, directors enter')
        ->assertSeeHtml('data-test="deadline-checkin_minutes"')
        ->assertDontSeeHtml('data-test="deadline-noshow_minutes"')->assertDontSeeHtml('data-test="deadline-report_hours"')->assertDontSeeHtml('data-test="deadline-response_minutes"')
        ->assertDontSeeHtml('data-test="round-clock"');

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => Tournament::factory()->rocketLeague()->create()])
        ->assertSee('Who enters results')->assertSee('Players report results')
        ->assertDontSeeHtml('data-test="deadline-checkin_minutes"')
        ->assertSeeHtml('data-test="deadline-noshow_minutes"')->assertSeeHtml('data-test="deadline-response_minutes"');

    // Directors enter the results: no deadline of the league applies.
    $directed = rulesFormBlockli(TournamentFormat::RoundRobin);
    $directed->forceFill(['results_mode' => TournamentResultsMode::Director])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $directed->refresh()])
        ->assertSeeHtml('data-test="tournament-deadlines-directors"')
        ->assertDontSeeHtml('data-test="deadline-checkin_minutes"');
});

test('the drawn decider shows under a knockout only; the tie-break help explains the tie-breaks chosen', function () {
    $admin = rulesFormAdmin();

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::RoundRobin)])
        ->assertDontSeeHtml('data-test="tournament-draw-decider"')
        ->assertSee('Head-to-head: who did better in the games between those tied.')
        ->assertDontSee('Buchholz')
        ->assertDontSeeHtml('data-test="tie-break-same"');

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::SingleElimination)])
        ->assertSeeHtml('data-test="tournament-draw-decider"');

    // Counted game by game, games won and game difference follow from the points: the help says so.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::RoundRobin, ['legPoints' => true])])
        ->assertSeeHtml('data-test="tie-break-same"')
        ->assertSee('Games won and Game difference follow from the points');
});

test('with the night pause on, an end past midnight moves to the next day; switched off it stays in the night', function () {
    $admin = rulesFormAdmin();
    $day = now()->addWeek()->timezone('Europe/Berlin')->format('Y-m-d');

    // Blockli blitz, 4 players, everyone three times: 4 h 36 min of play, about 5 h online, from 22:00.
    $page = Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('startsAt', $day.'T22:00')
        ->call('pickGame', 'blockli/blitz')
        ->set('players', '4')
        ->call('select', 'round-robin')
        ->call('option', 'iterations', 3);
    $range = $page->instance()->durationRange;

    // The test league plays through the night (ESPORTS_NIGHT_PAUSE=false): the end is in the night.
    $night = $page->instance()->expectedTimes()['typical']->timezone('Europe/Berlin');
    expect($night->format('G:i'))->toBe(CarbonImmutable::parse($day.' 22:00', 'Europe/Berlin')->addMinutes((int) ceil($range->typical))->format('G:i'));

    // With the pause, the time from 24:00 goes on at 18:00 the next day.
    $page->call('toggleNightPause');
    $typical = $page->instance()->expectedTimes()['typical']->timezone('Europe/Berlin');
    $played = 120; // 22:00 to the pause at 24:00

    expect($page->instance()->nightPauseOn())->toBeTrue()
        ->and($typical->format('Y-m-d'))->toBe(CarbonImmutable::parse($day)->addDay()->format('Y-m-d'))
        ->and($typical->format('G:i'))->toBe(CarbonImmutable::parse($day.' 18:00', 'Europe/Berlin')->addMinutes((int) ceil($range->typical) - $played)->format('G:i'));
});

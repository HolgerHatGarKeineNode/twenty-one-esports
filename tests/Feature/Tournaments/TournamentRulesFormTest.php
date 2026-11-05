<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\HyperOn;
use Tests\Support\PongOn;

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

test('a Blockli correspondence table counts and plans the two games of every pairing, as every Blockli mode plays them', function () {
    $profile = GameProfile::for('blockli', 'correspondence');
    $estimator = new Estimator;
    $row = fn (int $players, array $options, TournamentFormat $format = TournamentFormat::RoundRobin) => $estimator->evaluate($players, $profile, FormatOptions::fromArray($options, $profile), null, 365)->row($format);

    // Four players, everyone three times: 9 pairings of two legs, 18 games, 6 against each. It counted one game a pairing.
    $four = $row(4, ['iterations' => 3]);
    expect($four->perMatch)->toBe(2)->and($four->guaranteedGames())->toBe(18)
        ->and($row(10, ['iterations' => 3])->guaranteedGames())->toBe(54);

    // All pairings start at once, but the legs follow each other: 30 days a leg, 60 a pairing (it planned 30).
    expect($row(4, [])->total())->toBe(60.0)
        ->and($row(4, ['legs' => 1])->total())->toBe(30.0)
        ->and($row(4, ['legs' => 1])->guaranteedGames())->toBe(3)
        ->and($row(4, ['legs' => 1], TournamentFormat::SingleElimination)->perMatch)->toBe(2);

    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::RoundRobin, ['iterations' => 3], mode: 'correspondence')])
        ->assertSee('Everyone plays 18 games: 9 pairings of 2 games each, 6 against each opponent.')
        ->assertDontSee('Everyone plays 9 games.');
});

test('a two-stage Blockli tournament of one game per group pairing counts the knockout at two games a match', function () {
    $profile = GameProfile::for('blockli', 'blitz');
    $row = (new Estimator)->evaluate(8, $profile, FormatOptions::fromArray(['legs' => 1], $profile), null, 600)->row(TournamentFormat::TwoStage);

    // Two groups of four: 3 group games at one game a pairing, then up to 2 knockout matches of two games: 3 to 7.
    expect($row->guaranteedGames())->toBe(3)->and($row->maxGames())->toBe(7);

    $tournament = rulesFormBlockli(TournamentFormat::TwoStage, ['legs' => 1], players: 8);
    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('3 to 7');
});

/** The rules text the league publishes for a tournament (TournamentPublisher::content()). */
function rulesFormPublished(Tournament $tournament): string
{
    return (fn (): string => $this->content($tournament))->call(app(TournamentPublisher::class));
}

test('a deadline the chosen game never reads cannot block the save: a series no-show typed before switching to Blockli', function () {
    $page = Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-create')
        ->set('name', 'Blockli Evening')
        ->call('pickGame', 'rl3')
        ->set('noshowMinutes', '9999')
        ->call('pickGame', 'blockli/blitz')
        ->set('players', '4')
        ->call('select', 'round-robin')
        ->call('create')
        ->assertHasNoErrors();

    // Out of range, it is not stored either.
    $created = Tournament::query()->where('name', 'Blockli Evening')->sole();
    expect($created->game)->toBe('blockli')->and($created->noshow_minutes)->toBeNull();

    // The deadline the game reads still validates.
    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-create')
        ->set('name', 'Blockli Night')
        ->call('pickGame', 'blockli/blitz')
        ->set('checkinMinutes', '0')
        ->call('create')
        ->assertHasErrors(['checkinMinutes']);
});

test('Proof of Pong and Hyperbitcoinization are played on the league: the form asks where and shows the deadlines each reads', function () {
    PongOn::play();
    HyperOn::play();
    config(['esports.pong.tournaments' => true, 'esports.hyper.tournaments' => true]);
    $admin = rulesFormAdmin();
    $draft = fn (string $game, string $mode, TournamentFormat $format): Tournament => Tournament::factory()->create([
        'game' => $game, 'mode' => $mode, 'format' => $format, 'capacity' => 8,
        'options' => FormatOptions::defaults(GameProfile::for($game, $mode))->toArray(),
    ]);

    // Pong starts every match with the first-move window (TournamentMatchMaker::startPongMatch()), in either results mode.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $draft('proof-of-pong', 'live', TournamentFormat::SingleElimination)])
        ->assertSee('Where the games are played')->assertSee('Here in the browser')->assertDontSee('Players report results')
        ->assertSeeHtml('data-test="deadline-checkin_minutes"')->assertDontSeeHtml('data-test="deadline-noshow_minutes"')
        ->call('pickResultsMode', 'director')
        ->assertSeeHtml('data-test="deadline-checkin_minutes"')->assertDontSee('At real boards, directors enter');

    // Hyperbitcoinization runs on its own turn clock: no deadline of the tournament reaches it.
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $draft('hyperbitcoinization', 'live', TournamentFormat::FreeForAll)])
        ->assertSee('Where the games are played')
        ->assertSeeHtml('data-test="tournament-deadlines-none"')
        ->assertDontSeeHtml('data-test="deadline-checkin_minutes"')->assertDontSeeHtml('data-test="deadline-noshow_minutes"');

    // The published rules say the same, in both results modes.
    foreach ([['proof-of-pong', 'live', TournamentFormat::SingleElimination], ['hyperbitcoinization', 'live', TournamentFormat::FreeForAll]] as [$game, $mode, $format]) {
        foreach ([TournamentResultsMode::Players, TournamentResultsMode::Director] as $resultsMode) {
            $tournament = $draft($game, $mode, $format);
            $tournament->forceFill(['results_mode' => $resultsMode])->save();
            expect(rulesFormPublished($tournament->refresh()))->toContain('Every game is played on the league server; its result comes from the game itself.')
                ->not->toContain('Players report results')->not->toContain('Results are entered by the tournament directors.');
        }
    }
});

test('the published rules and the tournament page say a chess or board game is played on the league; directors at real boards enter results', function () {
    $chess = Tournament::factory()->signup()->create(['published_at' => now()]);
    $blockli = rulesFormBlockli(TournamentFormat::RoundRobin);
    $directed = Tournament::factory()->signup()->create(['published_at' => now(), 'results_mode' => TournamentResultsMode::Director]);

    expect(rulesFormPublished($chess))->toContain('Every game is played on the league server; its result comes from the game itself.')->not->toContain('Players report results')
        ->and(rulesFormPublished($blockli))->toContain('Every game is played on the league server; its result comes from the game itself.')
        ->and(rulesFormPublished($directed))->toContain('Results are entered by the tournament directors.')->not->toContain('Every game is played on the league server');

    $this->get(route('tournaments.show', $chess))->assertOk()
        ->assertSee('every game is played on the league server; its result comes from the game itself')->assertDontSee('Players report results');
    $this->get(route('tournaments.show', $directed))->assertOk()
        ->assertSee('Tournament directors enter results')->assertDontSee('every game is played on the league server');
});

test('before the draw another time control of the same game keeps the format and its options; the players are told of the switch', function () {
    $tournament = rulesFormBlockli(TournamentFormat::RoundRobin, ['iterations' => 3, 'legs' => 1, 'rankBy' => 'game-wins']);
    $tournament->forceFill(['status' => TournamentStatus::Signup, 'signup_closes_at' => now()->addDay(), 'published_at' => now()->subDay()])->save();
    foreach (User::factory()->count(2)->create() as $player) {
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'members' => [$player->id]]);
    }

    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->call('pickGame', 'blockli/rapid')
        ->assertSet('selected', TournamentFormat::RoundRobin->value)
        ->call('save')->assertSet('error', '');

    $options = $tournament->refresh()->formatOptions();
    expect($tournament->mode)->toBe('rapid')->and($tournament->format)->toBe(TournamentFormat::RoundRobin)
        ->and([$options->iterations, $options->legs, $options->rankBy])->toBe([3, 1, 'game-wins'])
        ->and(DB::table('notifications')->where('data', 'like', '%now plays%')->count())->toBe(2);

    // Another game starts from its own defaults.
    $page = Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament])->call('pickGame', 'blitz');
    expect($page->get('selected'))->toBeNull()->and($page->get('options')['iterations'])->toBe(1);
});

test('the recommendation ranks by the games everyone plays: a Blockli round robin of one game per pairing gives fewer games than a Swiss of two', function () {
    $profile = GameProfile::for('blockli', 'blitz');
    $evaluation = (new Estimator)->evaluate(6, $profile, FormatOptions::fromArray(['legs' => 1], $profile), null, 180);

    // Round robin: 5 pairings of one game, 5 games. Swiss: 3 pairings of two games, 6 games. By matches the round robin led.
    expect($evaluation->row(TournamentFormat::RoundRobin)->guaranteedGames())->toBe(5)
        ->and($evaluation->row(TournamentFormat::Swiss)->guaranteedGames())->toBe(6)
        ->and($evaluation->recommended)->toBe(TournamentFormat::Swiss);
});

test('custom points in a round robin are set in the form and saved with "Save changes" only', function () {
    $tournament = rulesFormBlockli(TournamentFormat::RoundRobin, ['rankBy' => 'custom']);

    $page = Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="custom-points"')
        ->assertSeeHtml('wire:model.live.debounce.500ms="options.pointsWin"')->assertSeeHtml('wire:model.live.debounce.500ms="options.pointsTie"')
        ->set('options.pointsWin', '3')->set('options.pointsTie', '1');

    expect($tournament->refresh()->formatOptions()->pointsWin)->toBe(1.0);

    $page->call('save')->assertSet('error', '');
    $options = $tournament->refresh()->formatOptions();
    expect([$options->rankBy, $options->pointsWin, $options->pointsTie])->toBe(['custom', 3.0, 1.0]);

    // Ranked by points, the block is gone.
    Livewire::actingAs(rulesFormAdmin())->test('pages::admin.tournament-edit', ['tournament' => rulesFormBlockli(TournamentFormat::RoundRobin)])
        ->assertDontSeeHtml('data-test="custom-points"');
});

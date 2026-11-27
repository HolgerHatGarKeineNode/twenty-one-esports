<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| A correspondence round robin: all games at once, or round by round
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-26: in a round robin "soll man noch die Wahl haben, ob die Spiele direkt parallel gespielt werden
| oder nacheinander". Only correspondence play has the choice (his pick): it started every game at once and still does
| by default; round by round, a player's next game starts once both players are done with their earlier ones
| (TournamentMatchMaker::waitsForEarlierRound()). Live play is always one after another, since a player plays one live
| game at a time (BoardGameService::start(), P5); a director opens the rounds himself.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

/** A drawn Blockli round robin of three, one game a pairing, with these options. */
function atOnceRoundRobin(string $mode, array $options = [], TournamentResultsMode $results = TournamentResultsMode::Players): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => $mode, 'format' => TournamentFormat::RoundRobin,
        'options' => FormatOptions::fromArray(['legs' => 1, ...$options], GameProfile::for('blockli', $mode))->toArray(),
        'capacity' => 3, 'results_mode' => $results, 'status' => TournamentStatus::Running,
        'starts_at' => now()->subMinute(), 'slug' => 'at-once-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, 3) as $index) {
        $player = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** How many of the round robin's matches have started, and how many wait for an earlier round. @return array{int, int} */
function atOnceStarted(Tournament $tournament): array
{
    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];
    $boxes = collect(array_merge(...array_values($part['rounds'])))->reject(fn (array $box): bool => $box['bracket'] === 'bye');

    return [$boxes->filter(fn (array $box): bool => $box['href'] !== null)->count(), $boxes->filter(fn (array $box): bool => $box['queued'])->count()];
}

test('the option: all at once unless chosen, also for options stored before it; only correspondence plays at once', function () {
    $daily = GameProfile::for('blockli', 'correspondence');
    $blitz = GameProfile::for('blockli', 'blitz');

    expect(FormatOptions::defaults($daily)->atOnce)->toBeTrue()
        ->and(FormatOptions::fromArray(['iterations' => 2], $daily)->atOnce)->toBeTrue()
        ->and(FormatOptions::fromArray(['atOnce' => false], $daily)->toArray()['atOnce'])->toBeFalse()
        ->and(FormatOptions::fromArray(['atOnce' => 'false'], $daily)->atOnce)->toBeFalse()
        ->and(FormatOptions::fromArray([], $daily)->roundsAtOnce($daily))->toBeTrue()
        ->and(FormatOptions::fromArray(['atOnce' => false], $daily)->roundsAtOnce($daily))->toBeFalse()
        // Live: one after another, whatever is stored.
        ->and(FormatOptions::fromArray(['atOnce' => true], $blitz)->roundsAtOnce($blitz))->toBeFalse();
});

test('correspondence, all at once: every game starts right away, as before', function () {
    $tournament = atOnceRoundRobin('correspondence');

    expect(atOnceStarted($tournament))->toBe([3, 0])
        ->and(TournamentMatchMaker::queuesRounds($tournament, $tournament->stages()->firstOrFail()))->toBeFalse();
});

test('correspondence, round by round: one game starts, the later rounds of its players wait; a finished game starts the next', function () {
    $tournament = atOnceRoundRobin('correspondence', ['atOnce' => false]);

    expect(atOnceStarted($tournament))->toBe([1, 2])
        ->and(TournamentMatchMaker::queuesRounds($tournament, $tournament->stages()->firstOrFail()))->toBeTrue();

    $page = $this->get(route('tournaments.show', $tournament))->assertOk();
    $page->assertSee('round by round: the next game once both players are done with their earlier ones')
        ->assertSee('data-test="queued"', false);

    $this->get(route('tournaments.show', atOnceRoundRobin('correspondence')))->assertOk()
        ->assertSee('all at once: every game starts right away');
});

test('live play is one after another whatever is stored, and shows no choice; a director opens the rounds himself', function () {
    expect(atOnceStarted(atOnceRoundRobin('blitz', ['atOnce' => true])))->toBe([1, 2])
        ->and(atOnceStarted(atOnceRoundRobin('correspondence', ['atOnce' => false], TournamentResultsMode::Director))[1])->toBe(0);

    $this->get(route('tournaments.show', atOnceRoundRobin('blitz')))->assertOk()->assertDontSee('data-test="fact-at-once"', false)->assertDontSee('Games of the rounds');
});

test('the plan: round by round takes a slot per round and has nobody play too many games at once', function () {
    $profile = GameProfile::for('blockli', 'correspondence');
    $estimator = new Estimator;
    $row = fn (int $players, array $options) => $estimator->evaluate($players, $profile, FormatOptions::fromArray(['legs' => 1, ...$options], $profile), null, 1000)->row(TournamentFormat::RoundRobin);

    // Four players, one game a pairing: three rounds of 30 days with a day between them, or all of them at once in 30.
    expect($row(4, [])->total())->toBe(30.0)
        ->and($row(4, ['atOnce' => false])->total())->toBe(92.0)
        // Fourteen at once is 13 games each, more than the league allows at once; round by round it is one.
        ->and($row(14, [])->heavy)->toBeTrue()
        ->and($row(14, ['atOnce' => false])->heavy)->toBeFalse()
        ->and($row(14, ['atOnce' => false])->atOnce)->toBe(0);
});

test('the chooser offers it for a correspondence round robin and its round-robin groups, not live nor for a director', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $draft = fn (string $mode, TournamentFormat $format = TournamentFormat::RoundRobin, array $attributes = []): Tournament => Tournament::factory()->create([
        'game' => 'blockli', 'mode' => $mode, 'format' => $format, 'capacity' => 8,
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', $mode))->toArray(), 'status' => TournamentStatus::Draft, ...$attributes,
    ]);

    $tournament = $draft('correspondence');
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('data-test="at-once-option"', false)
        ->assertSee('Every game starts right away: everyone plays all their opponents at the same time.')
        // The chooser is an island: after an action the state, not its HTML (as TournamentRulesFormTest).
        ->call('pickAtOnce', false)
        ->assertSet('options.atOnce', false)
        ->call('save')->assertSet('error', '');

    expect($tournament->refresh()->formatOptions()->atOnce)->toBeFalse();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSee('One game at a time for everyone');

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $draft('correspondence', TournamentFormat::TwoStage)])
        ->assertSee('data-test="at-once-option"', false);
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $draft('blitz')])
        ->assertDontSee('data-test="at-once-option"', false);
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $draft('correspondence', attributes: ['results_mode' => TournamentResultsMode::Director])])
        ->assertDontSee('data-test="at-once-option"', false);
});

<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentEditor;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| A drawn Blockli decider goes to the race standing (user, 2026-10-11)
|--------------------------------------------------------------------------
|
| DerCaddy's proposal from PR #5, as a tournament setting (`draw_decider`): at 1:1 after both legs a third game
| decides; drawn too, the side ahead in the race standing of its final position wins, a level standing is replayed
| as before. Existing tournaments (null) keep replaying; new Blockli tournaments get the standing. The rule is part
| of what players sign up under: it changes only in a draft or while sign-up is open.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    // The decider's colours by lot: the higher seed (slot 0) keeps White.
    Lottery::alwaysLose();
});

afterEach(function () {
    Lottery::determineResultNormally();
});

function deciderTournament(?string $rule): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-decider-'.fake()->unique()->numberBetween(1, 1_000_000), 'draw_decider' => $rule,
    ]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Racer {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

function deciderLive(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

function deciderMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->with('slots.participant', 'round.stage', 'boardGame', 'tournament')->sole();
}

/** 1:1 after both legs (each side resigns one), then the decider stands in `$position` and is drawn by agreement. */
function deciderDrawnAt(Tournament $tournament, string $position): BoardGame
{
    $service = app(BoardGameService::class);

    foreach ([1, 0] as $loserSlot) {
        $game = deciderLive($tournament);
        $service->resign($game, User::query()->findOrFail((int) deciderMatch($tournament)->slots[$loserSlot]->participant->memberIds()[0]));
    }

    $decider = BlockliOn::setUp(deciderLive($tournament), $position);
    expect($decider->tournament_game)->toBe(3);
    $service->offerDraw($decider, $decider->white);
    $service->acceptDraw($decider->refresh(), $decider->black);

    return $decider->refresh();
}

test('a drawn decider goes to White when White leads the race standing', function () {
    $tournament = deciderTournament(Tournament::DRAW_DECIDER_STANDING);
    // White one step from its goal, Black two, blocks level: White leads by 1 step.
    $decider = deciderDrawnAt($tournament, 'e8 e3 10 10 w - 0');
    $match = deciderMatch($tournament);
    $whiteSlot = (int) deciderMatch($tournament)->slots[0]->participant->memberIds()[0] === $decider->white_id ? 0 : 1;

    expect($decider->result)->toBe('1/2-1/2')
        ->and($match->result['decided'])->toBe('standing')
        ->and($match->result['winner'])->toBe($whiteSlot)
        ->and($match->result['games_won'])->toEqual([1.0, 1.0])
        ->and($match->result['standing'])->toEqual(['margin' => 1.0, 'legs' => '1–1'])
        ->and($match->result['label'])->toBe('1–1, decider drawn: race standing, ahead by 1 step')
        ->and(BoardGame::query()->count())->toBe(3)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    // The bracket words it in the viewer's language.
    app()->setLocale('de');
    expect((new TournamentView($tournament->refresh()))->box(deciderMatch($tournament))['label'])->toBe('1–1, Entscheidungspartie remis: Rennstand, 1 Schritt vorn');
});

test('a drawn decider goes to Black when Black leads the race standing, by its margin in half steps', function () {
    $tournament = deciderTournament(Tournament::DRAW_DECIDER_STANDING);
    // Steps level (2 each); White has one block fewer: Black leads by 1.5 steps.
    $decider = deciderDrawnAt($tournament, 'e7 e3 9 10 w a1hw 0');
    $match = deciderMatch($tournament);
    $blackSlot = (int) $match->slots[0]->participant->memberIds()[0] === $decider->black_id ? 0 : 1;

    expect($match->result['decided'])->toBe('standing')
        ->and($match->result['winner'])->toBe($blackSlot)
        ->and($match->result['label'])->toBe('1–1, decider drawn: race standing, ahead by 1.5 steps');
});

test('a level standing is replayed as before', function () {
    $tournament = deciderTournament(Tournament::DRAW_DECIDER_STANDING);
    deciderDrawnAt($tournament, 'e7 e3 10 10 w - 0');

    expect(deciderMatch($tournament)->result)->toBeNull()
        ->and(deciderLive($tournament)->tournament_game)->toBe(4);
});

test('a tournament set to replay (and every tournament from before the rule) replays a drawn decider whoever leads', function (?string $rule) {
    $tournament = deciderTournament($rule);
    deciderDrawnAt($tournament, 'e8 e3 10 10 w - 0');

    expect(deciderMatch($tournament)->result)->toBeNull()
        ->and(deciderLive($tournament)->tournament_game)->toBe(4);
})->with([
    'replay' => [Tournament::DRAW_DECIDER_REPLAY],
    'null (before 2026-10-11)' => [null],
]);

test('only a two-leg race game can be settled by the standing: the editor refuses it for any other game', function () {
    expect(TournamentRunner::canDecideByStanding('blockli'))->toBeTrue()
        ->and(TournamentRunner::canDecideByStanding('checkers'))->toBeFalse()
        ->and(TournamentRunner::canDecideByStanding('nine-mens-morris'))->toBeFalse()
        ->and(TournamentRunner::canDecideByStanding('chess'))->toBeFalse();

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $chess = Tournament::factory()->create(['status' => TournamentStatus::Draft, 'game' => 'chess', 'mode' => 'blitz']);

    expect(fn () => app(TournamentEditor::class)->update($chess, $admin, ['draw_decider' => Tournament::DRAW_DECIDER_STANDING]))
        ->toThrow(TournamentRuleViolation::class);
});

test('the rule changes in a draft and while sign-up is open, and is locked once sign-up closed', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $editor = app(TournamentEditor::class);
    $tournament = Tournament::factory()->create(['status' => TournamentStatus::Draft, 'game' => 'blockli', 'mode' => 'correspondence', 'starts_at' => now()->addDays(5)]);

    expect($editor->update($tournament, $admin, ['draw_decider' => Tournament::DRAW_DECIDER_STANDING]))->toBe(['draw_decider']);

    $tournament->forceFill(['status' => TournamentStatus::Signup, 'signup_closes_at' => now()->addDay()])->save();
    expect($editor->update($tournament->refresh(), $admin, ['draw_decider' => null]))->toBe(['draw_decider']);

    $tournament->forceFill(['signup_closes_at' => now()->subMinute()])->save();
    expect(fn () => $editor->update($tournament->refresh(), $admin, ['draw_decider' => Tournament::DRAW_DECIDER_STANDING]))
        ->toThrow(TournamentRuleViolation::class, 'cannot change after sign-up closed');
    expect($tournament->refresh()->draw_decider)->toBeNull();
});

test('a new Blockli tournament is created with the standing; the edit page shows it, switches it and locks it after sign-up', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Blockli Race')
        ->call('pickGame', 'blockli/correspondence')
        ->set('players', '8')
        // Only a knockout plays a decider: a round robin does not show the rule (DerCaddy, 2026-11-04).
        ->call('select', 'round-robin')
        ->assertDontSeeHtml('data-test="tournament-draw-decider"')
        ->call('select', 'single-elimination')
        ->assertSeeHtml('data-test="draw-decider-standing"')
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();
    expect($tournament->draw_decider)->toBe(Tournament::DRAW_DECIDER_STANDING);

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSet('drawDecider', Tournament::DRAW_DECIDER_STANDING)
        ->call('useDrawDecider', Tournament::DRAW_DECIDER_REPLAY)
        ->call('save')
        ->assertHasNoErrors();
    expect($tournament->refresh()->draw_decider)->toBeNull();

    $tournament->forceFill(['status' => TournamentStatus::Signup, 'signup_closes_at' => now()->subMinute()])->save();
    $this->travel(3)->seconds();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertSeeHtml('data-test="draw-decider-locked"')
        ->call('useDrawDecider', Tournament::DRAW_DECIDER_STANDING)
        ->assertSet('drawDecider', Tournament::DRAW_DECIDER_REPLAY);
});

test('a chess tournament is created without the rule and its editor shows no draw-decider section', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Blitz Night')
        ->call('pickGame', 'chess/blitz')
        ->set('players', '8')
        ->assertDontSeeHtml('data-test="tournament-draw-decider"')
        ->call('create')
        ->assertHasNoErrors();

    expect(Tournament::query()->sole()->draw_decider)->toBeNull();
});

test('the tournament page and the rules page say how a drawn decider is settled', function () {
    $standing = deciderTournament(Tournament::DRAW_DECIDER_STANDING);
    $replay = deciderTournament(null);

    $this->get(route('tournaments.show', $standing))->assertOk()->assertSee('data-fact="pairings"', false)
        ->assertSee(__('two games with the colours swapped; at 1:1 a third game decides, and if it is drawn the race standing of its final position decides (a block counts :rate steps); a level standing is replayed', ['rate' => '1.5']));
    $this->get(route('tournaments.show', $replay))->assertOk()->assertSee('data-fact="pairings"', false)->assertSee('a drawn one is replayed up to');
    $this->get(route('rules'))->assertOk()->assertSee('A Blockli pairing plays two games with the colours swapped');
});

test('the board page of a running decider says the race standing decides a draw; the legs do not', function () {
    $tournament = deciderTournament(Tournament::DRAW_DECIDER_STANDING);
    $first = deciderLive($tournament);
    $this->actingAs($first->white)->get(route('board.show', $first))->assertOk()->assertDontSee('data-test="decider-standing"', false);

    $service = app(BoardGameService::class);
    foreach ([1, 0] as $loserSlot) {
        $service->resign(deciderLive($tournament), User::query()->findOrFail((int) deciderMatch($tournament)->slots[$loserSlot]->participant->memberIds()[0]));
    }
    $decider = deciderLive($tournament);

    $this->actingAs($decider->white)->get(route('board.show', $decider))->assertOk()->assertSee('data-test="decider-standing"', false);
});

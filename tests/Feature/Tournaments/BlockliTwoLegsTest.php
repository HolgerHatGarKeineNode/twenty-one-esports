<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardInvites;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| Blockli pairings: two games with the colours swapped (plan "Blockli", P4)
|--------------------------------------------------------------------------
|
| The first move is an advantage, so a tournament or cup pairing plays two
| games, the second with the colours swapped. The points decide (a win 1, a
| draw ½); level points are a draw where the format allows one (round
| robin, Swiss), else a blitz game with colours drawn by lot decides.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

afterEach(function () {
    Lottery::determineResultNormally();
});

function blockliTournament(TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'blitz', 'format' => $format,
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'blitz'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-legs-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

function blockliLive(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

function blockliMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->with('slots.participant', 'round.stage', 'boardGame', 'tournament')->sole();
}

/** The user in a slot of the match (0 = the higher seed). */
function blockliSlotUser(Tournament $tournament, int $slot): int
{
    return (int) blockliMatch($tournament)->slots[$slot]->participant->memberIds()[0];
}

/** The live game ends: the player of `$loserSlot` resigns. */
function blockliLose(Tournament $tournament, int $loserSlot): BoardGame
{
    $game = blockliLive($tournament);
    $loser = User::query()->findOrFail(blockliSlotUser($tournament, $loserSlot));
    app(BoardGameService::class)->resign($game, $loser);

    return $game->refresh();
}

test('a knockout pairing plays two games with the colours swapped; 2–0 decides without a third game', function () {
    $tournament = blockliTournament();
    $first = blockliLose($tournament, 1);
    $second = blockliLive($tournament);

    expect($first->white_id)->toBe(blockliSlotUser($tournament, 0))
        // The second leg: the colours swapped, the match still open.
        ->and($second->white_id)->toBe(blockliSlotUser($tournament, 1))
        ->and($second->tournament_game)->toBe(2)
        ->and(blockliMatch($tournament)->result)->toBeNull();

    blockliLose($tournament, 1);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and(TournamentMatchMaker::needsBoardGame($match))->toBeFalse()
        ->and($match->result['winner'])->toBe(0)
        ->and($match->result['games_won'])->toEqual([2.0, 0.0])
        ->and($match->result['decided'])->toBe('legs')
        ->and($match->result['label'])->toBe('2–0')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('at 1:1 a blitz decider with colours drawn by lot decides the knockout pairing', function (bool $lot, int $whiteSlot) {
    $lot ? Lottery::alwaysWin() : Lottery::alwaysLose();
    $tournament = blockliTournament();
    blockliLose($tournament, 1);
    blockliLose($tournament, 0);
    $decider = blockliLive($tournament);

    expect($decider->tournament_game)->toBe(3)
        ->and($decider->mode)->toBe('blitz')
        ->and($decider->white_id)->toBe(blockliSlotUser($tournament, $whiteSlot))
        ->and(blockliMatch($tournament)->result)->toBeNull();

    // White resigns the decider: Black's player wins the pairing.
    blockliLose($tournament, $whiteSlot);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(3)
        ->and($match->result['winner'])->toBe(1 - $whiteSlot)
        ->and($match->result['games_won'])->toEqual([1.0, 1.0])
        ->and($match->result['decided'])->toBe('decider')
        ->and($match->result['label'])->toBe('1–1, decider '.TournamentRunner::chessLabel(1 - $whiteSlot))
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
})->with([
    'the lot swaps: the lower seed has White' => [true, 1],
    'the lot keeps: the higher seed has White' => [false, 0],
]);

test('a drawn leg counts half a point each: ½ + 1 decides 1½–½ without a decider', function () {
    $tournament = blockliTournament();
    $game = blockliLive($tournament);
    app(BoardGameService::class)->offerDraw($game, $game->white);
    app(BoardGameService::class)->acceptDraw($game->refresh(), $game->black);
    blockliLose($tournament, 0);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and($match->result['winner'])->toBe(1)
        ->and($match->result['games_won'])->toEqual([0.5, 1.5])
        ->and($match->result['label'])->toBe('½–1½');
});

test('in a round robin 1:1 is a draw: no decider', function () {
    $tournament = blockliTournament(TournamentFormat::RoundRobin);
    blockliLose($tournament, 1);
    blockliLose($tournament, 0);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and(BoardGame::query()->where('status', BoardGameStatus::Active)->count())->toBe(0)
        ->and($match->result['winner'])->toBeNull()
        ->and($match->result['games_won'])->toEqual([1.0, 1.0]);
});

test('Blockli has a casual cup slot of its own with two games a pairing, and the time plan counts both', function () {
    config(['esports.casual_cups.enabled' => ['blockli']]);
    $setup = CasualCups::setup('blockli');

    expect(CasualCups::enabledGames())->toContain('blockli')
        ->and($setup['best_of'])->toBe(2)
        ->and($setup['final_best_of'])->toBe(2)
        ->and(GameProfile::for('blockli', 'blitz')->slot(2))->toBe(2 * GameProfile::for('checkers', 'blitz')->slot(1));
});

test('the Blockli lobby pairs from its queue and by invite, as nine men\'s morris and checkers do', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();

    Livewire\Livewire::actingAs($a)->test('pages::board.lobby', ['board' => 'blockli'])->call('findOpponent')->assertSeeHtml('data-test="lobby-searching"');
    Livewire\Livewire::actingAs($b)->test('pages::board.lobby', ['board' => 'blockli'])->call('findOpponent')
        ->assertRedirect(route('board.show', BoardGame::query()->sole()));

    expect(BoardGame::query()->sole()->game)->toBe('blockli');

    app(BoardGameService::class)->resign(BoardGame::query()->sole(), $a);
    $a->forceFill(['looking_to_play' => 'blockli/blitz'])->save();
    $invite = app(BoardInvites::class)->invite($c, $a, 'blockli');
    app(BoardInvites::class)->accept($invite, $a);

    expect($invite->refresh()->board_game_id)->not->toBeNull()
        ->and(BoardGame::query()->findOrFail($invite->board_game_id)->game)->toBe('blockli');
});

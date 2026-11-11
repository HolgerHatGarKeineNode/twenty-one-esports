<?php

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\GameNames;
use App\Support\Series\Ladders;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentMatrix;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| A combined tournament: one game of Blockli and one of Blockspace per pairing
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-11-11: "kombinierte Turniere … zum Beispiel, in dem man Blockli und Blockspace gegeneinander
| spielt", as "eine Paarung das eine Spiel und dann noch eine Paarung gegen den Gegner im anderen Spiel". A Blockli
| tournament names a second board game (FormatOptions::$pairGame): leg 1 is Blockli, leg 2 the second game with the
| colours swapped, a decider after 1:1 alternates, Blockli first. The second game's legs are rated on its own ladder.
| The helpers (blockliTournament() and the rest) are tests/Support/blockli.php.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    BlockspaceOn::play();
});

test('the options: a Blockli tournament takes another board game of its mode, always with two legs; nothing else does', function () {
    $blockli = GameProfile::for('blockli', 'correspondence');

    expect(FormatOptions::pairGames($blockli))->toContain('blockspace')->not->toContain('blockli')
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace', 'legs' => 1], $blockli))->pairGame->toBe('blockspace')->legs->toBe(2)
        ->and(FormatOptions::fromArray([], $blockli)->pairGame)->toBeNull()
        // Chess combines too (any game with any): Blockli + chess.
        ->and(FormatOptions::fromArray(['pairGame' => 'chess'], $blockli)->pairGame)->toBe('chess')
        ->and(FormatOptions::fromArray(['pairGame' => 'rocket-league'], $blockli)->pairGame)->toBeNull()
        ->and(FormatOptions::fromArray(['pairGame' => 'blockli'], $blockli)->pairGame)->toBeNull()
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('chess', 'blitz'))->pairGame)->toBe('blockspace');

    // Blockspace switched off: no longer offered, and stored options fall back to one game.
    config(['esports.board_games.games.blockspace.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(FormatOptions::pairGames($blockli))->not->toContain('blockspace')
        ->and(FormatOptions::fromArray(['pairGame' => 'blockspace'], $blockli)->pairGame)->toBeNull();
});

test('the sequence: games in order, the tournament\'s game first, repeats allowed, cut at the configured length', function () {
    $blockli = GameProfile::for('blockli', 'blitz');

    // Options stored by #28 name only the second game: they read as the tournament's game, then that one.
    expect(FormatOptions::fromArray(['pairGame' => 'chess'], $blockli))->games->toBe(['blockli', 'chess'])->decider->toBe('cycle')
        ->and(FormatOptions::fromArray(['games' => ['blockli', 'chess', 'blockli', 'blockspace'], 'decider' => 'first'], $blockli))
        ->games->toBe(['blockli', 'chess', 'blockli', 'blockspace'])->decider->toBe('first')->legs->toBe(2)
        ->and(FormatOptions::fromArray(['games' => ['blockli', 'chess', 'blockli']], $blockli)->sequence())->toBeTrue()
        // A stored list wins over a #28 key beside it; an unknown decider is the default.
        ->and(FormatOptions::fromArray(['games' => [], 'pairGame' => 'chess'], $blockli)->sequence())->toBeFalse()
        ->and(FormatOptions::fromArray(['games' => ['blockli', 'chess'], 'decider' => 'lot'], $blockli)->decider)->toBe('cycle')
        // The tournament's game leads, or there is no sequence; one game alone is none; unknown games are dropped.
        ->and(FormatOptions::fromArray(['games' => ['chess', 'blockli']], $blockli)->games)->toBe([])
        ->and(FormatOptions::fromArray(['games' => ['blockli']], $blockli)->games)->toBe([])
        ->and(FormatOptions::fromArray(['games' => ['blockli', 'rocket-league', 7, 'chess']], $blockli)->games)->toBe(['blockli', 'chess'])
        // A round trip keeps the sequence.
        ->and(FormatOptions::fromArray(FormatOptions::fromArray(['games' => ['blockli', 'chess', 'chess']], $blockli)->toArray(), $blockli)->games)->toBe(['blockli', 'chess', 'chess']);

    // At most the configured number of games: the rest is cut.
    expect(FormatOptions::fromArray(['games' => ['blockli', 'chess', 'blockli', 'chess', 'blockli', 'chess']], $blockli)->games)->toHaveCount(5);
    config(['esports.tournaments.sequence_max_games' => 3]);
    expect(FormatOptions::fromArray(['games' => ['blockli', 'chess', 'blockspace', 'chess']], $blockli)->games)->toBe(['blockli', 'chess', 'blockspace']);

    // Blockspace switched off: dropped from a stored sequence; fewer than two games left is one game.
    config(['esports.board_games.games.blockspace.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(FormatOptions::fromArray(['games' => ['blockli', 'blockspace', 'chess']], $blockli)->games)->toBe(['blockli', 'chess'])
        ->and(FormatOptions::fromArray(['games' => ['blockli', 'blockspace']], $blockli)->games)->toBe([]);
});

test('a pairing plays Blockli, then Blockspace with the colours swapped, and after 1:1 a Blockli decider', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['pairGame' => 'blockspace']);
    $first = blockliLive($tournament);

    expect($first->game)->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 0))->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 1))->toBe('blockspace')
        ->and(TournamentRunner::legGame($tournament, 2))->toBe('blockli')
        ->and(TournamentRunner::legGame($tournament, 3))->toBe('blockspace');

    // Leg 1 to the higher seed.
    blockliLose($tournament, 1);
    $second = blockliLive($tournament);

    expect($second->game)->toBe('blockspace')
        ->and((int) $second->white_id)->toBe((int) $first->black_id)
        ->and(blockliMatch($tournament)->result)->toBeNull();

    // Leg 2 to the lower seed: 1:1, a decider in Blockli.
    blockliLose($tournament, 0);
    $decider = blockliLive($tournament);

    expect($decider->game)->toBe('blockli')
        ->and(blockliMatch($tournament)->result)->toBeNull();

    blockliLose($tournament, 1);
    $result = blockliMatch($tournament)->result;

    expect($result['winner'])->toBe(0)
        ->and($result['decided'])->toBe('decider')
        ->and(BoardGame::query()->where('tournament_match_id', blockliMatch($tournament)->id)->where('status', BoardGameStatus::Finished)->orderBy('id')->pluck('game')->all())
        ->toBe(['blockli', 'blockspace', 'blockli']);
});

test('a round robin with Blockspace plays both games per pairing and counts them like two legs', function () {
    $tournament = blockliTournament(TournamentFormat::RoundRobin, ['pairGame' => 'blockspace', 'legs' => 1, 'legPoints' => true]);

    expect($tournament->formatOptions()->legs)->toBe(2);

    blockliLose($tournament, 1);
    expect(blockliLive($tournament)->game)->toBe('blockspace');

    blockliLose($tournament, 1);
    [$rows, $twoLegs, $perLeg] = blockliTable($tournament);

    expect($twoLegs)->toBeTrue()->and($perLeg)->toBeTrue()
        ->and($rows['Player 1'][0])->toBe('2');
});

test('the Blockspace games are rated on the open Blockspace ladder of the season, the Blockli games on the tournament\'s', function () {
    $tournament = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'correspondence',
        'options' => FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'ladder_address' => Ladders::address('blockli', 'correspondence')]);

    expect($tournament->openLadderFor('blockli'))->toBe(Ladders::address('blockli', 'correspondence'))
        ->and($tournament->openLadderFor('blockspace'))->toBe(Ladders::address('blockspace', 'correspondence'));

    // The tournament's ladder is closed (another season): both casual.
    $tournament->forceFill(['ladder_address' => 'an-old-season'])->save();

    expect($tournament->openLadderFor('blockli'))->toBeNull()
        ->and($tournament->openLadderFor('blockspace'))->toBeNull();
});

test('the tournament names both games, and the board\'s game switch names each game\'s board game', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['pairGame' => 'blockspace']);

    expect(Lobbies::gameLine($tournament))->toStartWith('Blockli + Blockspace');

    blockliLose($tournament, 1);
    $banner = TournamentGameEnd::banner(blockliLive($tournament));

    expect(collect($banner['games'])->pluck('text')->all())->toBe([
        __('Game :n · won by :name', ['n' => '1 (Blockli)', 'name' => 'Player 1']),
        __('Game :n · under way', ['n' => '2 (Blockspace)']),
    ]);
});

test('the organizer picks the second game on the create page; it is stored and shown again on the edit page', function () {
    $organizer = organizer();

    // The chooser's game island is drawn by the browser test of the page (the action's response carries it apart).
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->set('name', 'Blockli und Blockspace')
        ->call('pickGame', 'blockli/blitz')
        ->call('pickPairGame', 'blockspace')
        ->assertSet('options.games', ['blockli', 'blockspace'])
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();

    expect($tournament->formatOptions()->pairGame)->toBe('blockspace');

    Livewire::actingAs($organizer)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pair-game-option"')
        ->assertSet('options.games', ['blockli', 'blockspace'])
        ->call('pickPairGame', '')
        ->assertSet('options.games', []);

    // Chess combines as well; a series game does not, the pick does nothing there.
    Livewire::actingAs($organizer)->test('pages::admin.tournament-create')
        ->call('pickGame', 'blitz')
        ->call('pickPairGame', 'blockspace')
        ->assertSet('options.games', ['chess', 'blockspace'])
        ->call('pickGame', 'rl1')
        ->call('pickPairGame', 'blockspace')
        ->assertSet('options.games', []);
});

test('the published tournament says in words that it is combined', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'game' => 'blockli', 'mode' => 'correspondence',
        'options' => FormatOptions::fromArray(['pairGame' => 'blockspace'], GameProfile::for('blockli', 'correspondence'))->toArray()]);

    app(TournamentPublisher::class)->publish($tournament, $tournament->creator, $tournament->starts_at->copy()->subHour());

    expect($tournament->refresh()->event->payload()['content'])
        ->toContain('A combined tournament: each pairing plays one game of Blockli and one of Blockspace, with the colours swapped');
});

/*
| Any game with any (DerCaddy, 2026-11-11: "Cooler wäre ja, wenn man jedes Spiel mit jedem Spiel kombinieren könnte"):
| chess and every board game, either way round; series, lobbies and score games combine with nothing.
*/

/** A drawn, running two-player tournament of `$game` combined with `$pair`, in `$mode`. */
function combinedTournament(string $game, string $pair, string $mode, TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => $mode, 'format' => $format,
        'options' => FormatOptions::fromArray(['pairGame' => $pair], GameProfile::for($game, $mode))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'combined-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach ([1, 2] as $index) {
        $player = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The live game of the tournament's one match, chess or board game. */
function combinedLive(Tournament $tournament): ChessGame|BoardGame
{
    $match = blockliMatch($tournament);

    return ChessGame::query()->where('tournament_match_id', $match->id)->where('status', ChessGameStatus::Active)->first()
        ?? BoardGame::query()->where('tournament_match_id', $match->id)->where('status', BoardGameStatus::Active)->sole();
}

/** The player of `$loserSlot` resigns the live game. */
function combinedLose(Tournament $tournament, int $loserSlot): void
{
    $game = combinedLive($tournament);
    $loser = User::query()->findOrFail(blockliSlotUser($tournament, $loserSlot));

    $game instanceof ChessGame
        ? app(ChessGameService::class)->resign($game, $loser)
        : app(BoardGameService::class)->resign($game, $loser);
}

test('chess combines with every board game of its mode, and a board game with chess', function () {
    expect(FormatOptions::pairGames(GameProfile::for('chess', 'blitz')))->toContain('blockli')->toContain('blockspace')->not->toContain('chess')
        ->and(FormatOptions::pairGames(GameProfile::for('blockspace', 'rapid')))->toContain('chess')->toContain('blockli')
        ->and(FormatOptions::fromArray(['pairGame' => 'blockli'], GameProfile::for('chess', 'blitz')))->pairGame->toBe('blockli')->legs->toBe(2)
        // A series game combines with nothing, and nothing with it.
        ->and(FormatOptions::pairGames(GameProfile::for('rocket-league', '1v1')))->toBe([])
        ->and(FormatOptions::pairGames(GameProfile::for('chess', 'blitz')))->not->toContain('rocket-league');
});

test('chess + Blockli: chess, then Blockli with the colours swapped, after 1:1 a chess decider', function () {
    $tournament = combinedTournament('chess', 'blockli', 'blitz');
    $first = combinedLive($tournament);

    expect($first)->toBeInstanceOf(ChessGame::class)
        ->and(Lobbies::gameLine($tournament))->toStartWith(GameNames::game('chess').' + Blockli');

    combinedLose($tournament, 1);
    $second = combinedLive($tournament);

    expect($second)->toBeInstanceOf(BoardGame::class)
        ->and($second->game)->toBe('blockli')
        ->and((int) $second->white_id)->toBe((int) $first->black_id)
        ->and(TournamentRunner::legsSoFar(blockliMatch($tournament), $tournament)['label'])->toBe('1–0');

    // The board's switch lists both games, the chess game first.
    $banner = TournamentGameEnd::banner($second);
    expect(collect($banner['games'])->pluck('href')->all())->toBe([route('games.show', $first->id), route('board.show', $second->id)]);

    combinedLose($tournament, 0);
    $decider = combinedLive($tournament);

    expect($decider)->toBeInstanceOf(ChessGame::class)
        ->and(blockliMatch($tournament)->result)->toBeNull();

    combinedLose($tournament, 0);
    $result = blockliMatch($tournament)->result;

    expect($result['winner'])->toBe(1)
        ->and($result['decided'])->toBe('decider');
});

test('Blockspace + chess in a round robin: both games count, a chess game in the table like a leg', function () {
    $tournament = combinedTournament('blockspace', 'chess', 'rapid', TournamentFormat::RoundRobin);

    expect(combinedLive($tournament))->toBeInstanceOf(BoardGame::class)->and(combinedLive($tournament)->game)->toBe('blockspace');

    combinedLose($tournament, 0);
    expect(combinedLive($tournament))->toBeInstanceOf(ChessGame::class);

    combinedLose($tournament, 0);
    $result = blockliMatch($tournament)->result;

    expect($result['winner'])->toBe(1)
        ->and($result['decided'])->toBe('legs')
        ->and($result['legs'])->toEqual([[0, 1], [0, 1]]);
});

test('the chess games of a combined tournament are rated on the chess ladder, the other game on its own', function () {
    $tournament = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'blitz',
        'options' => FormatOptions::fromArray(['pairGame' => 'chess'], GameProfile::for('blockli', 'blitz'))->toArray(),
        'ladder_address' => Ladders::address('blockli', 'blitz')]);

    expect($tournament->openLadderFor('chess'))->toBe(Ladders::address('chess', 'blitz'))
        ->and($tournament->openLadderFor('blockli'))->toBe(Ladders::address('blockli', 'blitz'));
});

test('a combined leg whose first move was missed is played again in the same game with the same colours', function () {
    $tournament = combinedTournament('chess', 'blockli', 'blitz');
    combinedLose($tournament, 1);
    $board = combinedLive($tournament);
    $board->forceFill(['status' => BoardGameStatus::Aborted])->save();
    $match = blockliMatch($tournament);

    expect(TournamentMatchMaker::lastLeg($match, $tournament)->is($board))->toBeTrue()
        ->and(TournamentMatchMaker::needsCombinedGame($match, $tournament))->toBeTrue();

    app(TournamentRunner::class)->sync($tournament);
    $again = combinedLive($tournament);

    expect($again)->toBeInstanceOf(BoardGame::class)
        ->and($again->id)->not->toBe($board->id)
        ->and($again->game)->toBe('blockli')
        ->and((int) $again->white_id)->toBe((int) $board->white_id);
});

test('a restarted round replays the whole combined pairing, even when the chess ids run ahead of the board ids', function () {
    // Fresh tables both count from 1, which hides a watermark compared across them: the chess table runs ahead here.
    $outsider = User::factory()->count(2)->create();
    ChessGame::factory()->count(50)->create(['white_id' => $outsider[0]->id, 'black_id' => $outsider[1]->id, 'status' => ChessGameStatus::Aborted]);

    $tournament = combinedTournament('chess', 'blockli', 'blitz');
    combinedLose($tournament, 1);
    $match = blockliMatch($tournament);

    expect(combinedLive($tournament))->toBeInstanceOf(BoardGame::class)
        ->and(ChessGame::query()->where('tournament_match_id', $match->id)->value('id'))->toBeGreaterThan(BoardGame::query()->where('tournament_match_id', $match->id)->value('id'));

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    expect(app(TournamentControl::class)->restartRound($tournament, $admin, $match->tournament_round_id, 0, 'Server outage'))->toBeTrue();

    $match = blockliMatch($tournament->refresh());

    expect(TournamentRunner::finishedLegs($match, $tournament))->toHaveCount(0)
        ->and(combinedLive($tournament))->toBeInstanceOf(ChessGame::class);
})->skip('P4: voids per table — restartRound sets replaced_through to the board id and keeps the higher chess id of leg 1');

test('the live matrix counts a finished chess leg of a combined pairing', function () {
    $tournament = combinedTournament('chess', 'blockli', 'blitz', TournamentFormat::RoundRobin);
    combinedLose($tournament, 1);

    $overview = (new TournamentMatrix($tournament->refresh()))->overview(CarbonImmutable::now());
    $names = collect($overview['groups'][0]['players'])->pluck('name', 'id');
    $scores = [];

    foreach ($overview['groups'][0]['cells'] as $row => $columns) {
        foreach ($columns as $column => $cell) {
            $scores[$names[$row].'-'.$names[$column]] = $cell['score'];
        }
    }

    expect($scores['Player 1-Player 2'])->toBe('1–0')
        ->and($scores['Player 2-Player 1'])->toBe('0–1');
})->skip('P5: matrix chess legs — TournamentMatrix::scores() reads only the board games');

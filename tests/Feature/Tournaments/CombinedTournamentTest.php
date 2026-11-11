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
use App\Support\Tournaments\Engine;
use App\Support\Tournaments\Estimator;
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
use App\Support\Tournaments\TournamentView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
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
    // Each game's first leg draws its colours by lot: pinned to slot 0 with White.
    Lottery::alwaysLose();
});

afterEach(function () {
    Lottery::determineResultNormally();
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

test('a pairing plays Blockli, then Blockspace, and after 1:1 a Blockli decider', function () {
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
        // Its game's first leg: by lot (pinned), not swapped against the other game's leg.
        ->and((int) $second->white_id)->toBe((int) $first->white_id)
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
    return sequenceTournament($game, $mode, ['pairGame' => $pair], $format);
}

/**
 * A drawn, running two-player tournament of `$game` in `$mode` with these options (a sequence: `games`, `decider`).
 *
 * @param  array<string, mixed>  $options
 */
function sequenceTournament(string $game, string $mode, array $options, TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => $mode, 'format' => $format,
        'options' => FormatOptions::fromArray($options, GameProfile::for($game, $mode))->toArray(),
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

/** The live game ends drawn: White offers, Black accepts. */
function combinedDraw(Tournament $tournament): void
{
    $game = combinedLive($tournament);
    $white = User::query()->findOrFail($game->white_id);
    $black = User::query()->findOrFail($game->black_id);

    if ($game instanceof ChessGame) {
        app(ChessGameService::class)->acceptDraw(app(ChessGameService::class)->offerDraw($game, $white), $black);
    } else {
        app(BoardGameService::class)->acceptDraw(app(BoardGameService::class)->offerDraw($game, $white), $black);
    }
}

/** The live game's first move is missed: it ends aborted, and the league hears of it as from the clock. */
function combinedMissFirstMove(Tournament $tournament): ChessGame|BoardGame
{
    $game = combinedLive($tournament);

    if ($game instanceof ChessGame) {
        $game->forceFill(['status' => ChessGameStatus::Aborted])->save();
        app(TournamentRunner::class)->chessGameAborted($game->id);
    } else {
        $game->forceFill(['status' => BoardGameStatus::Aborted])->save();
        app(TournamentRunner::class)->boardGameAborted($game->id);
    }

    return $game;
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

test('chess + Blockli: chess, then Blockli, after 1:1 a chess decider', function () {
    $tournament = combinedTournament('chess', 'blockli', 'blitz');
    $first = combinedLive($tournament);

    expect($first)->toBeInstanceOf(ChessGame::class)
        ->and(Lobbies::gameLine($tournament))->toStartWith(GameNames::game('chess').' + Blockli');

    combinedLose($tournament, 1);
    $second = combinedLive($tournament);

    expect($second)->toBeInstanceOf(BoardGame::class)
        ->and($second->game)->toBe('blockli')
        // Its game's first leg: by lot (pinned), not swapped against the other game's leg.
        ->and((int) $second->white_id)->toBe((int) $first->white_id)
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
});

test('a held combined final sets both of its legs aside, even when the board ids run ahead of the chess ids', function () {
    // The mirror of the restart above: hold() took the chess game's id, which a board table running ahead outnumbers.
    $outsiders = User::factory()->count(2)->create();

    foreach (range(1, 50) as $ignored) {
        BoardGame::query()->create(['game' => 'blockli', 'mode' => 'blitz', 'white_id' => $outsiders[0]->id, 'black_id' => $outsiders[1]->id, 'status' => 'aborted',
            'position' => '-', 'turn' => 'w', 'ply' => 0, 'initial_ms' => 300000, 'increment_ms' => 3000, 'white_ms' => 1, 'black_ms' => 1, 'turn_started_ms' => 0]);
    }

    $tournament = Tournament::factory()->create([
        'game' => 'chess', 'mode' => 'blitz', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['games' => ['chess', 'blockli'], 'thirdPlace' => false], GameProfile::for('chess', 'blitz'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'combined-hold',
    ]);

    foreach (range(1, 4) as $index) {
        $player = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    $round = fn (int $number) => $tournament->matches()->where('bracket', '!=', 'bye')->whereHas('round', fn ($query) => $query->where('number', $number))
        ->with('slots.participant', 'tournament')->orderBy('position')->get();

    // Every pairing plays chess, then Blockli: the higher seed wins both.
    foreach ([1, 2] as $number) {
        foreach ([ChessGame::class, BoardGame::class] as $table) {
            app(TournamentRunner::class)->sync($tournament->refresh());

            foreach ($round($number) as $match) {
                $leg = $table::query()->where('tournament_match_id', $match->id)->where('status', 'active')->sole();
                $loser = User::query()->findOrFail($match->slots[1]->participant->memberIds()[0]);
                $leg instanceof ChessGame ? app(ChessGameService::class)->resign($leg, $loser) : app(BoardGameService::class)->resign($leg, $loser);
            }
        }
    }

    $final = fn () => $round(2)->sole();

    expect($final()->result)->not->toBeNull()
        ->and(BoardGame::query()->where('tournament_match_id', $final()->id)->value('id'))->toBeGreaterThan(ChessGame::query()->where('tournament_match_id', $final()->id)->value('id'));

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    app(TournamentControl::class)->setResult($tournament->refresh(), $admin, $round(1)[0]->id, ['result' => '0-1'], 'The scoresheet said otherwise');

    expect($final()->held)->not->toBeNull()
        ->and(TournamentRunner::finishedLegs($final(), $tournament->refresh()))->toHaveCount(0);
});

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
});

test('the estimate plans every game of a sequence: chess blitz + Blockli blitz 28 minutes a pairing, a third game 42', function () {
    $profile = GameProfile::for('chess', 'blitz');
    $estimator = new Estimator;
    $minutes = function (array $games) use ($estimator, $profile): float {
        $options = FormatOptions::fromArray(['games' => $games], $profile);

        // Two players, one pairing: the planned minutes are that pairing's games, 14 each (chess and Blockli blitz).
        return $estimator->duration(TournamentFormat::SingleElimination, $estimator->structure(TournamentFormat::SingleElimination, 2, $options), $profile, $options, null)->total;
    };

    expect(Estimator::matchGames($profile, FormatOptions::fromArray(['games' => ['chess', 'blockli', 'blockspace']], $profile), true))->toBe(3)
        ->and($minutes(['chess', 'blockli']))->toBe(28.0)
        ->and($minutes(['chess', 'blockli', 'blockspace']))->toBe(42.0);
});

test('a forfeit in a table that counts game by game counts every game of the sequence', function () {
    $won = Engine\MatchResult::win(0);

    expect(Engine\Standings::perLeg([[1, 2, $won]], 3))->toHaveCount(3)
        ->and(Engine\Standings::perLeg([[1, 2, $won]]))->toHaveCount(2)
        ->and(FormatOptions::fromArray(['games' => ['chess', 'blockli', 'chess'], 'legPoints' => true], GameProfile::for('chess', 'blitz'))->legCount())->toBe(3);
});

test('a round robin of three games counted game by game: each game in the table, up to three points a pairing', function () {
    $tournament = sequenceTournament('chess', 'blitz', ['games' => ['chess', 'blockli', 'chess'], 'legPoints' => true], TournamentFormat::RoundRobin);
    combinedLose($tournament, 1);
    combinedLose($tournament, 0);

    // Two of three games played: both count at once, the pairing is still under way.
    expect(blockliTable($tournament)[0])->toBe(['Player 1' => ['1', 1, 0, 1], 'Player 2' => ['1', 1, 0, 1]]);

    combinedLose($tournament, 1);
    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    expect(blockliMatch($tournament)->result['legs'])->toHaveCount(3)
        ->and(blockliTable($tournament)[0])->toBe(['Player 1' => ['2', 2, 0, 1], 'Player 2' => ['1', 1, 0, 2]])
        ->and($part['leg_count'])->toBe(3);
});

/*
| A sequence the organizer composes (user, 2026-11-11: "dynamisch konfigurierbar"): 2..N games in order, repeats allowed,
| one leg each, then deciders by the tournament's rule; colours per game.
*/

/** The game slug of a chess or board game. */
function sequenceSlug(ChessGame|BoardGame $game): string
{
    return $game instanceof ChessGame ? 'chess' : (string) $game->game;
}

test('a three-game sequence plays its games in order as legs 1 to 3, and the points decide only after the third', function () {
    $tournament = sequenceTournament('blockli', 'blitz', ['games' => ['blockli', 'blockspace', 'chess']]);
    $played = [];

    // 2:0 after two legs still plays the third.
    foreach ([1, 1, 0] as $loser) {
        $live = combinedLive($tournament);
        $played[] = [sequenceSlug($live), $live->tournament_leg];

        expect(blockliMatch($tournament)->result)->toBeNull();
        combinedLose($tournament, $loser);
    }

    $result = blockliMatch($tournament)->result;

    expect($played)->toBe([['blockli', 1], ['blockspace', 2], ['chess', 3]])
        ->and($result['winner'])->toBe(0)
        ->and($result['decided'])->toBe('legs')
        ->and($result['legs'])->toEqual([[1, 0], [1, 0], [0, 1]]);
});

test('a repeated game: chess, Blockli, chess, colours alternating per game, and a missed first move restarts the leg in its game with its colours', function () {
    $tournament = sequenceTournament('chess', 'blitz', ['games' => ['chess', 'blockli', 'chess']]);
    $first = blockliSlotUser($tournament, 0);

    // Leg 1, chess, its first leg by lot (pinned: slot 0 White). Its first move is missed once: restarted as leg 1 with
    // the same colours, not drawn again (the lot now would swap them).
    Lottery::alwaysWin();
    $missed = combinedMissFirstMove($tournament);
    $leg1 = combinedLive($tournament);
    Lottery::alwaysLose();

    expect($leg1)->toBeInstanceOf(ChessGame::class)
        ->and($leg1->id)->not->toBe($missed->id)
        ->and($leg1->tournament_leg)->toBe(1)
        ->and((int) $leg1->white_id)->toBe($first);

    combinedLose($tournament, 1);

    // Leg 2, Blockli, its first leg by lot: slot 0 White again, not swapped against the chess leg.
    $leg2 = combinedLive($tournament);

    expect(sequenceSlug($leg2))->toBe('blockli')->and($leg2->tournament_leg)->toBe(2)->and((int) $leg2->white_id)->toBe($first);

    combinedLose($tournament, 0);

    // Leg 3, chess again: swapped against chess leg 1. Its own first move missed once restarts it, although
    // leg 1 already used a restart of the chess table.
    $leg3 = combinedLive($tournament);

    expect($leg3)->toBeInstanceOf(ChessGame::class)->and($leg3->tournament_leg)->toBe(3)->and((int) $leg3->black_id)->toBe($first);

    combinedMissFirstMove($tournament);
    $again = combinedLive($tournament);

    expect(blockliMatch($tournament)->result)->toBeNull()
        ->and($again)->toBeInstanceOf(ChessGame::class)
        ->and($again->id)->not->toBe($leg3->id)
        ->and($again->tournament_leg)->toBe(3)
        ->and((int) $again->white_id)->toBe((int) $leg3->white_id);

    combinedLose($tournament, 1);
    $result = blockliMatch($tournament)->result;

    expect($result['winner'])->toBe(0)
        ->and($result['legs'])->toEqual([[1, 0], [0, 1], [1, 0]])
        ->and(TournamentRunner::finishedLegs(blockliMatch($tournament), $tournament)->map(fn ($game): array => [sequenceSlug($game), $game->tournament_leg])->all())
        ->toBe([['chess', 1], ['blockli', 2], ['chess', 3]]);
});

test('the deciders after a level sequence: the games in turn (cycle), or always the first game (first)', function () {
    $tournament = sequenceTournament('blockli', 'blitz', ['games' => ['blockli', 'chess', 'blockspace']]);
    $first = sequenceTournament('blockli', 'blitz', ['games' => ['blockli', 'chess', 'blockspace'], 'decider' => 'first']);

    expect(array_map(fn (int $leg): string => TournamentRunner::legGame($tournament, $leg), range(0, 6)))
        ->toBe(['blockli', 'chess', 'blockspace', 'blockli', 'chess', 'blockspace', 'blockli'])
        ->and(array_map(fn (int $leg): string => TournamentRunner::legGame($first, $leg), range(0, 6)))
        ->toBe(['blockli', 'chess', 'blockspace', 'blockli', 'blockli', 'blockli', 'blockli']);

    // Played: chess and Blockli 1:1, a drawn first decider in chess, then the second decider by each rule.
    foreach (['cycle' => 'blockli', 'first' => 'chess'] as $decider => $second) {
        $played = sequenceTournament('chess', 'blitz', ['games' => ['chess', 'blockli'], 'decider' => $decider]);
        combinedLose($played, 1);
        combinedLose($played, 0);

        $decider1 = combinedLive($played);
        expect($decider1)->toBeInstanceOf(ChessGame::class)->and($decider1->tournament_leg)->toBe(3);

        combinedDraw($played);
        $decider2 = combinedLive($played);

        expect(blockliMatch($played)->result)->toBeNull()
            ->and(sequenceSlug($decider2))->toBe($second)
            ->and($decider2->tournament_leg)->toBe(4);

        combinedLose($played, 1);

        expect(blockliMatch($played)->result['winner'])->toBe(0)->and(blockliMatch($played)->result['decided'])->toBe('decider');
    }
});

test('a Blockli tournament of one game keeps its two legs on the single-game path, with no leg index on its games', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination);

    expect($tournament->formatOptions()->sequence())->toBeFalse();

    blockliLose($tournament, 1);
    blockliLive($tournament);

    expect(BoardGame::query()->where('tournament_match_id', blockliMatch($tournament)->id)->count())->toBe(2)
        ->and(BoardGame::query()->where('tournament_match_id', blockliMatch($tournament)->id)->whereNotNull('tournament_leg')->count())->toBe(0);
});

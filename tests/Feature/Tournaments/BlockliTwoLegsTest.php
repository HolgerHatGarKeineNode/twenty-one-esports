<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardInvites;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentGameEnd;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| Blockli pairings: two games with the colours swapped (plan "Blockli", P4)
|--------------------------------------------------------------------------
|
| The first move is an advantage, so a tournament or cup pairing plays two
| games, the second with the colours swapped. The points decide (a win 1, a
| draw ½); level points are a draw where the format allows one (round
| robin, Swiss), else a third game with colours drawn by lot decides, in the
| tournament's mode (correspondence: Blockli has no other since 2026-10-07).
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

afterEach(function () {
    Lottery::determineResultNormally();
});

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

test('at 1:1 a correspondence decider with colours drawn by lot decides the knockout pairing', function (bool $lot, int $whiteSlot) {
    $lot ? Lottery::alwaysWin() : Lottery::alwaysLose();
    $tournament = blockliTournament();
    blockliLose($tournament, 1);
    blockliLose($tournament, 0);
    $decider = blockliLive($tournament);

    expect($decider->tournament_game)->toBe(3)
        ->and($decider->mode)->toBe('correspondence')
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

    // Points per pairing, the default: the pairing is not in the table before its second game.
    expect(blockliTable($tournament))->toBe([['Player 1' => ['0', 0, 0, 0], 'Player 2' => ['0', 0, 0, 0]], true, false]);

    blockliLose($tournament, 0);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and(BoardGame::query()->where('status', BoardGameStatus::Active)->count())->toBe(0)
        ->and($match->result['winner'])->toBeNull()
        ->and($match->result['games_won'])->toEqual([1.0, 1.0])
        ->and(blockliTable($tournament))->toBe([['Player 1' => ['½', 0, 1, 0], 'Player 2' => ['½', 0, 1, 0]], true, false]);
});

test('the board games run no casual cup (correspondence only, user 2026-10-07), even listed in ESPORTS_CASUAL_CUP_GAMES', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockliOn::play();
    config(['esports.casual_cups.enabled' => ['chess', 'blockli', 'nine-mens-morris', 'checkers']]);

    expect(CasualCups::enabledGames())->toBe(['chess'])
        ->and(config('esports.casual_cups.games'))->not->toHaveKeys(['blockli', 'nine-mens-morris', 'checkers'])
        // A Blockli tournament still plays two games a pairing, one move a day.
        ->and(GameProfile::for('blockli', 'correspondence')->unit)->toBe('day');
});

test('the Blockli lobby queues blitz and rapid (user, 2026-10-11), and an invite without a mode still starts a correspondence game', function () {
    [$a, $c] = User::factory()->count(2)->create();

    Livewire\Livewire::actingAs($a)->test('pages::board.lobby', ['board' => 'blockli'])
        ->assertSeeHtml('data-test="play-blitz"')->assertSeeHtml('data-test="play-rapid"')
        ->call('findOpponent', false, 'rapid')->assertSeeHtml('data-test="lobby-searching"')->call('cancelSearch');

    expect(BoardGame::query()->count())->toBe(0);

    $a->forceFill(['looking_to_play' => 'blockli/correspondence'])->save();
    $invite = app(BoardInvites::class)->invite($c, $a, 'blockli');
    app(BoardInvites::class)->accept($invite, $a);
    $game = BoardGame::query()->findOrFail($invite->refresh()->board_game_id);

    expect($game->game)->toBe('blockli')
        ->and($game->mode)->toBe('correspondence');
});

test('after the first leg the bracket box and the game banner show the standing and the game under way, until the match is decided', function () {
    $tournament = blockliTournament();
    $box = fn (): array => (new TournamentView($tournament->refresh()))->box(blockliMatch($tournament));

    // Before any leg ended: nothing to show.
    expect($box()['legs'])->toBeNull()
        ->and(TournamentGameEnd::banner(blockliLive($tournament))['step'])->toBeNull();

    // The higher seed won the first leg: 1–0, game 2 under way, in the box and on the second game's banner (DerCaddy 2026-10-15).
    blockliLose($tournament, 1);
    expect($box()['legs'])->toMatchArray(['label' => '1–0', 'game' => 2])
        ->and(array_column($box()['sides'], 'score'))->toBe(['1', '0'])
        ->and(array_column($box()['sides'], 'won'))->toBe([false, false])
        ->and(TournamentGameEnd::banner(blockliLive($tournament))['step'])->toBe('Player 1 leads 1–0 · game 2 under way');

    // Decided: the stored result again (a board game's box scores the pairing 1 and 0), the winner raised.
    blockliLose($tournament, 1);
    expect($box()['legs'])->toBeNull()
        ->and(array_column($box()['sides'], 'won'))->toBe([true, false])
        ->and($box()['label'])->toBe('2–0');
});

test('the bracket box links every game of a pairing, the earlier legs too, with the score from the first side', function () {
    // DerCaddy 2026-10-30: from the bracket only the latest game of a pairing could be opened.
    $tournament = blockliTournament();
    $games = fn (): array => (new TournamentView($tournament->refresh()))->box(blockliMatch($tournament))['games'];
    $staged = fn (): array => collect((new TournamentView($tournament->refresh()))->stages())->flatMap(fn (array $stage) => $stage['parts'])
        ->flatMap(fn (array $part) => collect($part['sections'])->flatMap(fn (array $section) => $section['columns'])->flatMap(fn (array $column) => $column['matches']))
        ->first(fn (array $box): bool => $box['bracket'] !== 'bye')['games'];

    // One game: the key above the box opens it, no list.
    expect($games())->toBe([]);

    $first = blockliLose($tournament, 1);
    $second = blockliLive($tournament);
    $expected = [
        ['n' => 1, 'href' => route('board.show', $first), 'score' => '1–0', 'live' => false],
        ['n' => 2, 'href' => route('board.show', $second), 'score' => null, 'live' => true],
    ];

    // The bracket page reads all games in one query (stages()); the director page per match (box() alone): the same list.
    expect($games())->toBe($expected)
        ->and($staged())->toBe($expected);

    // 1:1 and a decider: three games, the second won by the second side (who had White then).
    blockliLose($tournament, 0);
    $decider = blockliLive($tournament);

    expect(array_column($games(), 'score'))->toBe(['1–0', '0–1', null])
        ->and(array_column($games(), 'href'))->toBe([route('board.show', $first), route('board.show', $second), route('board.show', $decider)]);
});

test('a board game\'s tournament banner lists the games of its pairing, this one marked, to switch between them', function () {
    // DerCaddy 2026-10-30: from game 2 the first leg was only reachable over the bracket.
    $tournament = blockliTournament();

    // One game so far: nothing to switch.
    expect(TournamentGameEnd::banner(blockliLive($tournament))['games'])->toBe([]);

    $first = blockliLose($tournament, 1);
    $second = blockliLive($tournament);
    $games = TournamentGameEnd::banner($second)['games'];

    expect(array_column($games, 'text'))->toBe(['Game 1 · won by Player 1', 'Game 2 · under way'])
        ->and(array_column($games, 'current'))->toBe([false, true])
        ->and(array_column($games, 'href'))->toBe([route('board.show', $first), route('board.show', $second)])
        // The first game's page has the same list, the other one marked.
        ->and(array_column(TournamentGameEnd::banner($first)['games'], 'current'))->toBe([true, false]);

    // After the pairing ended the list stays, with how each game ended.
    blockliLose($tournament, 0);
    blockliLose($tournament, 0);
    expect(array_column(TournamentGameEnd::banner($first->refresh())['games'], 'text'))
        ->toBe(['Game 1 · won by Player 1', 'Game 2 · won by Player 2', 'Game 3 · won by Player 2']);
});

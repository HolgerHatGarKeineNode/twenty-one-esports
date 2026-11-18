<?php

/*
| Blockspace and Go mine (user decision 2026-11-18): both are board games of
| the league's core, so a rated game of either is attested and mined on the
| path of every board game (BoardGameService::end(),
| SeasonChains::attestBoardGame()), at the board games' weights and out of
| their one share and daily limit (`board-games`). The season of every test
| here is released from the chain defaults as they are, with production's
| switches on.
|
| One rule is Blockspace's own: for consensus rule 2 it counts every
| transaction a side set as a move and a pass as none
| (Blockspace::minedMoves()), since its sides do not move in turn and a game
| sets 42 transactions at most. Every other board game counts full moves.
*/

use App\Enums\BoardGameStatus;
use App\Games\Blockli;
use App\Games\Blockspace;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\Go9;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Board\BlockspaceRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\RatedBoard;
use App\Support\Matches\ChainStamps;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\CheckersGame;
use Tests\Support\GoOn;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockspaceOn::play();
    GoOn::play();
    HyperOn::play();
    config(['esports.board_games.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/**
 * A live season released from the chain defaults as they are, with the values given over them.
 *
 * @param  array<string, mixed>  $parameters
 */
function defaultsSeason(array $parameters = []): Season
{
    return openSeason(['parameters' => [...array_intersect_key(ChainDraft::defaults(), array_flip(['weights', 'groups', 'shares', 'daily', 'pairlimit', 'subtree', 'moves'])), ...$parameters]]);
}

/**
 * A Blockspace game between two trusted players who list each other, with a deal of its own: both copies of the 21
 * forms in their order, begun at the form `$from`. Each deal is played by blockspacePlayedOut() to the same end.
 */
function blockspaceDeal(string $mode, int $from, User $white, User $black, bool $rated = true): BoardGame
{
    $game = app(BoardGameService::class)->start(Blockspace::SLUG, $white, $black, $mode, ratedGate: $rated ? app(RatedBoard::class)->pin($white, $black) : null);
    $rules = new BlockspaceRules;
    $forms = 'abcdefghijklmnopqrstuabcdefghijklmnopqrstu';
    $deck = substr($forms, $from).substr($forms, 0, $from);
    $game->forceFill(['position' => $rules->serialize([...$rules->deserialize($game->position), 'pool' => substr($deck, 0, BlockspaceRules::RUNGS), 'queue' => substr($deck, BlockspaceRules::RUNGS), 'deck' => $deck])])->save();

    return $game->refresh();
}

/** Plays a Blockspace game to its end by the rules, each side setting its first legal transaction; nobody passes. */
function blockspacePlayedOut(BoardGame $game): BoardGame
{
    $rules = new BlockspaceRules;

    while ($game->status === BoardGameStatus::Active) {
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $rules->legalMoves($rules->deserialize($game->position))[0], $game->ply + 1)->refresh();
    }

    return $game;
}

/**
 * A Go game on 9 x 9 played out and counted: Black builds two walls on the files c and d, White on f and g, `$stones`
 * stones in all, then both pass. Nothing is captured, each side counts its 18 points behind its walls, and White's
 * komi of 7.5 decides: the second player wins.
 */
function goCountedGame(User $first, User $second, int $stones, bool $rated = true, string $mode = 'rapid'): BoardGame
{
    $service = app(BoardGameService::class);
    $game = $service->start(Go9::SLUG, $first, $second, $mode, ratedGate: $rated ? app(RatedBoard::class)->pin($first, $second) : null);
    $moves = [];

    foreach ([['c', 'g'], ['d', 'f']] as [$black, $white]) {
        foreach (range(1, 9) as $rank) {
            array_push($moves, $black.$rank, $white.$rank);
        }
    }

    // Past the walls: stones inside each side's own area, which cost nothing under area scoring.
    array_push($moves, 'b1', 'h1', 'b2', 'h2');

    foreach ([...array_slice($moves, 0, $stones), 'pass', 'pass'] as $move) {
        $game = $service->move($game->refresh(), $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

test('a rated Blockspace game played out with 20 transactions or more mines one block for its winner at the weight of its mode', function (string $mode, int $from, int $reward) {
    $season = defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $game = blockspacePlayedOut(blockspaceDeal($mode, $from, $anna, $bert));

    $attestation = SeasonAttestation::query()->sole();
    $readout = app(SeasonChains::class)->readout($season);

    expect($game->only(['rated', 'result', 'end_reason', 'ply']))->toBe(['rated' => true, 'result' => '1-0', 'end_reason' => 'block_full', 'ply' => 20])
        ->and($attestation->only(['source', 'source_id', 'game', 'mode', 'height', 'reason', 'era', 'reward_per_player', 'reward']))
        ->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'game' => Blockspace::SLUG, 'mode' => $mode, 'height' => 1, 'reason' => null, 'era' => 1, 'reward_per_player' => $reward, 'reward' => $reward])
        ->and($attestation->candidate['moves'])->toBe(20)
        ->and($attestation->candidate['winners'])->toBe([$anna->pubkey])
        // The block is the board games', not a share of Blockspace's own.
        ->and($readout['mined_by_game_and_era'])->toBe(['board-games' => [1 => $reward]])
        ->and($readout['stored']['miners'])->toBe([$anna->pubkey => ['blocks' => 1, 'sats' => $reward]]);
})->with([
    'blitz, with the fuse' => ['blitz', 2, 2100],
    'rapid' => ['rapid', 14, 3150],
    'correspondence' => ['correspondence', 14, 4200],
]);

test('a Blockspace game played out with 19 transactions does not mine, and its stamp says too few moves', function () {
    defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $game = blockspacePlayedOut(blockspaceDeal('blitz', 6, $anna, $bert));

    $attestation = SeasonAttestation::query()->sole();
    $stamp = ChainStamps::for([SeasonAttestation::BOARD => [$game->id]])[SeasonAttestation::BOARD.':'.$game->id];

    expect($game->only(['result', 'end_reason', 'ply']))->toBe(['result' => '1-0', 'end_reason' => 'block_full', 'ply' => 19])
        ->and($attestation->only(['height', 'reason', 'subject', 'reward']))->toBe(['height' => null, 'reason' => 'too-few-moves', 'subject' => '19', 'reward' => 0])
        ->and($stamp['state'])->toBe('none')
        ->and($stamp['title'])->toBe('No block: too few moves');
});

test('a pass is no move of a Blockspace game: 19 transactions and six passes do not mine', function () {
    defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $rules = new BlockspaceRules;
    $game = blockspaceDeal('rapid', 14, $anna, $bert);

    // Six passes first, then 19 transactions, then Blue resigns: 25 plies, 13 full moves, 19 transactions.
    foreach (range(1, 25) as $ply) {
        $game = $service->move($game, $game->player($game->turn), $ply <= 6 ? BlockspaceRules::PASS : $rules->legalMoves($rules->deserialize($game->position))[0], $ply)->refresh();
    }

    $service->resign($game, $bert);

    expect($game->refresh()->only(['result', 'ply']))->toBe(['result' => '1-0', 'ply' => 25])
        ->and(SeasonAttestation::query()->sole()->only(['height', 'reason', 'subject']))->toBe(['height' => null, 'reason' => 'too-few-moves', 'subject' => '19']);
});

test('a rated Go game played out and counted over 20 full moves or more mines one block for its winner at the weight of its mode', function () {
    $season = defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $game = goCountedGame($anna, $bert, 40);

    $attestation = SeasonAttestation::query()->sole();
    $readout = app(SeasonChains::class)->readout($season);

    expect($game->only(['rated', 'result', 'end_reason', 'ply']))->toBe(['rated' => true, 'result' => '0-1', 'end_reason' => 'score', 'ply' => 42])
        ->and($attestation->only(['source', 'source_id', 'game', 'mode', 'height', 'reason', 'era', 'reward_per_player', 'reward']))
        ->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'game' => Go9::SLUG, 'mode' => 'rapid', 'height' => 1, 'reason' => null, 'era' => 1, 'reward_per_player' => 3150, 'reward' => 3150])
        ->and($attestation->candidate['moves'])->toBe(21)
        ->and($attestation->candidate['winners'])->toBe([$bert->pubkey])
        ->and($readout['mined_by_game_and_era'])->toBe(['board-games' => [1 => 3150]])
        ->and($readout['stored']['miners'])->toBe([$bert->pubkey => ['blocks' => 1, 'sats' => 3150]]);
});

test('Go counts full moves: a counted game of 36 stones is 19 moves and does not mine, and its stamp says too few moves', function () {
    defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    $game = goCountedGame($anna, $bert, 36);

    $stamp = ChainStamps::for([SeasonAttestation::BOARD => [$game->id]])[SeasonAttestation::BOARD.':'.$game->id];

    expect($game->only(['result', 'ply']))->toBe(['result' => '0-1', 'ply' => 38])
        ->and(SeasonAttestation::query()->sole()->only(['height', 'reason', 'subject']))->toBe(['height' => null, 'reason' => 'too-few-moves', 'subject' => '19'])
        ->and($stamp['title'])->toBe('No block: too few moves');
});

test('only Blockspace counts the transactions set: every other board game counts a move and its answer as one', function (string $game, int $moves) {
    // 38 moves and two passes at the end: 40 plies are 20 full moves, and 38 transactions set.
    expect(app(GameRegistry::class)->get($game)->minedMoves([...array_fill(0, 38, 'a1'), 'pass', 'pass']))->toBe($moves);
})->with([
    'Blockspace' => [Blockspace::SLUG, 38],
    'Go 9×9' => [Go9::SLUG, 20],
    'Go 13×13' => ['go-13', 20],
    'Go 19×19' => ['go-19', 20],
    'Blockli' => [Blockli::SLUG, 20],
    'nine men\'s morris' => [NineMensMorris::SLUG, 20],
    'checkers' => [Checkers::SLUG, 20],
]);

test('a casual game of Blockspace or Go is not attested, and a drawn rated one is attested without a block candidate', function () {
    defaultsSeason();
    [$anna, $bert] = User::factory()->count(2)->create();

    // Casual, each long enough to mine if it were rated.
    blockspacePlayedOut(blockspaceDeal('rapid', 14, $anna, $bert, rated: false));
    goCountedGame($anna, $bert, 40, rated: false);

    expect(SeasonAttestation::query()->count())->toBe(0);

    // Rated: Blockspace with equal yields at the end (109 to 109), Go drawn by agreement.
    $blockspace = blockspacePlayedOut(blockspaceDeal('rapid', 13, $anna, $bert));
    $go = app(BoardGameService::class)->start(Go9::SLUG, $anna, $bert, 'rapid', ratedGate: app(RatedBoard::class)->pin($anna, $bert));
    app(BoardGameService::class)->offerDraw($go, $anna);
    app(BoardGameService::class)->acceptDraw($go->refresh(), $bert);

    expect($blockspace->only(['rated', 'result', 'end_reason']))->toBe(['rated' => true, 'result' => '1/2-1/2', 'end_reason' => 'block_full'])
        ->and(SeasonAttestation::query()->orderBy('id')->get(['source_id', 'game', 'candidate', 'height', 'reason'])->toArray())->toBe([
            ['source_id' => $blockspace->id, 'game' => Blockspace::SLUG, 'candidate' => null, 'height' => null, 'reason' => null],
            ['source_id' => $go->id, 'game' => Go9::SLUG, 'candidate' => null, 'height' => null, 'reason' => null],
        ])
        // As for every board game: a draw has no stamp, so the strip says nothing of a block.
        ->and(ChainStamps::for([SeasonAttestation::BOARD => [$blockspace->id, $go->id]]))->toBe([]);
});

test('a practice game against a Go bot is judged in the browser\'s position: it stores no game and attests nothing', function () {
    defaultsSeason();

    $end = Livewire::test('pages::board.go-practice')->call('play', '4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3 x - 1', 'pass', [])->effects['returns'][0];

    expect($end['outcome'])->toBe(['result' => '1-0', 'reason' => 'score'])
        ->and(BoardGame::query()->count())->toBe(0)
        ->and(SeasonAttestation::query()->count())->toBe(0);
});

test('Blockspace and Go mine out of the board games\' one daily limit: a second block of the day in the other game is refused', function () {
    defaultsSeason(['daily' => [...ChainDraft::defaults()['daily'], 'board-games' => 1]]);
    [$anna, $bert, $cleo] = User::factory()->count(3)->create();

    blockspacePlayedOut(blockspaceDeal('rapid', 14, $anna, $bert));
    // Another opponent, another board game, the same day: Anna wins as White (the second player) on the count.
    goCountedGame($cleo, $anna, 40);

    expect(SeasonAttestation::query()->orderBy('id')->get(['game', 'height', 'reason', 'subject'])->toArray())->toBe([
        ['game' => Blockspace::SLUG, 'height' => 1, 'reason' => null, 'subject' => null],
        ['game' => Go9::SLUG, 'height' => null, 'reason' => 'player-daily-limit', 'subject' => $anna->pubkey],
    ]);
});

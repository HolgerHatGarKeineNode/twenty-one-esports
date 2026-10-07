<?php

/*
|--------------------------------------------------------------------------
| The board games on the stream and in the pride notes (plan "Mühle und Dame", P7)
|--------------------------------------------------------------------------
|
| The "board live" scene (d5) with and without a running board game, the
| rotation's board slot, and a board game win (and a tournament won with
| one) in the latest-win slide (e1) and its pride note.
|
*/

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BlockliOn;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

beforeEach(function () {
    Queue::fake();
});

/**
 * The board scene's data and SVG as the daemon builds them now.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function boardScene(?int $viewers = null): array
{
    $data = app(SceneSource::class)->rotation(RotationPlanner::BOARD_SCENE, null, [], 0, (int) now()->getTimestampMs(), app(StreamStats::class)->all());

    return [$data, SceneRenderer::fromConfig()->svg([...$data, 'viewers' => $viewers], RotationPlanner::VIEWS[RotationPlanner::BOARD_SCENE])];
}

/**
 * A finished two-player tournament of a board game: the first seed beat the second in its only game.
 *
 * @return array{0: Tournament, 1: BoardGame, 2: User, 3: User}
 */
function boardStreamTournamentWon(string $game): array
{
    $tournament = Tournament::factory()->create([
        'name' => 'Brett Cup', 'game' => $game, 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for($game, 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'brett-cup-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);
    $users = [];

    foreach ([1, 2] as $index) {
        $users[] = $user = User::factory()->create(['name' => "Seed {$index}"]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Seed {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $board = BoardGame::query()->whereNotNull('tournament_match_id')->sole();
    [$winner, $loser] = $board->white_id === $users[0]->id ? [$users[0], $users[1]] : [$users[1], $users[0]];
    $board = app(BoardGameService::class)->resign($board, $loser);

    return [$tournament->refresh(), $board, $winner, $loser];
}

/* ---------- The board scene (d5) --------------------------------------------------------------------------- */

test('with the board games switched off there is no board scene in the rotation, and the scene still renders', function () {
    [$data, $svg] = boardScene();

    expect(app(BoardScene::class)->state())->toBe(BoardScene::OFF)
        ->and($data['board'])->toBeNull()
        ->and($data['boards'])->toBe([])
        ->and($svg)->toContain('width="1280" height="720"')
        ->and($svg)->not->toContain('data-piece=');
});

test('without a running board game the scene is the teaser: both start positions, the claim and the facts', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    // A finished game is not on show.
    [$anna, $bert] = User::factory()->count(2)->create();
    app(BoardGameService::class)->abort(app(BoardGameService::class)->start(Checkers::SLUG, $anna, $bert), $anna);

    [$data, $svg] = boardScene();

    expect(app(BoardScene::class)->state())->toBe(BoardScene::IDLE)
        ->and($data['board'])->toBeNull()
        ->and(array_column($data['boards'], 'name'))->toBe(["Nine Men's Morris", 'Checkers'])
        ->and($svg)->toContain('>Board games</text>', 'Nine Men&#039;s Morris', '>Checkers</text>', '1 move a day, right in the browser.', 'Pick a player and send a challenge.')
        // Correspondence only since 2026-10-07: no blitz, no clock, no queue on the scene.
        ->and($svg)->not->toContain('Blitz')->not->toContain('5+3')->not->toContain('Find an opponent')
        // The morris board starts empty (24 points, 16 lines), checkers with twelve men a side.
        ->and(substr_count($svg, 'data-piece="w"'))->toBe(12)
        ->and(substr_count($svg, 'data-piece="b"'))->toBe(12)
        ->and(substr_count($svg, '<line '))->toBe(16)
        ->and($svg)->not->toContain('data-unit="mode"');
});

test('a running board game is on the scene: players, the time left for the move, the mode, the position and the last move, and no blitz', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $service = app(BoardGameService::class);
    $anna = User::factory()->create(['name' => 'Anna <script>alert(1)</script>']);
    $bert = User::factory()->create(['name' => 'Bert']);
    $game = $service->start(NineMensMorris::SLUG, $anna, $bert);
    $game = $service->move($game, $anna, 'd7');
    $game = $service->move($game->refresh(), $bert, 'a1');
    $game = $service->move($game->refresh(), $anna, 'g7');

    [$data, $svg] = boardScene(21);

    expect(app(BoardScene::class)->state())->toBe(BoardScene::LIVE)
        ->and($data['board'])->toMatchArray(['game' => "Nine Men's Morris", 'mode' => "Nine Men's Morris · Daily, casual", 'daily' => true, 'last' => ['g7']])
        ->and($data['board']['white'])->toMatchArray(['name' => 'Anna <script>alert(1)</script>', 'toMove' => false])
        ->and($data['board']['black'])->toMatchArray(['name' => 'Bert', 'toMove' => true])
        // The side to move has its day for the move (h:mm on the scene), the other side no clock.
        ->and($data['board']['black']['clockMs'])->toBeGreaterThan(86_300_000)->toBeLessThanOrEqual(86_400_000)
        ->and($svg)->toContain('>Nine Men&#039;s Morris · Daily, casual</text>', 'Anna &lt;script', '>Bert</text>', 'watching', '>1 move a day</text>')
        ->and(substr_count($svg, 'data-unit="clock"'))->toBe(2)
        ->and($svg)->not->toContain('<script>')->not->toContain('Blitz')->not->toContain('5+3')
        ->and(substr_count($svg, 'data-piece="w"'))->toBe(2)
        ->and(substr_count($svg, 'data-piece="b"'))->toBe(1)
        // The last move's point is marked.
        ->and($svg)->toContain('fill="#F7931A" fill-opacity="0.3"')
        ->and($svg)->not->toContain('Board games</text>');

    // A correspondence game started later has the latest turn: the scene shows the game moved in last.
    $this->travel(5)->seconds();
    $checkers = $service->start(Checkers::SLUG, User::factory()->create(), User::factory()->create());
    expect(boardScene()[0]['board']['game'])->toBe('Checkers');

    // Its end brings the other one back (or the teaser) on the next poll.
    $service->resign($checkers->refresh(), $checkers->white);
    expect(boardScene()[0]['board']['game'])->toBe("Nine Men's Morris");
});

test('a crowned checkers piece carries its ring on the scene', function () {
    CheckersGame::play();
    $game = CheckersGame::setUp(app(BoardGameService::class)->start(Checkers::SLUG, User::factory()->create(), User::factory()->create()), ['a1' => 'w', 'h8' => 'B', 'c7' => 'b']);

    [$data, $svg] = boardScene();

    expect($data['board']['game'])->toBe('Checkers')
        ->and(substr_count($svg, 'data-piece="bk"'))->toBe(1)
        ->and(substr_count($svg, 'data-piece="b"'))->toBe(1)
        ->and(substr_count($svg, 'data-piece="w"'))->toBe(1)
        // 32 dark cells, no lines.
        ->and(substr_count($svg, 'fill="#3F3F46"'))->toBe(32)
        ->and(substr_count($svg, '<line '))->toBe(0)
        ->and($game->id)->toBeInt();
});

test('a running Blockli game shows its blocks as bars, the blocks each side has left in its tray, and the credit (plan "Blockli", P5)', function () {
    BlockliOn::play();
    // White's pawn on e2, Black's on e8; White set e3h, Black c6v: nine blocks left each.
    $game = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', User::factory()->create(), User::factory()->create()), 'e2 e8 9 9 w e3hw,c6vb 0');

    [$data, $svg] = boardScene();
    $drawing = BoardScene::drawing($data['board']['view'], [], 48, 88, 544);
    $scale = 544 / 1020;

    expect($data['board']['mode'])->toStartWith('Blockli by DerCaddy · ')
        // Two pawns as plain discs: no king ring.
        ->and(substr_count($svg, 'data-piece="w"'))->toBe(1)
        ->and(substr_count($svg, 'data-piece="b"'))->toBe(1)
        ->and(substr_count($svg, 'data-piece="wk"') + substr_count($svg, 'data-piece="bk"'))->toBe(0)
        // The blocks on the board as bars, and the trays: nine blocks left a side.
        ->and(substr_count($svg, 'data-bar="w:block-h"'))->toBe(1)
        ->and(substr_count($svg, 'data-bar="b:block-v"'))->toBe(1)
        ->and(substr_count($svg, 'data-bar="w:spare"'))->toBe(9)
        ->and(substr_count($svg, 'data-bar="b:spare"'))->toBe(9)
        // A block spans two cells and the groove (80 + 20 + 80 view units), centred in the square with the trays.
        ->and(collect($drawing['bars'])->firstWhere('kind', 'block-h')['w'])->toEqualWithDelta(180 * $scale, 0.02)
        ->and($drawing['cells'][0][0])->toBeGreaterThan(48.0)
        ->and($game->id)->toBeInt();
});

/* ---------- The rotation ------------------------------------------------------------------------------------ */

/**
 * The slots a planner starts over `$seconds`, as "t kind scene".
 *
 * @return list<string>
 */
function boardRotation(RotationPlanner $planner, float $seconds, array $games, string $boards): array
{
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.25) {
        $slot = $planner->at($t, $games, [], $boards);
        $key = $slot['kind'].' '.$slot['scene'].' '.$slot['until'];

        if ($key !== $last) {
            $log[] = sprintf('%g %s %s', $t, $slot['kind'], $slot['scene'] ?? '-');
            $last = $key;
        }
    }

    return $log;
}

test('a live board game comes in every round after match and gallery, the teaser every third round, never while off', function () {
    $planner = fn (): RotationPlanner => new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15);
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]];

    expect(array_slice(boardRotation($planner(), 400, $games, BoardScene::LIVE), 0, 10))->toBe([
        '0 match a1', '60 gallery a2', '80 board d5', '125 teaser d1', '137 teaser d2', '149 teaser e1', '161 teaser a3', '173 teaser a4', '185 teaser a5',
        '197 match b1',
    ])
        ->and(array_slice(boardRotation($planner(), 400, $games, BoardScene::LIVE), 10, 3))->toBe(['257 gallery b2', '277 board d5', '322 teaser e4']);

    // Idle: a 12 s teaser in the first round, none in the next two, again in the fourth (rounds of 164, 152, 152 s).
    $idle = boardRotation($planner(), 1200, $games, BoardScene::IDLE);
    expect(array_values(array_filter($idle, fn (string $slot): bool => str_contains($slot, ' d5'))))->toBe(['80 board d5', '548 board d5', '1016 board d5'])
        ->and($idle[2])->toBe('80 board d5')
        ->and($idle[3])->toBe('92 teaser d1');

    // Without chess games: the loop first, then the board slot opens the next round.
    expect(array_slice(boardRotation($planner(), 200, [], BoardScene::LIVE), 0, 3))->toBe(['0 loop -', '30 board d5', '75 teaser d1'])
        ->and(implode(' ', boardRotation($planner(), 400, $games, BoardScene::OFF)))->not->toContain('d5');
});

/* ---------- Pride: the latest win (e1) and its note ---------------------------------------------------------- */

test('a board game win is the latest win when it is later than the latest chess win, with its page as the link', function () {
    CheckersGame::play();
    $kim = User::factory()->create(['name' => 'Kim']);
    $lou = User::factory()->create(['name' => 'Lou']);
    ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subHours(3)]);
    $board = app(BoardGameService::class)->start(Checkers::SLUG, $kim, $lou);
    $board = app(BoardGameService::class)->resign($board, $lou);

    $win = app(PrideSlides::class)->read()['win'];

    expect($win)->toMatchArray(['gameId' => $board->id, 'winner' => 'Kim', 'loser' => 'Lou', 'mode' => 'Checkers correspondence', 'tournament' => null, 'url' => route('board.show', $board)]);

    // A later chess win takes the slide back.
    $chess = ChessGame::factory()->finished('0-1')->create(['ended_at' => now()->addMinute()]);
    expect(app(PrideSlides::class)->read()['win'])->toMatchArray(['gameId' => $chess->id, 'mode' => 'Blitz chess', 'url' => route('games.show', $chess)]);
});

test('a recent board game win beats an older chess win, and board games switched off never show', function () {
    ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subDays(20)]);
    CheckersGame::play();
    $board = app(BoardGameService::class)->start(Checkers::SLUG, User::factory()->create(), User::factory()->create());
    app(BoardGameService::class)->resign($board, $board->black);
    $board->refresh()->forceFill(['ended_at' => now()->subDays(2)])->save();

    expect(app(PrideSlides::class)->read()['win']['url'])->toBe(route('board.show', $board));

    config(['esports.board_games.games.checkers.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(app(PrideSlides::class)->read()['win']['mode'])->toBe('Blitz chess');
});

test('the pride note of a board game win links the game and passes the stream bot copy rules', function () {
    CheckersGame::play();
    [$kim, $lou] = [keyedPlayer()[0], User::factory()->create(['name' => 'Lou #1'])];
    $board = app(BoardGameService::class)->start(Checkers::SLUG, $kim, $lou);
    app(BoardGameService::class)->resign($board, $lou);

    $note = app(PrideNotes::class)->compose(1, 0);

    expect($note)->not->toBeNull()
        ->and($note['body'])->toContain('nostr:npub1', 'Lou 1', '(checkers correspondence)', route('board.show', $board))
        ->and(StreamBotCopy::violations($note['body'], $note['tags']))->toBe([])
        ->and(collect($note['tags'])->where(0, 't')->all())->toBe([]);
});

test('a board game that won its winner a tournament is the tournament win on the slide and in the note', function () {
    CheckersGame::play();
    [$tournament, $board, $winner] = boardStreamTournamentWon(Checkers::SLUG);
    $winner->forceFill(['pubkey' => str_repeat('ab', 32)])->save();

    $pride = app(PrideSlides::class);
    $win = $pride->read()['win'];
    $svg = SceneRenderer::fromConfig()->svg(['pride' => $pride->framed($pride->read()), 'stats' => [], 'backdrop' => null, 'viewers' => null], RotationPlanner::VIEWS['e1']);

    expect($tournament->status)->toBe(TournamentStatus::Finished)
        ->and($win)->toMatchArray(['gameId' => $board->id, 'winner' => $winner->displayName(), 'tournament' => 'Brett Cup', 'url' => route('tournaments.show', $tournament)])
        ->and($svg)->toContain('Tournament win: Brett Cup')->not->toContain('Latest win');

    foreach ([0, 1] as $variant) {
        $note = app(PrideNotes::class)->compose(1, $variant);

        expect($note['body'])->toContain('Brett Cup', 'nostr:npub1', route('tournaments.show', $tournament))
            ->and(StreamBotCopy::violations($note['body'], $note['tags']))->toBe([]);
    }

    // A knockout's final decided it: the note says so.
    expect(app(PrideNotes::class)->compose(1, 0)['body'])->toContain('⚔️ Deciding game over Seed')
        ->and(app(PrideNotes::class)->compose(1, 1)['body'])->toContain('⚔️ Beat Seed');

    // A casual game won later is a plain win again.
    $casual = app(BoardGameService::class)->start(Checkers::SLUG, $winner, User::factory()->create());
    app(BoardGameService::class)->resign($casual, $casual->black);
    expect(app(PrideSlides::class)->read()['win'])->toMatchArray(['gameId' => $casual->id, 'tournament' => null]);
});

test('a table tournament (Swiss) won with a board game is a tournament win, but its last game is not called the deciding one', function () {
    CheckersGame::play();
    [$tournament, , $winner] = boardStreamTournamentWon(Checkers::SLUG);
    $winner->forceFill(['pubkey' => str_repeat('cd', 32)])->save();
    $tournament->forceFill(['format' => TournamentFormat::Swiss])->save();

    expect(app(PrideSlides::class)->read()['win'])->toMatchArray(['tournament' => 'Brett Cup', 'final' => false]);

    foreach ([0, 1] as $variant) {
        $note = app(PrideNotes::class)->compose(1, $variant);

        expect($note['body'])->toContain('Brett Cup', 'nostr:npub1', route('tournaments.show', $tournament))
            ->not->toContain('Deciding')->not->toContain('Beat ')
            ->and(StreamBotCopy::violations($note['body'], $note['tags']))->toBe([]);
    }
});

test('a tournament whose name cleans to nothing keeps the plain win note', function () {
    CheckersGame::play();
    [$tournament, , $winner] = boardStreamTournamentWon(Checkers::SLUG);
    $winner->forceFill(['pubkey' => str_repeat('ef', 32)])->save();
    $tournament->forceFill(['name' => '###'])->save();

    $note = app(PrideNotes::class)->compose(1, 0);

    expect($note['body'])->toContain('takes the win over', '(checkers correspondence)', route('tournaments.show', $tournament))
        ->not->toContain(' wins ')
        ->and(StreamBotCopy::violations($note['body'], $note['tags']))->toBe([]);
});

test('with the board game tables gone the pride slides still read, with the chess win', function () {
    CheckersGame::play();
    $chess = ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subHour()]);
    Schema::disableForeignKeyConstraints();
    Schema::drop('board_moves');
    Schema::drop('board_games');
    Schema::enableForeignKeyConstraints();

    $pride = app(PrideSlides::class)->read();

    expect($pride['win'])->toMatchArray(['gameId' => $chess->id, 'mode' => 'Blitz chess'])
        ->and($pride)->toHaveKeys(['climbers', 'signups', 'prizes']);
});

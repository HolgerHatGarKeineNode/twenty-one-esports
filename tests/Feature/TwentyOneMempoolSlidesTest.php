<?php

/*
| The mempool slide of the stream (m1, plan "Stream-Slides", P7): the games
| of every game as /matches shows them, casual while no season runs, and
| while one runs the season's mined blocks with their reward and miners plus
| the pending games. Voids never show as a win; board games only while on.
*/

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\ChessEndReason;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\AccountLink;
use App\Models\ChessGame;
use App\Models\FairPlayVoid;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\TwentyOne\Stream\MempoolLayout;
use App\Support\TwentyOne\Stream\MempoolSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

beforeEach(function () {
    Cache::flush();
});

/** The m1 slide as the stream renders it, through SceneSource like every rotation scene. */
function mempoolSlide(): string
{
    Cache::forget(MempoolSlides::CACHE_KEY);

    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation(MempoolSlides::SCENE, null, [], 0, 0, []), 'viewers' => null], RotationPlanner::VIEWS[MempoolSlides::SCENE]);
}

/**
 * A mined block of the season, as the league attests it: its height, the
 * block's reward and the pubkeys of the winners.
 *
 * @param  list<User>  $winners
 */
function mempoolMined(Season $season, string $source, int $id, int $height, int $reward, array $winners, string $game = 'chess', string $mode = 'blitz'): SeasonAttestation
{
    return SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => $source, 'source_id' => $id, 'label' => '#'.$id, 'game' => $game, 'mode' => $mode,
        'ladder_address' => 'x', 'attested_at' => now(), 'candidate' => ['winners' => array_map(fn (User $user): string => $user->pubkey, $winners)],
        'height' => $height, 'era' => 1, 'reward_per_player' => intdiv($reward, max(1, count($winners))), 'reward' => $reward, 'event_id' => bin2hex(random_bytes(32)),
    ]);
}

test('without a season the slide is the casual mempool: played games with the winner crowned, running ones, no voided series, English copy', function () {
    NineMensMorrisOn::play();
    $this->freezeTime();
    $ben = User::factory()->create(['name' => 'Ben <script>alert(1)</script>']);
    $zoe = User::factory()->create(['name' => 'Zoe']);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $ben->id, 'black_id' => $zoe->id, 'ended_at' => now()->subMinutes(10)]);
    mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '0-1', 'ended_at' => now()->subMinutes(5)]);
    $void = mempoolSeries(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()->subMinute()]);
    ChessGame::factory()->create(['ply' => 9]);
    // The stream stays English whatever the application's locale.
    App::setLocale('de');

    $data = app(MempoolSlides::class)->read();
    $svg = mempoolSlide();

    expect($data['mode'])->toBe('casual')
        ->and(array_column($data['finished'], 'slug'))->toBe(['chess', NineMensMorris::SLUG])
        ->and(array_column($data['running'], 'state'))->toBe(['live'])
        ->and(json_encode($data))->not->toContain('series-'.$void->id)
        ->and(App::getLocale())->toBe('de')
        ->and($svg)->toContain('width="1280" height="720"', '>Mempool<', 'Rated wins mine blocks once the season starts.', 'Ben &lt;script&gt;', 'beat Zoe', 'move 5', 'Nine Men&#039;s Morris', '>casual<')
        ->not->toContain('<script>')
        ->not->toContain(' sats')
        ->not->toContain('Schach')
        ->not->toContain('Zug');
});

test('a win nobody played for stays on the slide, uncrowned and labelled a forfeit, never "beat"', function () {
    NineMensMorrisOn::play();
    $this->freezeTime();
    $winner = User::factory()->create(['name' => 'Winner']);
    $absent = User::factory()->create(['name' => 'Absent']);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => $absent->id, 'ended_at' => now()->subMinutes(4), 'end_reason' => ChessEndReason::Forfeit]);
    mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()->subMinutes(3), 'end_reason' => BoardEndReason::Forfeit->value, 'white_id' => $winner->id, 'black_id' => $absent->id]);
    mempoolSeries(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Forfeit, 'result_games' => [], 'finished_at' => now()->subMinutes(2)]);
    // A game played to the end next to them keeps its crown.
    $played = User::factory()->create(['name' => 'Player']);
    ChessGame::factory()->finished('0-1')->create(['white_id' => $absent->id, 'black_id' => $played->id, 'ended_at' => now()->subSeconds(30)]);
    $first = app(MempoolSlides::class)->read();
    // A director's no-show result: the tournament match says forfeit (SeasonChains attests it so). Its tournament
    // runs games, so the right side takes a place and the oldest cube (the chess forfeit) leaves the row.
    $match = runningChess(TournamentFormat::SingleElimination, 4)->matches()->firstOrFail();
    $match->forceFill(['result' => [...(array) $match->result, 'forfeit' => true]])->save();
    ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => $absent->id, 'ended_at' => now(), 'end_reason' => ChessEndReason::Director, 'tournament_match_id' => $match->id]);

    $data = app(MempoolSlides::class)->read();
    $svg = mempoolSlide();

    expect(array_column($first['finished'], 'forfeit'))->toBe([true, true, true, false])
        ->and(array_column($data['finished'], 'forfeit'))->toBe([true, true, false, true])
        ->and(substr_count($svg, '>won by forfeit<'))->toBe(3)
        // Only the game played to the end: one "beat", one crown (partials/face draws it as this path).
        ->and(substr_count($svg, '>beat Absent<'))->toBe(1)
        ->and(substr_count($svg, 'd="M0 16L1.5 3.5L7 9L12 0L17 9L22.5 3.5L24 16Z"'))->toBe(1);
});

test('while a season runs the slide shows its mined blocks with height, reward and miners, never a voided one, and the pending games', function () {
    $season = openSeason();
    $ada = User::factory()->create(['name' => 'Ada']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $cat = User::factory()->create(['name' => 'Cat']);
    $first = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $ada->id]);
    $team = mempoolSeries(['rated' => true]);
    $reviewed = ChessGame::factory()->rated()->finished('1-0')->create();
    $linked = ChessGame::factory()->rated()->finished('1-0')->create();
    mempoolMined($season, SeasonAttestation::CHESS, $first->id, 811, 2100, [$ada]);
    mempoolMined($season, SeasonAttestation::SERIES, $team->id, 812, 4200, [$bob, $cat], 'rocket-league', '2v2');
    $void = mempoolMined($season, SeasonAttestation::CHESS, $reviewed->id, 813, 9999, [$ada]);
    SeasonBlockVoid::query()->create(['season_id' => $season->id, 'height' => 813, 'season_attestation_id' => $void->id, 'reason' => 'Farmed.', 'voided_by_pubkey' => str_repeat('a', 64)]);
    mempoolMined($season, SeasonAttestation::CHESS, $linked->id, 814, 7777, [$ada]);
    $link = AccountLink::query()->create(['main_pubkey' => $ada->pubkey, 'linked_pubkey' => $bob->pubkey, 'linked_by_pubkey' => $cat->pubkey, 'reason' => 'Same person.']);
    FairPlayVoid::query()->create(['account_link_id' => $link->id, 'source' => 'chess', 'source_id' => $linked->id, 'match_number' => null, 'previous' => []]);
    ChessGame::factory()->rated()->create(['ply' => 20]);

    $data = app(MempoolSlides::class)->read();
    $svg = mempoolSlide();

    expect($data['mode'])->toBe('season')
        ->and(array_column($data['blocks'], 'height'))->toBe([811, 812])
        ->and(array_column($data['blocks'], 'reward'))->toBe([2100, 4200])
        ->and(array_column($data['blocks'][1]['sides'], 'name'))->toBe(['Bob', 'Cat'])
        ->and($data['finished'])->toBe([])
        ->and(array_column($data['running'], 'state'))->toBe(['live'])
        ->and($svg)->toContain('Pre-Season chain', 'Block 811', 'Block 812', '2,100 sats<', '4,200 sats<', '>block reward<', '>2,100 sats each<', '>Ada<', 'Bob +1', '>Mempool<', 'move 11')
        ->not->toContain('Block 813')
        ->not->toContain('9,999')
        ->not->toContain('Block 814')
        ->not->toContain('7,777')
        // The total is named the block's reward; only the team block adds each winner's share.
        ->and(substr_count($svg, '>block reward<'))->toBe(2)
        ->and(substr_count($svg, ' sats each<'))->toBe(1);
});

test('an empty mempool invites to play, casual and in a season', function () {
    $casual = mempoolSlide();
    openSeason();
    $season = mempoolSlide();

    expect($casual)->toContain('The mempool is empty.', 'Start a game and it lands here first.', 'Your game', 'Start one now')
        ->and(substr_count($casual, 'stroke-dasharray="6 4"'))->toBe(15)
        ->and($season)->toContain('Pre-Season chain', 'The first one is up for grabs.', 'Next block', 'Win a rated game', 'Your game')
        ->not->toContain('Block 0');
});

test('with the board games off the slide shows no board game cube or block, and switches to the season version by itself', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $morris = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()]);
    mempoolBoard(Checkers::SLUG, ['ply' => 4]);
    ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subMinute()]);
    $slides = app(MempoolSlides::class);
    $on = $slides->read();

    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $off = $slides->read();

    NineMensMorrisOn::play();
    CheckersGame::play();

    $season = openSeason();
    mempoolMined($season, SeasonAttestation::BOARD, $morris->id, 5, 1000, [User::factory()->create()], NineMensMorris::SLUG);
    $live = $slides->read();

    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $liveOff = $slides->read();
    $svg = mempoolSlide();

    expect(array_column($off['finished'], 'slug'))->toBe(['chess'])
        ->and($off['running'])->toBe([])
        ->and(array_column($on['finished'], 'slug'))->toBe(['chess', NineMensMorris::SLUG])
        ->and(array_column($on['running'], 'slug'))->toBe([Checkers::SLUG])
        ->and([$off['mode'], $on['mode'], $live['mode']])->toBe(['casual', 'casual', 'season'])
        ->and(array_column($live['blocks'], 'height'))->toBe([5])
        ->and($liveOff['blocks'])->toBe([])
        ->and($svg)->not->toContain('Morris')
        ->not->toContain('Checkers');
});

test('a full side leaves a place for the invitation and keeps the row inside the frame, casual and in a season', function () {
    $games = ChessGame::factory()->count(6)->finished('1-0')->create();
    $casual = app(MempoolSlides::class)->read();
    $season = openSeason();
    foreach ($games as $i => $game) {
        mempoolMined($season, SeasonAttestation::CHESS, $game->id, $i + 1, 100, [User::factory()->create()]);
    }
    $chain = app(MempoolSlides::class)->read();

    foreach ([$casual, $chain] as $data) {
        $cubes = MempoolLayout::layout(app(MempoolSlides::class)->framed($data))['cubes'];

        expect(count($cubes))->toBe(MempoolSlides::COLUMNS)
            ->and(end($cubes)['ghost'])->toBe('ghost-game')
            ->and(min(array_column($cubes, 'x')))->toBeGreaterThanOrEqual(40)
            ->and(max(array_column($cubes, 'x')) + MempoolLayout::COL)->toBeLessThanOrEqual(1240);
    }

    expect(count($casual['finished']))->toBe(4)
        ->and(array_column($chain['blocks'], 'height'))->toBe([3, 4, 5, 6]);
});

test('the slide reads a fixed number of queries for any number of games and blocks, and once per cache period', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $season = openSeason();
    $seed = function (int $count) use ($season): void {
        foreach (range(1, $count) as $i) {
            $series = mempoolSeries(['rated' => true, 'finished_at' => now()->subSeconds($i)]);
            SeriesMatch::factory()->accepted()->create();
            $chess = ChessGame::factory()->rated()->finished('1-0')->create(['ended_at' => now()->subSeconds($i)]);
            ChessGame::factory()->create(['ply' => $i]);
            mempoolBoard(Checkers::SLUG, ['ply' => $i]);
            $height = SeasonAttestation::query()->max('height') + 1;
            mempoolMined($season, SeasonAttestation::SERIES, $series->id, $height, 1000, [User::factory()->create(), User::factory()->create()], 'rocket-league', '2v2');
            mempoolMined($season, SeasonAttestation::CHESS, $chess->id, $height + 1, 500, [User::factory()->create()]);
        }
    };
    $count = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MempoolSlides::class)->all();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Below every query's limit (five a kind in the strip, five blocks), so a query per game or block would show.
    $seed(1);
    $one = $count();
    $seed(1);
    $two = $count();
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(MempoolSlides::class)->all();
    $cached = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($two)->toBe($one)
        // Four mined, three shown: two places stay with the running games.
        ->and(count(app(MempoolSlides::class)->read()['blocks']))->toBe(3)
        ->and($cached)->toBe(0);
});

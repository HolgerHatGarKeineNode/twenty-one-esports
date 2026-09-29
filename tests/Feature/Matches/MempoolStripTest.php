<?php

/*
| The mempool strip on /matches (plan "Mempool-Streifen", P1): the matches of
| every game, casual and rated, played and waiting; not blocks. A finished
| rated match that mined shows its block of the season chain and links to
| /mining; a voided or unmined one says so briefly; before Block 0 nothing.
*/

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Matches\MatchBlocks;
use App\Support\Matches\MempoolStrip;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\Resolution;
use App\Support\Series\SeriesPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

test('the strip shows every game, casual and rated, finished and running, merged by time and framed as the mempool', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $this->freezeTime();

    $series = mempoolSeries(['finished_at' => now()->subMinutes(50)]);
    $blitz = ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subMinutes(40)]);
    $daily = ChessGame::factory()->daily()->rated()->finished('1/2-1/2')->create(['ended_at' => now()->subMinutes(30)]);
    $morris = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '0-1', 'ended_at' => now()->subMinutes(20)]);
    $checkers = mempoolBoard(Checkers::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()->subMinutes(10), 'rated' => true]);
    $running = ChessGame::factory()->create(['ply' => 9]);
    $scheduled = SeriesMatch::factory()->accepted()->create(['start_at' => now()->addHour()]);
    ChessGame::factory()->create(['status' => ChessGameStatus::Aborted]);

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    // Oldest left, the newest next to the divider; running before scheduled; the aborted game never happened.
    expect(stripCubes($html))->toBe([
        'rocket-league:fin', 'chess:fin', 'chess:fin', NineMensMorris::SLUG.':fin', Checkers::SLUG.':fin',
        'chess:live', 'rocket-league:next',
    ]);

    // Every game in its colour family; the legend names exactly the games on screen.
    preg_match_all('/data-test="strip-legend-game" data-game="([^"]+)"/', $html, $legend);
    expect($legend[1])->toBe(['rocket-league', 'chess', NineMensMorris::SLUG, Checkers::SLUG])
        ->and($html)->toContain('bs-cube g-morris is-fin')->toContain('bs-cube g-checkers is-fin')->toContain('bs-cube g-rl is-fin')
        ->and($html)->toContain('>Mempool</p>')
        ->toContain('Matches of every game, played and waiting.')
        ->toContain('href="'.route('mining').'"')
        ->toContain('href="'.route('board.show', $morris).'"')
        ->toContain('href="'.route('games.show', $daily).'"')
        // The match number stays a match number; a casual board game has none.
        ->toContain('<span class="bs-num" data-test="strip-number">'.$series->label().'</span>')
        ->toContain('<span class="bs-num" data-test="strip-number">'.$blitz->number().'</span>')
        ->toContain('<span class="bs-num" data-test="strip-number">#'.$checkers->number.'</span>')
        ->toContain('<span class="bs-num" data-test="strip-number"></span>')
        ->and($running->id)->toBeInt()->and($scheduled->id)->toBeInt();
});

test('a finished match without a winner reads void or draw in its cube, never playing', function () {
    $annulled = mempoolSeries(['status' => SeriesStatus::Resolved, 'winner' => null, 'resolution' => SeriesResolution::Void, 'result_games' => []]);
    $draw = mempoolSeries(['winner' => 'none', 'result_games' => [['winner' => 'challenger', 'challenger' => 1, 'challenged' => 0], ['winner' => 'challenged', 'challenger' => 0, 'challenged' => 1]]]);
    $noResult = mempoolSeries(['status' => SeriesStatus::Resolved, 'winner' => null, 'resolution' => SeriesResolution::Admin, 'result_games' => []]);

    expect(SeriesPresenter::block($annulled)['who'])->toBe('void')
        ->and(SeriesPresenter::block($draw)['who'])->toBe('Draw')
        ->and(SeriesPresenter::block($noResult)['who'])->toBe('no result')
        ->and(MatchBlocks::chess(ChessGame::factory()->finished('1/2-1/2')->create())['who'])->toBe('Draw')
        ->and(SeriesPresenter::block($annulled)['state'])->toBe('fin');

    NineMensMorrisOn::play();
    expect(MatchBlocks::board(mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1/2-1/2', 'ended_at' => now()]))['who'])->toBe('Draw');

    // In German too: the annulled #40 read "läuft".
    app()->setLocale('de');
    expect(SeriesPresenter::block($annulled)['who'])->toBe('annulliert')->not->toBe('läuft');
});

test('a mined rated match shows its block and links to it; a voided or unmined one says so; a casual one and a draw show nothing', function () {
    $season = openSeason();
    $mined = mempoolSeries(['rated' => true, 'finished_at' => now()->subMinutes(5)]);
    $voided = ChessGame::factory()->rated()->finished('1-0')->create(['ended_at' => now()->subMinutes(4)]);
    $rejected = ChessGame::factory()->rated()->finished('0-1')->create(['ended_at' => now()->subMinutes(3)]);
    $drawn = ChessGame::factory()->rated()->finished('1/2-1/2')->create(['ended_at' => now()->subMinutes(2)]);
    $casual = ChessGame::factory()->finished('1-0')->create(['ended_at' => now()->subMinute()]);

    mempoolAttest($season, SeasonAttestation::SERIES, $mined->id, 812);
    $void = mempoolAttest($season, SeasonAttestation::CHESS, $voided->id, 813);
    SeasonBlockVoid::query()->create(['season_id' => $season->id, 'height' => 813, 'season_attestation_id' => $void->id, 'reason' => 'Farmed between two accounts.', 'voided_by_pubkey' => str_repeat('a', 64)]);
    mempoolAttest($season, SeasonAttestation::CHESS, $rejected->id, null, 'not-trusted');
    mempoolAttest($season, SeasonAttestation::CHESS, $drawn->id, null, null, candidate: false);
    // The casual game's id under another source: a stamp must never cross sources.
    mempoolAttest($season, SeasonAttestation::BOARD, $casual->id, 900);

    $blocks = collect(MempoolStrip::build()['finished'])->keyBy('key');

    expect($blocks['series-'.$mined->id]['chain'])->toMatchArray(['state' => 'mined', 'height' => 812, 'href' => route('mining').'#block-812', 'text' => 'Block 812'])
        ->and($blocks['chess-'.$voided->id]['chain'])->toMatchArray(['state' => 'void', 'height' => 813, 'text' => 'Block 813 void', 'title' => 'Farmed between two accounts.'])
        ->and($blocks['chess-'.$rejected->id]['chain'])->toMatchArray(['state' => 'none', 'height' => null, 'href' => null, 'text' => 'no block', 'title' => 'No block: a player is not Trusted yet'])
        ->and($blocks['chess-'.$drawn->id]['chain'])->toBeNull()
        ->and($blocks['chess-'.$casual->id]['chain'])->toBeNull()
        ->and($blocks['series-'.$mined->id]['aria'])->toEndWith(', Block 812');

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="strip-block"'))->toBe(2)
        ->and($html)->toContain('href="'.route('mining').'#block-812"')
        ->toContain('is-mined')
        ->toContain('Block 813 void')
        ->toContain('data-test="strip-no-block"')
        ->not->toContain('#block-900');
});

test('/mining carries the anchor of each latest block, where the strip links a mined match', function () {
    $season = openSeason();
    $miner = User::factory()->create();
    $loser = User::factory()->create();
    $candidate = new Candidate('#412', 'series:1', 'rocket-league', 'rocket-league/1v1', CarbonImmutable::now(), Resolution::Confirmed, null,
        [$miner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:1', 'lineup:2'], [$miner->pubkey, $loser->pubkey], true,
        [$miner->pubkey => 100, $loser->pubkey => 100], [], []);
    SeasonAttestation::query()->create([
        'season_id' => $season->id, 'source' => 'series', 'source_id' => 1, 'label' => '#412', 'game' => 'rocket-league', 'mode' => '1v1',
        'ladder_address' => 'x', 'attested_at' => $candidate->attestedAt, 'candidate' => $candidate->toArray(), 'height' => 1, 'era' => 1,
        'reward_per_player' => 20_000, 'reward' => 20_000, 'link_event_id' => $season->genesisId(), 'event_id' => str_repeat('c', 64),
    ]);

    $this->get(route('mining'))->assertOk()->assertSee('id="block-1"', false);
});

test('before Block 0 there is no chain: the strip shows matches and no block, without errors', function () {
    planBlock0(now()->addDay()->toIso8601String());
    mempoolSeries(['rated' => true]);
    ChessGame::factory()->rated()->finished('1-0')->create();

    expect(Season::query()->count())->toBe(0)
        ->and(collect(MempoolStrip::build()['finished'])->pluck('chain')->filter()->all())->toBe([]);

    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toHaveCount(2)
        ->and($html)->not->toContain('data-test="strip-block"')
        ->not->toContain('data-test="strip-no-block"')
        ->not->toContain('class="bs-chain"');
});

test('with the board games off, or one of them off, /matches shows no cube, chip or row of it, and no link to a board route', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $morris = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now(), 'rated' => true]);
    $checkers = mempoolBoard(Checkers::SLUG, ['ply' => 4]);
    ChessGame::factory()->finished('1-0')->create();

    // Nine men's morris switched off on its own: checkers stays.
    config(['esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toBe(['chess:fin', Checkers::SLUG.':live'])
        ->and($html)->not->toContain('data-game="'.NineMensMorris::SLUG.'"')
        ->not->toContain('href="'.route('board.show', $morris).'"')
        ->toContain('href="'.route('board.show', $checkers).'"');

    // All board games off: none of them anywhere, the page still answers.
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $html = $this->get(route('matches.index'))->assertOk()->getContent();

    expect(stripCubes($html))->toBe(['chess:fin'])
        ->and($html)->not->toContain('/board/')
        ->not->toContain('data-game="'.Checkers::SLUG.'"');
});

test('the board games stay out while their route is not registered, even switched on (a route table cached with the switch off)', function () {
    config(['esports.board_games.enabled' => true, 'esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
    mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()]);

    expect(Route::has('board.show'))->toBeFalse()
        ->and(MempoolStrip::boardSlugs())->toBe([]);

    $this->get(route('matches.index'))->assertOk()->assertDontSee('data-game="'.NineMensMorris::SLUG.'"', false);
});

test('/matches asks the same number of queries for 1, 5 and 25 matches of every kind, strip and table together', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $season = openSeason();

    $seed = function (int $count) use ($season): void {
        foreach (range(1, $count) as $i) {
            $series = mempoolSeries(['rated' => true, 'finished_at' => now()->subSeconds($i)]);
            SeriesMatch::factory()->accepted()->create();
            $chess = ChessGame::factory()->finished('1-0')->create(['rated' => true, 'ended_at' => now()->subSeconds($i)]);
            ChessGame::factory()->create(['ply' => $i]);
            $board = mempoolBoard($i % 2 === 0 ? NineMensMorris::SLUG : Checkers::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()->subSeconds($i), 'rated' => true]);
            mempoolBoard($i % 2 === 0 ? Checkers::SLUG : NineMensMorris::SLUG, ['ply' => $i]);
            mempoolAttest($season, SeasonAttestation::SERIES, $series->id, 1000 + $series->id);
            mempoolAttest($season, SeasonAttestation::CHESS, $chess->id, 2000 + $chess->id);
            mempoolAttest($season, SeasonAttestation::BOARD, $board->id, 3000 + $board->id);
        }
    };
    // Cold caches both times: the footer counters are cached after the first request.
    $count = function (): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('matches.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // 5 and 25 per kind both fill the strip (5 a side) and the table's first page (20 rows), so a query per cube
    // or per row costs the same at both sizes. One per kind renders fewer cubes and rows: that step catches it.
    $seed(1);
    $one = $count();
    $seed(4);
    $five = $count();
    $seed(20);
    $twentyFive = $count();

    expect($five)->toBe($one)
        ->and($twentyFive)->toBe($five)
        ->and(SeriesMatch::query()->count())->toBe(50)
        ->and(BoardGame::query()->count())->toBe(50);
});

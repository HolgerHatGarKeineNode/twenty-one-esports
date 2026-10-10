<?php

use App\Enums\StackerRunStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Engagement\HomeBoard;
use App\Support\Engagement\HomeHub;
use App\Support\Engagement\Quests;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\TournamentDraws;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

/*
 * The score games on home (Blockfill live, 2026-10-01): every registered
 * score game has its card in the grid of the ladders, with the top three of
 * its current leaderboard by their best value, and a verified run of it
 * counts for the weekly "play 3 games" quest; a practice run does not.
 */

beforeEach(function () {
    $this->withoutVite();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    // A Wednesday: the Blockfill week started on Monday 2026-10-05 00:00 Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
});

/** A ranked run of `$user` with `$ticks`, decided by the verifier job as verified (it joins the week). */
function homeVerifiedRun(User $user, int $ticks, ?CarbonInterface $at = null): StackerRun
{
    $at ??= now();
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying,
        'issued_at' => $at,
        'started_at' => $at,
        'submitted_at' => $at,
        'ticks' => $ticks,
        'state_hash' => '00000000',
        'replay' => 'AAAA',
    ]);

    VerifyStackerRun::dispatchSync($run->id);

    return $run->refresh();
}

test('the Blockfill week leads a guest\'s start page with its time to beat, and HomeHub keeps the top three by best time', function () {
    BlockfillOn::play();
    app(BlockfillWeeks::class)->open();
    // Rendered once while empty: the cached places must not outlive the next run.
    expect($this->get(route('home'))->assertOk()->getContent())->toContain(e(__('#1 is free')));

    // 958 ticks = 15.966 s; four players, the slowest is fourth and not in the top three.
    foreach ([['zapmaster', 958], ['lena.k', 1200], ['hodlqueen', 1100], ['slowpoke', 3000]] as [$name, $ticks]) {
        homeVerifiedRun(User::factory()->create(['name' => $name]), $ticks);
    }

    $html = $this->get(route('home'))->assertOk()->getContent();
    $band = str($html)->after('data-test="home-hero" data-hero="blockfill"')->before('data-test="live-bar"')->toString();
    $board = collect((new HomeHub(null))->scores())->firstWhere('game', Blockfill::SLUG);

    expect($band)->toContain('0:15.966')->toContain('zapmaster')->toContain('href="'.route('stacker.play').'"')
        ->not->toContain(e(__('#1 is free')))
        ->and(array_column($board['rows'], 'name'))->toBe(['zapmaster', 'hodlqueen', 'lena.k'])
        ->and(array_column($board['rows'], 'value'))->toBe(['0:15.966', '0:18.333', '0:20.000'])
        ->and($board['weekly'])->toBeTrue();
});

test('a week nobody has a verified run in yet invites the first one, with a Play link; an unopened week has no band', function () {
    BlockfillOn::play();

    // Last week is still running (its review time), with a run in it: not this week's band.
    app(BlockfillWeeks::class)->open(now()->subWeek());
    homeVerifiedRun(User::factory()->create(['name' => 'lastweek']), 958, now()->subWeek());
    expect(ScoreRun::query()->count())->toBe(1);

    // Not opened yet (the hourly job comes later): no Blockfill band.
    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-hero="blockfill"');

    app(BlockfillWeeks::class)->open();
    $band = str($this->get(route('home'))->assertOk()->getContent())->after('data-hero="blockfill"')->before('data-test="live-bar"')->toString();

    expect($band)->toContain(e(__('#1 is free')))
        ->toContain('href="'.route('stacker.play').'"')
        ->toContain(e(__('Start a practice run')))
        ->not->toContain('lastweek');
});

test('with the switch off there is no Blockfill tile, and turned on there is one among the browser games', function () {
    $off = $this->get(route('home'))->assertOk()->getContent();

    BlockfillOn::play();
    $on = $this->get(route('home'))->assertOk()->getContent();

    expect($off)->not->toContain('data-test="game-tile" data-game="blockfill"')
        ->and(str($on)->after('data-test="browser-games"')->before('data-test="browser-games-list"')->toString())->toContain('data-test="game-tile" data-game="blockfill"');
});

test('the browser games keep the plan\'s order, Blockfill right after chess', function () {
    BlockfillOn::play();
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match_all('#data-test="game-tile" data-game="([a-z0-9-]+)"#', str($html)->after('data-test="browser-games"')->before('data-test="browser-games-list"')->toString(), $tiles);

    expect($tiles[1])->toBe(array_values(array_intersect(HomeBoard::BROWSER_ORDER, array_keys(app(GameRegistry::class)->all()))));
});

test('a verified ranked run counts for "play 3 games", a practice run and an unchecked value do not', function () {
    BlockfillOn::play();
    $player = User::factory()->create();
    $quests = app(Quests::class);

    // A practice run (it did not beat the week's best, so it was never verified), an unchecked and a rejected
    // manual value, and a director's entry (a correction, not a game played).
    StackerRun::factory()->for($player)->create(['status' => StackerRunStatus::Practice, 'submitted_at' => now(), 'ticks' => 2000]);
    $value = ['user_id' => $player->id, 'game' => Blockfill::SLUG, 'mode' => Blockfill::MODE, 'course' => Blockfill::MODE, 'unit' => 'ms', 'achieved_at' => now()];
    ScoreRun::query()->create($value + ['value' => 30_000, 'source' => ScoreRun::MANUAL]);
    ScoreRun::query()->create($value + ['value' => 31_000, 'source' => ScoreRun::MANUAL, 'verified_at' => now(), 'rejected_at' => now()]);
    ScoreRun::query()->create($value + ['value' => 32_000, 'source' => ScoreRun::DIRECTOR, 'verified_at' => now()]);

    expect($quests->progress($player)[Quests::THREE_GAMES]['progress'])->toBe(0);

    homeVerifiedRun($player, 1500);
    homeVerifiedRun($player, 1400, now()->addMinute());

    expect(ScoreRun::query()->where(['user_id' => $player->id, 'source' => 'replay'])->count())->toBe(2)
        ->and($quests->progress($player)[Quests::THREE_GAMES])->toBe(['progress' => 2, 'target' => 3, 'done' => false]);

    // Last week's run is not this week's game.
    ScoreRun::query()->create(['user_id' => $player->id, 'game' => Blockfill::SLUG, 'mode' => Blockfill::MODE, 'course' => Blockfill::MODE,
        'value' => 20_000, 'unit' => 'ms', 'source' => 'replay', 'achieved_at' => now()->subWeek(), 'verified_at' => now()->subWeek()]);

    expect($quests->progress($player)[Quests::THREE_GAMES]['progress'])->toBe(2);

    homeVerifiedRun($player, 1300, now()->addMinutes(2));

    expect($quests->progress($player)[Quests::THREE_GAMES])->toBe(['progress' => 3, 'target' => 3, 'done' => true])
        ->and($this->actingAs($player)->get(route('home'))->assertOk()->getContent())->toContain('3/3');
});

test('the score card costs the same queries for two players or twenty', function () {
    BlockfillOn::play();
    app(BlockfillWeeks::class)->open();

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get(route('home'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $grow = function (int $size): void {
        foreach (range(1, $size) as $index) {
            homeVerifiedRun(User::factory()->create(), 1000 + $index * 10, now()->addSeconds($index));
        }
    };

    // A cold render (every cache empty: the places read from the leaderboard) and a warm one (the places from the cache).
    $grow(2);
    $this->get(route('home'))->assertOk();
    Cache::flush();
    $fewCold = $queries();
    $fewWarm = $queries();
    $grow(18);
    Cache::flush();
    $manyCold = $queries();
    $manyWarm = $queries();
    fwrite(STDERR, "\n[home-scores] guest home queries, Blockfill on: cold {$fewCold}/{$manyCold}, warm {$fewWarm}/{$manyWarm} (2/20 players)\n");

    // A player with runs of their own: the quests count them in one query, however many there are.
    $player = User::factory()->create();
    homeVerifiedRun($player, 900, now()->addMinutes(5));
    $this->actingAs($player)->get(route('home'))->assertOk();
    $playerOne = $queries();
    homeVerifiedRun($player, 890, now()->addMinutes(6));
    homeVerifiedRun($player, 880, now()->addMinutes(7));
    $this->get(route('home'))->assertOk();
    $playerThree = $queries();
    fwrite(STDERR, "[home-scores] player home queries, warm: {$playerOne} with one run, {$playerThree} with three\n");

    expect($manyCold)->toBe($fewCold)->and($manyWarm)->toBe($fewWarm)->and($fewWarm)->toBeLessThan($fewCold)
        ->and($playerThree)->toBe($playerOne);
});

test('the minute tick leaves an open Blockfill week running, and a run after it still reaches the board', function () {
    // 2026-10-05: the week opened with nobody on it, the tournament tick's sync() read "no open match" as done and ended it 50 s later.
    BlockfillOn::play();
    $week = app(BlockfillWeeks::class)->open();

    app(TournamentDraws::class)->advanceDue();
    homeVerifiedRun(User::factory()->create(['name' => 'latecomer']), 1300);

    expect($week->refresh()->status)->toBe(TournamentStatus::Running)
        ->and($this->get(route('home'))->assertOk()->getContent())->toContain('latecomer');
});

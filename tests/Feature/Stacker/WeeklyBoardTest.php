<?php

/*
| The casual weekly hunt of Blockfill (plan "Blockfill", P4): a verified run
| joins its player to the week's leaderboard (Monday 00:00 to Monday 00:00
| Europe/Berlin) and is stored as a score run of the replay source; the
| score kind ranks it (lowest time, a tie to the earlier submission), ends it
| and gives the points per place. The verifier is a fake
| (Tests\Support\FakeStackerVerifier); the verdict goes through the real job.
*/

use App\Enums\StackerRunStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Scores\ScoreAccount;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScorePoints;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\Sources\ReplayScoreSource;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;

beforeEach(function () {
    $this->withoutVite();
    $this->verifier = new FakeStackerVerifier;
    $this->app->instance(Verifier::class, $this->verifier);
    // A Wednesday: this week started on Monday 2026-10-05 00:00 Berlin (CEST), 2026-10-04 22:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
});

/**
 * A run of `$user` handed in at `$at` with `$ticks`, decided by the verifier job as verified.
 */
function weeklyRun(User $user, int $ticks, CarbonInterface $at): StackerRun
{
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

function blockfillWeek(?CarbonInterface $at = null): ?Tournament
{
    return app(BlockfillWeeks::class)->current($at);
}

test('switched off, Blockfill is no score game: no route, no registry entry, no scheduled week, and a verified run joins nothing', function () {
    $user = User::factory()->create();
    $run = weeklyRun($user, 3000, now());

    expect(app(GameRegistry::class)->find(Blockfill::SLUG))->toBeNull()
        ->and(app(GameRegistry::class)->scores())->toBe([])
        ->and(Route::has('stacker.play'))->toBeFalse()
        ->and(Route::has('scores.show'))->toBeFalse()
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'blockfill:weeks')))->toBeFalse()
        ->and($run->status)->toBe(StackerRunStatus::Verified)
        ->and(app(BlockfillWeeks::class)->sweep())->toBe(['opened' => false, 'joined' => 0, 'read' => 0])
        ->and(Tournament::query()->count())->toBe(0)
        ->and(ScoreRun::query()->count())->toBe(0);

    $this->artisan('blockfill:weeks')->expectsOutputToContain('Blockfill is off')->assertSuccessful();
    expect(Tournament::query()->count())->toBe(0);
});

test('switched on, Blockfill is a score game timed in milliseconds without an account or manual submissions, and the weeks are scheduled', function () {
    BlockfillOn::play();
    $game = app(GameRegistry::class)->get(Blockfill::SLUG);
    $mode = $game->mode(Blockfill::MODE);

    // routes/console.php decides at boot (switch off here); read again with the switch on, the hourly week job is there.
    require base_path('routes/console.php');

    expect($game)->toBeInstanceOf(Blockfill::class)
        ->and($game->kind())->toBe(GameKind::Score)
        ->and(array_keys(app(GameRegistry::class)->scores()))->toContain(Blockfill::SLUG)
        ->and(app(GameRegistry::class)->versus())->not->toHaveKey(Blockfill::SLUG)
        ->and($game->metric($mode)->unit)->toBe('ms')
        ->and($game->metric($mode)->lowerIsBetter())->toBeTrue()
        ->and($game->accountService())->toBeNull()
        ->and($game->acceptsManual())->toBeFalse()
        ->and($game->sources())->toBe([ReplayScoreSource::class])
        // 60 ticks a second, to the millisecond: 2 ticks are 33 ms, 3001 ticks 50.016 s.
        ->and(Blockfill::milliseconds(2))->toBe(33)
        ->and(Blockfill::milliseconds(3001))->toBe(50_016)
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'blockfill:weeks') && $event->expression === '0 * * * *'))->toBeTrue();
});

test('a verified run joins its player to the week\'s leaderboard and is stored as a score run, once per player', function () {
    BlockfillOn::play();
    [$ada, $bob] = User::factory()->count(2)->create();

    weeklyRun($ada, 3000, now()->subHour());
    weeklyRun($ada, 2950, now()->subMinutes(30));
    weeklyRun($bob, 2990, now()->subMinutes(10));

    $week = blockfillWeek();
    $board = TournamentMatch::query()->where(['tournament_id' => $week->id, 'bracket' => 'board'])->with('slots')->sole();
    $rows = app(ScoreRuns::class)->standings($week);

    expect($week->slug)->toBe('blockfill-2026-10-05')
        ->and($week->status)->toBe(TournamentStatus::Running)
        ->and($week->format)->toBe(TournamentFormat::Leaderboard)
        ->and($week->score_course)->toBe(Blockfill::MODE)
        ->and($week->published_at)->not->toBeNull()
        ->and($week->starts_at->toIso8601String())->toBe('2026-10-04T22:00:00+00:00')
        // Automatic participation: one entry and one board slot per player, in the order they came; no sign-up.
        ->and(TournamentParticipant::query()->where('tournament_id', $week->id)->orderBy('seed')->pluck('user_id')->all())->toBe([$ada->id, $bob->id])
        ->and($board->slots->pluck('tournament_participant_id')->all())->toBe(TournamentParticipant::query()->where('tournament_id', $week->id)->orderBy('seed')->pluck('id')->all())
        ->and($week->signups()->count())->toBe(0)
        ->and(ScoreRun::query()->where(['user_id' => $ada->id, 'source' => ReplayScoreSource::KEY])->orderBy('value')->pluck('value')->all())->toBe([49_166, 50_000])
        ->and(array_map(fn ($row) => [$row->participant->user_id, $row->place, $row->value], $rows))->toBe([
            [$ada->id, 1, 49_166],
            [$bob->id, 2, 49_833],
        ]);

    // The hourly sweep reads both bests again and finds nothing new: nobody joins twice, no run is stored twice.
    $stored = ScoreRun::query()->count();
    expect(app(BlockfillWeeks::class)->sweep())->toBe(['opened' => true, 'joined' => 0, 'read' => 2])
        ->and(ScoreRun::query()->count())->toBe($stored)
        ->and(Tournament::query()->where('game', Blockfill::SLUG)->count())->toBe(1);
});

test('the week runs from Monday 00:00 to Monday 00:00 Berlin, 167 or 169 hours across the clock change', function () {
    BlockfillOn::play();
    $weeks = app(BlockfillWeeks::class);

    // Summer time: Monday 00:00 CEST is Sunday 22:00 UTC.
    expect(BlockfillWeeks::startOf(CarbonImmutable::parse('2026-10-04 21:59:59.999'))->toIso8601String())->toBe('2026-09-27T22:00:00+00:00')
        ->and(BlockfillWeeks::startOf(CarbonImmutable::parse('2026-10-04 22:00:00'))->toIso8601String())->toBe('2026-10-04T22:00:00+00:00');

    // Autumn: summer time ends on Sunday 2026-10-25, so the week from Monday 2026-10-19 has 169 hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00'));
    $autumn = ScoreWindow::of($weeks->open());
    expect($autumn->start->toIso8601String())->toBe('2026-10-18T22:00:00+00:00')
        ->and($autumn->end->toIso8601String())->toBe('2026-10-25T23:00:00+00:00')
        ->and((int) $autumn->start->diffInHours($autumn->end))->toBe(169)
        ->and($autumn->contains(CarbonImmutable::parse('2026-10-25 22:59:59')))->toBeTrue()
        ->and($autumn->contains(CarbonImmutable::parse('2026-10-25 23:00:00')))->toBeFalse();

    // Spring: summer time starts on Sunday 2027-03-28, so the week from Monday 2027-03-22 has 167 hours.
    $this->travelTo(CarbonImmutable::parse('2027-03-24 12:00:00'));
    $spring = ScoreWindow::of($weeks->open());
    expect($spring->start->toIso8601String())->toBe('2027-03-21T23:00:00+00:00')
        ->and($spring->end->toIso8601String())->toBe('2027-03-28T22:00:00+00:00')
        ->and((int) $spring->start->diffInHours($spring->end))->toBe(167);

    // A run on the last millisecond of a week counts there; one on the first of the next counts in the next.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 23:30:00'));
    $user = User::factory()->create();
    $late = weeklyRun($user, 3000, CarbonImmutable::parse('2026-10-25 22:59:59.999'));
    $next = weeklyRun($user, 3100, CarbonImmutable::parse('2026-10-25 23:00:00.000'));

    expect($late->week)->toBe('2026-10-19')
        ->and($next->week)->toBe('2026-10-26')
        ->and(app(ScoreRuns::class)->standings($weeks->find(CarbonImmutable::parse('2026-10-18 22:00:00')))[0]->value)->toBe(50_000)
        ->and(app(ScoreRuns::class)->standings(blockfillWeek())[0]->value)->toBe(51_666);
});

test('a tie goes to the earlier submission', function () {
    BlockfillOn::play();
    [$late, $early] = User::factory()->count(2)->create();

    // The later one joins first, so the seed order alone would rank it first.
    weeklyRun($late, 3000, now()->subMinutes(5));
    weeklyRun($early, 3000, now()->subMinutes(20));

    expect(array_map(fn ($row) => [$row->participant->user_id, $row->place], app(ScoreRuns::class)->standings(blockfillWeek())))
        ->toBe([[$early->id, 1], [$late->id, 2]]);
});

test('the week ends after its review time with points per place from the score table, and starts no rating, series, game or cup', function () {
    BlockfillOn::play();
    [$a, $b, $c] = User::factory()->count(3)->create();
    weeklyRun($a, 3200, now());
    weeklyRun($b, 3000, now());
    weeklyRun($c, 3100, now());
    $week = blockfillWeek();
    $game = app(GameRegistry::class)->get(Blockfill::SLUG);

    // Window closed (Monday 00:00 Berlin), review time not over yet: nothing ends.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 22:30:00'));
    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(1)
        ->and($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(app(ScorePoints::class)->awarded($week, $game))->toBe([$b->id => 25, $c->id => 18, $a->id => 15])
        ->and(app(ScorePoints::class)->ladder($game, Blockfill::MODE))->toBe([$b->id => 25, $c->id => 18, $a->id => 15])
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(SeriesMatch::query()->count())->toBe(0)
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(BoardGame::query()->count())->toBe(0)
        ->and(Tournament::query()->count())->toBe(1)
        ->and(Tournament::query()->whereNotNull('cup_series')->count())->toBe(0)
        ->and(CasualCups::enabledGames())->not->toContain(Blockfill::SLUG);
});

test('a run outside the week\'s window does not count there, and a finished week takes no late run', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    $start = BlockfillWeeks::startOf(now());

    weeklyRun($user, 3000, now());
    // Faster, but handed in last week: verified now, it never counts in this week.
    $before = weeklyRun($user, 2000, $start->subSecond());
    $week = blockfillWeek();
    $course = new ScoreCourse(app(GameRegistry::class)->get(Blockfill::SLUG), app(GameRegistry::class)->get(Blockfill::SLUG)->mode(Blockfill::MODE), Blockfill::MODE);
    $window = ScoreWindow::of($week);

    expect(app(ReplayScoreSource::class)->bestFor(new ScoreAccount($user->id), $course, $window->start, $window->end)?->value)->toBe(50_000)
        ->and(app(ScoreRuns::class)->standings($week)[0]->value)->toBe(50_000)
        // Last week was never opened (nobody played), and it is not opened late.
        ->and(app(BlockfillWeeks::class)->previous())->toBeNull()
        ->and(ScoreRun::query()->where('value', 33_333)->exists())->toBeFalse()
        ->and($before->status)->toBe(StackerRunStatus::Verified);

    // The week is over and finished: a run of it verified only now changes nothing.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    app(ScoreLeaderboards::class)->tick();
    $frozen = weeklyRun($user, 1500, $window->end->subHour());

    expect($week->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($frozen->status)->toBe(StackerRunStatus::Verified)
        ->and(ScoreRun::query()->where('value', 25_000)->exists())->toBeFalse()
        ->and(app(ScoreRuns::class)->standings($week)[0]->value)->toBe(50_000);
});

test('a player with a faster all-time best still gets the first run of a new week verified, and it joins that week', function () {
    BlockfillOn::play();
    $this->freezeTime();
    $user = User::factory()->create();
    // Last week's best: far faster than the reference run below.
    StackerRun::factory()->for($user)->verified(500)->create(['submitted_at' => now()->subWeek(), 'week' => StackerRuns::weekOf(now()->subWeek())]);

    $forty = BlockfillOn::fixture('forty-lines');
    $issued = $this->actingAs($user)->postJson(route('stacker.runs.issue'))->assertCreated()->assertJson(['best' => null, 'best_all_time' => 500])->json();
    $this->postJson(route('stacker.runs.start', $issued['token']))->assertNoContent();
    $this->travel(intdiv($forty['expected']['ticks'] * 1000, 60) + 500)->milliseconds();
    $this->postJson(route('stacker.runs.submit', $issued['token']), ['replay' => $forty['replay'], 'ticks' => $forty['expected']['ticks'], 'hash' => $forty['expected']['stateHash']])
        ->assertStatus(202)->assertJson(['status' => 'verifying']);

    $run = StackerRun::query()->where('seed', $issued['seed'])->sole();
    $rows = app(ScoreRuns::class)->standings(blockfillWeek());

    expect($run->status)->toBe(StackerRunStatus::Verified)
        ->and([$rows[0]->participant->user_id, $rows[0]->place, $rows[0]->value])->toBe([$user->id, 1, Blockfill::milliseconds($forty['expected']['ticks'])]);
    $this->getJson(route('stacker.runs.show', $issued['token']))->assertOk()->assertJson(['best' => $forty['expected']['ticks'], 'best_all_time' => 500]);

    // Within the same week the gate stays: a run that does not beat this week's best is practice.
    $again = $this->postJson(route('stacker.runs.issue'))->assertCreated()->json();
    $this->postJson(route('stacker.runs.start', $again['token']))->assertNoContent();
    $this->travel(intdiv($forty['expected']['ticks'] * 1000, 60) + 500)->milliseconds();
    $this->postJson(route('stacker.runs.submit', $again['token']), ['replay' => $forty['replay'], 'ticks' => $forty['expected']['ticks'], 'hash' => $forty['expected']['stateHash']])
        ->assertStatus(202)->assertJson(['status' => 'practice']);
});

test('a run the hook missed joins on the hourly sweep', function () {
    BlockfillOn::play();
    $user = User::factory()->create();
    // Verified while the switch was off: no week, no entry.
    config(['esports.blockfill.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    weeklyRun($user, 3000, now());
    expect(Tournament::query()->count())->toBe(0);

    BlockfillOn::play();
    $this->artisan('blockfill:weeks')->expectsOutputToContain('Joined 1 player(s), read 1 best run(s).')->assertSuccessful();

    expect(app(ScoreRuns::class)->standings(blockfillWeek())[0]->participant->user_id)->toBe($user->id);
});

test('the game page shows this week\'s board, your place and last week\'s winner, and scores/blockfill shows the board; both answer a roundtrip', function () {
    BlockfillOn::play();
    [$winner, $me, $other] = User::factory()->count(3)->create();

    // Empty at first: no week yet, no winner.
    $this->get(route('stacker.play'))->assertOk()
        ->assertSee(__('Nobody has a verified run this week yet. Yours could be the first.'))
        ->assertSee(__('No winner last week.'))
        ->assertSee(__('Log in and play a ranked run to get on the board.'));

    // Last week (its Thursday), then this week.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    weeklyRun($winner, 2800, now());
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    weeklyRun($other, 2900, now()->subHour());
    weeklyRun($me, 3000, now()->subMinutes(30));

    Livewire::actingAs($me)->test('pages::stacker.play')
        ->assertSeeInOrder([$other->displayName(), $me->displayName()])
        ->assertSeeHtml('data-test="stacker-week-place">#2</b>')
        ->assertSeeHtml('data-test="stacker-week-winner-name">'.e($winner->displayName()).'</a>')
        ->assertDontSee(__('No winner last week.'))
        ->call('$refresh')->assertOk();

    Livewire::test('pages::scores.show', ['game' => Blockfill::SLUG])
        ->assertSee('Blockfill Week 41, 2026')
        ->assertSeeInOrder([__('Leaderboards'), $other->displayName(), $me->displayName(), __('Points ladder')])
        // the course is the mode itself: the card names the mode, not its slug
        ->assertSeeHtml('data-test="score-board-mode">40 blocks')
        ->assertDontSeeHtml('font-mono">40-blocks')
        ->call('$refresh')->assertOk();

    // In German the week is "Woche", the mode "40 Blöcke"; the stored name stays English.
    app()->setLocale('de');
    Livewire::test('pages::scores.show', ['game' => Blockfill::SLUG])
        ->assertSee('Blockfill Woche 41, 2026')
        ->assertSeeHtml('data-test="score-board-mode">40 Blöcke')
        ->assertDontSee('Blockfill Week 41');
    app()->setLocale('en');
    expect(blockfillWeek()->name)->toBe('Blockfill Week 41, 2026');

    // A player sees the week's best, the all-time best and, quietest, their practice best.
    Livewire::actingAs($me)->test('pages::stacker.play')
        ->assertSeeInOrder([__('Best this week'), __('All-time best'), __('Practice best')])
        ->assertSeeHtml('data-test="best-practice"');

    $this->get(route('scores.show', Blockfill::SLUG))->assertOk();
    $this->get(route('tournaments.scores', blockfillWeek()))->assertOk()->assertSee($me->displayName());
    $this->get(route('tournaments.show', blockfillWeek()))->assertOk();
});

test('the week\'s page names its course by the mode above the board, "40 blocks", never the slug', function () {
    BlockfillOn::play();
    weeklyRun(User::factory()->create(), 3000, now());

    // The line above the board alone: the slug still stands in links and data attributes.
    $windowLine = function (string $url): string {
        preg_match('/data-test="score-window">(.*?)<\/span>\s*<\/span>/s', $this->get($url)->assertOk()->getContent(), $line);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($line[1] ?? '')));
    };

    expect($windowLine(route('tournaments.show', blockfillWeek())))->toEndWith('· 40 blocks')->not->toContain('40-blocks')
        ->and($windowLine(route('tournaments.show', blockfillWeek()).'?lang=de'))->toEndWith('· 40 Blöcke')->not->toContain('40-blocks');
});

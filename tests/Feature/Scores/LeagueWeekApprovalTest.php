<?php

use App\Enums\NotificationKind;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\LeagueWeek;
use App\Models\NostrEvent;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\User;
use App\Support\Scores\LeagueWeekDrafts;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\TmnfNotes;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\TwentyOne\Stream\BlockfillSlide;
use App\Support\TwentyOne\Stream\TmnfSlide;
use App\Support\TwentyOne\Stream\TmnfSlides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The admins approve every league week (user 2026-10-02)
|--------------------------------------------------------------------------
|
| "Bevor eine neue Woche pro Spiel beginnt, ob Blockfill oder Trackmania,
| müssen die Admins selbst die Einstellungen justieren können … Also kein
| automatisches weiter für die Woche." Week 41 of 2026 runs (Monday
| 2026-10-05 00:00 Berlin, 2026-10-04 22:00 UTC) on Blockfill Hard and on
| TMNF A02-Race with 10 minutes a round; week 42 starts Monday 2026-10-12
| 00:00 Berlin (2026-10-11 22:00 UTC) and its draft is due Thursday
| 2026-10-08 12:00 Berlin (10:00 UTC).
|
*/

const WEEK_41 = '2026-10-04 22:00:00';
const WEEK_42 = '2026-10-11 22:00:00';
const TRACK_A02 = 'JwKdDsOUh4L9_eYyRsdiA2o1fW1';
const TRACK_A05 = 'I7rI7jAga6C4tGAe5OTDoyLF2fh';

beforeEach(function () {
    Cache::flush();
    $this->withoutVite();
    BlockfillOn::play();
    tmnfOn();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $this->admin->pubkey]);

    // Week 41 as the admins approved it, opened by the hourly runs on Wednesday.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    leagueWeeksApproved(Blockfill::SLUG, ['difficulty' => 'bf1hard'], before: 0, after: 0);
    leagueWeeksApproved(TrackmaniaNationsForever::SLUG, ['track' => TRACK_A02, 'time_limit_minutes' => 10], before: 0, after: 0);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('tmnf:weeks')->assertSuccessful();
});

/** The plan of week 42 of `$game`, if the drafts made it. */
function week42(string $game): ?LeagueWeek
{
    return LeagueWeek::query()->where('game', $game)->where('starts_at', WEEK_42)->first();
}

/** The hourly runs of both games at `$at` (UTC). */
function weeksAt(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at));
    test()->artisan('blockfill:weeks')->assertSuccessful();
    test()->artisan('tmnf:weeks')->assertSuccessful();
}

/**
 * The bell entries of the league week approvals a user has, oldest first (one second: by title): their titles.
 *
 * @return list<string>
 */
function approvalBell(User $user): array
{
    return $user->notifications()->where('type', NotificationKind::LeagueWeekApproval->value)->reorder()->orderBy('created_at')->get()->sortBy(fn ($entry): string => $entry->getRawOriginal('created_at').' '.$entry->data['title'])->map(fn ($entry): string => $entry->data['title'])->values()->all();
}

test('week 41 runs on its approved settings, and the next week is a draft with them from Thursday 12:00 Berlin on, made once', function () {
    $blockfill = app(BlockfillWeeks::class)->current();
    $tmnf = app(TmnfWeeks::class)->current();

    weeksAt('2026-10-08 09:59:59');
    $before = [week42(Blockfill::SLUG), week42(TrackmaniaNationsForever::SLUG)];

    weeksAt('2026-10-08 10:00:00');
    weeksAt('2026-10-08 11:00:00');

    expect($blockfill->starts_at->toDateTimeString())->toBe(WEEK_41)
        ->and(app(BlockfillWeeks::class)->difficultyOf($blockfill))->toBe('bf1hard')
        ->and($tmnf->score_course)->toBe(TRACK_A02)
        ->and($before)->toBe([null, null])
        ->and(LeagueWeek::query()->where('starts_at', WEEK_42)->count())->toBe(2)
        ->and(week42(Blockfill::SLUG)->settings)->toBe(['difficulty' => 'bf1hard'])
        ->and(week42(TrackmaniaNationsForever::SLUG)->settings)->toBe(['track' => TRACK_A02, 'time_limit_minutes' => 10])
        ->and(week42(Blockfill::SLUG)->isApproved())->toBeFalse()
        ->and(week42(TrackmaniaNationsForever::SLUG)->isApproved())->toBeFalse()
        // Configurable: Friday 18:00 Berlin of the week before.
        ->and(LeagueWeekDrafts::draftMoment(CarbonImmutable::parse(WEEK_42))->toDateTimeString())->toBe('2026-10-08 10:00:00')
        ->and((function () {
            config(['esports.league_weeks.draft_weekday' => 5, 'esports.league_weeks.draft_time' => '18:00']);

            return LeagueWeekDrafts::draftMoment(CarbonImmutable::parse(WEEK_42))->toDateTimeString();
        })())->toBe('2026-10-09 16:00:00');
});

test('a draft, approved or not, shows on no public surface: no board, no week page, no calendar event, no bot note, no stream slide, no score page line', function () {
    weeksAt('2026-10-08 10:00:00');
    // An admin picked A05-Race for TMNF week 42 and approved it; Blockfill week 42 stays a draft.
    app(LeagueWeekDrafts::class)->update(week42(TrackmaniaNationsForever::SLUG), ['track' => TRACK_A05, 'time_limit_minutes' => null]);
    app(LeagueWeekDrafts::class)->approve(week42(TrackmaniaNationsForever::SLUG)->refresh(), $this->admin);
    weeksAt('2026-10-10 12:00:00');
    config(['esports.stream_bot.enabled' => true, 'esports.stream_bot.nsec' => (new TestSigner)->secret]);
    $now = CarbonImmutable::now();
    $calendar = NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->get()->map(fn (NostrEvent $event): string => (string) collect($event->payload()['tags'])->firstWhere(0, 'd')[1])->sort()->values()->all();
    $notes = collect([...app(BlockfillNotes::class)->due($now), ...app(TmnfNotes::class)->due($now)])->map(fn (array $due): string => $due['week']->slug)->all();
    $pages = [
        $this->get(route('scores.show', Blockfill::SLUG))->assertOk()->getContent(),
        $this->get(route('scores.show', TrackmaniaNationsForever::SLUG))->assertOk()->getContent(),
        $this->get(route('stacker.play'))->assertOk()->getContent(),
        $this->get(route('home'))->assertOk()->getContent(),
        $this->get(route('tournaments.index'))->assertOk()->getContent(),
    ];
    $slides = json_encode([app(BlockfillSlide::class)->data($now), app(TmnfSlide::class)->data($now), app(TmnfSlides::class)->data($now)]);

    expect(Tournament::query()->whereIn('slug', ['blockfill-2026-10-12', 'tmnf-2026-10-12'])->count())->toBe(0)
        // Positive control: week 41's events are there, week 42 has none.
        ->and($calendar)->toBe(['blockfill-2026-10-05', 'tmnf-2026-10-05'])
        // Positive control: the bot would announce week 41 of both games, never week 42.
        ->and($notes)->toContain('blockfill-2026-10-05')->toContain('tmnf-2026-10-05')->not->toContain('blockfill-2026-10-12')->not->toContain('tmnf-2026-10-12')
        ->and(implode('', $pages))->toContain('Week 41')->not->toContain('Week 42')->not->toContain('A05-Race')
        ->and($slides)->toContain('A02-Race')->not->toContain('Week 42')->not->toContain('A05-Race');
});

test('the admins get a bell entry for each draft once, and a reminder 24 hours before the start while it is not approved', function () {
    $player = User::factory()->create();
    $organizer = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);

    weeksAt('2026-10-08 10:00:00');
    weeksAt('2026-10-08 11:00:00');
    $drafted = approvalBell($this->admin);

    // TMNF is approved on Saturday; Blockfill is not.
    app(LeagueWeekDrafts::class)->approve(week42(TrackmaniaNationsForever::SLUG), $this->admin);
    weeksAt('2026-10-10 21:00:00');
    $beforeReminder = approvalBell($this->admin);
    weeksAt('2026-10-10 22:00:00');
    weeksAt('2026-10-10 23:00:00');

    expect($drafted)->toBe(['Blockfill week 42 needs your approval', 'TMNF week 42 needs your approval'])
        ->and($beforeReminder)->toBe($drafted)
        ->and(approvalBell($this->admin))->toBe([...$drafted, 'Reminder: Blockfill week 42 is not approved yet'])
        ->and($this->admin->notifications()->first()->data['url'])->toBe(route('admin.league-weeks'))
        ->and(approvalBell($player))->toBe([])
        ->and(approvalBell($organizer))->toBe([])
        ->and(week42(Blockfill::SLUG)->reminded_at?->toDateTimeString())->toBe('2026-10-10 22:00:00')
        ->and(week42(TrackmaniaNationsForever::SLUG)->reminded_at)->toBeNull();
});

test('only admins see the page, change a week and approve it; a started week is changed by nobody', function () {
    $player = User::factory()->create();
    $organizer = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    weeksAt('2026-10-08 10:00:00');
    $draft = week42(Blockfill::SLUG);
    $started = LeagueWeek::query()->where('game', Blockfill::SLUG)->whereNotNull('tournament_id')->sole();
    $can = fn (User $user): array => [
        Gate::forUser($user)->allows('viewAny', LeagueWeek::class),
        Gate::forUser($user)->allows('update', $draft),
        Gate::forUser($user)->allows('approve', $draft),
        Gate::forUser($user)->allows('update', $started),
    ];

    expect($can($this->admin))->toBe([true, true, true, false])
        ->and($can($organizer))->toBe([false, false, false, false])
        ->and($can($player))->toBe([false, false, false, false]);

    $this->get(route('admin.league-weeks'))->assertRedirect();
    $this->actingAs($player)->get(route('admin.league-weeks'))->assertForbidden();
    $this->actingAs($organizer)->get(route('admin.league-weeks'))->assertForbidden();
    Livewire::actingAs($organizer)->test('pages::admin.league-weeks')->assertForbidden();

    // An admin changes the difficulty and approves; the week keeps what the form showed.
    Livewire::actingAs($this->admin)->test('pages::admin.league-weeks')
        ->assertSee('Blockfill week 42')->assertSee('TMNF week 42')
        ->set("form.{$draft->id}.difficulty", 'bf1expert')
        ->call('save', $draft->id)
        ->assertSet('notice', 'Saved. Approve the week to let it start with these settings.')
        ->set("form.{$draft->id}.difficulty", 'bf1master')
        ->call('approve', $draft->id)
        ->assertSee('Blockfill week 42 is approved.')
        ->call('$refresh')->assertOk();

    // A tampered value is refused, and a week already approved is not approved twice.
    $tmnf = week42(TrackmaniaNationsForever::SLUG);
    Livewire::actingAs($this->admin)->test('pages::admin.league-weeks')
        ->set("form.{$tmnf->id}.track", 'not-a-track')->call('save', $tmnf->id)->assertHasErrors(["form.{$tmnf->id}.track"])
        ->set("form.{$tmnf->id}.track", TRACK_A02)->set("form.{$tmnf->id}.limit", '500')->call('save', $tmnf->id)->assertHasErrors(["form.{$tmnf->id}.limit"])
        // E05-Endurance takes an hour (author time 1:00:05.940): the server's 15 minutes would end every run before the finish.
        ->set("form.{$tmnf->id}.track", 'eMBnCjky7WmlP9G0R9xOrNFQV7c')->set("form.{$tmnf->id}.limit", '')->call('save', $tmnf->id)
        ->assertHasErrors(["form.{$tmnf->id}.limit"])->assertSee('A round must be longer than the author time of E05-Endurance (1:00:05.940).')
        ->call('approve', $draft->id)->assertForbidden();

    expect($draft->refresh()->settings)->toBe(['difficulty' => 'bf1master'])
        ->and($draft->approved_by_id)->toBe($this->admin->id)
        ->and($tmnf->refresh()->settings)->toBe(['track' => TRACK_A02, 'time_limit_minutes' => 10]);
});

test('an approved week starts on time on its settings and ends the Monday after', function () {
    weeksAt('2026-10-08 10:00:00');
    app(LeagueWeekDrafts::class)->update(week42(Blockfill::SLUG), ['difficulty' => 'bf1expert']);
    app(LeagueWeekDrafts::class)->approve(week42(Blockfill::SLUG)->refresh(), $this->admin);
    app(LeagueWeekDrafts::class)->approve(week42(TrackmaniaNationsForever::SLUG), $this->admin);

    weeksAt(WEEK_42);
    $blockfill = app(BlockfillWeeks::class)->current();
    // TMNF waits for the listener to see the server on its track (TmnfTrackSwitchTest); here it saw it 20 s later.
    $waiting = app(TmnfWeeks::class)->open();
    week42(TrackmaniaNationsForever::SLUG)->forceFill(['track_ready_at' => CarbonImmutable::parse(WEEK_42)->addSeconds(20)])->save();
    $this->travel(20)->seconds();
    $tmnf = app(TmnfWeeks::class)->open();

    expect($blockfill->starts_at->toDateTimeString())->toBe(WEEK_42)
        ->and(ScoreWindow::of($blockfill)->end->toDateTimeString())->toBe('2026-10-18 22:00:00')
        ->and($blockfill->title())->toBe('Blockfill Week 42, 2026')
        ->and(app(BlockfillWeeks::class)->difficultyOf($blockfill))->toBe('bf1expert')
        ->and(week42(Blockfill::SLUG)->tournament_id)->toBe($blockfill->id)
        ->and($waiting)->toBeNull()
        ->and($tmnf->score_course)->toBe(TRACK_A02)
        ->and($tmnf->starts_at->toDateTimeString())->toBe('2026-10-11 22:01:00')
        ->and(ScoreWindow::of($tmnf)->end->toDateTimeString())->toBe('2026-10-18 22:00:00')
        // Runs are issued on the week's difficulty, and only a run on it counts there.
        ->and(app(StackerRuns::class)->issue(User::factory()->create(), now())[0]->engine)->toBe('bf1expert');
});

test('a late approval starts the week at the approval, and it still ends on Monday', function () {
    weeksAt('2026-10-08 10:00:00');
    weeksAt(WEEK_42);
    weeksAt('2026-10-14 08:00:00');
    $stillNone = app(BlockfillWeeks::class)->current();

    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:30:20'));
    Livewire::actingAs($this->admin)->test('pages::admin.league-weeks')
        ->call('approve', week42(Blockfill::SLUG)->id)
        ->assertSee('Blockfill week 42 is approved and runs now.');
    $week = app(BlockfillWeeks::class)->current();
    $window = ScoreWindow::of($week);

    expect($stillNone)->toBeNull()
        ->and($week->starts_at->toDateTimeString())->toBe('2026-10-14 08:31:00')
        ->and($week->published_at->toDateTimeString())->toBe('2026-10-14 08:31:00')
        ->and($window->end->toDateTimeString())->toBe('2026-10-18 22:00:00')
        ->and($week->title())->toBe('Blockfill Week 42, 2026')
        ->and($week->slug)->toBe('blockfill-2026-10-12');
});

test('an unapproved week never starts: the game says the next week starts soon, runs and finishes count nowhere, and week 41 ends with its results', function () {
    $winner = User::factory()->create(['name' => 'Ada']);
    $run = StackerRun::factory()->for($winner)->verified(3000)->create(['engine' => 'bf1hard', 'submitted_at' => now()]);
    app(BlockfillWeeks::class)->record($run, now());
    $driver = tmnfPlayer('ben_drives', linked: true);

    weeksAt('2026-10-08 10:00:00');
    foreach (['2026-10-11 22:00:00', '2026-10-12 06:00:00', '2026-10-14 12:00:00', '2026-10-18 21:00:00'] as $at) {
        weeksAt($at);
        $this->artisan('scores:tick')->assertSuccessful();
    }
    $late = StackerRun::factory()->for($winner)->verified(2800)->create(['engine' => app(BlockfillWeeks::class)->difficultyAt(), 'submitted_at' => now()]);
    $finish = tmnfFinish('ben_drives', 15_900);

    expect(Tournament::query()->whereIn('slug', ['blockfill-2026-10-12', 'tmnf-2026-10-12'])->count())->toBe(0)
        ->and(app(BlockfillWeeks::class)->record($late, now()))->toBeNull()
        ->and(app(TmnfWeeks::class)->record($finish))->toBeNull()
        ->and($late->engine)->toBe('bf1')
        ->and(week42(Blockfill::SLUG)->isApproved())->toBeFalse();

    $this->get(route('scores.show', Blockfill::SLUG))->assertOk()->assertSee('Next week starts soon. Ranked runs count again once it is open.')
        ->assertSee('Blockfill Week 41, 2026');
    $this->get(route('scores.show', TrackmaniaNationsForever::SLUG))->assertOk()->assertSee('Next week starts soon')->assertDontSee('Week 42');
    $this->get(route('stacker.play'))->assertOk()->assertSee('Next week starts soon. Ranked runs count again once it is open.');

    $week41 = Tournament::query()->where('slug', 'blockfill-2026-10-05')->sole();
    expect($week41->status)->toBe(TournamentStatus::Finished)
        ->and(app(ScoreRuns::class)->standings($week41)[0]->participant->user_id)->toBe($winner->id)
        ->and($driver->id)->toBeInt();

    // Its week over, the draft cannot be approved any more; week 43's draft took its settings.
    weeksAt('2026-10-22 10:00:00');
    expect(app(LeagueWeekDrafts::class)->approve(week42(Blockfill::SLUG), $this->admin))->toBeFalse()
        ->and(LeagueWeek::query()->where('game', Blockfill::SLUG)->where('starts_at', '2026-10-18 22:00:00')->sole()->settings)->toBe(['difficulty' => 'bf1hard'])
        ->and(Tournament::query()->where('slug', 'blockfill-2026-10-12')->exists())->toBeFalse();
});

test('a week counts only runs on its difficulty, and the week before the approvals keeps Normal', function () {
    $player = User::factory()->create();
    $week = app(BlockfillWeeks::class)->current();
    $normal = StackerRun::factory()->for($player)->verified(2500)->create(['engine' => 'bf1', 'submitted_at' => now()]);
    $hard = StackerRun::factory()->for($player)->verified(2900)->create(['engine' => 'bf1hard', 'submitted_at' => now()->addSecond()]);
    $old = Tournament::factory()->create(['game' => Blockfill::SLUG, 'slug' => 'blockfill-2026-09-28', 'starts_at' => '2026-09-27 22:00:00']);

    expect(app(BlockfillWeeks::class)->record($normal, now()))->toBeNull()
        ->and(app(BlockfillWeeks::class)->record($hard, now())?->value)->toBe(Blockfill::milliseconds(2900))
        ->and(app(ScoreRuns::class)->standings($week)[0]->value)->toBe(Blockfill::milliseconds(2900))
        ->and(app(BlockfillWeeks::class)->difficultyOf($old))->toBe('bf1')
        ->and(app(StackerRuns::class)->issue($player, now())[0]->engine)->toBe('bf1hard');
});

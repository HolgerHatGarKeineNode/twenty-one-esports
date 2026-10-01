<?php

/*
| Blockfill ready to switch on (plan "Blockfill", P6): its cover, its place in
| the navigation, the sitemap and the rules; the weekly board kept apart from
| the organizers' tournaments (lists, counts, player stats, the stream bot);
| the week's own 31923 and the bot's week notes; and with the switch off
| nothing of it anywhere.
*/

use App\Enums\StackerRunStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Cards\PageCardFacts;
use App\Support\Navigation\ShellNavigation;
use App\Support\Nostr\SignedEvent;
use App\Support\Pages\RulesPage;
use App\Support\Players\PlayerStats;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Seo\Sitemap;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\OrganizerBoard;
use App\Support\TwentyOne\PublishResult;
use App\Support\TwentyOne\Stream\BlockfillSlide;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    // A Wednesday: the week started on Monday 2026-10-05 00:00 Berlin (CEST), ISO week 41.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
});

/** A verified run of `$user` with `$ticks`, handed in at `$at`, through the real verdict job. */
function goLiveRun(User $user, int $ticks, CarbonImmutable $at): StackerRun
{
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
    ]);
    VerifyStackerRun::dispatchSync($run->id);

    return $run->refresh();
}

/** This week's board with `$players` (name => ticks) on it, and an organizer's running tournament next to it. */
function goLiveWeek(array $players = ['Ada' => 3000]): array
{
    BlockfillOn::play();
    $users = [];
    foreach ($players as $name => $ticks) {
        $users[$name] = User::factory()->create(['name' => $name]);
        goLiveRun($users[$name], $ticks, CarbonImmutable::now()->subHour());
    }
    $organizer = Tournament::factory()->create(['name' => 'Friday Blitz Cup', 'status' => TournamentStatus::Running, 'published_at' => now()->subDay(), 'created_by_id' => User::factory()->create()->id]);

    return [app(BlockfillWeeks::class)->current(), $organizer, $users];
}

/** Ends the week as the score kind does: past its window and review time. */
function goLiveFinish(Tournament $week): Tournament
{
    test()->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    return $week->refresh();
}

/** A stand-in for the stream relays that keeps every event sent. */
function goLiveRelays(): object
{
    $relays = new class extends StreamBotPublisher
    {
        /** @var list<SignedEvent> */
        public array $sent = [];

        public function __construct() {}

        public function publish(array $event, array $relays): array
        {
            $this->sent[] = SignedEvent::fromInput($event);

            return array_map(fn (string $relay): PublishResult => new PublishResult($relay, true, ''), $relays);
        }
    };
    app()->instance(StreamBotPublisher::class, $relays);
    config([
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => (new TestSigner)->secret,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);

    return $relays;
}

test('switched on, Blockfill has its own cover at 480 and 1280 px, each file under 80 kB', function () {
    expect(app(GameRegistry::class)->cover(Blockfill::SLUG))->toBeNull();

    BlockfillOn::play();
    $cover = app(GameRegistry::class)->cover(Blockfill::SLUG);

    expect($cover?->name)->toBe(Blockfill::SLUG)
        ->and($cover->widths)->toBe([480, 1280])
        ->and(app(GameRegistry::class)->coverPath(Blockfill::SLUG))->toBe(public_path('images/games/blockfill-1280.jpg'));

    foreach ($cover->widths as $width) {
        foreach (['webp', 'jpg'] as $format) {
            $file = public_path($cover->path($width, $format));
            expect(is_file($file))->toBeTrue("missing {$file}")
                ->and(getimagesize($file)[0])->toBe($width)
                ->and(getimagesize($file)[1])->toBe((int) round($width * 9 / 16))
                ->and(filesize($file))->toBeLessThan(80_000);
        }
    }
});

test('switched on, Blockfill is in the hub, on /play and on home, its page is /blockfill, and /blockfill and scores/blockfill share its context bar and tab bar', function () {
    BlockfillOn::play();
    $nav = ShellNavigation::current();
    $game = collect($nav->games())->firstWhere('slug', Blockfill::SLUG);

    expect($game['page'])->toBe(route('stacker.play'))
        ->and(array_column($game['actions'], 'href', 'key'))->toBe([
            'play' => route('stacker.play'),
            'leaderboard' => route('scores.show', Blockfill::SLUG),
            'rules' => route('rules').'#blockfill',
        ])
        ->and(array_column($game['actions'], 'tab', 'key'))->toBe(['play' => 'play', 'leaderboard' => 'ladder', 'rules' => null]);

    $this->get(route('play'))->assertOk()->assertSee('data-test="play-game-blockfill"', false);
    $this->get(route('home'))->assertOk()->assertSee('data-game="blockfill"', false);

    foreach ([route('stacker.play'), route('scores.show', Blockfill::SLUG)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<nav class="tabbar.*?</nav>#s', $html, $tabbar);

        expect($html)->toContain('data-test="context-bar" data-game="blockfill"')
            ->and($tabbar[0] ?? '')->toContain('href="'.route('stacker.play').'"')
            ->and($tabbar[0] ?? '')->toContain('href="'.route('scores.show', Blockfill::SLUG).'"')
            ->and($tabbar[0] ?? '')->not->toContain(route('chess.lobby'));
    }
});

test('switched on, the sitemap lists /blockfill and its leaderboards, the rules explain a week, and the game page links there', function () {
    BlockfillOn::play();
    $pages = array_column(app(Sitemap::class)->entries('pages', 1), 'url');
    $rules = collect(RulesPage::sections())->firstWhere('id', Blockfill::SLUG);

    expect($pages)->toContain(route('stacker.play'))->toContain(route('scores.show', Blockfill::SLUG))
        ->and($rules['title'])->toBe('Blockfill')
        ->and(implode(' ', $rules['items']))->toContain('Monday')->toContain('Practice')->toContain('replays');

    $this->get(route('rules'))->assertOk()->assertSee('id="blockfill"', false);
    $this->get(route('stacker.play'))->assertOk()->assertSee(route('rules').'#blockfill', false);
});

test('the weekly board stays out of the tournaments page, the organizers\' cards and the admin list, and its page and title are in the page\'s language', function () {
    [$week, $organizer] = goLiveWeek();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->get(route('tournaments.index'))->assertOk()->assertSee('Friday Blitz Cup')->assertDontSee('Blockfill Week');
    expect(collect(app(OrganizerBoard::class)->groups())->flatten(1)->pluck('tournament.id')->all())->toBe([$organizer->id]);
    Livewire::actingAs($admin)->test('pages::admin.tournaments')->assertSee('Friday Blitz Cup')->assertDontSee('Blockfill Week');

    // Its own page still works, in the page's language.
    $this->get(route('tournaments.show', $week))->assertOk()->assertSee('Blockfill Week 41, 2026');
    $this->get(route('tournaments.show', $week).'?lang=de')->assertOk()->assertSee('Blockfill Woche 41, 2026')->assertDontSee('Blockfill Week 41');
});

test('the weekly board stays out of the sitemap\'s tournaments', function () {
    [$week, $organizer] = goLiveWeek();
    $urls = array_column(app(Sitemap::class)->entries('tournaments', 1) ?? [], 'url');

    expect($urls)->toContain(route('tournaments.show', $organizer))
        ->and($urls)->not->toContain(route('tournaments.show', $week))
        ->and(app(Sitemap::class)->files()['tournaments'])->toBe(1);
});

test('the weekly board is not a played tournament in the player\'s stats, and the page cards do not count it', function () {
    [$week, $organizer, $users] = goLiveWeek();
    $ada = $users['Ada'];
    TournamentParticipant::query()->create(['tournament_id' => $organizer->id, 'user_id' => $ada->id, 'name' => 'Ada', 'seed' => 1, 'members' => [$ada->id]]);

    $stats = (new PlayerStats($ada))->tournaments();
    $figures = collect(PageCardFacts::page('tournaments')['figures'] ?? [])->mapWithKeys(fn (array $figure): array => [$figure[0] => $figure[1]]);

    expect(TournamentParticipant::query()->where(['tournament_id' => $week->id, 'user_id' => $ada->id])->exists())->toBeTrue()
        ->and($stats['count'])->toBe(1)
        ->and(array_map(fn (array $row): int => $row['tournament']->id, $stats['rows']))->toBe([$organizer->id])
        ->and($figures['tournaments-running'])->toBe(1)
        ->and(PageCardFacts::live()['tournaments'])->toBe(['Friday Blitz Cup']);
});

test('the stream bot never calls the weekly board a live tournament or links its TV, and names no winner of it; the TV answers 404', function () {
    [$week, $organizer] = goLiveWeek();
    $builders = app(StreamBotBuilders::class);
    $builders->pickVariantsWith(fn (int $variants): int => 0);

    $live = $builders->build('tournament_live', CarbonImmutable::now());
    expect(array_map(fn ($message): string => $message->factKey, $live))->toBe(['tournament-live:'.$organizer->id]);
    $this->get(route('tournaments.tv', $week))->assertNotFound();
    $this->get(route('tournaments.show', $week))->assertOk()->assertDontSee(route('tournaments.tv', $week));

    goLiveFinish($week);
    expect($week->status)->toBe(TournamentStatus::Finished)
        ->and(collect($builders->build('tournament_winner', CarbonImmutable::now()))->filter(fn ($message): bool => str_contains($message->content, 'Blockfill'))->all())->toBe([]);
});

test('each week gets one 31923, its slug as `d`, without a hashtag, however often the hourly job runs', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    BlockfillOn::play();

    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $week = app(BlockfillWeeks::class)->current();
    $events = NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->get();
    $event = SignedEvent::fromInput($events->first()->payload());

    expect($events)->toHaveCount(1)
        ->and($week->event_id)->toBe($events->first()->id)
        ->and($event->tag('d'))->toBe('blockfill-2026-10-05')
        ->and($event->tag('title'))->toBe('Blockfill Week 41, 2026')
        ->and((int) $event->tag('end'))->toBe(CarbonImmutable::parse('2026-10-11 22:00:00')->getTimestamp())
        ->and($event->tagsNamed('t'))->toBe([])
        ->and($event->content)->toContain('replay')->not->toContain('#')
        // The organizers' tournament notes leave it to the week notes.
        ->and(app(TournamentNotes::class)->due(CarbonImmutable::now()))->toBe([]);
});

test('the bot announces a new week once, and after the week its winner with the top 3; both pass the copy rules without a hashtag or a fee', function () {
    $relays = goLiveRelays();
    [$week] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100, 'Dee' => 3200]);

    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    expect($relays->sent)->toHaveCount(1);
    $open = $relays->sent[0];

    goLiveFinish($week);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $winner = collect($relays->sent)->first(fn (SignedEvent $event): bool => str_contains($event->content, 'Ada'));

    foreach ([$open, $winner] as $note) {
        expect($note->kind)->toBe(1)
            ->and(StreamBotCopy::violations($note->content, $note->tags))->toBe([])
            ->and($note->content)->not->toContain('#')
            ->and(strtolower($note->content))->not->toContain('fee')
            ->and($note->tagsNamed('t'))->toBe([]);
    }

    expect($open->content)->toContain('Blockfill Week 41, 2026')->toContain(route('stacker.play'))
        ->and($winner->content)->toContain('Ada')->toContain('Ben')->toContain('Cy')->not->toContain('Dee')
        // The new week (opened by the hourly job after the old one ended) is announced too, each note once.
        ->and(BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->whereNotNull('published_at')->count())->toBe(3);
});

test('the stream still f1 shows the top 5 and the leader\'s chain for an empty, a running and a finished week', function () {
    BlockfillOn::play();
    // The daemon reads the slide through the cache for 15 s; each state here is read fresh.
    $render = function (): string {
        Cache::flush();

        return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation(BlockfillSlide::SCENE, null, [], 0, 0, app(StreamStats::class)->all()), 'viewers' => null], RotationPlanner::VIEWS[BlockfillSlide::SCENE]);
    };

    $empty = app(BlockfillSlide::class)->data();
    expect($empty['state'])->toBe('empty')->and($empty['top'])->toBe([])
        ->and($render())->toContain('width="1280" height="720"')->toContain('Blockfill');

    [$week] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100, 'Dee' => 3200, 'Eve' => 3300, 'Fay' => 3400]);
    $running = app(BlockfillSlide::class)->data();
    expect($running['state'])->toBe('running')
        ->and(array_column($running['top'], 'name'))->toBe(['Ada', 'Ben', 'Cy', 'Dee', 'Eve'])
        ->and($running['leader']['time'])->toBe('0:48.333')
        ->and($running['title'])->toBe('Blockfill Week 41, 2026')
        ->and($render())->toContain('Ada')->not->toContain('Fay');

    goLiveFinish($week);
    $finished = app(BlockfillSlide::class)->data();
    expect($finished['state'])->toBe('finished')
        ->and($finished['leader']['name'])->toBe('Ada')
        ->and($render())->toContain('Ada');
});

test('the f1 slide joins the teaser pool only while Blockfill is registered', function () {
    $teasers = function (): array {
        $planner = RotationPlanner::fromConfig(60);
        $scenes = [];
        for ($t = 0; $t < 2000; $t += 5) {
            $scenes[] = $planner->at($t, [])['scene'];
        }

        return array_values(array_unique(array_filter($scenes)));
    };

    expect($teasers())->not->toContain(BlockfillSlide::SCENE);

    BlockfillOn::play();
    expect($teasers())->toContain(BlockfillSlide::SCENE);
});

test('switched off, nothing of Blockfill shows: hub, /play, home, sitemap, rules, stream and scheduler', function () {
    $nav = ShellNavigation::current();
    require base_path('routes/console.php');
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

    expect(array_column($nav->games(), 'slug'))->not->toContain(Blockfill::SLUG)
        ->and(implode(' ', array_column(app(Sitemap::class)->entries('pages', 1), 'url')))->not->toContain('blockfill')
        ->and(array_column(RulesPage::sections(), 'id'))->not->toContain(Blockfill::SLUG)
        ->and(app(BlockfillSlide::class)->data())->toBeNull()
        ->and($scheduled)->not->toContain('stacker:sweep')
        ->and($scheduled)->not->toContain('blockfill')
        ->and($scheduled)->not->toContain(StackerRun::class);

    foreach (['play', 'home', 'rules'] as $page) {
        $this->get(route($page))->assertOk()->assertDontSee('Blockfill');
    }

    BlockfillOn::play();
    require base_path('routes/console.php');
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
    expect($scheduled)->toContain('stacker:sweep')->toContain('blockfill:weeks')->toContain('twentyone:stream-bot:blockfill')->toContain(StackerRun::class);
});

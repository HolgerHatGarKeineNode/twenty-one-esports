<?php

/*
| Blockfill ready to switch on (plan "Blockfill", P6): its cover, its place in
| the navigation, the sitemap and the rules; the weekly board kept apart from
| the organizers' tournaments (lists, counts, player stats, the stream bot);
| the week's own 31923 and the bot's week notes; and with the switch off
| nothing of it anywhere.
*/

use App\Enums\ClanRole;
use App\Enums\StackerRunStatus;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\BotPost;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\NostrEvent;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Cards\PageCardFacts;
use App\Support\Cards\ShareMoments;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Clans\ClanPride;
use App\Support\Engagement\PlayerHub;
use App\Support\Lightning\WinnerZaps;
use App\Support\Navigation\ShellNavigation;
use App\Support\Nostr\NostrKeys;
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
use App\Support\Tournaments\TournamentGames;
use App\Support\TwentyOne\PublishResult;
use App\Support\TwentyOne\Stream\BlockfillSlide;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
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
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
    config(['esports.stream_bot.profile_gap_minutes' => 0]);
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

test('switched on, Blockfill is in the hub, on /play and on home, its page is /blockfill, and /blockfill, scores/blockfill and its replays share its context bar and tab bar', function () {
    BlockfillOn::play();
    $nav = ShellNavigation::current();
    $game = collect($nav->games())->firstWhere('slug', Blockfill::SLUG);

    expect($game['page'])->toBe(route('stacker.play'))
        ->and(array_column($game['actions'], 'href', 'key'))->toBe([
            'play' => route('stacker.play'),
            'leaderboard' => route('scores.show', Blockfill::SLUG),
            'replays' => route('stacker.replays'),
            'rules' => route('rules').'#blockfill',
        ])
        ->and(array_column($game['actions'], 'tab', 'key'))->toBe(['play' => 'play', 'leaderboard' => 'ladder', 'replays' => 'replays', 'rules' => null]);

    $this->get(route('play'))->assertOk()->assertSee('data-test="play-game-blockfill"', false);
    $this->get(route('home'))->assertOk()->assertSee('data-game="blockfill"', false);

    foreach ([route('stacker.play'), route('scores.show', Blockfill::SLUG), route('stacker.replays')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        // Phones: the game's own pages in the More sheet (the tab bar is the same five places everywhere, Header.dc.html).
        preg_match('#data-test="more-game">.*?</section>#s', $html, $sheet);

        expect($html)->toContain('data-test="context-bar" data-game="blockfill"')
            ->and($html)->toContain('href="'.route('stacker.replays').'"')
            ->and($sheet[0] ?? '')->toContain('href="'.route('stacker.play').'"')
            ->and($sheet[0] ?? '')->toContain('href="'.route('scores.show', Blockfill::SLUG).'"')
            ->and($sheet[0] ?? '')->toContain('data-test="more-replays"')
            ->and($sheet[0] ?? '')->not->toContain(route('chess.lobby'));
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
        // A week has neither matches nor a prize pool (F3).
        ->and($event->content)->not->toContain('Tournament matches never mine')->not->toContain('prize pool')
        // The organizers' tournament notes leave it to the week notes.
        ->and(app(TournamentNotes::class)->due(CarbonImmutable::now()))->toBe([]);
});

test('the bot announces a new week once, and after the week its winner with the top 3, each tagged; both pass the copy rules without a hashtag or a fee', function () {
    $relays = goLiveRelays();
    [$week, , $users] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100, 'Dee' => 3200]);
    $npub = fn (string $name): string => 'nostr:'.NostrKeys::hexToNpub($users[$name]->pubkey);

    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    expect($relays->sent)->toHaveCount(1);
    $open = $relays->sent[0];

    goLiveFinish($week);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $winner = collect($relays->sent)->first(fn (SignedEvent $event): bool => str_contains($event->content, 'Top 3:') || str_contains($event->content, 'Podium:'));

    foreach ([$open, $winner] as $note) {
        expect($note->kind)->toBe(1)
            ->and(StreamBotCopy::violations($note->content, $note->tags))->toBe([])
            ->and($note->content)->not->toContain('#')
            // The wording only: a bech32 npub or naddr may spell "fee" by chance.
            ->and(strtolower((string) preg_replace('~nostr:\S+~', '', $note->content)))->not->toContain('fee')
            ->and($note->tagsNamed('t'))->toBe([]);
    }

    expect($open->content)->toContain('Blockfill Week 41, 2026')->toContain(route('stacker.play'))
        ->and($winner->content)->toContain($npub('Ada'))->toContain('Blockfill Week 41, 2026')
        ->and($winner->content)->toContain('1. '.$npub('Ada').' ')->toContain('2. '.$npub('Ben').' ')->toContain('3. '.$npub('Cy').' ')
        ->and($winner->content)->not->toContain($npub('Dee'))->not->toContain('Dee')->not->toContain('Ada ')
        // One p tag per player, the winner once although named twice.
        ->and($winner->tagsNamed('p'))->toBe([[$users['Ada']->pubkey], [$users['Ben']->pubkey], [$users['Cy']->pubkey]])
        // The new week (opened by the hourly job after the old one ended) is announced too, each note once.
        ->and(BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->whereNotNull('published_at')->count())->toBe(3);
});

test('a winner note names a podium entry whose account is gone plainly and tags the others', function () {
    $relays = goLiveRelays();
    [$week, , $users] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100]);
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();

    goLiveFinish($week);
    TournamentParticipant::query()->where(['tournament_id' => $week->id, 'user_id' => $users['Cy']->id])->update(['user_id' => null]);
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $winner = collect($relays->sent)->first(fn (SignedEvent $event): bool => str_contains($event->content, 'Top 3:') || str_contains($event->content, 'Podium:'));
    [$text] = explode("\n\nnostr:", $winner->content, 2);

    expect($text)->toContain('3. Cy ')->toContain('2. nostr:'.NostrKeys::hexToNpub($users['Ben']->pubkey).' ')
        ->and($winner->tagsNamed('p'))->toBe([[$users['Ada']->pubkey], [$users['Ben']->pubkey]])
        ->and(StreamBotCopy::violations($text, $winner->tags))->toBe([]);
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

test('organizers are never offered Blockfill: not in the format chooser, not on the create page, and a create with its key is refused', function () {
    BlockfillOn::play();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $key = Blockfill::SLUG.'/'.Blockfill::MODE;

    expect(array_column(TournamentGames::grouped(), 'slug'))->not->toContain(Blockfill::SLUG)
        ->and(TournamentGames::find($key))->toBeNull()
        ->and(TournamentGames::keyOf(Blockfill::SLUG, Blockfill::MODE))->toBeNull();

    $create = Livewire::actingAs($admin)->test('pages::admin.tournament-create');
    // P5: the admin map has its own "Blockfill review" page (held runs); the page below it offers no Blockfill
    expect(preg_replace('#<nav aria-label="Admin".*?</nav>#s', '', $create->html()))->not->toContain('Blockfill')
        ->and($create->html())->toContain('Blockfill review');

    $create
        ->assertDontSeeHtml($key)
        // The chooser's own action ignores the key, and the property set directly is refused at create.
        ->call('pickGame', $key)->assertSet('game', 'blitz')
        ->set('game', $key)
        ->set('name', 'Sneaky Blockfill Cup')
        ->call('select', 'leaderboard')
        ->call('create')
        ->assertHasErrors('game');

    expect(Tournament::query()->count())->toBe(0);
});

test('both Blockfill bot templates pass the copy rules: a link, no hashtag, no fee and no face wording', function () {
    $values = ['name' => 'Blockfill Week 41, 2026', 'ends' => 'Mon, 12 Oct 2026, 12:00 AM CEST', 'url' => 'https://esports.test/blockfill', 'blocks' => '60',
        'winner' => 'Ada', 'time' => '0:48.333', 'podium' => '1. Ada 0:48.333 · 2. Ben 0:50.000 · 3. Cy 0:51.666'];

    foreach (['blockfill_note_week', 'blockfill_note_winner'] as $template) {
        foreach (range(0, StreamBotCopy::variants($template) - 1) as $variant) {
            $text = StreamBotCopy::render($template, $variant, $values);

            expect(StreamBotCopy::violations($text))->toBe([], "{$template} #{$variant}")
                ->and($text)->not->toContain('#')
                ->and(mb_strtolower($text))->not->toContain('fee')->not->toContain('face')->not->toContain('gesicht')
                ->and($text)->toContain('https://esports.test/blockfill');
        }
    }
});

test('a Blockfill week\'s winner gets no zap button and no tournament win to share', function () {
    [$week, , $users] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000]);
    $ada = $users['Ada'];
    $ada->forceFill(['lud16' => 'ada@getalby.com'])->save();
    goLiveFinish($week);

    expect($week->status)->toBe(TournamentStatus::Finished)
        ->and(app(WinnerZaps::class)->winners('tournament', (string) $week->id, null))->toBe([])
        ->and(ShareMoments::tournamentWins($ada))->toBe([])
        ->and(fn () => app(SharePosts::class)->post($ada, 'tournament', (string) $week->id))->toThrow(ShareRefused::class);

    $this->actingAs($ada)->get(route('tournaments.show', $week))->assertOk()
        ->assertDontSeeHtml('wire:name="zap-winner"')
        ->assertDontSeeHtml('wire:name="share-button"');
});

test('the page of a week tells how a week works: play, verified, leaderboard, the replay as its results, a Play button; no sign-up, no no-show, no invite row', function (string $locale, array $seen, array $unseen) {
    [$week] = goLiveWeek(['Ada' => 2900]);
    $html = $this->get(route('tournaments.show', $week).'?lang='.$locale)->assertOk()->getContent();
    // P4 (plan "Restposten nach TMNF"): the steps as a track, the rules in the hero's chips, the facts as chips beside the board.
    preg_match('#data-test="blockfill-steps".*?</ol>#s', $html, $how);
    preg_match('#data-test="hero-chips".*?</ul>#s', $html, $results);
    preg_match('#data-test="week-facts".*?</ul>#s', $html, $course);
    $chain = [''];

    expect($html)->toMatch('#<a href="'.preg_quote(route('stacker.play'), '#').'"[^>]*data-test="play-now"#')
        ->and($html)->not->toContain('data-test="to-signup"')
        ->and($html)->not->toContain(route('tournaments.signup', $week))
        ->and($html)->not->toContain('data-test="who-is-in"')
        ->and($html)->not->toContain('data-test="places-meter"')
        ->and($html)->not->toContain('data-test="tournament-share"')
        ->and($html)->not->toContain('data-test="nostr-bar"');

    foreach ($seen as $text) {
        expect(html_entity_decode($how[0].$results[0].$course[0].$chain[0], ENT_QUOTES))->toContain($text);
    }
    foreach ($unseen as $text) {
        expect(html_entity_decode($html, ENT_QUOTES))->not->toContain($text);
    }
})->with([
    'en' => ['en', ['Ranked, logged in', 'The server replays it', 'Your best time counts', '40 blocks', 'Steady 1 row/s', 'Fastest time wins', 'Mines no season blocks'],
        ['What if someone does not show up?', 'link that proves it', 'values read from the game', 'Confirm with your Nostr key', 'tournament matches never mine', 'Play a ranked run of Blockfill']],
    'de' => ['de', ['Ranked, eingeloggt', 'Server spielt ihn nach', 'Deine Bestzeit zählt', '40 Blöcke', 'Gleichbleibend 1 Reihe/s', 'Schnellste Zeit gewinnt', 'Schürft keine Season-Blöcke'],
        ['Was, wenn jemand nicht erscheint?', 'Link, der ihn belegt', 'Spiel einen gewerteten Blockfill-Lauf']],
]);

test('the draw page of a week is 404; with Blockfill switched off its page, calendar file and TV are 404 too; on, the calendar file is in the language of the request and ends with the week', function () {
    [$week] = goLiveWeek(['Ada' => 2900]);

    $this->get(route('tournaments.draw', $week))->assertNotFound();
    $ics = $this->get(route('tournaments.calendar', $week).'?lang=de')->assertOk()->getContent();
    expect($ics)->toContain('SUMMARY:Blockfill Woche 41\, 2026')
        ->and($ics)->toContain('DTSTART:20261004T220000Z')
        ->and($ics)->toContain('DTEND:20261011T220000Z');

    config(['esports.blockfill.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    foreach (['tournaments.show', 'tournaments.draw', 'tournaments.calendar', 'tournaments.tv'] as $route) {
        $this->get(route($route, $week))->assertNotFound();
    }

    // Any other tournament stays readable.
    $other = Tournament::query()->where('name', 'Friday Blitz Cup')->firstOrFail();
    $this->get(route('tournaments.show', $other))->assertOk();
});

test('an entry in a week does not tick "Sign up for a tournament" on the own page', function () {
    [, , $users] = goLiveWeek(['Ada' => 2900]);
    $step = collect((new PlayerHub($users['Ada']))->steps())->firstWhere('key', 'tournament');

    expect($step['done'])->toBeFalse();
});

test('the podium of a week is no proud clan moment, and the live tournament slides leave the week out', function () {
    [$week, $organizer, $users] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000]);
    $clan = Clan::factory()->create(['created_at' => now()->subDays(60)]);
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $users['Ada']->id, 'role' => ClanRole::Member, 'joined_at' => now()->subDays(60)]);

    expect(app(TournamentLiveSlides::class)->tournaments()->pluck('id')->all())->toBe([$organizer->id]);

    goLiveFinish($week);
    $moments = collect(app(ClanPride::class)->read()[$clan->id] ?? []);

    expect($week->status)->toBe(TournamentStatus::Finished)
        ->and($moments->where('type', 'tournament')->all())->toBe([])
        // A finished week is no live tournament either.
        ->and(app(TournamentLiveSlides::class)->tournaments()->pluck('id')->all())->not->toContain($week->id);
});

test('switched on, the Blockfill notes of the stream bot are scheduled every five minutes', function () {
    BlockfillOn::play();
    require base_path('routes/console.php');
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'twentyone:stream-bot:blockfill'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *');
});

test('an empty week says nobody has a verified run yet, in en and de, with no seats, sign-up line or places', function (string $locale, string $empty) {
    BlockfillOn::play();
    $week = app(BlockfillWeeks::class)->open();
    $html = $this->get(route('tournaments.show', $week).'?lang='.$locale)->assertOk()->getContent();

    expect($week->participants()->count())->toBe(0)
        ->and(html_entity_decode($html, ENT_QUOTES))->toContain($empty)
        ->and($html)->not->toContain('Nobody has signed up yet.')
        ->and($html)->not->toContain('Noch hat sich niemand angemeldet.')
        ->and($html)->not->toContain('data-test="places-meter"')
        ->and($html)->not->toContain('data-test="open-seat"')
        ->and($html)->not->toContain('data-test="who-is-in"')
        // The places meter waits for the draw, and a week is drawn from its start: it is opened running.
        ->and($week->status)->toBe(TournamentStatus::Running);
})->with([
    // P4 (plan "Restposten nach TMNF"): the empty podium of the board says it, as on the week's board page.
    'en' => ['en', 'No time yet this week. The first verified run takes #1.'],
    'de' => ['de', 'Noch keine Zeit diese Woche. Der erste geprüfte Lauf holt #1.'],
]);

test('a week shows no seed numbers and no prize pool, even to an admin, and has no sign-up, director or pool page, switched on or off', function () {
    [$week] = goLiveWeek(['Ada' => 2900, 'Ben' => 3000]);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('tournaments.show', $week))->assertOk()
        ->assertSee('Ada')
        ->assertDontSee('title="Seed 1"', false)
        ->assertDontSeeHtml('wire:name="tournament-pool"')
        ->assertDontSee('data-test="prize-pool"', false)
        ->assertDontSee('data-test="manage-pool"', false);
    $this->actingAs($admin)->get(route('admin.tournaments.edit', $week))->assertDontSee('data-test="manage-pool"', false);

    foreach (['tournaments.signup', 'tournaments.director', 'tournaments.pool'] as $route) {
        $this->actingAs($admin)->get(route($route, $week))->assertNotFound();
    }

    config(['esports.blockfill.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    foreach (['tournaments.signup', 'tournaments.director', 'tournaments.pool'] as $route) {
        $this->actingAs($admin)->get(route($route, $week))->assertNotFound();
    }
});

test('the calendar file of a week across a clock change ends with the week: 169 hours in October, 167 in March', function (string $at, string $start, string $end) {
    BlockfillOn::play();
    $this->travelTo(CarbonImmutable::parse($at));
    leagueWeeksApproved(Blockfill::SLUG);
    $week = app(BlockfillWeeks::class)->open();
    $ics = $this->get(route('tournaments.calendar', $week))->assertOk()->getContent();

    expect($ics)->toContain('DTSTART:'.$start)->toContain('DTEND:'.$end);
})->with([
    'autumn, 169 h' => ['2026-10-21 12:00:00', '20261018T220000Z', '20261025T230000Z'],
    'spring, 167 h' => ['2026-03-25 12:00:00', '20260322T230000Z', '20260329T220000Z'],
]);

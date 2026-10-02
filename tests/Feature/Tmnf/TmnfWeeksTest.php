<?php

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Matches\ScoreAttempts;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Scores\ManualSubmissions;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScorePoints;
use App\Support\Scores\ScoreRuns;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TmnfNotes;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tmnf\TmnfLinks;
use App\Support\Tmnf\TmnfMoments;
use App\Support\Tmnf\TmnfWeeks;
use App\Support\TwentyOne\PublishResult;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\TmnfSlide;
use App\Support\TwentyOne\Stream\TmnfSlides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| TMNF's weekly time attack (plan "Trackmania und Restposten", P2)
|--------------------------------------------------------------------------
|
| A test week with fixtures: week 41 of 2026, Monday 2026-10-05 00:00 to
| Monday 2026-10-12 00:00 Europe/Berlin, on A01-Race. Finishes go in as the
| server sends them (tmnfFinish(): BeginChallenge, then PlayerFinish through
| the listener). Board, points, calendar event, bot notes, outlier holds and
| the pages.
|
*/

beforeEach(function () {
    Cache::flush();
    tmnfOn();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.tmnf.server.address' => 'tmnf.example.org:2350']);
    // A Wednesday noon of week 41.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
});

/** Ends the week as the score kind does: past its window and review time. */
function tmnfWeekOver(Tournament $week): Tournament
{
    test()->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    return $week->refresh();
}

/** A stand-in for the stream relays that keeps every event sent. */
function tmnfRelays(): object
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
    config(['esports.stream_bot.enabled' => true, 'esports.stream_bot.nsec' => (new TestSigner)->secret, 'twentyone.stream.relays' => ['wss://one.test']]);

    return $relays;
}

test('the hourly job opens one week on the week\'s track, from Monday 00:00 Berlin, and signs its calendar event once', function () {
    $this->artisan('tmnf:weeks')->assertSuccessful();
    $this->artisan('tmnf:weeks')->assertSuccessful();

    $week = Tournament::query()->where('game', 'tmnf')->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->where('kind', Tournament::CALENDAR_EVENT)->sole()->payload());

    expect($week->only(['slug', 'mode', 'score_course', 'name', 'opened_by_league']))->toBe(['slug' => 'tmnf-2026-10-05', 'mode' => 'time-attack', 'score_course' => TMNF_A01, 'name' => 'TMNF Week 41, 2026', 'opened_by_league' => false])
        ->and($week->status)->toBe(TournamentStatus::Running)
        ->and($week->starts_at->toDateTimeString())->toBe('2026-10-04 22:00:00')
        ->and($week->isTmnfWeek())->toBeTrue()->and($week->isLeagueWeek())->toBeTrue()
        ->and($event->tag('d'))->toBe('tmnf-2026-10-05')
        ->and($event->tag('title'))->toBe('TMNF Week 41, 2026')
        ->and((int) $event->tag('end'))->toBe(CarbonImmutable::parse('2026-10-11 22:00:00')->getTimestamp())
        ->and($event->tagsNamed('t'))->toBe([])
        ->and($event->content)->toContain('A01-Race')->toContain('our own TMNF server')->not->toContain('#')->not->toContain('prize pool')
        // An organizer's tournament notes leave the week to its own notes.
        ->and(app(TournamentNotes::class)->due(CarbonImmutable::now()))->toBe([]);
});

test('a linked player\'s best finish inside the window ranks; a finish before the week, of an unlinked login or on another track does not', function () {
    $ada = tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    $ben = tmnfPlayer('ben_drives', linked: true, attributes: ['name' => 'Ben']);
    tmnfPlayer('cy_drives', attributes: ['name' => 'Cy']);

    tmnfFinish('ada_drives', 26_400);
    tmnfFinish('ada_drives', 25_100);
    tmnfFinish('ada_drives', 25_800);
    tmnfFinish('ben_drives', 25_400);
    // Sunday 23:59 Berlin of the week before: outside the window.
    tmnfFinish('ben_drives', 23_900, CarbonImmutable::parse('2026-10-04 21:59:59'));
    tmnfFinish('cy_drives', 24_000);

    $week = app(TmnfWeeks::class)->current();
    $rows = array_map(fn ($row): array => [$row->participant->name, $row->place, $row->value], app(ScoreRuns::class)->standings($week));

    expect($rows)->toBe([['Ada', 1, 25_100], ['Ben', 2, 25_400]])
        ->and(ScoreRun::query()->where('account_id', 'cy_drives')->sole()->user_id)->toBeNull()
        ->and($week->participants()->pluck('user_id')->all())->toBe([$ada->id, $ben->id]);
});

test('after the week its places score points on the ladder, and a new week opens', function () {
    foreach (['ada' => 25_100, 'ben' => 25_400, 'cy' => 25_900] as $login => $ms) {
        tmnfPlayer($login, linked: true);
        tmnfFinish($login, $ms);
    }
    $week = app(TmnfWeeks::class)->current();

    tmnfWeekOver($week);
    $this->artisan('tmnf:weeks')->assertSuccessful();
    $game = app(GameRegistry::class)->get('tmnf');
    $ladder = app(ScorePoints::class)->ladder($game, TrackmaniaNationsForever::MODE);

    expect($week->status)->toBe(TournamentStatus::Finished)
        ->and(array_values($ladder))->toBe([25, 18, 15])
        ->and(app(TmnfWeeks::class)->current()?->slug)->toBe('tmnf-2026-10-12');
});

test('a finish far below the author time that would enter the top 10 is held for an admin, counts once approved', function () {
    $ada = tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfPlayer('ben_drives', linked: true, attributes: ['name' => 'Ben']);
    tmnfFinish('ben_drives', 25_400);

    // A01's author time is 24.540; the margin 1.5 s: below 23.040 is flagged.
    $run = tmnfFinish('ada_drives', 21_000);
    $week = app(TmnfWeeks::class)->current();

    expect($run->isHeld())->toBeTrue()
        ->and($run->tournament_id)->toBe($week->id)
        ->and($run->raw['hint'])->toBe(['kind' => 'below_author_time', 'author_ms' => 24_540, 'margin_ms' => 1_500, 'threshold_ms' => 23_040])
        ->and(app(ScoreRuns::class)->standings($week)[0]->value)->toBe(25_400)
        ->and(ScoreRun::query()->pendingReview()->pluck('id')->all())->toBe([$run->id]);

    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    app(ManualSubmissions::class)->approve($run, $admin);

    expect(app(ScoreRuns::class)->standings($week)[0]->participant->user_id)->toBe($ada->id)
        ->and(app(ScoreRuns::class)->standings($week)[0]->value)->toBe(21_000);
});

test('a flagged finish that would not enter the top 10 counts at once and keeps its hint; an ordinary one carries none', function () {
    foreach (range(0, 9) as $i) {
        tmnfPlayer("fast_{$i}", linked: true);
        $fast = tmnfFinish("fast_{$i}", 20_000 + $i * 100);
        // An admin looked at these already.
        $fast->forceFill(['verified_at' => now(), 'tournament_id' => null])->save();
    }
    tmnfPlayer('slow_flag', linked: true);
    tmnfPlayer('plain', linked: true);

    $flagged = tmnfFinish('slow_flag', 22_900);
    $plain = tmnfFinish('plain', 26_000);

    expect($flagged->verified_at)->not->toBeNull()->and($flagged->isHeld())->toBeFalse()->and($flagged->raw['hint']['kind'])->toBe('below_author_time')
        ->and($plain->verified_at)->not->toBeNull()->and($plain->raw)->not->toHaveKey('hint');
});

test('a fast finish of a login linked later gets the same look when the link hands it over', function () {
    tmnfPlayer('ben_drives', linked: true);
    tmnfFinish('ben_drives', 25_400);
    $ada = tmnfPlayer('ada_drives');
    $pending = tmnfFinish('ada_drives', 21_000);

    expect($pending->user_id)->toBeNull()->and($pending->verified_at)->not->toBeNull();

    expect(TmnfLinks::fromChat('ada_drives', 'link '.TmnfLinks::codeFor($ada)))->toBe('linked');

    expect($pending->refresh()->user_id)->toBe($ada->id)
        ->and($pending->isHeld())->toBeTrue()
        ->and(app(TmnfWeeks::class)->current()->participants()->where('user_id', $ada->id)->exists())->toBeTrue();
});

test('the bot posts the week with its track, then its winner and top 3, each player tagged by the Nostr key and never by a login', function () {
    $relays = tmnfRelays();
    $users = [];
    foreach (['Ada' => 25_100, 'Ben' => 25_400, 'Cy' => 25_900, 'Dee' => 26_000] as $name => $ms) {
        $users[$name] = tmnfPlayer(strtolower($name).'_drives', linked: true, attributes: ['name' => $name]);
        tmnfFinish(strtolower($name).'_drives', $ms);
    }
    $week = app(TmnfWeeks::class)->current();
    $npub = fn (string $name): string => 'nostr:'.NostrKeys::hexToNpub($users[$name]->pubkey);

    $this->artisan('twentyone:stream-bot:tmnf')->assertSuccessful();
    $open = $relays->sent[0];
    tmnfWeekOver($week);
    $this->artisan('twentyone:stream-bot:tmnf')->assertSuccessful();
    $winner = collect($relays->sent)->first(fn (SignedEvent $event): bool => str_contains($event->content, 'goes to'));

    foreach ([$open, $winner] as $note) {
        expect(StreamBotCopy::violations($note->content, $note->tags))->toBe([])
            ->and($note->content)->not->toContain('#')->not->toContain('_drives')
            ->and(strtolower((string) preg_replace('~nostr:\S+~', '', $note->content)))->not->toContain('fee')
            ->and($note->tagsNamed('t'))->toBe([]);
    }

    expect($open->content)->toContain('TMNF Week 41, 2026')->toContain('Track A01-Race')->toContain(route('tournaments.show', $week))
        ->and($winner->content)->toStartWith('🏆 TMNF Week 41, 2026 goes to '.$npub('Ada').' in 0:25.100 on A01-Race')
        ->and($winner->content)->toContain('2. '.$npub('Ben').' ')->toContain('3. '.$npub('Cy').' ')->not->toContain($npub('Dee'))
        ->and($winner->tagsNamed('p'))->toBe([[$users['Ada']->pubkey], [$users['Ben']->pubkey], [$users['Cy']->pubkey]])
        ->and(BotPost::query()->where('subject_type', TmnfNotes::SUBJECT)->whereNotNull('published_at')->count())->toBeGreaterThanOrEqual(2);
});

test('the week page leads with How to join: the server, the link and the track; no sign-up and no login of anybody', function () {
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfFinish('ada_drives', 25_100);
    $week = app(TmnfWeeks::class)->current();

    $this->get(route('tournaments.show', $week))->assertOk()
        ->assertSee('data-test="to-tmnf-join"', false)->assertSee('data-test="tmnf-join"', false)
        ->assertSee('TWENTY ONE')->assertSee('tmnf.example.org:2350')->assertSee('A01-Race')->assertSee('24.540')
        ->assertSee('https://store.steampowered.com/app/11020/', false)
        ->assertDontSee('data-test="to-signup"', false)->assertDontSee('data-test="to-blockfill"', false)
        ->assertDontSee('ada_drives')->assertDontSee(TMNF_A01);
});

test('the game page shows the week\'s board, the time to beat and How to join, in English and in German', function () {
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfFinish('ada_drives', 25_100);
    app(TmnfWeeks::class)->open();

    $this->get(route('scores.show', 'tmnf'))->assertOk()
        ->assertSee('data-test="tmnf-hero"', false)->assertSee('data-test="time-to-beat"', false)->assertSee('25.100')
        ->assertSee('data-test="tmnf-join"', false)->assertSee('How to join')->assertDontSee('ada_drives');

    $this->withSession(['locale' => 'de'])->get(route('scores.show', 'tmnf'))->assertOk()
        ->assertSee('So machst du mit')->assertSee('Login verknüpfen');
});

test('switched off, nothing of it shows: no game page, no week page, no job opens a week', function () {
    app(TmnfWeeks::class)->open();
    $week = Tournament::query()->where('game', 'tmnf')->sole();

    config(['esports.tmnf.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $this->get(route('scores.show', 'tmnf'))->assertNotFound();
    $this->get(route('tournaments.show', $week))->assertNotFound();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00'));
    $this->artisan('tmnf:weeks')->assertSuccessful();

    expect(Tournament::query()->where('game', 'tmnf')->count())->toBe(1)
        ->and(app(TmnfWeeks::class)->open())->toBeNull();
});

test('a counted finish that took first place is a moment to share: its card, its post to the week page, never a login', function () {
    $ada = tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    $run = tmnfFinish('ada_drives', 25_100);
    $week = app(TmnfWeeks::class)->current();

    $moment = app(TmnfMoments::class)->of($run);
    $post = app(SharePosts::class)->post($ada, 'tmnf', (string) $run->id);
    $card = ShareCard::tmnf($run, $moment);

    expect($moment)->toMatchArray(['kind' => 'first', 'place' => 1, 'final' => false, 'week' => '2026-10-05', 'track' => 'A01-Race'])
        ->and(app(TmnfMoments::class)->shareableOn($ada, $week))->toBe((string) $run->id)
        ->and($post->link)->toBe(route('tournaments.show', $week))
        ->and($post->sentence)->toBe('New first place in TMNF Week 41, 2026: A01-Race in 0:25.100 on TWENTY ONE Esports.')
        ->and($post->sentence)->not->toContain('#')->not->toContain('ada_drives')
        ->and(substr($card->render('wide'), 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(substr($card->render('story'), 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        // Not another player's: nothing to share.
        ->and(fn () => app(SharePosts::class)->post(User::factory()->create(), 'tmnf', (string) $run->id))->toThrow(ShareRefused::class);

    $this->actingAs($ada)->get(route('scores.show', 'tmnf'))->assertOk()->assertSee('data-test="tmnf-share"', false);
});

test('the stream\'s TMNF slide shows the week, its track, the top 5 by league name and how to join, in the teaser pool while TMNF is on', function () {
    foreach (['Ada' => 25_100, 'Ben' => 25_400] as $name => $ms) {
        tmnfPlayer(strtolower($name).'_drives', linked: true, attributes: ['name' => $name]);
        tmnfFinish(strtolower($name).'_drives', $ms);
    }

    $svg = SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation(TmnfSlide::SCENE, null, [], 0, 0, []), 'viewers' => null], RotationPlanner::VIEWS[TmnfSlide::SCENE]);

    expect($svg)->toContain('>TMNF Week 41, 2026<', '>A01-Race<', '>Ada<', '>Ben<', '>0:25.100<', 'Join TWENTY ONE in TMNF', '/scores/tmnf<')
        ->and($svg)->not->toContain('_drives')
        ->and(RotationPlanner::fromConfig(60)->teasers())->toContain(TmnfSlide::SCENE);

    config(['esports.tmnf.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(RotationPlanner::fromConfig(60)->teasers())->not->toContain(TmnfSlide::SCENE)
        ->and(app(TmnfSlide::class)->data())->toBeNull();
});

/** A slide of TMNF's set as the stream renders it, read fresh. */
function tmnfSlideSvg(string $scene): string
{
    Cache::flush();

    return SceneRenderer::fromConfig()->svg([...app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), []), 'viewers' => null], RotationPlanner::VIEWS[$scene]);
}

/** The words a slide shows. */
function tmnfSlideWords(string $svg): string
{
    preg_match_all('/<text[^>]*>([^<]*)<\/text>/', $svg, $m);

    return html_entity_decode(implode("\n", $m[1]));
}

test('the stream\'s TMNF set races the top 5 to the author time, calls to join TWENTY ONE and shows the time to beat, each over a screenshot of the game', function () {
    config(['esports.tmnf.server.login' => 'twentyone_srv']);
    foreach (['Ada' => 24_420, 'Ben' => 25_100, 'Cy' => 26_900] as $name => $ms) {
        tmnfPlayer(strtolower($name).'_drives', linked: true, attributes: ['name' => $name]);
        tmnfFinish(strtolower($name).'_drives', $ms, now()->subHours(3));
    }

    $race = tmnfSlideSvg(TmnfSlides::RACE);
    $join = tmnfSlideSvg(TmnfSlides::JOIN);
    $leader = tmnfSlideSvg(TmnfSlides::LEADER);
    preg_match_all('/data-unit="lane-marker-(\d)" data-x="([\d.]+)"/', $race, $markers);

    // The race: every lane by league name with its time and its gap to the author time (A01-Race: 0:24.540), the
    // marker of a time under the author time past the line, the slower ones further from it.
    expect(tmnfSlideWords($race))->toContain('TMNF Week 41, 2026', 'The race to the author time', 'A01-Race by Nadeo: author time 0:24.540', 'Author 0:24.540',
        "Ada\n0:24.420\n-0.120", "Ben\n0:25.100\n+0.560", "Cy\n0:26.900\n+2.360")
        ->and(array_combine($markers[1], array_map('floatval', $markers[2])))->toBe(['1' => 1092.9, '2' => 924.7, '3' => 400.0])
        // The call to join: our server, the favourite link and the four steps, the QR code of How to join.
        ->and(tmnfSlideWords($join))->toContain('Join TWENTY ONE', 'Paste tmtp://#addfavourite=twentyone_srv into the Explorer bar', 'Get TrackMania Nations Forever, free on Steam',
            "Link your login: type the code from the site in the\nserver chat", 'Drive A01-Race. Your best time of the week counts.', 'How to join', 'esports.einundzwanzig.space/scores/tmnf')
        ->and($join)->toContain('shape-rendering="crispEdges"')
        // The time to beat: the leader big, under the author time; set three hours ago, so not "new".
        ->and(tmnfSlideWords($leader))->toContain('The time to beat', 'Ada', '0.120 s under the author time', 'Set 3 hours ago')->not->toContain('New #1');

    foreach (TmnfSlides::SCENES as $scene) {
        $svg = tmnfSlideSvg($scene);
        $data = app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), []);

        // The game on every slide: its cover as the mark, its name in the copy; the backdrop is the slide's own screenshot.
        expect($svg)->toMatch('/<g data-unit="game-mark"[^>]*>\s*(<[^>]+>\s*)*<image [^>]*xlink:href="data:image\/jpeg;base64,/')
            ->and(tmnfSlideWords($svg))->toMatch('/\bTMNF\b|TrackMania/')
            ->and($data['backdrop'])->toBe(TmnfSlides::still($scene))->toStartWith('data:image/jpeg;base64,/9j/')
            // Never a TMNF login, a fee, a hashtag or a face (the favourite link's "#addfavourite" is TMNF's own link syntax).
            ->and($svg)->not->toContain('_drives')
            ->and(str_replace('tmtp://#addfavourite=', '', tmnfSlideWords($svg)))->not->toMatch('/#[A-Za-z]|\bfees?\b|\bface\b|Gesicht/i');
    }

    app()->setLocale('de');
    expect(tmnfSlideWords(tmnfSlideSvg(TmnfSlides::LEADER)))->toContain('The time to beat')->not->toContain('Woche');

    // A #1 younger than an hour is new.
    tmnfPlayer('dee_drives', linked: true, attributes: ['name' => 'Dee']);
    tmnfFinish('dee_drives', 24_300, now()->subMinutes(5));
    expect(tmnfSlideWords(tmnfSlideSvg(TmnfSlides::LEADER)))->toContain('New #1 on the board', 'Dee', 'Set 5 minutes ago');
});

test('TMNF\'s slides are spread over the teaser pool while TMNF is on, never two in a row, and none of them while it is off', function () {
    $pool = RotationPlanner::fromConfig(60)->teasers();
    $tmnf = array_keys(array_intersect($pool, RotationPlanner::TMNF_SCENES));
    $next = array_map(fn (int $i): string => $pool[($i + 1) % count($pool)], $tmnf);

    expect(array_values(array_intersect($pool, RotationPlanner::TMNF_SCENES)))->toBe(['g1', 'g2', 'g3', 'g4'])
        ->and(array_intersect($next, RotationPlanner::TMNF_SCENES))->toBe([])
        ->and(array_values(array_diff($pool, RotationPlanner::TMNF_SCENES)))->toBe([...RotationPlanner::TEASERS, ...(in_array('f1', $pool, true) ? ['f1'] : [])]);

    config(['esports.tmnf.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(array_intersect(RotationPlanner::fromConfig(60)->teasers(), RotationPlanner::TMNF_SCENES))->toBe([])
        ->and(app(TmnfSlides::class)->data())->toBeNull();

    // Switched off, a slide still renders: the invitation to every game over the brand.
    foreach (TmnfSlides::SCENES as $scene) {
        expect(tmnfSlideWords(tmnfSlideSvg($scene)))->toContain('Every game');
    }
});

test('an admin sees a held finish with its hint instead of a proof link, and approves it', function () {
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    $run = tmnfFinish('ada_drives', 21_000);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.scores')
        ->assertSee('data-test="score-held-hint"', false)->assertSee('0:24.540')->assertDontSee('data-test="score-proof"', false)
        ->call('approve', $run->id)->assertHasNoErrors();

    expect($run->refresh()->verified_at)->not->toBeNull()->and($run->verified_by_id)->toBe($admin->id);
});

test('home, the rules and the attempts on /matches carry the week: its card shows the time, a finish links its week', function () {
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    $run = tmnfFinish('ada_drives', 25_100);
    $week = app(TmnfWeeks::class)->current();

    $home = $this->get(route('home'))->assertOk()->getContent();
    $card = (string) str($home)->after('data-test="score-top" data-game="tmnf"')->before('</li>');

    expect($card)->toContain('This week')->toContain('Ada')->toContain('0:25.100')
        ->and(ScoreAttempts::links([$run]))->toBe([ScoreAttempts::key($run) => route('tournaments.scores', $week)]);

    $this->get(route('rules'))->assertOk()->assertSee('id="tmnf"', false)->assertSee('Only finishes of a linked login count.');
});

test('with a server login, How to join gives the favourite link and the restart step free Nations accounts need', function () {
    config(['esports.tmnf.server.login' => 'e21league']);
    tmnfPlayer('ada_drives', linked: true);
    tmnfFinish('ada_drives', 25_100);
    $week = app(TmnfWeeks::class)->current();

    $this->get(route('tournaments.show', $week))->assertOk()
        ->assertSee('tmtp://#addfavourite=e21league', false)
        ->assertSee('data-test="join-favourite-steps"', false)
        ->assertSee('Open the Explorer in TMNF and paste the link into the bar at the top, then press Enter.')
        ->assertSee('Restart TMNF.')
        ->assertSee('data-test="join-search-name"', false);
});

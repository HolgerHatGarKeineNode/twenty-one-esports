<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\Blockpile;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

/*
 * The upcoming tournaments the stream's tournament slides show
 * (TournamentSlides): the contract fields as the public tournament page
 * reads them, the countdown from the frame's clock, the cache.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A keyed player with a casual Elo, signed up solo. */
function slidePlayer(Tournament $tournament, string $name, int $elo): User
{
    [$user, $signer] = keyedPlayer();
    $user->forceFill(['name' => $name])->save();
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => $tournament->game, 'mode' => $tournament->mode, 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $elo, 'results' => 10]);
    soloSignup($tournament, $user, $signer);

    return $user;
}

/** The contract's keys (resources/views/stream/rotation/ta1-hero.blade.php); SceneSource lifts `backdrop` onto the scene. */
const SLIDE_KEYS = ['id', 'name', 'description', 'status', 'game', 'mode', 'format', 'teamSize', 'rated', 'where', 'startsAt', 'signupClosesAt',
    'countdown', 'countdownLabel', 'taken', 'places', 'spotsLeft', 'roster', 'solos', 'openSpots', 'preview', 'cover', 'coverTile', 'backdrop', 'url', 'pot', 'cup', 'region', 'howItRuns'];

/**
 * Roster rows or preview sides without `avatar` and `logo` (TwentyOneStreamImagesTest checks those).
 *
 * @param  list<array<string, mixed>>  $entries
 * @return list<array<string, mixed>>
 */
function slideEntriesWithoutPictures(array $entries): array
{
    return array_map(fn (array $entry): array => array_diff_key($entry, ['avatar' => true, 'logo' => true]), $entries);
}

test('a two stage tournament: every contract field, the seeds by Elo, the projected groups, the ticking countdown', function () {
    $tournament = openTournament([
        'name' => "Halving\u{202E} Cup\nFinals",
        'description' => "Bring your own board.\n\nPizza at the break.",
        'format' => TournamentFormat::TwoStage,
        'capacity' => 8,
        'starts_at' => now()->addWeek()->setTime(18, 0),
    ]);
    slidePlayer($tournament, 'satsjaeger', 1100);
    slidePlayer($tournament, "hodl\u{200B}queen", 1400);
    $tournament->refresh();
    $closesMs = (int) $tournament->signup_closes_at->getTimestampMs();

    $data = app(TournamentSlides::class)->data($tournament, $closesMs - (6 * 86400 + 6 * 3600 + 5 * 60 + 1) * 1000);

    expect(array_keys($data))->toEqualCanonicalizing(SLIDE_KEYS)
        ->and($data)->toMatchArray([
            'id' => $tournament->id,
            'pot' => null,
            'cup' => false,
            'region' => null,
            'name' => 'Halving Cup Finals',
            'description' => 'Bring your own board. Pizza at the break.',
            'status' => 'Sign-up open',
            'game' => 'Chess',
            'mode' => 'Blitz 5+3',
            'format' => 'Two Stage',
            'teamSize' => 1,
            'rated' => false,
            'where' => 'Online',
            'startsAt' => $tournament->starts_at->copy()->timezone('Europe/Berlin')->format('D j M, H:i T'),
            'signupClosesAt' => $tournament->signup_closes_at->copy()->timezone('Europe/Berlin')->format('D j M, H:i T'),
            'countdown' => '6d 06:05:01',
            'countdownLabel' => 'Sign-up closes in',
            'taken' => 2,
            'places' => 8,
            'spotsLeft' => 6,
            'openSpots' => 6,
            'url' => 'esports.einundzwanzig.space/tournaments/'.$tournament->id,
        ])
        ->and(slideEntriesWithoutPictures($data['roster']))->toBe([['seed' => 1, 'name' => 'hodl queen', 'rating' => 1400, 'seats' => 1], ['seed' => 2, 'name' => 'satsjaeger', 'rating' => 1100, 'seats' => 1]])
        // Berlin is UTC+1 or +2: 18:00 UTC is 19:00 or 20:00 there.
        ->and($data['startsAt'])->toMatch('/^[A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2}, (19:00 CET|20:00 CEST)$/')
        ->and($data['cover'])->toStartWith('data:image/jpeg;base64,/9j/')
        ->and($data['preview']['kind'])->toBe('groups')
        ->and($data['preview']['stageNote'])->toBe('Groups if sign-up closed now')
        ->and(array_keys($data['preview']['groups']))->toBe(['A', 'B'])
        ->and(slideEntriesWithoutPictures(array_merge(...array_values($data['preview']['groups']))))->toContain(['seed' => 1, 'name' => 'hodl queen'], ['seed' => 2, 'name' => 'satsjaeger'], ['seed' => 8, 'name' => null])
        ->and($data['preview'])->not->toHaveKey('matches')
        // Below a day the days go.
        ->and(app(TournamentSlides::class)->data($tournament, $closesMs - 3_661_000)['countdown'])->toBe('01:01:01');
});

test('a single elimination tournament previews round 1 with open spots and byes', function () {
    $tournament = openTournament(['format' => TournamentFormat::SingleElimination, 'capacity' => 6]);
    slidePlayer($tournament, 'topseed', 1500);

    $preview = app(TournamentSlides::class)->data($tournament->refresh(), now()->getTimestampMs())['preview'];

    expect($preview['kind'])->toBe('bracket')
        ->and($preview['stageNote'])->toBe('Round 1 if sign-up closed now')
        ->and($preview)->not->toHaveKey('groups')
        ->and($preview['byes'])->toBe([1, 2])
        ->and($preview['matches'])->toHaveCount(2)
        ->and(slideEntriesWithoutPictures(array_merge(...array_column($preview['matches'], 'sides'))))->toEqualCanonicalizing([
            ['seed' => 3, 'name' => null], ['seed' => 4, 'name' => null], ['seed' => 5, 'name' => null], ['seed' => 6, 'name' => null],
        ]);
});

test('a team mode counts player places, seeds the lineup under its clan and leaves solo players unseeded', function () {
    $tournament = openTournament([], rocketLeague: true);
    [$lineup, $captain, $signer] = keyedLineup();
    lineupSignup($tournament, $lineup, $captain, $signer);
    [$solo, $soloSigner] = keyedPlayer();
    soloSignup($tournament, $solo, $soloSigner);

    $data = app(TournamentSlides::class)->data($tournament->refresh(), now()->getTimestampMs());

    expect($data)->toMatchArray([
        'game' => 'Rocket League',
        'mode' => '3v3',
        'format' => 'Single Elimination',
        'teamSize' => 3,
        'taken' => 4,
        'places' => 24,
        'spotsLeft' => 20,
    ])->and(slideEntriesWithoutPictures($data['roster']))->toBe([['seed' => 1, 'name' => $lineup->clan->name, 'rating' => (int) config('season.rating.start', 1000), 'seats' => 3]])
        ->and(slideEntriesWithoutPictures($data['preview']['matches'][0]['sides'])[0])->toBe(['seed' => 1, 'name' => $lineup->clan->name]);
});

test('a full tournament stays upcoming with no spot left', function () {
    $tournament = openTournament(['capacity' => 2]);
    slidePlayer($tournament, 'a', 1100);
    slidePlayer($tournament, 'b', 1000);

    $slides = app(TournamentSlides::class);

    expect($slides->upcoming()->modelKeys())->toBe([$tournament->id])
        ->and($slides->data($tournament->refresh(), now()->getTimestampMs()))->toMatchArray(['taken' => 2, 'places' => 2, 'spotsLeft' => 0, 'openSpots' => 0]);
});

test('upcoming means open for sign-up, soonest close first; a draft, a closed sign-up and a missing close are left out', function () {
    $later = openTournament();
    $later->forceFill(['signup_closes_at' => now()->addDays(3)])->save();
    $sooner = openTournament();
    $sooner->forceFill(['signup_closes_at' => now()->addHours(2)])->save();
    Tournament::factory()->create();
    Tournament::factory()->signup()->create();
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->subMinute()]);
    Tournament::factory()->create(['status' => TournamentStatus::Running, 'signup_closes_at' => now()->addDay()]);

    expect(app(TournamentSlides::class)->upcoming()->modelKeys())->toBe([$sooner->id, $later->id]);
});

test('all() reads the database once per cache period, ticks from the frame clock and drops a tournament the second its sign-up closes', function () {
    $tournament = openTournament();
    $closesMs = (int) $tournament->refresh()->signup_closes_at->getTimestampMs();
    $slides = app(TournamentSlides::class);

    $first = $slides->all($closesMs - 2000);
    DB::enableQueryLog();
    $second = $slides->all($closesMs - 1000);
    $queries = count(DB::getQueryLog());

    expect(array_column($first, 'id'))->toBe([$tournament->id])
        ->and($first[0]['countdown'])->toBe('00:00:02')
        ->and($second[0]['countdown'])->toBe('00:00:01')
        ->and($queries)->toBe(0)
        ->and($slides->all($closesMs))->toBe([]);
});

test('a cover file that cannot be read costs only the cover, not the slides', function () {
    $file = tempnam(sys_get_temp_dir(), 'cover');
    file_put_contents($file, "\xFF\xD8\xFFjpeg");

    expect(TournamentSlides::coverUri($file))->toBe('data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFFjpeg"));

    chmod($file, 0);

    expect(TournamentSlides::coverUri($file))->toBeNull()
        ->and(TournamentSlides::coverUri(null))->toBeNull();

    chmod($file, 0600);
    unlink($file);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root reads any file');

test('changed tournaments reach the slide data once the cache period passed, not before; a new avatar file within the memory TTL', function () {
    config(['twentyone.stream.images.dir' => $dir = storage_path('framework/testing/slides-images-'.bin2hex(random_bytes(4)))]);
    $tournament = openTournament(['name' => 'First Cup', 'capacity' => 8]);
    $first = slidePlayer($tournament, 'first', 1400);
    $first->forceFill(['picture' => 'https://cdn.example/first.png'])->save();
    $firstFile = StreamImages::avatarFile($first->id, 'https://cdn.example/first.png');
    File::ensureDirectoryExists(dirname($firstFile));
    File::put($firstFile, 'one');
    $slides = app(TournamentSlides::class);
    $source = app(SceneSource::class);
    // What the supervisor renders: this poll's frames, through the scene data of a tournament slide.
    $shown = function () use ($slides, $source): array {
        $now = (int) now()->getTimestampMs();

        return array_map(fn (array $frame): array => $source->rotation('ta1', null, [], 0, $now, [], $frame)['tournament'], $slides->all($now));
    };

    $before = $shown();
    $second = slidePlayer($tournament, 'second', 1200);
    $tournament->forceFill(['name' => 'Renamed Cup'])->save();
    $other = openTournament(['name' => 'Second Cup']);
    File::put($firstFile, 'two');
    $this->travel(10)->seconds();
    $cached = $shown();
    $this->travel(6)->seconds();
    $after = $shown();
    $this->travel(600)->seconds();
    $later = $shown();
    File::deleteDirectory($dir);

    expect($before)->toHaveCount(1)
        ->and($before[0])->toMatchArray(['name' => 'First Cup', 'taken' => 1, 'spotsLeft' => 7])
        ->and($before[0]['roster'][0])->toMatchArray(['name' => 'first', 'avatar' => 'data:image/jpeg;base64,'.base64_encode('one')])
        // Within the cache period nothing moved yet.
        ->and(array_column($cached, 'id'))->toBe([$tournament->id])
        ->and($cached[0])->toMatchArray(['name' => 'First Cup', 'taken' => 1, 'spotsLeft' => 7])
        ->and($cached[0]['roster'])->toHaveCount(1)
        // After it: the new sign-up with its avatar, the new name, the new tournament.
        ->and(array_column($after, 'id'))->toEqualCanonicalizing([$tournament->id, $other->id])
        ->and(collect($after)->firstWhere('id', $tournament->id))->toMatchArray(['name' => 'Renamed Cup', 'taken' => 2, 'spotsLeft' => 6])
        ->and(collect($after)->firstWhere('id', $other->id)['name'])->toBe('Second Cup')
        ->and(array_column(collect($after)->firstWhere('id', $tournament->id)['roster'], 'name'))->toBe(['first', 'second'])
        ->and(collect($after)->firstWhere('id', $tournament->id)['roster'][1]['avatar'])->toBe('data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($second->pubkey)))
        // The changed avatar file: held for the memory TTL, then shown.
        ->and(collect($after)->firstWhere('id', $tournament->id)['roster'][0]['avatar'])->toBe('data:image/jpeg;base64,'.base64_encode('one'))
        ->and(collect($later)->firstWhere('id', $tournament->id)['roster'][0]['avatar'])->toBe('data:image/jpeg;base64,'.base64_encode('two'));
});

test('the casual cups get no slides of their own; d2 shows them together', function () {
    expect(TournamentSlides::featured([['id' => 1, 'cup' => true], ['id' => 2, 'cup' => false], ['id' => 3], ['id' => 4, 'cup' => true]]))->toBe([2, 3])
        ->and(TournamentSlides::featured([]))->toBe([]);
});

test('a casual cup shows its times in its region\'s zone: the US cup at 8 pm Eastern, the EU cup at 8 pm Berlin', function () {
    // Stored in UTC, as the app does: 20:00 in New York is 00:00 UTC, 20:00 in Berlin 18:00 UTC.
    $usStart = CarbonImmutable::parse('2026-10-03 20:00', 'America/New_York')->utc();
    $euStart = CarbonImmutable::parse('2026-10-03 20:00', 'Europe/Berlin')->utc();
    $us = openTournament(['cup_series' => 'chess-us', 'starts_at' => $usStart, 'signup_closes_at' => $usStart]);
    $eu = openTournament(['cup_series' => 'chess-eu', 'starts_at' => $euStart, 'signup_closes_at' => $euStart]);
    $special = openTournament(['starts_at' => $usStart, 'signup_closes_at' => $usStart->subHour()]);
    $slides = app(TournamentSlides::class);
    $now = (int) now()->getTimestampMs();

    expect($slides->data($us, $now))->toMatchArray(['startsAt' => 'Sat 3 Oct, 20:00 EDT', 'region' => 'US'])
        ->and($slides->data($eu, $now))->toMatchArray(['startsAt' => 'Sat 3 Oct, 20:00 CEST', 'region' => 'EU'])
        // Every other tournament stays in the league's zone.
        ->and($slides->data($special, $now)['startsAt'])->toBe('Sun 4 Oct, 02:00 CEST');
});

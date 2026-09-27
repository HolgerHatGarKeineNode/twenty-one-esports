<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Clans\ClanLogos;
use App\Support\Nostr\Blockpile;
use App\Support\Nostr\HostResolver;
use App\Support\TwentyOne\Stream\ImageFetcher;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamImageBuilder;
use App\Support\TwentyOne\Stream\StreamImageFailed;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\TwentyOne\Stream\StreamStats;
use App\Support\TwentyOne\Stream\TournamentSlides;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestSigner;

/*
 * The stream's pictures (StreamImageBuilder writes, StreamImages reads):
 * the guarded fetch of a stranger's kind-0 picture, the redraw with GD, the
 * 24 h refresh, the Blockpile fallback, the bounded memory of the daemon,
 * and the `avatar` / `logo` / `backdrop` keys of every scene. DNS and HTTP
 * are faked: no test touches the network.
 */

beforeEach(function () {
    $this->imagesDir = storage_path('framework/testing/stream-images-'.bin2hex(random_bytes(4)));
    config(['twentyone.stream.images.dir' => $this->imagesDir]);
    Http::preventStrayRequests();
    streamImageHosts(['cdn.example' => ['93.184.215.14']]);
});

afterEach(function () {
    File::deleteDirectory($this->imagesDir);
});

/**
 * Answer DNS from a table (a name can point at a private address on purpose).
 *
 * @param  array<string, list<string>>  $answers
 */
function streamImageHosts(array $answers): void
{
    app()->instance(HostResolver::class, new class($answers) extends HostResolver
    {
        /** @param array<string, list<string>> $answers */
        public function __construct(private array $answers) {}

        public function addresses(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    });
}

/**
 * A real picture GD can read, one colour.
 *
 * @param  array{int, int, int}  $rgb
 */
function streamPicture(int $width = 300, int $height = 200, array $rgb = [200, 40, 40]): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A PNG that is only a header: it claims `$width` x `$height` pixels.
 */
function streamPngHeader(int $width, int $height): string
{
    $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
}

function pictureResponse(string $bytes, string $type = 'image/png', int $status = 200): PromiseInterface
{
    return Http::response($bytes, $status, ['Content-Type' => $type]);
}

/**
 * The red channel in the middle of a cached avatar.
 */
function avatarRed(string $path): int
{
    $image = imagecreatefromjpeg($path);

    return (imagecolorat($image, 64, 64) >> 16) & 0xFF;
}

test('a picture is fetched from the pinned address, without redirects, and redrawn as a 128 px JPEG', function () {
    $user = User::factory()->create(['picture' => 'https://cdn.example/me.png?token=secret']);
    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return pictureResponse(streamPicture(300, 200));
    });

    $outcome = app(StreamImageBuilder::class)->refreshAvatar($user);
    $path = StreamImages::avatarFile($user->id, 'https://cdn.example/me.png?token=secret');
    $size = getimagesize($path);

    expect($outcome)->toBe('fetched')
        ->and([$size[0], $size[1], $size['mime']])->toBe([128, 128, 'image/jpeg'])
        ->and(avatarRed($path))->toBeGreaterThan(180)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example:443:93.184.215.14'])
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(5);
});

test('a picture pointing inside the network is refused before any request', function (string $url, array $dns = []) {
    streamImageHosts(['cdn.example' => ['93.184.215.14'], ...$dns]);
    Http::fake(['*' => pictureResponse(streamPicture())]);

    expect(fn () => app(ImageFetcher::class)->fetch($url))->toThrow(StreamImageFailed::class);
    Http::assertNothingSent();
})->with([
    'a private IP' => ['https://10.0.0.5/me.png'],
    'the metadata address' => ['https://169.254.169.254/latest/meta-data'],
    'IPv6 loopback' => ['https://[::1]/me.png'],
    'a loopback name' => ['https://localhost/me.png', ['localhost' => ['127.0.0.1']]],
    'http' => ['http://cdn.example/me.png'],
    'a DNS answer that is private' => ['https://mixed.example/me.png', ['mixed.example' => ['93.184.215.14', '192.168.1.10']]],
    'a private IPv6 answer' => ['https://six.example/me.png', ['six.example' => ['fd00::1']]],
    'a name that does not resolve' => ['https://nowhere.example/me.png'],
    'another port' => ['https://cdn.example:8443/me.png'],
    'user info' => ['https://user:pass@cdn.example/me.png'],
]);

test('a redirect is not followed, so one to a private address never happens', function () {
    Http::fake([
        'https://cdn.example/*' => Http::response('', 302, ['Location' => 'https://10.0.0.1/me.png']),
        // Where the redirect points: it would deliver a picture, if it were followed.
        'https://10.0.0.1/*' => pictureResponse(streamPicture()),
    ]);

    expect(fn () => app(ImageFetcher::class)->fetch('https://cdn.example/me.png'))->toThrow(StreamImageFailed::class, 'status 302');
    Http::assertSentCount(1);
});

test('a body past 2 MB is cut off while streaming, and a larger announced length is refused', function () {
    $pulled = 0;
    $endless = new PumpStream(function () use (&$pulled): string|false {
        if ($pulled >= 50 * 1024 * 1024) {
            return false;
        }

        $pulled += 65536;

        return str_repeat('x', 65536);
    });
    Http::fake(['https://cdn.example/endless.png' => Http::response($endless, 200, ['Content-Type' => 'image/png']),
        'https://cdn.example/announced.png' => Http::response('small', 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) (3 * 1024 * 1024)])]);
    $fetcher = app(ImageFetcher::class);

    expect(fn () => $fetcher->fetch('https://cdn.example/endless.png'))->toThrow(StreamImageFailed::class, 'larger than 2097152 bytes')
        ->and($pulled)->toBeLessThanOrEqual(2 * 1024 * 1024 + 2 * 65536)
        ->and(fn () => $fetcher->fetch('https://cdn.example/announced.png'))->toThrow(StreamImageFailed::class, 'larger than 2097152 bytes');
});

test('an answer that is not an image is refused, whatever its bytes', function () {
    Http::fake(['https://cdn.example/*' => pictureResponse(streamPicture(), 'text/html')]);

    expect(fn () => app(ImageFetcher::class)->fetch('https://cdn.example/me.png'))->toThrow(StreamImageFailed::class, 'is not an image');
});

test('a picture larger than 4096 px a side is refused before it is decoded', function () {
    $builder = app(StreamImageBuilder::class);

    expect(fn () => $builder->avatarJpeg(streamPicture(4097, 1)))->toThrow(StreamImageFailed::class, 'image is 4097x1')
        ->and(fn () => $builder->avatarJpeg(streamPngHeader(50000, 50000)))->toThrow(StreamImageFailed::class, 'image is 50000x50000')
        ->and(getimagesizefromstring($builder->avatarJpeg(streamPicture(4096, 1)))[0])->toBe(128);
});

test('a broken picture keeps the old file, logs one line without the query and waits before the next try', function () {
    $user = User::factory()->create(['picture' => 'https://cdn.example/me.png?token=secret']);
    $path = StreamImages::avatarFile($user->id, $user->picture);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $old = app(StreamImageBuilder::class)->avatarJpeg(streamPicture()));
    touch($path, now()->subDays(2)->getTimestamp());
    // A real header (300x200), cut off: it passes the size check and fails to decode.
    Http::fake(['https://cdn.example/*' => pictureResponse(substr(streamPicture(), 0, 80))]);
    Log::spy();
    $builder = app(StreamImageBuilder::class);

    $first = $builder->refreshAvatar($user);
    $second = $builder->refreshAvatar($user);

    expect([$first, $second])->toBe(['failed', 'waiting'])
        ->and(file_get_contents($path))->toBe($old);
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $line): bool => str_contains($line, 'https://cdn.example/me.png: the image could not be decoded')
        && ! str_contains($line, 'token') && ! str_contains($line, 'secret'));

    $this->travel(61)->minutes();

    expect($builder->refreshAvatar($user))->toBe('failed');
    Http::assertSentCount(2);
});

test('an avatar is kept for 24 hours, then fetched again', function () {
    $user = User::factory()->create(['picture' => 'https://cdn.example/me.png']);
    $colours = [[200, 40, 40], [40, 40, 200]];
    Http::fake(function () use (&$colours) {
        return pictureResponse(streamPicture(rgb: array_shift($colours)));
    });
    $builder = app(StreamImageBuilder::class);
    $path = StreamImages::avatarFile($user->id, $user->picture);

    expect($builder->refreshAvatar($user))->toBe('fetched')
        ->and(avatarRed($path))->toBeGreaterThan(180);

    $this->travel(23)->hours();

    expect($builder->refreshAvatar($user))->toBe('fresh');
    Http::assertSentCount(1);

    $this->travel(1)->hours();

    expect($builder->refreshAvatar($user))->toBe('fetched')
        ->and(avatarRed($path))->toBeLessThan(60);
    Http::assertSentCount(2);
});

test('our own upload is redrawn from the public disk, without a request', function () {
    Storage::fake('public');
    Storage::disk('public')->put('avatars/me.png', streamPicture());
    $user = User::factory()->create(['avatar_path' => 'avatars/me.png', 'picture' => 'https://cdn.example/me.png']);
    Http::fake();

    expect(app(StreamImageBuilder::class)->refreshAvatar($user))->toBe('fetched')
        ->and(is_file(StreamImages::avatarFile($user->id, 'public:avatars/me.png')))->toBeTrue();
    Http::assertNothingSent();
});

test('without a cached picture a player gets their Blockpile, an open spot nothing', function () {
    $plain = User::factory()->create();
    $pending = User::factory()->create(['picture' => 'https://cdn.example/me.png']);
    $images = app(StreamImages::class);

    expect($images->forUser($plain))->toBe('data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($plain->pubkey)))
        ->and($images->forUser($pending))->toBe('data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($pending->pubkey)))
        ->and($images->forUser(null))->toBeNull()
        ->and($images->avatar(null))->toBeNull();
});

test('the daemon holds a bounded number of data URIs, and reads a refreshed file after the TTL', function () {
    config(['twentyone.stream.images.memory_entries' => 3, 'twentyone.stream.images.memory_seconds' => 600]);
    $images = app(StreamImages::class);

    foreach (User::factory()->count(5)->create() as $user) {
        $images->forUser($user);
    }

    expect($images->held())->toBe(3);

    $user = User::factory()->create(['picture' => 'https://cdn.example/me.png']);
    $path = StreamImages::avatarFile($user->id, $user->picture);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'first');
    $first = $images->forUser($user);
    File::put($path, 'second');
    $cached = $images->forUser($user);
    $this->travel(601)->seconds();

    expect($first)->toBe('data:image/jpeg;base64,'.base64_encode('first'))
        ->and($cached)->toBe($first)
        ->and($images->forUser($user))->toBe('data:image/jpeg;base64,'.base64_encode('second'))
        ->and($images->held())->toBeLessThanOrEqual(3);
});

test('a backdrop is built once per cover and again when the cover changes', function () {
    $builder = app(StreamImageBuilder::class);
    $covers = count(array_filter(array_map(fn (string $slug): ?string => app(GameRegistry::class)->coverPath($slug), array_keys(app(GameRegistry::class)->all()))));

    $first = $builder->buildBackdrops();
    $second = $builder->buildBackdrops();
    touch(StreamImages::backdropFile(StreamImages::CHESS), 1);
    $third = $builder->buildBackdrops();
    $size = getimagesize(StreamImages::backdropFile(StreamImages::CHESS));

    expect($first)->toBe(['built' => $covers + 1, 'fresh' => 0, 'failed' => 0])
        ->and($second)->toBe(['built' => 0, 'fresh' => $covers + 1, 'failed' => 0])
        ->and($third)->toBe(['built' => 1, 'fresh' => $covers, 'failed' => 0])
        ->and([$size[0], $size[1], $size['mime']])->toBe([640, 360, 'image/jpeg'])
        ->and(is_file(StreamImages::backdropFile(StreamImages::BRAND)))->toBeTrue();
});

test('the command survives a failing player, removes stale files and runs every ten minutes without overlapping', function () {
    $good = User::factory()->create(['picture' => 'https://cdn.example/good.png']);
    User::factory()->create(['picture' => 'https://cdn.example/bad.png']);
    User::factory()->create();
    $stale = StreamImages::dir().'/avatars/'.sha1('999|https://cdn.example/old.png').'.jpg';
    File::ensureDirectoryExists(dirname($stale));
    File::put($stale, 'old');
    Http::fake(['https://cdn.example/good.png' => pictureResponse(streamPicture()), 'https://cdn.example/bad.png' => Http::response('', 500)]);

    $exit = Artisan::call('twentyone:stream:images');
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'twentyone:stream:images'));

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Avatars: 1 fetched, 0 fresh, 0 waiting after a failure, 1 failed, 1 without a picture, 1 old file(s) removed.')
        ->and(is_file(StreamImages::avatarFile($good->id, $good->picture)))->toBeTrue()
        ->and(is_file($stale))->toBeFalse()
        ->and($event?->expression)->toBe('*/10 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue();
});

test('every person on a scene has an avatar, a clan its logo, every scene its backdrop', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    Storage::fake('public');
    app(StreamImageBuilder::class)->buildBackdrops();
    $images = app(StreamImages::class);
    $chessBackdrop = $images->backdrop(StreamImages::CHESS);
    $brandBackdrop = $images->backdrop(StreamImages::BRAND);
    $rlBackdrop = $images->backdrop('rocket-league');

    // White has a cached picture, black only their Blockpile.
    $white = User::factory()->create(['picture' => 'https://cdn.example/white.png']);
    $black = User::factory()->create();
    $whitePath = StreamImages::avatarFile($white->id, $white->picture);
    File::ensureDirectoryExists(dirname($whitePath));
    File::put($whitePath, app(StreamImageBuilder::class)->avatarJpeg(streamPicture()));
    $whiteAvatar = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($whitePath));
    $blackAvatar = 'data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($black->pubkey));
    $game = app(ChessGameService::class)->start($white, $black);
    ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$white->id, 'user_id' => $white->id, 'rating' => 1100, 'results' => 3]);

    // One clan, with a logo we redrew: the spotlight and the lineup show it.
    $logos = app(ClanLogos::class);
    $png = streamPicture(64, 64, [10, 200, 10]);
    $logos->store($png);
    $logo = 'data:image/png;base64,'.base64_encode($png);
    [$lineup, $captain, $signer] = keyedLineup();
    $lineup->clan->forceFill(['picture' => $logos->urlFor($png)])->save();
    $teams = openTournament([], rocketLeague: true);
    lineupSignup($teams, $lineup, $captain, $signer);

    $solo = openTournament(['capacity' => 4]);
    [$entrant, $entrantSigner] = keyedPlayer();
    soloSignup($solo, $entrant, $entrantSigner);
    $entrantAvatar = 'data:image/svg+xml;base64,'.base64_encode(Blockpile::svg($entrant->pubkey));

    $source = app(SceneSource::class);
    ['games' => $games, 'more' => $more] = $source->sceneGames(60);
    $stats = app(StreamStats::class)->all();
    $now = (int) now()->getTimestampMs();
    $slides = app(TournamentSlides::class);
    $soloSlide = $slides->data($solo->refresh(), $now);
    $teamSlide = $slides->data($teams->refresh(), $now);

    $scenes = [];
    foreach (array_keys(RotationPlanner::VIEWS) as $scene) {
        $scenes[$scene] = $source->rotation($scene, $game->id, $games, $more, $now, $stats, $soloSlide);
    }
    $teamScene = $source->rotation('ta1', null, $games, $more, $now, $stats, $teamSlide);
    $cards = $scenes['a2']['games'];
    $soloSides = array_merge(...array_column($scenes['ta2']['tournament']['preview']['matches'], 'sides'));

    expect($chessBackdrop)->toStartWith('data:image/jpeg;base64,/9j/')
        ->and($brandBackdrop)->toStartWith('data:image/jpeg;base64,/9j/')
        ->and($rlBackdrop)->toStartWith('data:image/jpeg;base64,/9j/')
        ->and([$chessBackdrop, $brandBackdrop, $rlBackdrop])->toBe(array_unique([$chessBackdrop, $brandBackdrop, $rlBackdrop]))
        // Match, gallery, daily game: every player.
        ->and([$scenes['a1']['game']['white']['avatar'], $scenes['a1']['game']['black']['avatar']])->toBe([$whiteAvatar, $blackAvatar])
        ->and(array_column(array_column($cards, 'white'), 'avatar'))->toBe([$whiteAvatar, $whiteAvatar])
        ->and(array_column(array_column($cards, 'black'), 'avatar'))->toBe([$blackAvatar, $blackAvatar])
        ->and($scenes['b3']['dailyGame']['black']['avatar'])->toBe($blackAvatar)
        // Ladders and the clan spotlight.
        ->and($scenes['a3']['stats']['ladders']['blitz'][0]['avatar'])->toBe($whiteAvatar)
        ->and($scenes['b4']['stats']['clan']['logo'])->toBe($logo)
        // A tournament: the solo player's row and side, the open spots without; the lineup with its clan logo.
        ->and($scenes['ta1']['tournament']['roster'][0])->toMatchArray(['avatar' => $entrantAvatar, 'logo' => null])
        ->and(collect($soloSides)->firstWhere('seed', 1))->toMatchArray(['avatar' => $entrantAvatar, 'logo' => null])
        ->and(collect($soloSides)->whereNull('name')->pluck('avatar')->unique()->all())->toBe([null])
        ->and(collect($soloSides)->whereNull('name')->count())->toBeGreaterThan(0)
        ->and($teamScene['tournament']['roster'][0])->toMatchArray(['avatar' => null, 'logo' => $logo])
        ->and($teamScene['tournament']['preview']['matches'][0]['sides'][0])->toMatchArray(['avatar' => null, 'logo' => $logo])
        // Backdrops: the game of the tournament, chess for match and gallery, the brand for the teasers.
        ->and($teamScene['backdrop'])->toBe($rlBackdrop)
        ->and($teamScene['tournament'])->not->toHaveKey('backdrop')
        ->and(collect($scenes)->map(fn (array $data): ?string => $data['backdrop'])->all())->toBe([
            'a1' => $chessBackdrop, 'a2' => $chessBackdrop, 'a3' => $brandBackdrop, 'a4' => $brandBackdrop, 'a5' => $brandBackdrop,
            'b1' => $chessBackdrop, 'b2' => $chessBackdrop, 'b3' => $brandBackdrop, 'b4' => $brandBackdrop, 'b5' => $brandBackdrop,
            'c1' => $chessBackdrop, 'c2' => $chessBackdrop, 'c3' => $brandBackdrop, 'c4' => $brandBackdrop, 'c5' => $brandBackdrop,
            'ta1' => $chessBackdrop, 'ta2' => $chessBackdrop, 'tb1' => $chessBackdrop, 'tb2' => $chessBackdrop, 'tc1' => $chessBackdrop, 'tc2' => $chessBackdrop,
        ])
        // The fallback scene (gallery or single game) too.
        ->and($source->gallery($games, $more, $now)['backdrop'])->toBe($chessBackdrop)
        ->and($source->gallery([$game], 0, $now))->toMatchArray(['backdrop' => $chessBackdrop])
        ->and($source->gallery([$game], 0, $now)['white']['avatar'])->toBe($whiteAvatar);
});

test('pictures cost the frame no database query', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $tournament = openTournament();
    [$entrant, $signer] = keyedPlayer();
    soloSignup($tournament, $entrant, $signer);
    Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$entrant->id, 'user_id' => $entrant->id, 'rating' => 1100, 'results' => 3]);
    Clan::factory()->create();
    $source = app(SceneSource::class);
    $counts = app(StreamStats::class);
    $slides = app(TournamentSlides::class);
    $counts->all();
    $slides->all((int) now()->getTimestampMs());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $now = (int) now()->getTimestampMs();
    $stats = $counts->all();
    $frames = $slides->all($now);
    $teaser = $source->rotation('a3', null, [], 0, $now, $stats);
    $slide = $source->rotation('ta1', null, [], 0, $now, $stats, $frames[0]);

    expect(DB::getQueryLog())->toBe([])
        ->and($teaser['stats']['ladders']['blitz'][0]['avatar'])->toStartWith('data:image/svg+xml;base64,')
        ->and($slide['tournament']['roster'][0]['avatar'])->toStartWith('data:image/svg+xml;base64,');
});

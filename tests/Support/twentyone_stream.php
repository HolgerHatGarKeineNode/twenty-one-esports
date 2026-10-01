<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Nostr\NostrKeys;
use App\Support\TwentyOne\Stream\PrideSlides;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneSource;
use App\Support\TwentyOne\Stream\StreamImages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;

/*
| Helpers of the twentyone:stream daemon tests, which live in tests/Feature/TwentyOneStream*Test.php.
| They were one file; the daemon tests run real wall-clock seconds (--stop-after), and the parallel
| runner hands out whole files, so that one file was the longest tail of the suite. Split into four
| files of equal length, the helpers moved here so that they exist once.
*/

/** What every test of the stream daemon starts with: a key, a scratch directory and the config pointing at it. */
function twentyOneStreamUp(object $test): void
{
    $test->key = new TestSigner;
    $test->nsec = (new Key)->convertPrivateKeyToBech32($test->key->secret);
    $test->dir = storage_path('framework/testing/stream-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($test->dir);

    config([
        'twentyone.nostr.nsec' => $test->nsec,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($test->key->pubkey),
        'twentyone.stream.prepared' => $test->dir.'/promo-stream.mp4',
        'twentyone.stream.hls_dir' => $test->dir.'/hls',
        // Any executable passes the start-up check; the tests fake or replace it.
        'twentyone.stream.ffmpeg' => PHP_BINARY,
        'twentyone.stream.scene.rsvg_convert' => PHP_BINARY,
        'twentyone.stream.scene.work_dir' => $test->dir.'/work',
        'twentyone.stream.music.dir' => $test->dir.'/music',
        'twentyone.stream.music.instrumental_dir' => $test->dir.'/music/instrumental',
        // A socket directory of its own per test (parallel workers), short enough for sun_path (107 bytes);
        // no ACL, as there is no `forge` user here.
        'twentyone.stream.viewers.dir' => sys_get_temp_dir().'/tos-'.bin2hex(random_bytes(6)),
        'twentyone.stream.viewers.nginx_user' => '',
        'twentyone.stream.session_file' => $test->dir.'/session.json',
        'twentyone.stream.music.timeline_file' => $test->dir.'/music-timeline.json',
    ]);

    File::ensureDirectoryExists($test->dir.'/music');

    foreach (['a__v1', 'a__v2', 'b__v1', 'c__v1'] as $track) {
        File::put($test->dir."/music/{$track}.m4a", 'aac');
    }
}

/** Removes the scratch directory, the viewer socket directory and the variables the fake encoders read. */
function twentyOneStreamDown(object $test): void
{
    File::deleteDirectory($test->dir);
    $viewerDir = config('twentyone.stream.viewers.dir');
    is_link($viewerDir) ? unlink($viewerDir) : File::deleteDirectory($viewerDir);
    File::deleteDirectory($viewerDir.'-target');

    foreach (['TWENTYONE_NOSTR_NSEC', 'TWENTYONE_TEST_API_TOKEN', 'FAKE_ENCODER_CAPTURE', 'FAKE_ENCODER_SEGMENTS', 'FAKE_ENCODER_DELAY', 'FAKE_ENCODER_EXIT_AFTER', 'FAKE_ENCODER_LOOP_EXIT_AFTER', 'FAKE_ENCODER_SCENE_DELAY'] as $name) {
        putenv($name);
        unset($_SERVER[$name]);
    }
}

/**
 * Put a variable into the environment children inherit. Symfony Process
 * passes on only names that are also in $_SERVER, so putenv() alone is not
 * enough.
 */
function setChildEnv(string $name, string $value): void
{
    putenv($name.'='.$value);
    $_SERVER[$name] = $value;
}

/**
 * An ffmpeg stand-in: a shell script with the given body.
 */
function fakeFfmpeg(string $dir, string $body): string
{
    File::put($dir.'/ffmpeg', "#!/bin/sh\n".$body."\n");
    chmod($dir.'/ffmpeg', 0755);
    config(['twentyone.stream.ffmpeg' => $dir.'/ffmpeg']);

    return $dir.'/ffmpeg';
}

/**
 * An ffmpeg stand-in that writes run-prefixed segments like the HLS muxer
 * (tests/Support/fake-encoder.php).
 */
function fakeEncoder(string $dir): string
{
    return fakeFfmpeg($dir, 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/Support/fake-encoder.php')).' "$@"');
}

/**
 * The files a public playlist references (segments and init files), as
 * paths under hls_dir.
 *
 * @return list<string>
 */
function referencedFiles(string $playlist): array
{
    preg_match_all('/^(?:#EXT-X-MAP:URI="([^"]+)"|([^#\s]\S*))$/m', $playlist, $matches);

    return array_values(array_unique(array_filter([...$matches[1], ...$matches[2]])));
}

/**
 * A SceneSource whose sceneGames() answers from a script: the one game on
 * show, none (null), or a Throwable to throw, one entry per poll (the last one repeats).
 *
 * @param  list<ChessGame|Throwable|null>  $answers
 */
function scriptedSource(array $answers): void
{
    $source = Mockery::mock(SceneSource::class, [app(ChessGameService::class), app(GameRegistry::class), app(StreamImages::class), app(PrideSlides::class)])->makePartial();
    $source->shouldReceive('sceneGames')->andReturnUsing(function () use (&$answers) {
        $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return ['games' => $answer === null ? [] : [$answer->fresh(['white', 'black'])], 'more' => 0];
    });
    app()->instance(SceneSource::class, $source);
}

/**
 * A render "binary" that answers with a PNG signature; with `$failRotation`
 * it fails on the rotation scenes (their defs carry "mark-dark") and
 * renders the old game scene.
 */
function fakeRenderer(string $dir, bool $failRotation = false): void
{
    $check = $failRotation ? 'if printf %s "$svg" | grep -q mark-dark; then echo rotation broken >&2; exit 1; fi'."\n" : '';
    File::put($dir.'/rsvg-convert', "#!/bin/sh\nsvg=$(cat)\n{$check}printf '\\211PNG'\n");
    chmod($dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $dir.'/rsvg-convert']);
}

/**
 * Short rotation slots, so a few seconds show a whole round.
 */
function shortRotation(): void
{
    config(['twentyone.stream.rotation' => [
        'match_seconds' => 1, 'blitz_match_seconds' => 1, 'gallery_seconds' => 1, 'teaser_seconds' => 1,
        'teasers_per_round' => 3, 'loop_every_rounds' => 3, 'loop_fallback_seconds' => 1,
    ]]);
}

/**
 * A render "binary" that always fails.
 */
function failingRenderer(string $dir): void
{
    File::put($dir.'/rsvg-convert', "#!/bin/sh\necho broken >&2\nexit 1\n");
    chmod($dir.'/rsvg-convert', 0755);
    config(['twentyone.stream.scene.rsvg_convert' => $dir.'/rsvg-convert']);
}

/**
 * Record the `viewers` every stream view is rendered with ('missing' when a
 * view gets none), as [view, viewers] pairs.
 *
 * @return ArrayObject<int, array{0: string, 1: int|string|null}>
 */
function captureViewers(): ArrayObject
{
    $seen = new ArrayObject;

    View::composer([...array_values(RotationPlanner::VIEWS), 'stream.scene'], function ($view) use ($seen): void {
        $data = $view->getData();
        $seen[] = [$view->name(), array_key_exists('viewers', $data) ? $data['viewers'] : 'missing'];
    });

    return $seen;
}

/**
 * A child process that plays nginx: it waits for the socket and then sends
 * `$datagrams` every 100 ms until `$seconds` have passed.
 *
 * @param  list<string>  $datagrams
 * @return resource
 */
function fakeNginx(string $socket, array $datagrams, float $seconds)
{
    $script = '$until = microtime(true) + '.$seconds.'; $lines = json_decode($argv[2], true);'
        .' while (microtime(true) < $until) { $c = @stream_socket_client("udg://".$argv[1]);'
        .' if ($c !== false) { foreach ($lines as $line) { @fwrite($c, $line); } fclose($c); } usleep(100000); }';

    return proc_open([PHP_BINARY, '-r', $script, $socket, json_encode($datagrams)], [], $pipes);
}

/**
 * A fake relay that accepts one event and keeps it in `$file`; [process, ws URL].
 *
 * @return array{0: resource, 1: string}
 */
function recordingRelay(string $file): array
{
    $relay = proc_open([PHP_BINARY, base_path('tests/Support/fake-relay.php'), 'record', $file], [1 => ['pipe', 'w']], $pipes);

    return [$relay, 'ws://127.0.0.1:'.(int) fgets($pipes[1])];
}

/**
 * One daemon run of `$seconds` against a recording relay: the log and the
 * event the relay accepted (null when none).
 *
 * @return array{0: string, 1: array<string, mixed>|null}
 */
function streamRunRecorded(string $dir, int $run, float $seconds = 2): array
{
    [$relay, $url] = recordingRelay($dir."/event-{$run}.json");
    Artisan::call('twentyone:stream', ['--relays' => $url, '--stop-after' => $seconds]);
    $output = Artisan::output();
    proc_terminate($relay);
    proc_close($relay);
    $event = is_file($dir."/event-{$run}.json") ? json_decode((string) file_get_contents($dir."/event-{$run}.json"), true)[1] : null;

    return [$output, $event];
}

/**
 * The value of the first `$name` tag of an event.
 *
 * @param  array<string, mixed>  $event
 */
function eventTag(array $event, string $name): ?string
{
    return collect($event['tags'])->firstWhere(0, $name)[1] ?? null;
}

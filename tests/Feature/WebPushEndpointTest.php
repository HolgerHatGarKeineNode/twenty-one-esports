<?php

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeHostResolver;

/*
 * A push endpoint is a URL a player's browser hands in (P47 audit follow-up):
 * the server posts to it only when it is https on port 443 of a DNS name whose
 * every address is public, pinned to the checked address, without following a
 * redirect, within one deadline, and without taking or inflating a large
 * answer. Against a real local HTTPS server (tests/Support/fake-https-image.php),
 * never Http::fake.
 *
 * Measured before the fix (the same server, its CA trusted): a POST to
 * https://localhost:<port>/push/abc went out and got 200; a 302 to
 * https://127.0.0.1:<port>/internal was followed (a second connection).
 */

/**
 * The real WebPush against the local server: its port, its CA, loopback allowed unless said otherwise.
 */
function localWebPush(int $port, string $caFile, bool $loopbackAllowed = true, int $timeoutSeconds = 2): WebPush
{
    [$public, $private] = WebPush::generateKeyPair();

    return new class(WebPush::base64UrlEncode($public), WebPush::base64UrlEncode($private), $timeoutSeconds, $port, $caFile, $loopbackAllowed) extends WebPush
    {
        public function __construct(string $public, string $private, int $timeoutSeconds, private int $testPort, private string $caFile, private bool $loopbackAllowed)
        {
            parent::__construct($public, $private, 'mailto:ops@esports.test', 60, $timeoutSeconds, app(HostResolver::class));
        }

        protected function port(): int
        {
            return $this->testPort;
        }

        protected function isAllowedAddress(string $address): bool
        {
            return $this->loopbackAllowed ? in_array($address, ['127.0.0.1', '127.0.0.2'], true) : parent::isAllowedAddress($address);
        }

        protected function extraOptions(): array
        {
            return ['verify' => $this->caFile];
        }
    };
}

/**
 * @return array{0: resource, 1: int, 2: string}
 */
function pushServer(string $mode, string $host): array
{
    $dir = storage_path('framework/testing/push-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($dir);
    $process = proc_open([PHP_BINARY, base_path('tests/Support/fake-https-image.php'), $mode, $host, $dir], [1 => ['pipe', 'w']], $pipes);

    return [$process, (int) fgets($pipes[1]), $dir];
}

/**
 * Stop the server; the requests it saw ("<Host> <path>").
 *
 * @param  resource  $process
 * @return list<string>
 */
function stopPushServer($process, string $dir): array
{
    $seen = is_file($dir.'/connections') ? array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/connections')))) : [];
    proc_terminate($process);
    proc_close($process);
    File::deleteDirectory($dir);

    return $seen;
}

function pushSubscriptionTo(string $endpoint): PushSubscription
{
    [$browser] = WebPush::generateKeyPair();

    return PushSubscription::query()->create([
        'user_id' => User::factory()->create()->id,
        'endpoint' => $endpoint,
        'public_key' => WebPush::base64UrlEncode($browser),
        'auth_token' => WebPush::base64UrlEncode(random_bytes(16)),
    ]);
}

/**
 * Send one push and measure it: [status, seconds, MB the peak memory grew by, requests the server saw].
 *
 * @return array{0: int, 1: float, 2: float, 3: list<string>}
 */
function pushMeasure(string $mode, string $host, array $dns, bool $loopbackAllowed = true): array
{
    app()->instance(HostResolver::class, new FakeHostResolver($dns));
    [$server, $port, $dir] = pushServer($mode, $host);
    $subscription = pushSubscriptionTo('https://'.$host.':'.$port.'/push/abc');

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = microtime(true);
    $status = localWebPush($port, $dir.'/ca.pem', $loopbackAllowed)->send($subscription, ['title' => 'Your move']);
    $seconds = microtime(true) - $started;
    $grew = (memory_get_peak_usage() - $before) / 1048576;

    return [$status, $seconds, $grew, stopPushServer($server, $dir)];
}

beforeEach(fn () => Http::preventStrayRequests(false));

test('an endpoint on a name that resolves to a private address is never contacted', function () {
    [$status, , , $seen] = pushMeasure('image', 'push.localtest', ['push.localtest' => ['127.0.0.1']], loopbackAllowed: false);

    expect($status)->toBe(0)->and($seen)->toBe([]);
});

test('positive control: the same endpoint allowed is posted to, at the pinned address', function () {
    // A name no DNS knows: only the pin can take the connection to the server.
    [$status, , , $seen] = pushMeasure('image', 'pinned.invalid', ['pinned.invalid' => ['127.0.0.1']]);

    expect($status)->toBe(200)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toMatch('#^pinned\.invalid:\d+ /push/abc$#');
});

test('the connection goes to the checked address, never where the system resolver points', function () {
    // The guard is told 127.0.0.2; the system resolver would answer 127.0.0.1, where the server listens.
    [$status, , , $seen] = pushMeasure('image', 'pin.localhost', ['pin.localhost' => ['127.0.0.2']]);

    expect($status)->toBe(0)->and($seen)->toBe([]);
});

test('a redirect is answered, never followed', function () {
    [$status, , , $seen] = pushMeasure('redirect:0:302:https://pinned.invalid:{port}/internal|image', 'pinned.invalid', ['pinned.invalid' => ['127.0.0.1']]);

    expect($status)->toBe(302)->and($seen)->toHaveCount(1);
});

test('a push service that drips its answer is cut off at the deadline', function () {
    [$status, $seconds] = pushMeasure('trickle', 'pinned.invalid', ['pinned.invalid' => ['127.0.0.1']]);

    expect($status)->toBe(0)->and($seconds)->toBeLessThan(3.0);
});

test('an answer past the cap is aborted, and an encoded one is never inflated', function () {
    // What the HTTP client hands the handler: anything but false lets curl inflate into its sink,
    // which neither the cap nor the memory measure below sees (a mutant with decode_content true
    // stayed green on them alone).
    $decode = new ArrayObject;
    Http::globalMiddleware(fn (callable $handler): Closure => function ($request, array $options) use ($handler, $decode) {
        $decode[] = $options['decode_content'] ?? 'default';

        return $handler($request, $options);
    });

    [$big, $bigSeconds] = pushMeasure('big:5000000', 'pinned.invalid', ['pinned.invalid' => ['127.0.0.1']]);
    // 256 MB of zeros, gzipped twice: a few hundred bytes on the wire.
    [$bomb, $bombSeconds, $bombGrewMb] = pushMeasure('gzip2:256', 'pinned.invalid', ['pinned.invalid' => ['127.0.0.1']]);

    expect($big)->toBe(0)->and($bigSeconds)->toBeLessThan(2.0)
        ->and($bomb)->toBe(200)->and($bombSeconds)->toBeLessThan(2.0)->and($bombGrewMb)->toBeLessThan(16.0)
        ->and($decode->getArrayCopy())->toBe([false, false]);
});

test('endpoints that are not https on port 443 of a public DNS name are refused before any lookup', function (string $endpoint) {
    $resolver = new FakeHostResolver;
    $push = new WebPush('k', 'k', 'mailto:ops@esports.test', 60, 2, $resolver);

    expect($push->endpointTarget($endpoint))->toBeNull()
        ->and($resolver->lookups)->toBe([]);
})->with([
    'plain http' => 'http://push.example.com/x',
    'localhost' => 'https://localhost/x',
    'IPv4 literal' => 'https://127.0.0.1/x',
    'IPv6 literal' => 'https://[::1]/x',
    'user info' => 'https://user@push.example.com/x',
    'other port' => 'https://push.example.com:8443/x',
]);

test('an endpoint is refused when any address of its host is not public', function () {
    $resolver = new FakeHostResolver(['mixed.example.com' => ['93.184.215.14', '10.0.0.5'], 'metadata.example.com' => ['169.254.169.254']]);
    $push = new WebPush('k', 'k', 'mailto:ops@esports.test', 60, 2, $resolver);

    expect($push->endpointTarget('https://mixed.example.com/x'))->toBeNull()
        ->and($push->endpointTarget('https://metadata.example.com/x'))->toBeNull()
        ->and($push->endpointTarget('https://fcm.googleapis.com/fcm/send/abc'))->toBe(['host' => 'fcm.googleapis.com', 'port' => 443, 'address' => '93.184.215.14']);
});

test('a browser subscription to a non-public push service is refused with a reason, a public one is saved', function () {
    app()->instance(HostResolver::class, new FakeHostResolver(['push.internal.example' => ['10.0.0.5']]));
    $player = User::factory()->create();
    [$browser] = WebPush::generateKeyPair();
    $subscription = fn (string $endpoint): string => json_encode(['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::base64UrlEncode($browser), 'auth' => WebPush::base64UrlEncode(random_bytes(16))]]);
    $page = Livewire::actingAs($player)->test('pages::settings.notifications');

    foreach (['https://push.internal.example/x', 'https://localhost:8080/x', 'https://127.0.0.1/x'] as $endpoint) {
        expect($page->instance()->savePushSubscription($subscription($endpoint)))
            ->toBe("This browser's push service cannot be reached from here: its address must be https on a public host.");
    }

    expect(PushSubscription::query()->count())->toBe(0)
        ->and($page->instance()->savePushSubscription($subscription('https://updates.push.services.mozilla.com/wpush/v2/abc')))->toBeNull()
        ->and(PushSubscription::query()->where('user_id', $player->id)->pluck('endpoint')->all())->toBe(['https://updates.push.services.mozilla.com/wpush/v2/abc']);
});

<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Lightning\LightningAddress;
use App\Support\Lightning\LightningAddressFailure;
use App\Support\Lightning\WinnerZaps;
use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * Outbound requests to hosts a stranger chose (P47 security audit F1, F3),
 * against a real local HTTPS server (tests/Support/fake-https-image.php),
 * never Http::fake:
 *
 * - F1: a Lightning address server that drips one byte every 2 s is cut off
 *   at the total deadline, for the plain fetch and through the Livewire zap
 *   action; the stream handler waited as long as the drip went on.
 * - F3: the connection goes to the address the guard checked (CURLOPT_RESOLVE
 *   holds on the curl handler), for Lightning addresses and NIP-05 checks;
 *   where DNS points elsewhere, nothing connects.
 */

/**
 * A resolver that answers from `$answers` only.
 *
 * @param  array<string, list<string>>  $answers
 */
function outboundHosts(array $answers): void
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

/** The real LightningAddress against the local server: its port, loopback allowed, its CA. */
function localLightningAddress(int $port, string $caFile): LightningAddress
{
    return new class(app(Factory::class), app(HostResolver::class), $port, $caFile) extends LightningAddress
    {
        public function __construct(Factory $http, HostResolver $resolver, private int $testPort, private string $caFile)
        {
            parent::__construct($http, $resolver);
        }

        protected function port(): int
        {
            return $this->testPort;
        }

        protected function isAllowedAddress(string $address): bool
        {
            return in_array($address, ['127.0.0.1', '127.0.0.2'], true);
        }

        protected function extraOptions(): array
        {
            return ['verify' => $this->caFile];
        }
    };
}

/** The real Nip05Verifier against the local server. */
function localNip05Verifier(int $port, string $caFile): Nip05Verifier
{
    return new class(app(Factory::class), app(HostResolver::class), $port, $caFile) extends Nip05Verifier
    {
        public function __construct(Factory $http, HostResolver $resolver, private int $testPort, private string $caFile)
        {
            parent::__construct($http, $resolver);
        }

        protected function port(): int
        {
            return $this->testPort;
        }

        protected function isAllowedAddress(string $address): bool
        {
            return in_array($address, ['127.0.0.1', '127.0.0.2'], true);
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
function outboundServer(string $mode, string $host): array
{
    $dir = storage_path('framework/testing/outbound-'.bin2hex(random_bytes(4)));
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
function stopOutboundServer($process, string $dir): array
{
    $seen = is_file($dir.'/connections') ? array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/connections')))) : [];
    proc_terminate($process);
    proc_close($process);
    File::deleteDirectory($dir);

    return $seen;
}

beforeEach(function () {
    Http::preventStrayRequests(false);
    config(['esports.wallet.lnurl_request_seconds' => 2, 'esports.wallet.lnurl_budget_seconds' => 3]);
});

test('F1: a Lightning address server that drips its body is cut off at the total deadline', function () {
    outboundHosts(['drip.localhost' => ['127.0.0.1']]);
    [$server, $port, $dir] = outboundServer('trickle', 'drip.localhost');
    $started = microtime(true);

    try {
        localLightningAddress($port, $dir.'/ca.pem')->zapInvoice('tips@drip.localhost', 21, '{}', 'lnurl1x');
        $reason = null;
    } catch (LightningAddressFailure $failure) {
        $reason = $failure->reason;
    }

    $elapsed = microtime(true) - $started;
    $seen = stopOutboundServer($server, $dir);
    fwrite(STDERR, sprintf("\n[outbound] drip: %s after %.2f s\n", $reason, $elapsed));

    // The drip lasts 20 s; the deadline is 2 s per request.
    expect($reason)->toBe('lnurl_unreachable')
        ->and($seen)->toBe(['drip.localhost:'.$port.' /.well-known/lnurlp/tips'])
        ->and($elapsed)->toBeLessThan(3.0);
});

test('F1: the zap action of the page returns within the budget against a dripping wallet', function () {
    outboundHosts(['drip.localhost' => ['127.0.0.1']]);
    [$server, $port, $dir] = outboundServer('trickle', 'drip.localhost');
    app()->instance(LightningAddress::class, localLightningAddress($port, $dir.'/ca.pem'));

    $zapperKey = new TestSigner;
    $zapper = User::factory()->withPubkey($zapperKey->pubkey)->create();
    $winner = User::factory()->create(['lud16' => 'tips@drip.localhost']);
    $game = ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => User::factory()->create()->id, 'ply' => 12]);

    $page = Livewire::actingAs($zapper)->test('zap-winner', ['type' => 'game', 'subject' => (string) $game->id]);
    $template = $page->instance()->prepareZap($winner->id, 21, '', app(WinnerZaps::class))['template'];
    $signed = $zapperKey->sign(9734, $template['tags'], '', now()->getTimestamp());

    $started = microtime(true);
    $answer = $page->instance()->zapInvoice($winner->id, 21, '', json_encode($signed), app(WinnerZaps::class));
    $elapsed = microtime(true) - $started;
    stopOutboundServer($server, $dir);
    fwrite(STDERR, sprintf("\n[outbound] zap action: %.2f s\n", $elapsed));

    expect($answer)->toHaveKey('error')
        ->and($elapsed)->toBeLessThan(3.5);
});

test('F1: a body past 64 KB is aborted, not buffered', function () {
    outboundHosts(['big.localhost' => ['127.0.0.1']]);
    [$server, $port, $dir] = outboundServer('big:5000000', 'big.localhost');

    $fetch = fn () => localLightningAddress($port, $dir.'/ca.pem')->zapInvoice('tips@big.localhost', 21, '{}', 'lnurl1x');

    expect($fetch)->toThrow(LightningAddressFailure::class, 'no answer');
    stopOutboundServer($server, $dir);
});

test('F3: a Lightning address connects to the address the guard checked, never where DNS points now', function () {
    // A name no DNS knows: only the pin can take the connection to the server.
    outboundHosts(['pinned.invalid' => ['127.0.0.1']]);
    [$server, $port, $dir] = outboundServer('image', 'pinned.invalid');

    try {
        localLightningAddress($port, $dir.'/ca.pem')->zapInvoice('tips@pinned.invalid', 21, '{}', 'lnurl1x');
    } catch (LightningAddressFailure) {
        // A picture is no payRequest; what counts is where the request went.
    }

    expect(stopOutboundServer($server, $dir))->toBe(['pinned.invalid:'.$port.' /.well-known/lnurlp/tips']);

    // The guard is told 127.0.0.2; the system resolver would answer 127.0.0.1, where the server listens: nothing may connect.
    outboundHosts(['pin.localhost' => ['127.0.0.2']]);
    [$control, $controlPort, $controlDir] = outboundServer('image', 'pin.localhost');

    expect(fn () => localLightningAddress($controlPort, $controlDir.'/ca.pem')->zapInvoice('tips@pin.localhost', 21, '{}', 'lnurl1x'))
        ->toThrow(LightningAddressFailure::class)
        ->and(stopOutboundServer($control, $controlDir))->toBe([]);
});

test('F3: a NIP-05 check connects to the address the guard checked', function () {
    outboundHosts(['pinned.invalid' => ['127.0.0.1']]);
    [$server, $port, $dir] = outboundServer('image', 'pinned.invalid');

    expect(localNip05Verifier($port, $dir.'/ca.pem')->check('anna@pinned.invalid', str_repeat('a', 64)))->toBeFalse()
        ->and(stopOutboundServer($server, $dir))->toBe(['pinned.invalid:'.$port.' /.well-known/nostr.json?name=anna']);
});

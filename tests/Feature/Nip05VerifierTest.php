<?php

use App\Jobs\VerifyNip05;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| NIP-05 check (App\Support\Nostr\Nip05Verifier)
|--------------------------------------------------------------------------
|
| The address comes from a stranger's profile, so the check is an outbound
| request to a host an attacker picks. DNS is answered by a fake resolver:
| no test touches the network, and a name can point at 127.0.0.1 on purpose.
|
*/

/**
 * @param  array<string, list<string>>  $answers  host => addresses
 */
function resolveHosts(array $answers): void
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

function playerWithAddress(string $nip05): User
{
    return User::factory()->create(['nip05' => $nip05, 'profile_event_at' => now()]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('an address whose nostr.json names the key is verified', function () {
    resolveHosts(['mempool.example' => ['93.184.215.14']]);
    $player = playerWithAddress('max@mempool.example');
    Http::fake(['https://mempool.example/.well-known/nostr.json?name=max' => Http::response(['names' => ['max' => $player->pubkey]])]);

    VerifyNip05::dispatchSync($player);

    expect($player->refresh())->nip05_verified_at->not->toBeNull()->nip05_checked_at->not->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://mempool.example/.well-known/nostr.json?name=max');
});

test('an address that is not confirmed is checked but not verified', function (int $status, string $body, array $headers = []) {
    resolveHosts(['mempool.example' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]);
    $player = playerWithAddress('max@mempool.example');
    Http::fake([
        'https://mempool.example/.well-known/nostr.json?name=max' => Http::response(str_replace(':pubkey', $player->pubkey, $body), $status, $headers),
        // Where the redirect points: it would confirm the key, if it were followed.
        'https://elsewhere.example/*' => Http::response(['names' => ['max' => $player->pubkey]]),
    ]);

    VerifyNip05::dispatchSync($player);

    expect($player->refresh())->nip05_verified_at->toBeNull()->nip05_checked_at->not->toBeNull();
})->with([
    'another key' => [200, '{"names":{"max":"'.str_repeat('0', 64).'"}}'],
    'another name' => [200, '{"names":{"maxi":":pubkey"}}'],
    'not found' => [404, '{"names":{"max":":pubkey"}}'],
    'a redirect, which NIP-05 says is never followed' => [301, '', ['Location' => 'https://elsewhere.example/.well-known/nostr.json?name=max']],
    'more than 64 KB' => [200, '{"names":{"max":":pubkey"},"pad":"'.str_repeat('x', 70_000).'"}'],
    'not JSON' => [200, '<html>'],
]);

test('an address pointing inside the network is refused before any request', function (string $nip05, array $dns) {
    resolveHosts($dns);
    $player = playerWithAddress($nip05);
    // Any host would confirm the key: only the refusal keeps the address unverified,
    // and a request that went out anyway is recorded (a stray one would just throw).
    Http::fake(['*' => Http::response(['names' => ['max' => $player->pubkey]])]);

    VerifyNip05::dispatchSync($player);

    Http::assertNothingSent();
    expect($player->refresh())->nip05_verified_at->toBeNull()->nip05_checked_at->not->toBeNull();
})->with([
    // The first three would even resolve to a public address: the address pattern alone refuses them.
    'IPv4 literal' => ['max@127.0.0.1', ['127.0.0.1' => ['93.184.215.14']]],
    'IPv6 literal' => ['max@[::1]', ['[::1]' => ['93.184.215.14']]],
    'a port' => ['max@mempool.example:8080', ['mempool.example:8080' => ['93.184.215.14'], 'mempool.example' => ['93.184.215.14']]],
    'localhost' => ['max@localhost', ['localhost' => ['127.0.0.1']]],
    'a name on loopback' => ['max@loop.example', ['loop.example' => ['127.0.0.1']]],
    'a name on a private network' => ['max@intranet.example', ['intranet.example' => ['10.0.0.5']]],
    'cloud metadata' => ['max@meta.example', ['meta.example' => ['169.254.169.254']]],
    'carrier-grade NAT' => ['max@cgnat.example', ['cgnat.example' => ['100.64.0.1']]],
    'IPv6 unique local' => ['max@ula.example', ['ula.example' => ['fd00::1']]],
    'IPv4-mapped loopback' => ['max@mapped.example', ['mapped.example' => ['::ffff:127.0.0.1']]],
    'NAT64 to a private address' => ['max@nat64.example', ['nat64.example' => ['64:ff9b::a00:5']]],
    'one public, one private address' => ['max@mixed.example', ['mixed.example' => ['93.184.215.14', '192.168.1.10']]],
    'no address at all' => ['max@nxdomain.example', []],
]);

test('a check whose address changed meanwhile stores nothing', function () {
    resolveHosts(['mempool.example' => ['93.184.215.14']]);
    $player = playerWithAddress('max@mempool.example');
    Http::fake(['https://mempool.example/*' => function () use ($player) {
        $player->forceFill(['nip05' => 'max@other.example'])->save();

        return Http::response(['names' => ['max' => $player->pubkey]]);
    }]);

    VerifyNip05::dispatchSync($player);

    expect($player->refresh())->nip05->toBe('max@other.example')->nip05_verified_at->toBeNull()->nip05_checked_at->toBeNull();
});

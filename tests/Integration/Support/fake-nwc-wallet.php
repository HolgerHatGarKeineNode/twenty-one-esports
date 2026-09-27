<?php

/*
 * `php fake-nwc-wallet.php`: the fake NIP-47 wallet service of the P15/P9
 * integration suite, a relay client of the local `nak serve`
 * (Tests\Integration\Support\FakeNwc). It publishes its info event (13194),
 * subscribes to requests (23194) for its pubkey and answers each one
 * (23195) with Tests\Support\FakeNwcWallet, signatures checked, over the
 * relay the league's app server and queue worker also use.
 *
 * Shared state (env FAKE_NWC_STATE, JSON under an exclusive lock), read
 * before and written after every request:
 *   known   payment hash => preimage of the fake Lightning addresses' invoices
 *   settle  payment hashes of the wallet's own invoices a payer has paid
 *   calls   every request: connection, method, payment hash (never a secret)
 *   paid    outgoing payments by payment hash
 *
 * Env: FAKE_NWC_RELAY, FAKE_NWC_SECRET, FAKE_NWC_PAY_SECRET, FAKE_NWC_RECEIVE_SECRET.
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Support\Lightning\Bolt11;
use Tests\Support\FakeNwcWallet;
use WebSocket\Client;
use WebSocket\Message\Text;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;

$statePath = (string) getenv('FAKE_NWC_STATE');
$wallet = new FakeNwcWallet((string) getenv('FAKE_NWC_SECRET'), ['pay' => (string) getenv('FAKE_NWC_PAY_SECRET'), 'receive' => (string) getenv('FAKE_NWC_RECEIVE_SECRET')]);
$wallet->verifyRequests = true;

$withState = function (callable $work) use ($statePath): void {
    $handle = fopen($statePath, 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode((string) stream_get_contents($handle), true) ?: [];
    $state = $work($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);
};

// One live subscription for the whole run: NIP-47 requests (23194) are
// ephemeral, a relay only hands them to someone listening at that moment
// (`nak serve` answers "mute: no one was listening for this" otherwise), so the
// wallet cannot poll. The connection has no read timeout: a phrity client that
// times out is left half closed and throws on every later read, and replacing
// it again and again was measured (2026-09-27, P9) to stall `nak serve` for
// every other client of the stack. It is replaced only when the relay itself
// closed it, and requests seen before are not handled twice.
$relay = (string) getenv('FAKE_NWC_RELAY');
$handled = [];
$subscribe = function () use ($relay, $wallet): Client {
    $client = new Client($relay);
    // A connection held for the whole run answers the relay's pings and its close
    // (phrity 3 does neither by itself): khatru drops a client that stays silent.
    $client->addMiddleware(new CloseHandler)->addMiddleware(new PingResponder);
    $client->setTimeout(86400);
    $client->text((string) json_encode(['EVENT', $wallet->infoEvent()], JSON_UNESCAPED_SLASHES));
    $client->text((string) json_encode(['REQ', 'requests', ['kinds' => [23194], '#p' => [$wallet->pubkey], 'since' => time() - 60]]));

    return $client;
};
$client = $subscribe();

while (true) {
    try {
        $frame = $client->receive();
    } catch (Throwable) {
        try {
            $client->disconnect();
        } catch (Throwable) {
            // already gone
        }

        sleep(1);

        try {
            $client = $subscribe();
        } catch (Throwable) {
            // the next round tries again
        }

        continue;
    }

    $message = $frame instanceof Text ? json_decode($frame->getContent(), true) : null;

    // Subscribed: from now on no request can pass unheard (FakeNwc::start() waits for this).
    if (is_array($message) && ($message[0] ?? null) === 'EOSE' && ($message[1] ?? null) === 'requests') {
        $withState(function (array $state): array {
            $state['ready'] = true;

            return $state;
        });

        continue;
    }

    if (! is_array($message) || ($message[0] ?? null) !== 'EVENT' || ($message[1] ?? null) !== 'requests' || ! is_array($message[2] ?? null)) {
        continue;
    }

    $request = $message[2];
    $id = (string) ($request['id'] ?? '');

    if ($id === '' || isset($handled[$id])) {
        continue;
    }

    $handled[$id] = true;
    $answer = null;
    $withState(function (array $state) use ($wallet, $request, &$answer): array {
        $wallet->known = $state['known'] ?? [];

        foreach ($state['settle'] ?? [] as $hash) {
            $wallet->settleIncoming($hash);
        }

        $answer = $wallet->handle($request);
        $state['calls'] = array_map(fn (array $call): array => [
            'client' => $call['client'],
            'method' => $call['method'],
            'payment_hash' => $call['params']['payment_hash'] ?? Bolt11::decode((string) ($call['params']['invoice'] ?? ''))?->paymentHash,
        ], $wallet->calls);
        $state['paid'] = array_map(fn (array $paid): array => ['amount_msats' => $paid['amount_msats']], $wallet->paid);

        return $state;
    });

    if ($answer !== null) {
        $client->text((string) json_encode(['EVENT', $answer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

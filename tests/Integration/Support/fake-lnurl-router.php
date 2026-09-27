<?php

/*
 * Router for `php -S 127.0.0.1:<port> fake-lnurl-router.php`
 * (Tests\Integration\Support\FakeNwc): the players' Lightning addresses
 * `<name>@127.0.0.1:<port>` as an LNURL-pay server (LUD-06/16) whose regtest
 * invoices the fake NWC wallet can pay. Each invoice's preimage goes into the
 * shared state file (env FAKE_NWC_STATE, under an exclusive lock), where the
 * wallet process finds it when the league pays. Never a real node.
 *
 *   GET /.well-known/lnurlp/{name}  -> payRequest, callback /cb/{name}
 *   GET /cb/{name}?amount=<msat>    -> {"pr": <bolt11>, "routes": []}
 */

require __DIR__.'/../../../vendor/autoload.php';

use Tests\Support\Bolt11Fixture;

$statePath = (string) getenv('FAKE_NWC_STATE');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$metadata = fn (string $name): string => (string) json_encode([['text/plain', 'Pay '.$name], ['text/identifier', $name.'@'.$host]]);

header('Content-Type: application/json');

if (preg_match('#^/\.well-known/lnurlp/([a-z0-9._-]+)$#', $path, $match) === 1) {
    echo json_encode(['tag' => 'payRequest', 'callback' => 'http://'.$host.'/cb/'.$match[1], 'minSendable' => 1000, 'maxSendable' => 100_000_000_000, 'metadata' => $metadata($match[1])]);

    return;
}

if (preg_match('#^/cb/([a-z0-9._-]+)$#', $path, $match) === 1) {
    $amount = (int) ($_GET['amount'] ?? 0);
    $fixture = Bolt11Fixture::make($amount, hash('sha256', $metadata($match[1])));

    $handle = fopen($statePath, 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode((string) stream_get_contents($handle), true) ?: [];
    $state['known'][$fixture['payment_hash']] = $fixture['preimage'];
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($state));
    flock($handle, LOCK_UN);
    fclose($handle);

    echo json_encode(['pr' => $fixture['invoice'], 'routes' => []]);

    return;
}

http_response_code(404);
echo json_encode(['status' => 'ERROR', 'reason' => 'not found']);

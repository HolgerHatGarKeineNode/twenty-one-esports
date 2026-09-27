<?php

/*
 * Router for `php -S 127.0.0.1:<port> fake-bitcoin-router.php`
 * (Tests\Integration\Support\FakeBitcoin), an Esplora-shaped stand-in for
 * `esports.bitcoin.api` (App\Support\Tournaments\BitcoinBlocks) — never the
 * live mempool.space API. State is a JSON file (env FAKE_BTC_STATE), read
 * fresh on every request: FakeBitcoin::mine() from the test process writes
 * it, this process only reads it, so there is no cache to invalidate.
 *
 * Endpoints, matching BitcoinBlocks exactly:
 *   GET /blocks/tip/height  -> "<int>"
 *   GET /block-height/{h}   -> "<64-hex hash>" or 404
 *   GET /block/{hash}       -> {"timestamp":<unix>} or 404
 */

$statePath = (string) getenv('FAKE_BTC_STATE');
$state = is_string($statePath) && $statePath !== '' && is_file($statePath)
    ? json_decode((string) file_get_contents($statePath), true)
    : null;
$state = is_array($state) ? $state : ['tip' => 0, 'hashes' => [], 'times' => []];

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/blocks/tip/height') {
    header('Content-Type: text/plain');
    echo (string) $state['tip'];

    return;
}

if (preg_match('#^/block-height/(\d+)$#', $path, $m) === 1) {
    $height = (string) (int) $m[1];
    header('Content-Type: text/plain');

    if (isset($state['hashes'][$height])) {
        echo $state['hashes'][$height];
    } else {
        http_response_code(404);
        echo 'Block not found';
    }

    return;
}

if (preg_match('#^/block/([0-9a-f]{64})$#', $path, $m) === 1) {
    $hash = $m[1];

    if (isset($state['times'][$hash])) {
        header('Content-Type: application/json');
        echo json_encode(['id' => $hash, 'timestamp' => $state['times'][$hash]]);
    } else {
        http_response_code(404);
        echo 'Block not found';
    }

    return;
}

http_response_code(404);
echo 'not found';

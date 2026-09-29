<?php

/*
 * A hostile relay for the RelayPublisher limits (P45 security audit F1; after
 * the auditor's slowrelay.py). Prints its port, accepts one client and then:
 *
 *   huge       completes the upgrade, then announces a 1e9-byte text frame and
 *              sends 1 MiB of it (a client that allocates the announced length dies)
 *   drip       completes the upgrade, announces a 50-byte frame, sends one byte a second
 *   handshake  drips the "101 Switching Protocols" answer one byte a second
 *   flood      completes the upgrade, then sends 100 NOTICE frames of 1 KiB each
 *   ok         completes the upgrade and answers OK true (positive control)
 *   big        completes the upgrade, then sends one 100 000-byte NOTICE frame
 *   fragments  completes the upgrade, then sends one text message in 200 fragments
 *              of 60 000 bytes each (every frame small, the message 12 MB)
 *   answer     a NIP-47 relay (P47 follow-up): answers the client's REQ with EOSE,
 *              waits argv[2] seconds (default 3) and then sends an EVENT on that
 *              subscription (positive control for a wallet that answers late)
 *
 * Lives at most 60 s.
 */

$mode = $argv[1] ?? 'ok';
$server = stream_socket_server('tcp://127.0.0.1:0');
echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n";
fflush(STDOUT);

$client = stream_socket_accept($server, 30);

if ($client === false) {
    exit(1);
}

stream_set_timeout($client, 5);
$request = '';

while (! str_contains($request, "\r\n\r\n")) {
    $chunk = fread($client, 8192);

    if ($chunk === '' || $chunk === false) {
        exit(1);
    }

    $request .= $chunk;
}

preg_match('/Sec-WebSocket-Key: (\S+)/i', $request, $key);
$accept = base64_encode(sha1($key[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
$upgrade = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
$until = microtime(true) + 60;

if ($mode === 'handshake') {
    foreach (str_split($upgrade) as $byte) {
        if (@fwrite($client, $byte) === false || microtime(true) > $until) {
            exit(0);
        }
        fflush($client);
        sleep(1);
    }
    exit(0);
}

fwrite($client, $upgrade);
// The client's first frame (RelayPublisher's EVENT, the NWC transport's REQ).
$first = (string) fread($client, 65536);

/*
 * The payload of a masked client frame read in one piece (small frames only).
 */
$unmask = function (string $frame): string {
    $length = ord($frame[1]) & 0x7F;
    $offset = 2;

    if ($length === 126) {
        $length = unpack('n', substr($frame, 2, 2))[1];
        $offset = 4;
    }

    $mask = substr($frame, $offset, 4);
    $payload = substr($frame, $offset + 4, $length);

    for ($i = 0; $i < strlen($payload); $i++) {
        $payload[$i] = $payload[$i] ^ $mask[$i % 4];
    }

    return $payload;
};

$text = fn (string $payload): string => "\x81".match (true) {
    strlen($payload) < 126 => chr(strlen($payload)),
    strlen($payload) < 65536 => "\x7E".pack('n', strlen($payload)),
    default => "\x7F".pack('J', strlen($payload)),
}.$payload;

switch ($mode) {
    case 'ok':
        fwrite($client, $text(json_encode(['OK', str_repeat('a', 64), true, ''])));
        sleep(2);
        break;

    case 'huge':
        @fwrite($client, "\x81\x7F".pack('J', 1_000_000_000));
        $block = str_repeat('x', 65536);
        for ($i = 0; $i < 16 && microtime(true) < $until; $i++) {
            if (@fwrite($client, $block) === false) {
                break;
            }
        }
        sleep(2);
        break;

    case 'drip':
        @fwrite($client, "\x81".chr(50));
        while (microtime(true) < $until && @fwrite($client, 'x') !== false) {
            fflush($client);
            sleep(1);
        }
        break;

    case 'big':
        @fwrite($client, $text(json_encode(['NOTICE', str_repeat('b', 100000)])));
        sleep(2);
        break;

    case 'fragments':
        $block = str_repeat('f', 60000);
        for ($i = 0; $i < 200 && microtime(true) < $until; $i++) {
            $head = chr(($i === 0 ? 0x1 : 0x0))."\x7E".pack('n', strlen($block));
            if (@fwrite($client, $head.$block) === false) {
                break;
            }
        }
        sleep(2);
        break;

    case 'answer':
        $subscription = json_decode($unmask($first), true)[1] ?? '';
        fwrite($client, $text(json_encode(['EOSE', $subscription])));
        // The client's EVENT (the wallet request) follows the EOSE.
        fread($client, 65536);
        sleep((int) ($argv[2] ?? 3));
        @fwrite($client, $text(json_encode(['EVENT', $subscription, ['id' => str_repeat('e', 64), 'kind' => 23195, 'created_at' => time(), 'content' => 'late']])));
        sleep(2);
        break;

    case 'flood':
        for ($i = 0; $i < 100 && microtime(true) < $until; $i++) {
            if (@fwrite($client, $text(json_encode(['NOTICE', str_repeat('n', 1000)]))) === false) {
                break;
            }
        }
        sleep(2);
        break;
}

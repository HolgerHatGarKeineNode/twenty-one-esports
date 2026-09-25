<?php

/*
 * A one-connection fake relay for RelayPublisher tests.
 *
 * Usage: php fake-relay.php <scenario>
 * Prints the port it listens on, accepts one client, answers the WebSocket
 * upgrade, reads the client's EVENT frame and then answers according to the
 * scenario. Frames are built here by hand so the receive path of
 * RelayConnection meets 16-bit and 64-bit lengths, split writes, PING and
 * CLOSE. The OK reason in the scenarios is "reason:<length>:" padded with x.
 */

$scenario = $argv[1] ?? 'ok';
$server = stream_socket_server('tcp://127.0.0.1:0');
echo parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n";
fflush(STDOUT);

$client = stream_socket_accept($server, 10);
stream_set_timeout($client, 5);

$request = '';

while (! str_contains($request, "\r\n\r\n")) {
    $request .= fread($client, 8192);
}

preg_match('/Sec-WebSocket-Key: (\S+)/i', $request, $key);
$accept = base64_encode(sha1($key[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

[$opcode, $payload] = readClientFrame($client);
$eventId = json_decode($payload, true)[1]['id'];

$reason = fn (int $length): string => str_pad("reason:{$length}:", $length, 'x');
$ok = fn (string $message): string => json_encode(['OK', $eventId, false, $message]);

switch ($scenario) {
    case 'ok16':
        fwrite($client, frame(0x1, $ok($reason(300))));
        break;

    case 'ok64':
        fwrite($client, frame(0x1, $ok($reason(70000))));
        break;

    case 'split':
        // A long NOTICE first, then the OK, both dribbled out in small pieces.
        $bytes = frame(0x1, json_encode(['NOTICE', $reason(200)])).frame(0x1, $ok($reason(400)));

        foreach (str_split($bytes, 7) as $piece) {
            fwrite($client, $piece);
            fflush($client);
            usleep(2000);
        }
        break;

    case 'continuation':
        // One message in three frames: text (FIN=0), continuation, continuation (FIN=1).
        $message = $ok($reason(500));
        [$first, $second, $third] = [substr($message, 0, 100), substr($message, 100, 200), substr($message, 300)];
        fwrite($client, frame(0x1, $first, false).frame(0x0, $second, false).frame(0x0, $third));
        break;

    case 'ping':
        fwrite($client, frame(0x9, 'are-you-there'));
        [$opcode, $payload] = readClientFrame($client);
        $answer = $opcode === 0xA && $payload === 'are-you-there' ? 'pong received' : 'no pong';
        fwrite($client, frame(0x1, $ok($answer)));
        break;

    case 'close':
        fwrite($client, frame(0x8, pack('n', 1008).'policy: '.$reason(100)));
        break;
}

// Keep the socket open until the client is done with it.
stream_set_timeout($client, 3);
while (! feof($client) && fread($client, 8192) !== '') {
}

/**
 * An unmasked server frame.
 */
function frame(int $opcode, string $payload, bool $final = true): string
{
    $length = strlen($payload);
    $head = chr(($final ? 0x80 : 0x00) | $opcode);

    if ($length < 126) {
        return $head.chr($length).$payload;
    }

    if ($length < 65536) {
        return $head.chr(126).pack('n', $length).$payload;
    }

    return $head.chr(127).pack('J', $length).$payload;
}

/**
 * @param  resource  $client
 * @return array{0: int, 1: string}
 */
function readClientFrame($client): array
{
    $head = readExactly($client, 2);
    $length = ord($head[1]) & 0x7F;

    if ($length === 126) {
        $length = unpack('n', readExactly($client, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', readExactly($client, 8))[1];
    }

    $mask = readExactly($client, 4);
    $data = readExactly($client, $length);

    return [ord($head[0]) & 0x0F, $data ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length)];
}

/**
 * @param  resource  $client
 */
function readExactly($client, int $length): string
{
    $data = '';

    while (strlen($data) < $length && ! feof($client)) {
        $data .= fread($client, $length - strlen($data));
    }

    return $data;
}
